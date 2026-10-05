<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Launches the Python workers that transcribe and enrich lessons.
 */
final class LessonProcessor
{
    public function processAll(string $whisper): void
    {
        ProcessRunner::background(
            [AiConfig::pythonBinary(), Config::worker('process_lessons.py'), '--whisper-model', $whisper],
            'process_lessons_all'
        );
    }

    public function processOne(string $file, string $whisper): void
    {
        ProcessRunner::background(
            [AiConfig::pythonBinary(), Config::worker('process_lessons.py'), $file, '--whisper-model', $whisper],
            'process_lesson_' . md5($file)
        );
    }

    public function reEnrich(int|string $id): void
    {
        ProcessRunner::background(
            [AiConfig::pythonBinary(), Config::worker('enrich_lesson.py'), (string) $id, '--force'],
            'enrich_' . (int) $id
        );
    }

    /**
     * Stream the live processing log as Server-Sent Events.
     */
    public function streamLive(string $file, string $whisper): void
    {
        Config::ensureDirectory(Config::tmpDir());

        $logFile = Config::tmpDir() . '/live_process_' . md5($file . $whisper) . '.log';
        @unlink($logFile);

        $script = escapeshellarg(Config::worker('process_lessons.py'));
        $python = escapeshellarg(AiConfig::pythonBinary());
        $cmd = 'PYTHONUNBUFFERED=1 ' . $python . ' ' . $script
             . ' ' . escapeshellarg($file)
             . ' --whisper-model ' . escapeshellarg($whisper)
             . ' --force'
             . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';

        $pid = (int) trim((string) shell_exec($cmd));

        for ($i = 0; $i < 40 && !file_exists($logFile); $i++) {
            usleep(250000);
        }

        if (!file_exists($logFile)) {
            echo "data: No se pudo iniciar el proceso\n\n";
            return;
        }

        $handle = fopen($logFile, 'r');
        while (true) {
            $line = fgets($handle);
            if ($line !== false) {
                echo 'data: ' . str_replace(["\r", "\n"], '', $line) . "\n\n";
                @ob_flush();
                flush();
            } else {
                if ($pid <= 0 || !file_exists("/proc/$pid")) {
                    echo "data: --- Proceso finalizado ---\n\n";
                    break;
                }
                clearstatcache();
                fseek($handle, ftell($handle));
                usleep(500000);
            }
        }
        fclose($handle);
        @unlink($logFile);
    }
}
