<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\Lesson;

/**
 * Full lesson pipeline: scan videos, extract audio, transcribe locally and
 * enrich with AI. Port of backend/workers/process_lessons.py.
 */
final class LessonPipeline
{
    /**
     * @return string[] relative paths using forward slashes, sorted
     */
    public function scanVideos(string $videoDir): array
    {
        if ($videoDir === '' || !is_dir($videoDir)) {
            return [];
        }

        $base = rtrim(str_replace('\\', '/', realpath($videoDir) ?: $videoDir), '/');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'mp4') {
                continue;
            }
            $path = str_replace('\\', '/', $fileInfo->getPathname());
            $files[] = ltrim(substr($path, strlen($base)), '/');
        }

        sort($files);
        return $files;
    }

    public function processFile(
        string $fileName,
        ?string $whisperModel = null,
        bool $doEnrich = true,
        bool $force = false,
        ?callable $log = null
    ): bool {
        $log = $log ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };

        $videoDir = AiConfig::videoDir();
        $lessonModel = new Lesson();
        $lesson = $lessonModel->findByFileName($fileName);
        if ($lesson === null) {
            $lessonModel->insertIfMissing($fileName, pathinfo($fileName, PATHINFO_FILENAME));
            $lesson = $lessonModel->findByFileName($fileName);
        }

        $hasTranscription = !empty($lesson['transcription']);
        $hasExplanation = !empty($lesson['explanation']);

        $log('--- Iniciando proceso para: ' . $fileName . ' ---');
        if (!$force && $hasTranscription && $hasExplanation) {
            $log('Ya procesado (transcripcion + IA). Omitiendo. Usa --force para regenerar.');
            return true;
        }

        $videoPath = rtrim($videoDir, "/\\") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fileName);
        if ($videoDir === '' || !is_file($videoPath)) {
            $log('Error: el archivo ' . $videoPath . ' no existe.');
            return false;
        }

        $model = $whisperModel ?: Whisper::defaultModel();

        if (!$force && $hasTranscription) {
            $log('La transcripcion ya existe; solo falta la IA.');
        } else {
            Config::ensureDirectory(Config::tmpDir());
            $wav = Config::tmpDir() . DIRECTORY_SEPARATOR . 'lesson_audio_' . md5($fileName) . '.wav';

            $log('Extrayendo audio de ' . basename($videoPath) . '...');
            if (!Ffmpeg::extractAudio($videoPath, $wav)) {
                @unlink($wav);
                $log('Error de ffmpeg: ' . (Ffmpeg::lastError() ?? 'sin detalle'));
                return false;
            }

            $log('Iniciando transcripcion con ' . Whisper::engineLabel() . ' (modelo: ' . $model . ')...');
            $text = Whisper::transcribe($wav, $model);

            // Keep only the conventional sample used by /settings/test-whisper;
            // the per-lesson WAV can be large.
            if ($text !== null) {
                @copy($wav, Config::tmpDir() . DIRECTORY_SEPARATOR . 'lesson_audio.wav');
            }
            @unlink($wav);

            if ($text === null) {
                $log('Error de Whisper: ' . (Whisper::lastError() ?? 'sin detalle'));
                return false;
            }

            $lessonModel->saveTranscription($fileName, $text);
            $lesson = $lessonModel->findByFileName($fileName);
            $log('Transcripcion completada (' . str_word_count($text) . ' palabras) y guardada en la base de datos.');
        }

        if ($doEnrich && $lesson !== null) {
            $log('Iniciando enriquecimiento de la leccion (IA)...');
            $enricher = new Enricher();
            if (!$enricher->enrich((int) $lesson['id'], $force, $log)) {
                $log('Aviso: el enriquecimiento de la leccion ' . $lesson['id'] . ' fallo.');
                return false;
            }
        }

        return true;
    }

    /**
     * Process every video that is not complete yet.
     *
     * @return array{pending:int, processed:int}
     */
    public function processAll(?string $whisperModel = null, bool $doEnrich = true, bool $force = false, ?callable $log = null): array
    {
        $log = $log ?? static function (string $line): void {
            echo $line . PHP_EOL;
        };

        $videoDir = AiConfig::videoDir();
        if ($videoDir === '' || !is_dir($videoDir)) {
            $log('Error: video_dir invalido: ' . $videoDir);
            return ['pending' => 0, 'processed' => 0];
        }

        $files = $this->scanVideos($videoDir);
        if ($files === []) {
            $log('No se encontraron videos en ' . $videoDir . '.');
            return ['pending' => 0, 'processed' => 0];
        }

        $lessonModel = new Lesson();
        $pending = 0;
        $processed = 0;

        foreach ($files as $fileName) {
            $state = $lessonModel->findByFileName($fileName);
            if (!$force && $state !== null && !empty($state['transcription']) && !empty($state['explanation'])) {
                $log('Omitiendo ' . $fileName . ' (ya procesado).');
                continue;
            }
            $pending++;
            if ($this->processFile($fileName, $whisperModel, $doEnrich, $force, $log)) {
                $processed++;
            }
        }

        if ($pending === 0) {
            $log('No hay videos pendientes por procesar.');
        } else {
            $log('Todos los archivos pendientes han sido procesados (' . $processed . '/' . $pending . ').');
        }

        return ['pending' => $pending, 'processed' => $processed];
    }
}
