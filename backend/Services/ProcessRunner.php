<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Launches background worker processes with real logging and PID tracking,
 * so long AI/Whisper tasks are not silent on failure.
 */
final class ProcessRunner
{
    /**
     * Start a command in the background, redirecting stdout+stderr to a log.
     * Returns the child PID (Linux) or 0 when the PID is unknown (Windows).
     */
    public static function background(array $command, string $logName): ?int
    {
        if (!self::canRunShell() || !$command) {
            return null;
        }

        Config::ensureDirectory(Config::tmpDir());
        $log = self::logPath($logName);
        $lock = self::lockPath($logName);
        @unlink($lock);

        $cmd = implode(' ', array_map('escapeshellarg', $command));

        if (self::isWindows()) {
            $line = $cmd . ' > ' . escapeshellarg($log) . ' 2>&1';
            $handle = @popen('start "" /B ' . $line, 'r');
            if (is_resource($handle)) {
                pclose($handle);
            }
            @file_put_contents($lock, '0');
            return 0;
        }

        $full = 'nohup ' . $cmd
              . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pid = (int) trim((string) shell_exec($full));

        if ($pid > 0) {
            @file_put_contents($lock, (string) $pid);
            return $pid;
        }
        return null;
    }

    /**
     * @return array{state:string, pid:int, tail:string, modified:int}
     */
    public static function status(string $logName): array
    {
        $log = self::logPath($logName);
        $lock = self::lockPath($logName);
        $pid = is_file($lock) ? (int) trim((string) file_get_contents($lock)) : 0;
        $running = $pid > 0 && self::isRunning($pid);
        $tail = self::readLog($logName, 8);

        if ($running) {
            $state = 'running';
        } elseif (is_file($log)) {
            $state = self::looksLikeError($tail) ? 'error' : 'done';
        } else {
            $state = 'none';
        }

        if (!$running && $pid > 0 && is_file($lock)) {
            @unlink($lock);
        }
        // Windows workers are started with `start /B` and do not expose a PID;
        // once the log has been idle for a while the lock is no longer useful.
        if ($pid === 0 && is_file($lock) && is_file($log) && (time() - (int) filemtime($log)) > 300) {
            @unlink($lock);
        }

        return [
            'state'    => $state,
            'pid'      => $pid,
            'tail'     => $tail,
            'modified' => is_file($log) ? (int) filemtime($log) : 0,
        ];
    }

    public static function readLog(string $logName, int $lines = 200): string
    {
        $file = self::logPath($logName);
        if (!is_file($file)) {
            return '';
        }
        $handle = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($handle === false) {
            return '';
        }
        return implode("\n", array_slice($handle, -max(1, $lines)));
    }

    /**
     * @return array<int, array{name:string, state:string, modified:string, tail:string}>
     */
    public static function recentLogs(int $limit = 8): array
    {
        $dir = Config::tmpDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.log') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $logs = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $name = basename($file, '.log');
            $state = self::status($name);
            $logs[] = [
                'name'     => $name,
                'state'    => $state['state'],
                'modified' => date('Y-m-d H:i:s', (int) filemtime($file)),
                'tail'     => $state['tail'],
            ];
        }
        return $logs;
    }

    public static function resolveBinary(string $binary): ?string
    {
        if (!self::canRunShell() || $binary === '') {
            return null;
        }
        $command = self::isWindows() ? 'where ' : 'command -v ';
        $output = trim((string) shell_exec($command . escapeshellarg($binary) . ' 2>&1'));
        if ($output === '') {
            return null;
        }
        return (string) strtok($output, "\n");
    }

    public static function canRunShell(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = (string) ini_get('disable_functions');
        if ($disabled !== '') {
            $list = array_map('trim', explode(',', $disabled));
            if (in_array('shell_exec', $list, true)) {
                return false;
            }
        }
        return true;
    }

    public static function logDirWritable(): bool
    {
        $dir = Config::tmpDir();
        Config::ensureDirectory($dir);
        return is_dir($dir) && is_writable($dir);
    }

    public static function logPath(string $name): string
    {
        return Config::tmpDir() . '/' . self::safeName($name) . '.log';
    }

    public static function lockPath(string $name): string
    {
        return Config::tmpDir() . '/' . self::safeName($name) . '.lock';
    }

    private static function safeName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);
        return $safe === '' ? 'worker' : $safe;
    }

    private static function looksLikeError(string $text): bool
    {
        return $text !== '' && preg_match(
            '/(fatal error|parse error|traceback|uncaught|\berror\b|no se pud|no pud|fallo|exception|no such file|not recognized|command not found)/i',
            $text
        ) === 1;
    }

    private static function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (self::isWindows()) {
            $out = (string) shell_exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL');
            return preg_match('/\b' . $pid . '\b/', $out) === 1;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return is_file('/proc/' . $pid);
    }

    private static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
