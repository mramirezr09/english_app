<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Launches the Python worker that generates exam questions with AI.
 *
 * Runs in the background with a real log (see ProcessRunner) so failures are
 * visible in /exams instead of being silently swallowed.
 */
final class ExamProcessor
{
    public function generateForLesson(int|string $id, bool $force = false): void
    {
        $args = [AiConfig::pythonBinary(), Config::worker('generate_exam.py'), '--lesson', (string) $id];
        if ($force) {
            $args[] = '--force';
        }
        ProcessRunner::background($args, 'exam_generate_' . (int) $id);
    }

    public function generateAll(bool $force = false): void
    {
        $args = [AiConfig::pythonBinary(), Config::worker('generate_exam.py'), '--all'];
        if ($force) {
            $args[] = '--force';
        }
        ProcessRunner::background($args, 'exam_generate_all');
    }
}
