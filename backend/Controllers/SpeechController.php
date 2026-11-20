<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Lesson;
use App\Services\Ffmpeg;
use App\Services\SpeechEvaluator;
use App\Services\Whisper;

/**
 * Speaking practice endpoints.
 *
 * - evaluate(): receives a browser microphone recording (multipart upload),
 *   converts it with ffmpeg, transcribes it with the existing Whisper service
 *   and asks the AI to grade the answer word by word.
 * - question(): generates the first (or a replacement) conversation question
 *   for the lesson. The conversation state lives in the browser, like the tutor.
 */
final class SpeechController extends Controller
{
    private const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
    private const MIN_UPLOAD_BYTES = 1024;

    public function evaluate(array $params = []): void
    {
        // Whisper + AI on a short clip; the built-in server needs the raise.
        @set_time_limit(300);
        $this->sweepStaleUploads();

        $lesson = $this->lesson($params);
        if ($lesson === null) {
            $this->json(['ok' => false, 'code' => 'no_lesson', 'error' => 'Leccion no encontrada.']);
        }

        $question = trim((string) Request::post('question', ''));
        $expected = trim((string) Request::post('expected', ''));
        $mode = $expected !== '' ? 'exercise' : 'conversation';

        $file = $_FILES['audio'] ?? null;
        if (!is_array($file)) {
            $this->json(['ok' => false, 'code' => 'no_upload', 'error' => 'No llego ningun audio.']);
        }

        // Response::json() calls exit(), which skips finally blocks: stage the
        // upload first and only then start the block that guarantees cleanup.
        [$stagedPath, $ext, $uploadError] = $this->stageUpload($file);
        if ($uploadError !== null) {
            $this->json($uploadError);
        }

        $wavPath = Config::tmpDir() . DIRECTORY_SEPARATOR . 'speech_' . bin2hex(random_bytes(6)) . '.wav';

        try {
            $response = $this->runEvaluation((string) $stagedPath, $wavPath, $question, $expected, $mode, $lesson);
        } finally {
            // Audio never lingers in storage/tmp (mirrors the pipeline cleanup).
            @unlink((string) $stagedPath);
            @unlink($wavPath);
        }

        $this->json($response);
    }

    public function question(array $params = []): void
    {
        @set_time_limit(180);

        $lesson = $this->lesson($params);
        if ($lesson === null) {
            $this->json(['ok' => false, 'code' => 'no_lesson', 'error' => 'Leccion no encontrada.']);
        }

        $input = Request::json();
        $history = is_array($input['history'] ?? null) ? array_slice($input['history'], -10) : [];

        $generated = SpeechEvaluator::generateQuestion($lesson, $history);
        if ($generated === null) {
            $this->json([
                'ok'    => false,
                'code'  => 'ai_unavailable',
                'error' => 'No se pudo generar la pregunta. Revisa la IA en /settings.',
            ]);
        }

        $this->json(array_merge(['ok' => true], $generated));
    }

    // ------------------------------------------------------------------ #
    // Pipeline
    // ------------------------------------------------------------------ #

    /**
     * @param array<string, mixed> $lesson
     * @return array<string, mixed>
     */
    private function runEvaluation(string $stagedPath, string $wavPath, string $question, string $expected, string $mode, array $lesson): array
    {
        $missing = Whisper::missingStatus(Whisper::speechModel());
        if ($missing !== null) {
            return [
                'ok'    => false,
                'code'  => $missing,
                'error' => $missing === 'whisper_model_missing'
                    ? 'Falta el modelo de Whisper para la practica oral. Descargalo en /settings.'
                    : 'No hay motor Whisper disponible. Configuralo en /settings.',
            ];
        }

        if (!Ffmpeg::audioToWav($stagedPath, $wavPath, 60)) {
            return [
                'ok'    => false,
                'code'  => Ffmpeg::binary() === null ? 'ffmpeg_missing' : 'ffmpeg_failed',
                'error' => Ffmpeg::lastError() ?? 'No se pudo convertir el audio.',
            ];
        }

        $detail = Whisper::transcribeDetailed($wavPath, null, 120);
        if ($detail === null) {
            return [
                'ok'    => false,
                'code'  => 'whisper_failed',
                'error' => Whisper::lastError() ?? 'Whisper no pudo transcribir el audio.',
            ];
        }

        $heard = trim($detail['text']);
        $alignment = $expected !== '' ? SpeechEvaluator::align($expected, $heard) : null;

        $result = SpeechEvaluator::evaluate(
            $question,
            $expected,
            $heard,
            $alignment,
            $detail['duration'],
            $mode === 'conversation',
            $lesson
        );
        $source = 'ai';
        if ($result === null) {
            $result = SpeechEvaluator::localResult($alignment, $expected, $heard);
            $source = 'local';
        }
        if ($result === null) {
            // No expected answer (free conversation) and the AI is down.
            return [
                'ok'    => false,
                'code'  => 'ai_unavailable',
                'error' => 'La IA no esta disponible para evaluar la respuesta libre. '
                    . 'Revisa la configuracion en /settings.',
                'heard' => $heard,
            ];
        }

        return [
            'ok'           => true,
            'mode'         => $mode,
            'heard'        => $heard,
            'score'        => $alignment['score'] ?? $result['score'],
            'chips'        => $alignment['chips'] ?? [],
            'result'       => [
                'ok'          => $result['ok'],
                'score'       => $result['score'],
                'feedback_es' => $result['feedback_es'],
                'corrected'   => $result['corrected'],
                'issues'      => $result['issues'],
            ],
            'next'         => $result['next'],
            'source'       => $source,
            'word_detail'  => $alignment !== null,
            'engine'       => $detail['engine'],
            'word_timings' => $detail['word_timings'],
            'seconds'      => round($detail['duration'], 1),
        ];
    }

    // ------------------------------------------------------------------ #
    // Helpers
    // ------------------------------------------------------------------ #

    /**
     * Requests killed mid-flight (server restart, timeout) skip the finally
     * cleanup, so sweep speech uploads older than an hour on each evaluate.
     */
    private function sweepStaleUploads(): void
    {
        $cut = time() - 3600;
        foreach (glob(Config::tmpDir() . DIRECTORY_SEPARATOR . 'speech_*.*') ?: [] as $file) {
            if (@filemtime($file) < $cut) {
                @unlink($file);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lesson(array $params): ?array
    {
        $id = (int) ($params['id'] ?? Request::input('lesson_id', 0));
        if ($id <= 0) {
            return null;
        }
        return (new Lesson())->find($id);
    }

    /**
     * Validate the uploaded blob by magic bytes (the client MIME is not
     * trustworthy and MediaRecorder differs across browsers) and move it to
     * storage/tmp with a matching extension for ffmpeg.
     *
     * @param array<string, mixed> $file
     * @return array{0:?string, 1:?string, 2:?array<string,mixed>}
     */
    private function stageUpload(array $file): array
    {
        $fail = static function (string $code, string $message): array {
            return [null, null, ['ok' => false, 'code' => $code, 'error' => $message]];
        };

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return $fail('no_upload', 'No llego ningun audio. Prueba de nuevo.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $fail('too_large', 'La grabacion es demasiado grande para el servidor (upload_max_filesize).');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return $fail('bad_upload', 'No se pudo recibir la grabacion (codigo ' . $error . ').');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return $fail('bad_upload', 'Subida invalida.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return $fail('too_large', 'La grabacion supera los 8 MB. Usa una respuesta mas corta.');
        }
        if ($size < self::MIN_UPLOAD_BYTES) {
            return $fail('too_small', 'El audio esta vacio o es demasiado corto. Grabate respondiendo y proba de nuevo.');
        }

        $header = (string) file_get_contents($tmpName, false, null, 0, 16);
        $ext = self::sniffAudioExtension($header);
        if ($ext === null) {
            return $fail('bad_upload', 'Formato de audio no reconocido (lo grabo el navegador pero ffmpeg no podra leerlo).');
        }

        $dest = Config::tmpDir() . DIRECTORY_SEPARATOR . 'speech_' . bin2hex(random_bytes(6)) . '.' . $ext;
        Config::ensureDirectory(Config::tmpDir());
        if (!@move_uploaded_file($tmpName, $dest)) {
            return $fail('bad_upload', 'No se pudo guardar el audio en el servidor.');
        }

        return [$dest, $ext, null];
    }

    /**
     * Map the container signature that MediaRecorder produces in the main
     * browsers (Chrome/Firefox webm, Safari mp4, Firefox/Android ogg).
     */
    private static function sniffAudioExtension(string $header): ?string
    {
        $magic = substr($header, 0, 12);
        return match (true) {
            str_starts_with($magic, "\x1A\x45\xDF\xA3") => 'webm', // Matroska/WebM
            str_starts_with($magic, 'OggS')             => 'ogg',
            str_starts_with($magic, 'fLaC')             => 'flac',
            str_starts_with($magic, 'RIFF') && str_contains($magic, 'WAVE') => 'wav',
            substr($magic, 4, 4) === 'ftyp'             => 'm4a',  // ISO MP4 (Safari)
            str_starts_with($magic, 'ID3')              => 'mp3',
            (ord($magic[0] ?? "\x00") === 0xFF && (ord($magic[1] ?? "\x00") & 0xE0) === 0xE0) => 'mp3',
            default => null,
        };
    }
}
