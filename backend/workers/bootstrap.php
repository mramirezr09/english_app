<?php

declare(strict_types=1);

/**
 * Shared bootstrap for the PHP CLI workers.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
@set_time_limit(0);
if (function_exists('ob_implicit_flush')) {
    @ob_implicit_flush(true);
}

/**
 * @param string[] $argv
 * @return array{0:?string, 1:array<string, mixed>}
 */
function worker_parse_args(array $argv, array $valueOptions = []): array
{
    $positional = [];
    $options = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $name = substr($arg, 2);
            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
                $options[$name] = $value;
                continue;
            }
            if (in_array($name, $valueOptions, true)) {
                $i++;
                $options[$name] = $argv[$i] ?? '';
                continue;
            }
            $options[$name] = true;
            continue;
        }
        $positional[] = $arg;
    }

    return [$positional[0] ?? null, $options];
}

function worker_log(string $line): void
{
    echo $line . PHP_EOL;
    @flush();
}
