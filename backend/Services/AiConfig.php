<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Loads and persists config/ai_config.json.
 */
final class AiConfig
{
    public static function defaults(): array
    {
        return [
            'backend' => 'ollama',
            'paths'   => [
                'video_dir' => '/home/mauricio/.openclaw/workspace/ingles',
            ],
            'ollama'  => [
                'endpoint' => 'http://localhost:11434',
                'model'    => 'gemma4:31b-cloud',
                'timeout'  => 300,
            ],
            'openclaw' => [
                'binary'  => '/home/mauricio/.openclaw/tmp/agent-cli/openclaw',
                'timeout' => 300,
            ],
            'whisper' => [
                'model' => 'base',
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
        return rtrim((string) ($cfg['paths']['video_dir'] ?? ''), '/');
    }
}
