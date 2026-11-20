<?php

declare(strict_types=1);

/**
 * Read-only check for the speaking-practice transcription path.
 *
 * Verifies that the active Whisper engine can turn a short mic-like clip into
 * text (and segment timings) the same way SpeechController does, without
 * needing a browser or the microphone.
 *
 * Usage:
 *   php bin/test_speech.php                 # usa storage/tmp/lesson_audio.wav
 *   php bin/test_speech.php ruta/al/audio   # wav/mp3/m4a/webm/ogg... (ffmpeg lo convierte)
 *   php bin/test_speech.php --model tiny    # fuerza un modelo concreto
 */

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Core\Config;
use App\Services\Ffmpeg;
use App\Services\Whisper;

$options = $argv ?? [];
array_shift($options);

$model = null;
$audioArg = null;
for ($i = 0; $i < count($options); $i++) {
    if ($options[$i] === '--model') {
        $model = (string) ($options[$i + 1] ?? '');
        $i++;
    } elseif (!str_starts_with($options[$i], '--')) {
        $audioArg = $options[$i];
    }
}

echo "Motor Whisper: " . Whisper::engineLabel() . "\n";
$binary = Whisper::activeBinary();
echo "Binario:       " . ($binary ?? 'NO ENCONTRADO') . "\n";
$engine = Whisper::engine();
if ($engine === 'openai_whisper') {
    echo "Aviso: openai-whisper no expone timestamps por palabra via CLI; la practica oral\n"
        . "degradara a nivel de texto (sin marcado palabra por palabra de tiempos).\n";
}

if ($model !== null && $model !== '' && !in_array($model, Config::WHISPER_MODELS, true)) {
    fwrite(STDERR, "Modelo invalido: $model (validos: " . implode(', ', Config::WHISPER_MODELS) . ")\n");
    exit(1);
}
$model = $model !== null && $model !== '' ? $model : Whisper::speechModel();
$modelPath = Whisper::activeModelPath($model);
echo "Modelo voz:    $model (" . $modelPath . ") " . (is_file($modelPath) ? 'OK' : 'FALTA') . "\n";

$audio = $audioArg ?? (Config::tmpDir() . DIRECTORY_SEPARATOR . 'lesson_audio.wav');
$audio = App\Services\AiConfig::absolutePath($audio);
if (!is_file($audio)) {
    fwrite(STDERR, "\nNo existe el audio de prueba: $audio\n"
        . "Procesa un video en /sync (crea storage/tmp/lesson_audio.wav) o pasa uno por argumento:\n"
        . "  php bin/test_speech.php mi_clip.wav\n");
    exit(1);
}

$wav = $audio;
$generatedWav = null;
if (strtolower((string) pathinfo($audio, PATHINFO_EXTENSION)) !== 'wav') {
    $generatedWav = Config::tmpDir() . DIRECTORY_SEPARATOR . 'test_speech_' . getmypid() . '.wav';
    echo "\nConvirtiendo a WAV 16k con ffmpeg: " . Ffmpeg::binary() . "\n";
    if (!Ffmpeg::audioToWav($audio, $generatedWav, 120)) {
        fwrite(STDERR, 'ffmpeg: ' . (Ffmpeg::lastError() ?? 'fallo desconocido') . "\n");
        exit(1);
    }
    $wav = $generatedWav;
}

echo "\nTranscribiendo (transcribeDetailed)...\n";
$started = microtime(true);
$detail = Whisper::transcribeDetailed($wav, $model, 300);
$elapsed = round(microtime(true) - $started, 1);

if ($generatedWav !== null) {
    @unlink($generatedWav);
}

if ($detail === null) {
    fwrite(STDERR, 'Error: ' . (Whisper::lastError() ?? 'transcripcion fallida') . "\n");
    exit(1);
}

$text = $detail['text'];
echo "OK en {$elapsed}s (motor: {$detail['engine']})\n";
echo "Duracion estimada del audio: {$detail['duration']} s\n";
echo "Segmentos con tiempos: " . count($detail['segments']) . "\n";
echo "Timestamps por palabra en el JSON: " . ($detail['word_timings'] ? 'SI' : 'no (la alineacion por palabra se hara sobre el texto)') . "\n";
echo "Palabras: " . str_word_count($text) . "\n";
echo "Texto (primeros 300): " . substr($text, 0, 300) . "\n";

if (count($detail['segments']) > 0) {
    echo "\nPrimeros segmentos:\n";
    foreach (array_slice($detail['segments'], 0, 3) as $seg) {
        printf("  [%6.2fs -> %6.2fs] %s\n", $seg['from'], $seg['to'], str_limit($seg['text'], 90));
    }
}

echo "\nResultado: el pipeline de voz puede transcribir audios cortos en este equipo.\n";
exit(0);
