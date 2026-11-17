<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Small process runner shared by the OpenCode, ffmpeg and Whisper clients.
 *
 * stdout/stderr are redirected to temporary files and the process is polled
 * with proc_get_status(): PHP on Windows blocks on pipe reads and ignores
 * stream_select()/stream_set_blocking() for process pipes, which made long
 * prompts hang until the child exited.
 */
final class Shell
{
    /**
     * @param string[] $command
     * @param array<string, string>|null $env
     * @return array{stdout:string, stderr:string, exit:int, timeout:bool, seconds:float}
     */
    public static function run(
        array $command,
        ?string $stdin = null,
        int $timeout = 300,
        ?string $cwd = null,
        ?array $env = null
    ): array {
        $started = microtime(true);
        Config::ensureDirectory(Config::tmpDir());
        $token = bin2hex(random_bytes(8));
        $tmp = Config::tmpDir();
        $stdinFile = null;
        $outFile = $tmp . DIRECTORY_SEPARATOR . 'out_' . $token . '.log';
        $errFile = $tmp . DIRECTORY_SEPARATOR . 'err_' . $token . '.log';

        if ($stdin !== null && $stdin !== '') {
            $stdinFile = $tmp . DIRECTORY_SEPARATOR . 'stdin_' . $token . '.txt';
            if (@file_put_contents($stdinFile, $stdin) === false) {
                return [
                    'stdout'  => '',
                    'stderr'  => 'No se pudo escribir el prompt temporal en ' . $tmp,
                    'exit'    => -1,
                    'timeout' => false,
                    'seconds' => 0.0,
                ];
            }
        }

        $descriptors = [
            0 => $stdinFile !== null
                ? ['file', $stdinFile, 'r']
                : ['file', self::nullDevice(), 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, $env ?? self::environment());
        if (!is_resource($process)) {
            @unlink($outFile);
            @unlink($errFile);
            if ($stdinFile !== null) {
                @unlink($stdinFile);
            }
            return [
                'stdout'  => '',
                'stderr'  => 'No se pudo iniciar el proceso: ' . implode(' ', $command),
                'exit'    => -1,
                'timeout' => false,
                'seconds' => 0.0,
            ];
        }

        $timedOut = false;
        $exit = -1;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = (int) ($status['exitcode'] ?? -1);
                break;
            }

            if ($timeout > 0 && (microtime(true) - $started) > $timeout) {
                $timedOut = true;
                @proc_terminate($process, 9);

                // Give the child a moment to die before proc_close().
                $deadline = microtime(true) + 5;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        $exit = (int) ($status['exitcode'] ?? -1);
                        break;
                    }
                    usleep(50000);
                }
                break;
            }

            usleep(50000);
        }

        $closeExit = @proc_close($process);
        if ($exit < 0) {
            $exit = $closeExit;
        }

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        @unlink($outFile);
        @unlink($errFile);
        if ($stdinFile !== null) {
            @unlink($stdinFile);
        }

        return [
            'stdout'  => $stdout,
            'stderr'  => $stderr,
            'exit'    => $exit,
            'timeout' => $timedOut,
            'seconds' => round(microtime(true) - $started, 2),
        ];
    }

    public static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    /** @return array<string, string> */
    public static function environment(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        if (PHP_OS_FAMILY === 'Windows' && empty($env['SystemRoot'])) {
            $env['SystemRoot'] = 'C:\\Windows';
        }
        return $env;
    }

    /**
     * Resolve an executable from PATH (and a few known locations).
     *
     * @param string[] $names
     * @param string[] $knownLocations
     */
    public static function resolveBinary(array $names, string $configured = '', array $knownLocations = []): ?string
    {
        $configured = trim($configured);
        $cacheKey = md5((string) json_encode([$configured, $names, $knownLocations]));
        $cache = self::binaryCache();
        if (isset($cache[$cacheKey])) {
            $entry = $cache[$cacheKey];
            $ttl = $entry['path'] !== null ? 3600 : 300;
            if ((time() - (int) ($entry['ts'] ?? 0)) < $ttl) {
                if ($entry['path'] === null) {
                    return null;
                }
                if (is_file((string) $entry['path'])) {
                    return (string) $entry['path'];
                }
            }
        }

        $resolved = self::resolveBinaryUncached($names, $configured, $knownLocations);
        $cache[$cacheKey] = ['path' => $resolved, 'ts' => time()];
        self::writeBinaryCache($cache);

        return $resolved;
    }

    /**
     * @param string[] $names
     * @param string[] $knownLocations
     */
    private static function resolveBinaryUncached(array $names, string $configured, array $knownLocations): ?string
    {
        if ($configured !== '') {
            $direct = self::normalizeBinary($configured);
            if ($direct !== null) {
                return $direct;
            }
            if (is_dir($configured)) {
                foreach ($names as $name) {
                    $inside = self::normalizeBinary(rtrim($configured, "/\\") . DIRECTORY_SEPARATOR . $name);
                    if ($inside !== null) {
                        return $inside;
                    }
                }
            }
        }

        $candidates = self::whichCandidates($names);
        usort($candidates, static function (string $a, string $b): int {
            $weight = static function (string $path): int {
                return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
                    'exe' => 0,
                    'cmd' => 1,
                    'bat' => 2,
                    default => 3,
                };
            };
            return $weight($a) <=> $weight($b);
        });

        foreach ($candidates as $candidate) {
            $resolved = self::normalizeBinary($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach ($knownLocations as $location) {
            $resolved = self::normalizeBinary($location);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    public static function normalizeBinary(string $path): ?string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return null;
        }

        $real = @realpath($path);
        if ($real === false || !is_file($real)) {
            return null;
        }

        if (PHP_OS_FAMILY !== 'Windows' && !is_executable($real)) {
            return null;
        }

        return $real;
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    public static function whichCandidates(array $names): array
    {
        $found = [];
        $probe = PHP_OS_FAMILY === 'Windows' ? 'where.exe' : 'command -v';
        $args = implode(' ', array_map('escapeshellarg', $names));
        $output = @shell_exec($probe . ' ' . $args . ' 2>&1');
        if (is_string($output) && trim($output) !== '') {
            foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, 'INFO:')) {
                    $found[] = $line;
                }
            }
        }

        if ($found !== []) {
            return array_values(array_unique($found));
        }

        // Fallback: search PATH manually only when shell_exec is unavailable.
        if (function_exists('shell_exec')) {
            return [];
        }

        $path = (string) (getenv('PATH') ?: '');
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $found[] = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name;
            }
        }

        return array_values(array_unique($found));
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /** @return array<string, array{path:?string, ts:int}> */
    private static function binaryCache(): array
    {
        $path = Config::tmpDir() . '/binaries.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, array{path:?string, ts:int}> $cache */
    private static function writeBinaryCache(array $cache): void
    {
        Config::ensureDirectory(Config::tmpDir());
        @file_put_contents(Config::tmpDir() . '/binaries.json', (string) json_encode($cache));
    }
}
