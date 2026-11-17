<?php

declare(strict_types=1);

/**
 * Download a whisper.cpp ggml model into storage/models/whisper.
 *
 * Usage:
 *   php bin/download-whisper-model.php            # default model
 *   php bin/download-whisper-model.php small
 *   php bin/download-whisper-model.php base --force
 */

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Services\Whisper;

$args = array_values(array_filter(array_slice($argv ?? [], 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$force = in_array('--force', $argv ?? [], true);
$model = $args[0] ?? Whisper::defaultModel();
$url = Whisper::modelUrl($model);
$target = Whisper::modelFile($model);
$dir = dirname($target);

if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, 'No se pudo crear ' . $dir . PHP_EOL);
    exit(1);
}

if (is_file($target) && !$force) {
    echo 'Ya existe: ' . $target . ' (' . round(filesize($target) / 1048576) . ' MB)' . PHP_EOL;
    exit(0);
}

echo 'Descargando ' . basename($target) . ' desde ' . $url . PHP_EOL;
$tmp = $target . '.part';
@unlink($tmp);

if (extension_loaded('curl')) {
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        fwrite(STDERR, 'No se pudo abrir ' . $tmp . PHP_EOL);
        exit(1);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_USERAGENT      => 'english-app/1.0',
    ]);
    $ok = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok) {
        @unlink($tmp);
        fwrite(STDERR, 'Error de descarga: ' . $error . PHP_EOL);
        exit(1);
    }
} else {
    $in = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 600, 'user_agent' => 'english-app/1.0']]));
    if ($in === false) {
        fwrite(STDERR, 'No se pudo descargar (falta curl y allow_url_fopen).' . PHP_EOL);
        exit(1);
    }
    $out = fopen($tmp, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
}

if (!is_file($tmp) || filesize($tmp) < 1048576) {
    @unlink($tmp);
    fwrite(STDERR, 'La descarga quedo vacia o incompleta.' . PHP_EOL);
    exit(1);
}

if (!@rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, 'No se pudo mover el modelo a ' . $target . PHP_EOL);
    exit(1);
}

echo 'Listo: ' . $target . ' (' . round(filesize($target) / 1048576) . ' MB)' . PHP_EOL;
