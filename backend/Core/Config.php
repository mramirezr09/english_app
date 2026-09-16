<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Central paths and application-wide constants.
 */
final class Config
{
    public const WHISPER_MODELS = ['tiny', 'base', 'small', 'medium', 'large-v3', 'large'];

    public static function root(): string
    {
        return BASE_PATH;
    }

    public static function configDir(): string
    {
        return self::root() . '/config';
    }

    public static function dataDir(): string
    {
        return self::root() . '/data';
    }

    public static function storageDir(): string
    {
        return self::root() . '/storage';
    }

    public static function tmpDir(): string
    {
        return self::storageDir() . '/tmp';
    }

    public static function viewsDir(): string
    {
        return self::root() . '/frontend/views';
    }

    public static function dbPath(): string
    {
        return self::dataDir() . '/lessons.db';
    }

    public static function aiConfigPath(): string
    {
        return self::configDir() . '/ai_config.json';
    }

    public static function worker(string $name): string
    {
        return self::root() . '/backend/workers/' . $name;
    }

    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}
