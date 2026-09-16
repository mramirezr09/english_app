<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Runs a prompt through the shared Python AI client (backend/workers/ai_client.py).
 */
final class AiService
{
    public static function ask(string $prompt, int $timeout = 240): ?string
    {
        $script = Config::worker('ai_client.py');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['python3', $script], $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        if ($exit !== 0 && trim((string) $stdout) === '') {
            error_log('AiService::ask failed: ' . trim((string) $stderr));
            return null;
        }
        return trim((string) $stdout);
    }
}
