<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Launches the Python worker that generates exam questions with AI.
 */
final class ExamProcessor
{
    public function generateForLesson(int|string $id, bool $force = false): void
    {
        $script = escapeshellarg(Config::worker('generate_exam.py'));
        $idArg = escapeshellarg((string) $id);
        $forceArg = $force ? ' --force' : '';
        shell_exec("PYTHONUNBUFFERED=1 python3 $script --lesson $idArg$forceArg > /dev/null 2>&1 &");
    }

    public function generateAll(bool $force = false): void
    {
        $script = escapeshellarg(Config::worker('generate_exam.py'));
        $forceArg = $force ? ' --force' : '';
        shell_exec("PYTHONUNBUFFERED=1 python3 $script --all$forceArg > /dev/null 2>&1 &");
    }
}
