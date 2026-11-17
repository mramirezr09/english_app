<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader/writer (same pattern used by retro_app).
 *
 * Values loaded here take precedence over process environment variables, so
 * the settings page can persist and override them without restarting PHP.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $data = [];
    private static bool $loaded = false;
    private static string $path = '';

    public static function load(string $path): void
    {
        self::$path = $path;
        self::$data = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];
                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }
                self::$data[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }
        $path = defined('BASE_PATH') ? BASE_PATH . '/.env' : dirname(__DIR__, 2) . '/.env';
        self::load($path);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::ensureLoaded();
        if (array_key_exists($key, self::$data)) {
            return self::$data[$key];
        }
        $env = getenv($key);
        return $env === false ? $default : (string) $env;
    }

    public static function has(string $key): bool
    {
        self::ensureLoaded();
        return array_key_exists($key, self::$data) && self::$data[$key] !== '';
    }

    public static function set(string $key, string $value): void
    {
        self::ensureLoaded();
        self::$data[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::ensureLoaded();
        unset(self::$data[$key]);
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        self::ensureLoaded();
        return self::$data;
    }

    public static function path(): string
    {
        self::ensureLoaded();
        return self::$path;
    }

    public static function save(): void
    {
        self::ensureLoaded();
        $lines = [];
        foreach (self::$data as $key => $value) {
            $lines[] = $key . '=' . $value;
        }
        $content = implode(PHP_EOL, $lines) . PHP_EOL;

        $written = @file_put_contents(self::$path, $content, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException(
                'No se pudo escribir .env en ' . self::$path . '. Revisa los permisos del usuario del servidor.'
            );
        }
    }

    public static function mask(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 8) {
            return str_repeat('*', $length);
        }
        return substr($value, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($value, -4);
    }
}
