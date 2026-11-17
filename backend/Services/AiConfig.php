<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Loads and persists config/ai_config.json.
 *
 * Secrets (OpenCode API key) and the selected model live in .env; the rest of
 * the local settings stay in JSON. Environment values win over JSON.
 */
final class AiConfig
{
    public static function defaults(): array
    {
        return [
            'backend' => 'opencode',
            'paths'   => [
                'video_dir' => '',
                'php'       => 'php',
            ],
            'opencode' => [
                'binary'           => '',
                'model'            => 'opencode-go/deepseek-v4.1-flash',
                'timeout'          => 300,
                'archive_sessions' => true,
                'service_port'     => 28994,
            ],
            'whisper' => [
                'engine'        => 'auto',
                'model'         => 'base',
                'language'      => 'English',
                'english_only'  => true,
                'binary'        => '',
                'openai_binary' => '',
                'models_dir'    => '',
                'ffmpeg'        => '',
            ],
            'exam' => [
                'passing_score' => 80,
                'questions'     => 12,
            ],
        ];
    }

    public static function load(): array
    {
        $cfg = self::defaults();
        $path = Config::aiConfigPath();
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) {
                $cfg = array_replace_recursive($cfg, $data);
            }
        }
        return $cfg;
    }

    public static function save(array $cfg): bool
    {
        Config::ensureDirectory(dirname(Config::aiConfigPath()));
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return file_put_contents(Config::aiConfigPath(), $json . "\n") !== false;
    }

    public static function videoDir(): string
    {
        $cfg = self::load();
        return rtrim((string) ($cfg['paths']['video_dir'] ?? ''), "/\\");
    }

    /**
     * PHP CLI binary used to launch background workers.
     */
    public static function phpBinary(): string
    {
        $cfg = self::load();
        $binary = trim((string) ($cfg['paths']['php'] ?? ''));
        if ($binary !== '') {
            return $binary;
        }
        return PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
    }

    /**
     * Effective OpenCode model: .env wins, then ai_config.json.
     */
    public static function openCodeModel(): string
    {
        $fromEnv = trim((string) \App\Core\Env::get('OPENCODE_MODEL', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        $cfg = self::load();
        return trim((string) ($cfg['opencode']['model'] ?? ''));
    }

    public static function whisperModelsDir(): string
    {
        $fromEnv = trim((string) \App\Core\Env::get('WHISPER_MODELS_DIR', ''));
        if ($fromEnv !== '') {
            return self::absolutePath($fromEnv);
        }
        $cfg = self::load();
        $configured = trim((string) ($cfg['whisper']['models_dir'] ?? ''));
        return $configured !== '' ? self::absolutePath($configured) : Config::modelsDir();
    }

    /**
     * Accept absolute paths, paths relative to the project root, and ~/ paths.
     */
    public static function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '~/') || str_starts_with($path, '~\\')) {
            $home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
            if ($home !== '') {
                return rtrim($home, "/\\") . DIRECTORY_SEPARATOR . substr($path, 2);
            }
        }
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        return Config::root() . DIRECTORY_SEPARATOR . ltrim($path, "/\\");
    }
}
