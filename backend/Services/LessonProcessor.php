<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Launches the PHP workers that transcribe and enrich lessons.
 */
final class LessonProcessor
{
    public function processAll(string $whisper): void
    {
        ProcessRunner::background(
            [AiConfig::phpBinary(), Config::worker('process_lessons.php'), '--whisper-model', $whisper],
            'process_lessons_all'
        );
    }

    public function processOne(string $file, string $whisper): void
    {
        ProcessRunner::background(
            [AiConfig::phpBinary(), Config::worker('process_lessons.php'), $file, '--whisper-model', $whisper],
            'process_lesson_' . md5($file)
        );
    }

    public function reEnrich(int|string $id): void
    {
        ProcessRunner::background(
            [AiConfig::phpBinary(), Config::worker('enrich_lesson.php'), (string) $id, '--force'],
            'enrich_' . (int) $id
        );
    }

    /**
     * Stream the live processing log as Server-Sent Events.
     *
     * Runs in-process (instead of spawning/tailing a file) so it works the
     * same on Windows and Linux.
     */
    public function streamLive(string $file, string $whisper): void
    {
        $emit = static function (string $line): void {
            echo 'data: ' . str_replace(["\r", "\n"], '', $line) . "\n\n";
            @ob_flush();
            @flush();
        };

        try {
            (new LessonPipeline())->processFile($file, $whisper, true, true, $emit);
        } catch (\Throwable $e) {
            $emit('Error: ' . $e->getMessage());
        }

        echo "data: --- Proceso finalizado ---\n\n";
        @ob_flush();
        @flush();
    }
}
