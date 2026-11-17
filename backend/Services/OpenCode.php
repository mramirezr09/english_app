<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Env;

/**
 * OpenCode CLI client.
 *
 * Runs `opencode run --format json -m <model>` with the prompt on stdin and
 * parses the JSONL stream. Uses an isolated HOME/XDG tree so the app never
 * mixes its sessions with the interactive user's OpenCode profile.
 */
final class OpenCode
{
    public const DEFAULT_MODELS = [
        'opencode-go/deepseek-v4.1-flash',
        'opencode-go/glm-5.3-flash',
        'opencode-go/minimax-m3.1-flash',
        'opencode-go/deepseek-v4-pro',
        'opencode-go/claude-haiku-5-5',
        'opencode-go/gpt-6-luna',
        'opencode-go/gemini-4-argon',
        'opencode-go/hy4-flash-free',
        'opencode-go/beam-free',
        'opencode/glm-5.3-flash-free',
        'opencode/deepseek-v4.1-flash-free',
    ];

    private static ?string $binary = null;
    private static bool $binaryResolved = false;
    private static bool $portEnsured = false;
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function binary(): ?string
    {
        if (self::$binaryResolved) {
            return self::$binary;
        }
        self::$binaryResolved = true;

        $cfg = AiConfig::load();
        $configured = trim((string) Env::get('OPENCODE_BIN', (string) ($cfg['opencode']['binary'] ?? '')));

        $names = Shell::isWindows()
            ? ['opencode.exe', 'opencode.cmd', 'opencode.bat', 'opencode']
            : ['opencode'];

        $known = [];
        if (Shell::isWindows()) {
            foreach ([getenv('APPDATA') ?: null, getenv('LOCALAPPDATA') ?: null, getenv('USERPROFILE') ?: null] as $dir) {
                if ($dir) {
                    $known[] = $dir . '\\opencode\\bin\\opencode.exe';
                    $known[] = $dir . '\\npm\\node_modules\\@opencode\\cli\\bin\\opencode.exe';
                }
            }
        } else {
            $home = (string) (getenv('HOME') ?: '');
            $known = array_filter([
                '/usr/local/bin/opencode',
                '/usr/bin/opencode',
                '/opt/opencode/bin/opencode',
                '/snap/bin/opencode',
                $home !== '' ? rtrim($home, '/') . '/.opencode/bin/opencode' : null,
                $home !== '' ? rtrim($home, '/') . '/.local/bin/opencode' : null,
            ]);
        }

        $resolved = Shell::resolveBinary($names, $configured, array_values($known));

        // npm's .cmd shim is not always runnable without a shell; prefer the
        // native executable shipped next to it.
        if ($resolved !== null && Shell::isWindows()) {
            $extension = strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION));
            if ($extension === 'cmd' || $extension === 'bat') {
                $native = self::nativeShimTarget($resolved);
                if ($native !== null) {
                    $resolved = $native;
                }
            }
        }

        self::$binary = $resolved;
        return self::$binary;
    }

    private static function nativeShimTarget(string $shim): ?string
    {
        $dir = dirname($shim);
        $candidates = [
            $dir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . '@opencode' . DIRECTORY_SEPARATOR . 'cli' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opencode.exe',
            $dir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'opencode-ai' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opencode.exe',
        ];
        foreach ($candidates as $candidate) {
            $resolved = Shell::normalizeBinary($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        return null;
    }

    public static function apiKey(): string
    {
        return trim((string) Env::get('OPENCODE_API_KEY', ''));
    }

    public static function model(): string
    {
        return AiConfig::openCodeModel();
    }

    public static function isFreeModel(string $model): bool
    {
        return str_contains($model, '-free') || str_starts_with($model, 'opencode/');
    }

    /**
     * Send a prompt and return the assistant text, or null on failure.
     *
     * @param array{model?:string, timeout?:int, json_mode?:bool} $options
     */
    public static function chat(string $prompt, array $options = []): ?string
    {
        self::$lastError = null;

        $model = trim((string) ($options['model'] ?? self::model()));
        if ($model === '') {
            self::$lastError = 'No hay modelo de OpenCode configurado (OPENCODE_MODEL).';
            return null;
        }

        $key = self::apiKey();
        if (str_starts_with($model, 'opencode-go/') && $key === '') {
            self::$lastError = 'Falta OPENCODE_API_KEY en .env para usar modelos de OpenCode Go.';
            return null;
        }

        $binary = self::binary();
        if ($binary === null) {
            self::$lastError = 'No se encontro el ejecutable de opencode. Configura la ruta en Ajustes o en OPENCODE_BIN.';
            return null;
        }

        self::ensureServicePort();

        $timeout = max(10, (int) ($options['timeout'] ?? self::timeout()));
        $arguments = ['run', '--format', 'json', '-m', $model];
        if (self::archiveSessionsSupported()) {
            $arguments[] = '--archived';
        }

        $result = Shell::run(
            array_merge([$binary], $arguments),
            $prompt,
            $timeout,
            self::workDir(),
            self::environment($key)
        );

        if ($result['timeout']) {
            self::$lastError = 'OpenCode excedio el tiempo limite de ' . $timeout . ' s.';
            self::log('[opencode] timeout model=' . $model . ' seconds=' . $result['seconds']);
            return null;
        }

        $parsed = self::parseEvents($result['stdout']);
        $text = $parsed['text'];

        if ($result['exit'] !== 0 || $text === '') {
            $detail = $parsed['error'];
            if ($detail === '') {
                $detail = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
            }
            self::$lastError = $detail !== ''
                ? $detail
                : 'OpenCode no devolvio contenido (exit ' . $result['exit'] . ').';
            self::log('[opencode] error model=' . $model . ' exit=' . $result['exit'] . ': ' . self::short($detail));
            return null;
        }

        self::log(
            '[opencode] model=' . $model
            . ' prompt=' . strlen($prompt)
            . ' chars reply=' . strlen($text)
            . ' chars seconds=' . $result['seconds']
            . ' tokens_in=' . $parsed['tokens_in']
            . ' tokens_out=' . $parsed['tokens_out']
        );

        return $text;
    }

    /**
     * Run a short health check. Returns detailed information for the UI.
     *
     * @return array{ok:bool, text:string, error:?string, model:string, seconds:float, tokens:?array}
     */
    public static function test(string $prompt = 'Reply with exactly: READY'): array
    {
        $started = microtime(true);
        $text = self::chat($prompt, ['timeout' => 90]);

        return [
            'ok'      => $text !== null && trim($text) !== '',
            'text'    => (string) $text,
            'error'   => $text === null ? self::$lastError : null,
            'model'   => self::model(),
            'seconds' => round(microtime(true) - $started, 2),
            'tokens'  => null,
        ];
    }

    /**
     * List provider/model identifiers from the CLI (cached for 10 minutes).
     *
     * @return string[]
     */
    public static function models(bool $fresh = false): array
    {
        $cache = Config::tmpDir() . '/opencode_models.json';
        if (is_file($cache)) {
            $data = json_decode((string) file_get_contents($cache), true);
            if (is_array($data) && (time() - (int) ($data['ts'] ?? 0)) < 600 && !empty($data['models'])) {
                return array_values(array_map('strval', $data['models']));
            }
        }

        // Slow path: only run the CLI when explicitly requested. The settings
        // page uses the cached/default list so it opens instantly.
        if (!$fresh) {
            return self::DEFAULT_MODELS;
        }

        $binary = self::binary();
        if ($binary === null) {
            return self::DEFAULT_MODELS;
        }

        self::ensureServicePort();

        $result = Shell::run([$binary, 'models'], null, 30, self::workDir(), self::environment(self::apiKey()));
        $models = [];
        foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('#^[a-z0-9._-]+/[a-z0-9._-]+#i', $line, $match) === 1) {
                $models[] = $match[0];
            }
        }
        $models = array_values(array_unique($models));

        if (!$models) {
            return self::DEFAULT_MODELS;
        }

        Config::ensureDirectory(Config::tmpDir());
        @file_put_contents($cache, json_encode(['ts' => time(), 'models' => $models]));

        return $models;
    }

    public static function version(bool $fresh = false): ?string
    {
        $binary = self::binary();
        if ($binary === null) {
            return null;
        }

        $cache = Config::tmpDir() . '/opencode_version.json';
        if (!$fresh && is_file($cache)) {
            $data = json_decode((string) file_get_contents($cache), true);
            if (is_array($data)
                && ($data['binary'] ?? '') === $binary
                && (time() - (int) ($data['ts'] ?? 0)) < 86400
                && is_string($data['version'] ?? null)
            ) {
                return $data['version'];
            }
        }

        $result = Shell::run([$binary, '--version'], null, 20, self::workDir(), self::environment(self::apiKey()));
        $version = trim($result['stdout'] . ' ' . $result['stderr']);
        $version = $version !== '' ? $version : null;

        if ($version !== null) {
            Config::ensureDirectory(Config::tmpDir());
            @file_put_contents($cache, (string) json_encode(['ts' => time(), 'binary' => $binary, 'version' => $version]));
        }

        return $version;
    }

    public static function timeout(): int
    {
        $cfg = AiConfig::load();
        return max(10, (int) ($cfg['opencode']['timeout'] ?? 300));
    }

    /**
     * @return array{binary:?string, version:?string, key:string, key_masked:string, model:string, work_dir:string, service_port:int}
     */
    public static function diagnostics(): array
    {
        $key = self::apiKey();
        return [
            'binary'       => self::binary(),
            'version'      => self::version(),
            'key'          => $key !== '' ? 'ok' : 'missing',
            'key_masked'   => $key !== '' ? Env::mask($key) : '',
            'model'        => self::model(),
            'work_dir'     => self::workDir(),
            'service_port' => self::servicePort(),
        ];
    }

    public static function servicePort(): int
    {
        $fromEnv = trim((string) Env::get('OPENCODE_SERVICE_PORT', ''));
        if ($fromEnv !== '' && ctype_digit($fromEnv)) {
            return (int) $fromEnv;
        }
        $cfg = AiConfig::load();
        return max(1024, (int) ($cfg['opencode']['service_port'] ?? 28994));
    }

    /**
     * Give the app's isolated profile its own service port.
     *
     * OpenCode's default port is shared per machine, so an isolated HOME would
     * otherwise collide with the interactive user's background service and
     * fail to start ("port already in use").
     */
    private static function ensureServicePort(): void
    {
        if (self::$portEnsured) {
            return;
        }
        self::$portEnsured = true;

        $binary = self::binary();
        if ($binary === null) {
            return;
        }

        $configDir = self::homeDir() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'opencode';
        Config::ensureDirectory($configDir);
        $file = $configDir . DIRECTORY_SEPARATOR . 'service.json';
        $port = self::servicePort();

        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && (int) ($data['port'] ?? 0) === $port) {
                return;
            }
        }

        $result = Shell::run(
            [$binary, 'service', 'set', 'port', (string) $port],
            null,
            20,
            self::workDir(),
            self::environment(self::apiKey())
        );
        self::log('[opencode] service port=' . $port . ' exit=' . $result['exit']);
    }

    private static function archiveSessionsSupported(): bool
    {
        $cfg = AiConfig::load();
        if (empty($cfg['opencode']['archive_sessions'])) {
            return false;
        }

        $cache = Config::tmpDir() . '/opencode_caps.json';
        if (is_file($cache)) {
            $data = json_decode((string) file_get_contents($cache), true);
            if (is_array($data) && (time() - (int) ($data['ts'] ?? 0)) < 86400 && array_key_exists('archived', $data)) {
                return (bool) $data['archived'];
            }
        }

        $binary = self::binary();
        if ($binary === null) {
            return false;
        }
        $result = Shell::run([$binary, 'run', '--help'], null, 20, self::workDir(), self::environment(self::apiKey()));
        $supported = str_contains($result['stdout'] . $result['stderr'], '--archived');

        Config::ensureDirectory(Config::tmpDir());
        @file_put_contents($cache, json_encode(['ts' => time(), 'archived' => $supported]));

        return $supported;
    }

    /**
     * @return array{text:string, error:string, tokens_in:?int, tokens_out:?int}
     */
    private static function parseEvents(string $output): array
    {
        $texts = [];
        $lastStepTexts = [];
        $stepTexts = [];
        $sawStep = false;
        $error = '';
        $tokensIn = null;
        $tokensOut = null;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }

            $type = (string) ($event['type'] ?? '');
            if ($type === 'step_start') {
                if ($sawStep && $stepTexts !== []) {
                    $lastStepTexts = $stepTexts;
                }
                $stepTexts = [];
                $sawStep = true;
                continue;
            }

            if ($type === 'text') {
                $text = $event['part']['text'] ?? ($event['text'] ?? null);
                if (is_string($text) && trim($text) !== '') {
                    $texts[] = trim($text);
                    $stepTexts[] = trim($text);
                }
                continue;
            }

            if ($type === 'step_finish') {
                $tokens = $event['part']['tokens'] ?? null;
                if (is_array($tokens)) {
                    $in = $tokens['input'] ?? null;
                    $out = $tokens['output'] ?? null;
                    if (is_numeric($in)) {
                        $tokensIn = ($tokensIn ?? 0) + (int) $in;
                    }
                    if (is_numeric($out)) {
                        $tokensOut = ($tokensOut ?? 0) + (int) $out;
                    }
                }
                continue;
            }

            if ($type === 'error') {
                $message = $event['error']['message'] ?? ($event['message'] ?? '');
                if (is_string($message) && $message !== '') {
                    $error = $message;
                }
            }
        }

        if ($stepTexts !== []) {
            $lastStepTexts = $stepTexts;
        }

        $text = $lastStepTexts !== [] ? implode("\n", $lastStepTexts) : implode("\n", $texts);
        if ($text === '') {
            // Some versions/commands emit plain text instead of JSONL.
            $text = trim($output);
        }

        return [
            'text'       => trim($text),
            'error'      => $error,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }

    /** @return array<string, string> */
    private static function environment(string $key): array
    {
        $env = Shell::environment();
        if ($key !== '') {
            $env['OPENCODE_API_KEY'] = $key;
        }

        $home = self::homeDir();
        $env['HOME'] = $home;
        $env['XDG_CONFIG_HOME'] = $home . DIRECTORY_SEPARATOR . 'config';
        $env['XDG_CACHE_HOME'] = $home . DIRECTORY_SEPARATOR . 'cache';
        $env['XDG_DATA_HOME'] = $home . DIRECTORY_SEPARATOR . 'data';
        $env['XDG_STATE_HOME'] = $home . DIRECTORY_SEPARATOR . 'state';

        return $env;
    }

    private static function homeDir(): string
    {
        $home = Config::runtimeDir() . DIRECTORY_SEPARATOR . 'opencode';
        foreach (['', 'config', 'cache', 'data', 'state', 'work'] as $sub) {
            Config::ensureDirectory($sub === '' ? $home : $home . DIRECTORY_SEPARATOR . $sub);
        }
        return $home;
    }

    private static function workDir(): string
    {
        $dir = self::homeDir() . DIRECTORY_SEPARATOR . 'work';
        Config::ensureDirectory($dir);
        return $dir;
    }

    private static function log(string $message): void
    {
        Config::ensureDirectory(Config::tmpDir());
        $path = Config::tmpDir() . '/ai_client.log';
        @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }

    private static function short(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return strlen($value) > 500 ? substr($value, 0, 500) . '...' : $value;
    }
}
