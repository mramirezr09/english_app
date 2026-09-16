<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Queries the Ollama HTTP API.
 */
final class Ollama
{
    /**
     * List the model tags available in Ollama (empty array on failure).
     *
     * @return string[]
     */
    public static function models(?string $endpoint = null, int $timeout = 5): array
    {
        $cfg = AiConfig::load();
        $endpoint = $endpoint ?: ($cfg['ollama']['endpoint'] ?? 'http://localhost:11434');
        $url = rtrim($endpoint, '/') . '/api/tags';

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        $names = [];
        foreach (($data['models'] ?? []) as $model) {
            $name = $model['name'] ?? ($model['model'] ?? null);
            if ($name) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }
}
