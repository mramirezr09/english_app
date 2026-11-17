<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Single entry point for AI prompts used by controllers.
 *
 * All backends are native now (no Python bridge): OpenCode CLI only.
 */
final class AiService
{
    public static function ask(string $prompt, int $timeout = 240, bool $jsonMode = false): ?string
    {
        // The built-in server and php-fpm default to 30 s; AI calls need more.
        @set_time_limit(max(60, $timeout + 30));

        return OpenCode::chat($prompt, [
            'timeout'   => $timeout,
            'json_mode' => $jsonMode,
        ]);
    }

    public static function lastError(): ?string
    {
        return OpenCode::lastError();
    }
}
