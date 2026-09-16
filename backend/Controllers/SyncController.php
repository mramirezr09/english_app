<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Lesson;
use App\Services\AiConfig;
use App\Services\LessonProcessor;

final class SyncController extends Controller
{
    public function index(array $params = []): void
    {
        $videoDir = AiConfig::videoDir();
        $model = new Lesson();

        if ($videoDir !== '' && is_dir($videoDir)) {
            foreach ($this->scanVideos($videoDir) as $relative) {
                $model->insertIfMissing($relative, pathinfo($relative, PATHINFO_FILENAME));
            }
        }

        $this->view('sync.index', [
            'title'         => 'Gestion de Lecciones',
            'lessons'       => $model->allByFileName(),
            'stats'         => $model->stats(),
            'config'        => AiConfig::load(),
            'whisperModels' => Config::WHISPER_MODELS,
            'status'        => $this->statusMessage((string) Request::query('status', '')),
        ]);
    }

    public function processAll(array $params = []): void
    {
        $whisper = $this->resolveWhisper(Request::query('whisper'));
        (new LessonProcessor())->processAll($whisper);
        $this->redirect('/sync?status=processing&whisper=' . urlencode($whisper));
    }

    public function processOne(array $params): void
    {
        $file = (string) $params['file'];
        $whisper = $this->resolveWhisper(Request::query('whisper'));
        (new LessonProcessor())->processOne($file, $whisper);
        $this->redirect('/sync?status=processing_one&file=' . urlencode($file));
    }

    public function reEnrich(array $params): void
    {
        (new LessonProcessor())->reEnrich($params['id']);
        $this->redirect('/sync?status=re_enrich');
    }

    public function live(array $params = []): void
    {
        set_time_limit(0);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $file = (string) Request::query('file', '');
        if ($file === '') {
            echo "data: Error: no se especifico ningun archivo\n\n";
            return;
        }

        $whisper = $this->resolveWhisper(Request::query('whisper'));
        (new LessonProcessor())->streamLive($file, $whisper);
    }

    /**
     * Recursively collect .mp4 files, returned as paths relative to $videoDir
     * (e.g. "4A/Regla_1.mp4"), sorted for a stable listing.
     *
     * @return string[]
     */
    private function scanVideos(string $videoDir): array
    {
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

    private function resolveWhisper(mixed $requested): string
    {
        $default = AiConfig::load()['whisper']['model'];
        $value = is_string($requested) ? $requested : '';
        return in_array($value, Config::WHISPER_MODELS, true) ? $value : $default;
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'processing'     => 'Procesando los videos pendientes en segundo plano. Recarga la pagina en unos minutos.',
            'processing_one' => 'Procesando el archivo en segundo plano.',
            're_enrich'      => 'Regenerando la explicacion y los ejercicios con IA.',
            'reset'          => 'Leccion reseteada exitosamente.',
            default          => '',
        };
    }
}
