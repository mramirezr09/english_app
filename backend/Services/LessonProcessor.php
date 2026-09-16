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
        $script = escapeshellarg(Config::worker('process_lessons.py'));
        $arg = '--whisper-model ' . escapeshellarg($whisper);
        shell_exec('PYTHONUNBUFFERED=1 python3 ' . $script . " $arg > /dev/null 2>&1 &");
    }

    public function processOne(string $file, string $whisper): void
    {
        $script = escapeshellarg(Config::worker('process_lessons.py'));
        $fileArg = escapeshellarg($file);
        $whisperArg = '--whisper-model ' . escapeshellarg($whisper);
        shell_exec('PYTHONUNBUFFERED=1 python3 ' . $script . " $fileArg $whisperArg > /dev/null 2>&1 &");
    }

    public function reEnrich(int|string $id): void
    {
        $script = escapeshellarg(Config::worker('enrich_lesson.py'));
        $idArg = escapeshellarg((string) $id);
        shell_exec("python3 $script $idArg --force > /dev/null 2>&1 &");
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
        $cmd = 'PYTHONUNBUFFERED=1 python3 ' . $script
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
