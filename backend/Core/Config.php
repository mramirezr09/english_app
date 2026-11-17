<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Central paths and application-wide constants.
 */
final class Config
{
    public const WHISPER_MODELS = ['tiny', 'base', 'small', 'medium', 'large-v3'];

    public static function root(): string
    {
        return BASE_PATH;
    }

    public static function envPath(): string
    {
        return self::root() . '/.env';
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

    /**
     * Writable HOME/XDG directory used by the OpenCode CLI when the app runs
     * as a service user. Keeps the app's sessions separate from the user's.
     */
    public static function runtimeDir(): string
    {
        return self::storageDir() . '/runtime';
    }

    /**
     * Default directory for whisper.cpp ggml models.
     */
    public static function modelsDir(): string
    {
        return self::storageDir() . '/models/whisper';
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
