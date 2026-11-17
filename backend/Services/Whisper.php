<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Env;

/**
 * Local transcription with whisper.cpp (native binary, no Python).
 *
 * Model names are the logical ones used by the UI (tiny/base/small/medium/
 * large-v3); for English lessons the .en variants are preferred.
 */
final class Whisper
{
    private static ?string $binary = null;
    private static bool $resolved = false;
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    public static function binary(): ?string
    {
        if (self::$resolved) {
            return self::$binary;
        }
        self::$resolved = true;

        $cfg = AiConfig::load();
        $configured = trim((string) Env::get('WHISPER_BIN', (string) ($cfg['whisper']['binary'] ?? '')));
        $names = Shell::isWindows()
            ? ['whisper-cli.exe', 'whisper.exe', 'main.exe', 'whisper-cli']
            : ['whisper-cli', 'whisper', 'main'];

        $known = [
            Config::root() . '/storage/bin/whisper/whisper-cli' . (Shell::isWindows() ? '.exe' : ''),
            Config::root() . '/storage/bin/whisper/Release/whisper-cli.exe',
            Config::root() . '/storage/bin/whisper/Release/main.exe',
            Config::root() . '/storage/bin/whisper-cli' . (Shell::isWindows() ? '.exe' : ''),
            Config::root() . '/storage/bin/whisper.cpp/build/bin/whisper-cli' . (Shell::isWindows() ? '.exe' : ''),
            Config::root() . '/storage/bin/whisper.cpp/build/bin/Release/whisper-cli.exe',
        ];
        if (!Shell::isWindows()) {
            $known[] = '/opt/whisper.cpp/build/bin/whisper-cli';
            $known[] = '/opt/whisper.cpp/whisper-cli';
            $known[] = '/usr/local/bin/whisper-cli';
            $known[] = '/usr/bin/whisper-cli';
        }

        self::$binary = Shell::resolveBinary($names, $configured, $known);
        return self::$binary;
    }

    public static function version(): ?string
    {
        $binary = self::binary();
        if ($binary === null) {
            return null;
        }
        $result = Shell::run([$binary, '--help'], null, 15);
        $text = $result['stdout'] . ' ' . $result['stderr'];
        if (preg_match('/whisper[^\r\n]*/i', $text, $match) === 1) {
            return trim($match[0]);
        }
        return $binary;
    }

    public static function modelsDir(): string
    {
        return AiConfig::whisperModelsDir();
    }

    public static function englishOnly(): bool
    {
        $cfg = AiConfig::load();
        return !empty($cfg['whisper']['english_only']);
    }

    public static function modelFile(string $model): string
    {
        $model = preg_replace('/[^A-Za-z0-9._-]/', '', $model) ?: 'base';
        $englishOnly = self::englishOnly();
        $englishCapable = in_array($model, ['tiny', 'base', 'small', 'medium'], true);

        $file = 'ggml-' . $model . ($englishOnly && $englishCapable ? '.en' : '') . '.bin';
        return rtrim(self::modelsDir(), "/\\") . DIRECTORY_SEPARATOR . $file;
    }

    public static function modelUrl(string $model): string
    {
        return 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/' . basename(self::modelFile($model));
    }

    public static function ready(?string $model = null): bool
    {
        $model = $model ?: self::defaultModel();
        return self::binary() !== null && is_file(self::modelFile($model));
    }

    public static function defaultModel(): string
    {
        $cfg = AiConfig::load();
        $model = (string) ($cfg['whisper']['model'] ?? 'base');
        return in_array($model, Config::WHISPER_MODELS, true) ? $model : 'base';
    }

    /**
     * Transcribe a 16 kHz mono WAV file and return the plain text.
     */
    public static function transcribe(string $wavPath, ?string $model = null, int $timeout = 7200, int $threads = 0): ?string
    {
        self::$lastError = null;

        $model = $model ?: self::defaultModel();
        $binary = self::binary();
        if ($binary === null) {
            self::$lastError = 'No se encontro whisper-cli. Configura WHISPER_BIN o la ruta en Ajustes.';
            return null;
        }
        if (!is_file($wavPath)) {
            self::$lastError = 'No existe el audio: ' . $wavPath;
            return null;
        }

        $modelPath = self::modelFile($model);
        if (!is_file($modelPath)) {
            self::$lastError = 'Falta el modelo ' . basename($modelPath) . '. Descargalo en ' . self::modelsDir()
                . ' (' . self::modelUrl($model) . ').';
            return null;
        }

        $outBase = Config::tmpDir() . DIRECTORY_SEPARATOR . 'whisper_' . md5($wavPath . $model . microtime(true));
        $arguments = [
            $binary,
            '-m', $modelPath,
            '-f', $wavPath,
            '-l', 'en',
            '-otxt',
            '-nt',
            '-np',
            '-of', $outBase,
        ];
        if ($threads > 0) {
            $arguments[] = '-t';
            $arguments[] = (string) $threads;
        }

        $result = Shell::run($arguments, null, $timeout, Config::tmpDir());
        $txt = $outBase . '.txt';
        @unlink($outBase . '.json');

        if ($result['timeout']) {
            self::$lastError = 'Whisper excedio el tiempo limite de ' . $timeout . ' s.';
            @unlink($txt);
            return null;
        }

        $text = is_file($txt) ? trim((string) file_get_contents($txt)) : '';
        @unlink($txt);

        if ($result['exit'] !== 0 || $text === '') {
            $detail = trim($result['stderr']);
            self::$lastError = 'Whisper no genero texto (exit ' . $result['exit'] . '). '
                . (strlen($detail) > 800 ? substr($detail, -800) : $detail);
            return null;
        }

        return $text;
    }

    /**
     * @return array{ok:bool, model:string, seconds:float, words:int, text:string, error:?string}
     */
    public static function test(string $model): array
    {
        $audio = Config::tmpDir() . DIRECTORY_SEPARATOR . 'lesson_audio.wav';
        if (!is_file($audio)) {
            return [
                'ok'      => false,
                'model'   => $model,
                'seconds' => 0.0,
                'words'   => 0,
                'text'    => '',
                'error'   => 'No existe audio de prueba. Procesa un video primero (se guarda en storage/tmp/lesson_audio.wav).',
            ];
        }

        $started = microtime(true);
        $text = self::transcribe($audio, $model, 1800);
        $elapsed = round(microtime(true) - $started, 1);

        if ($text === null) {
            return [
                'ok'      => false,
                'model'   => $model,
                'seconds' => $elapsed,
                'words'   => 0,
                'text'    => '',
                'error'   => self::$lastError,
            ];
        }

        return [
            'ok'      => true,
            'model'   => $model,
            'seconds' => $elapsed,
            'words'   => str_word_count($text),
            'text'    => substr($text, 0, 600),
            'error'   => null,
        ];
    }

    /**
     * @return array{binary:?string, version:?string, models_dir:string, model:string, model_file:string, model_exists:bool, ffmpeg:?string}
     */
    public static function diagnostics(): array
    {
        $model = self::defaultModel();
        $path = self::modelFile($model);
        return [
            'binary'       => self::binary(),
            'version'      => self::version(),
            'models_dir'   => self::modelsDir(),
            'model'        => $model,
            'model_file'   => $path,
            'model_exists' => is_file($path),
            'ffmpeg'       => Ffmpeg::binary(),
        ];
    }
}
