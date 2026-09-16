<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiConfig;

/**
 * Streams lesson videos from paths.video_dir. Keeps the media outside the
 * webroot (no symlinks) and handles HTTP Range so the player can seek.
 */
final class VideoController extends Controller
{
    public function stream(array $params): void
    {
        $relative = ltrim((string) ($params['path'] ?? ''), '/');
        $base = realpath(AiConfig::videoDir());

        if ($relative === '' || $base === false) {
            Response::notFound('Video no encontrado');
        }

        $target = realpath($base . DIRECTORY_SEPARATOR . $relative);
        if ($target === false
            || !is_file($target)
            || !str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
            Response::notFound('Video no encontrado');
        }

        $this->serveFile($target);
    }

    private function serveFile(string $path): void
    {
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';

        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
                $end = $size - 1;
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min((int) $m[2], $size - 1);
                }
            }

            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }

            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            http_response_code(200);
        }

        $length = $end - $start + 1;

        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        if (Request::method() === 'HEAD') {
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        fseek($handle, $start);
        $remaining = $length;
        $chunkSize = 128 * 1024;

        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, (int) min($chunkSize, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }

        fclose($handle);
        exit;
    }
}
