<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Runs the Whisper test worker and returns its decoded JSON result.
 */
final class Whisper
{
    public static function test(string $model): array
    {
        $script = escapeshellarg(Config::worker('whisper_test.py'));
        $modelArg = escapeshellarg($model);
        $output = shell_exec("python3 $script $modelArg 2>&1");
        $data = json_decode((string) $output, true);

        if (!is_array($data)) {
            $data = ['ok' => false, 'error' => trim((string) $output)];
        }
        return $data;
    }
}
