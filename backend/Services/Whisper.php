<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Env;

/**
 * Local transcription with a selectable engine:
 *
 * - whisper_cpp: native whisper.cpp (preferred, no Python)
 * - openai_whisper: the existing `whisper` CLI (Python), reusing its .pt cache
 * - auto: whisper.cpp when the binary+model are ready, openai-whisper otherwise
 */
final class Whisper
{
    private static ?string $cppBinary = null;
    private static bool $cppResolved = false;
    private static ?string $pyBinary = null;
    private static bool $pyResolved = false;
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    // ------------------------------------------------------------------ #
    // Engine selection
    // ------------------------------------------------------------------ #
    public static function configuredEngine(): string
    {
        $cfg = AiConfig::load();
        $value = strtolower(trim((string) Env::get('WHISPER_ENGINE', (string) ($cfg['whisper']['engine'] ?? 'auto'))));
        return match ($value) {
            'whisper_cpp', 'whisper-cpp', 'cpp' => 'whisper_cpp',
            'openai_whisper', 'openai-whisper', 'python', 'openai' => 'openai_whisper',
            default => 'auto',
        };
    }

    /**
     * Resolve which engine will be used, or null if none is available.
     */
    public static function engine(): ?string
    {
        $configured = self::configuredEngine();

        if ($configured === 'whisper_cpp') {
            return self::cppBinary() !== null ? 'whisper_cpp' : null;
        }
        if ($configured === 'openai_whisper') {
            return self::openAiBinary() !== null ? 'openai_whisper' : null;
        }

        $model = self::defaultModel();
        if (self::cppReady($model)) {
            return 'whisper_cpp';
        }
        if (self::openAiBinary() !== null) {
            return 'openai_whisper';
        }
        if (self::cppBinary() !== null) {
            // Binary present but model missing: report whisper.cpp so the UI
            // can point to the missing ggml file instead of "no engine".
            return 'whisper_cpp';
        }
        return null;
    }

    public static function engineLabel(?string $engine = null): string
    {
        $engine = $engine ?? self::engine();
        return match ($engine) {
            'whisper_cpp' => 'whisper.cpp (nativo)',
            'openai_whisper' => 'openai-whisper (Python)',
            default => 'no disponible',
        };
    }

    // ------------------------------------------------------------------ #
    // whisper.cpp
    // ------------------------------------------------------------------ #
    public static function cppBinary(): ?string
    {
        if (self::$cppResolved) {
            return self::$cppBinary;
        }
        self::$cppResolved = true;

        $cfg = AiConfig::load();
        $configured = trim((string) Env::get('WHISPER_BIN', (string) ($cfg['whisper']['binary'] ?? '')));
        $names = Shell::isWindows()
            ? ['whisper-cli.exe', 'whisper.exe', 'main.exe', 'whisper-cli']
            : ['whisper-cli', 'whisper', 'main'];

        $known = [
            Config::root() . '/storage/bin/whisper/whisper-cli' . (Shell::isWindows() ? '.exe' : ''),
            Config::root() . '/storage/bin/whisper/Release/whisper-cli.exe',
            Config::root() . '/storage/bin/whisper/Release/main.exe',
            Config::root() . '/storage/bin/whisper/whisper-bin-ubuntu-x64/whisper-cli',
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

        self::$cppBinary = Shell::resolveBinary($names, $configured, $known);
        return self::$cppBinary;
    }

    /** Backwards-compatible alias used by older views/scripts. */
    public static function binary(): ?string
    {
        return self::cppBinary();
    }

    public static function cppReady(?string $model = null): bool
    {
        $model = $model ?: self::defaultModel();
        return self::cppBinary() !== null && is_file(self::modelFile($model));
    }

    public static function version(): ?string
    {
        $binary = self::cppBinary();
        if ($binary === null) {
            return null;
        }

        $result = Shell::run([$binary, '--version'], null, 15);
        $text = trim($result['stdout'] . ' ' . $result['stderr']);
        if ($text !== '') {
            $line = strtok($text, "\n");
            return $line !== false ? trim($line) : $text;
        }

        $result = Shell::run([$binary, '--help'], null, 15);
        $text = $result['stdout'] . ' ' . $result['stderr'];
        if (preg_match('/whisper[^\r\n]*/i', $text, $match) === 1) {
            return trim($match[0]);
        }
        return $binary;
    }

    // ------------------------------------------------------------------ #
    // openai-whisper (Python CLI)
    // ------------------------------------------------------------------ #
    public static function openAiBinary(): ?string
    {
        if (self::$pyResolved) {
            return self::$pyBinary;
        }
        self::$pyResolved = true;

        $cfg = AiConfig::load();
        $configured = trim((string) Env::get('WHISPER_PY_BIN', (string) ($cfg['whisper']['openai_binary'] ?? '')));
        $names = Shell::isWindows()
            ? ['whisper.exe', 'whisper.cmd', 'whisper.bat', 'whisper']
            : ['whisper'];

        $home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        $known = $home !== ''
            ? [rtrim($home, "/\\") . '/.local/bin/whisper']
            : [];
        if (!Shell::isWindows()) {
            $known[] = '/usr/local/bin/whisper';
            $known[] = '/usr/bin/whisper';
        } else {
            $local = (string) (getenv('LOCALAPPDATA') ?: '');
            if ($local !== '') {
                foreach (['Python313', 'Python312', 'Python311', 'Python310'] as $python) {
                    $known[] = rtrim($local, '\\') . '\\Programs\\Python\\' . $python . '\\Scripts\\whisper.exe';
                }
            }
            $appdata = (string) (getenv('APPDATA') ?: '');
            if ($appdata !== '') {
                foreach (['Python313', 'Python312', 'Python311', 'Python310'] as $python) {
                    $known[] = rtrim($appdata, '\\') . '\\Python\\' . $python . '\\Scripts\\whisper.exe';
                }
            }
        }

        self::$pyBinary = Shell::resolveBinary($names, $configured, $known);
        return self::$pyBinary;
    }

    public static function openAiModelFile(string $model): string
    {
        $model = preg_replace('/[^A-Za-z0-9._-]/', '', $model) ?: 'base';
        $home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        $cache = $home !== '' ? rtrim($home, "/\\") . DIRECTORY_SEPARATOR . '.cache' : '.cache';
        return $cache . DIRECTORY_SEPARATOR . 'whisper' . DIRECTORY_SEPARATOR . $model . '.pt';
    }

    public static function openAiModelExists(string $model): bool
    {
        return is_file(self::openAiModelFile($model));
    }

    // ------------------------------------------------------------------ #
    // Shared model/config helpers
    // ------------------------------------------------------------------ #
    public static function modelsDir(): string
    {
        return AiConfig::whisperModelsDir();
    }

    public static function englishOnly(): bool
    {
        $cfg = AiConfig::load();
        return !empty($cfg['whisper']['english_only']);
    }

    public static function language(): string
    {
        $cfg = AiConfig::load();
        $value = trim((string) Env::get('WHISPER_LANGUAGE', (string) ($cfg['whisper']['language'] ?? 'English')));
        return $value !== '' ? $value : 'English';
    }

    public static function languageCode(): string
    {
        $value = strtolower(self::language());
        return match ($value) {
            'spanish', 'espanol', 'español', 'es' => 'es',
            'english', 'en', 'ingles', 'inglés' => 'en',
            default => 'en',
        };
    }

    public static function modelFile(string $model): string
    {
        $model = preg_replace('/[^A-Za-z0-9._-]/', '', $model) ?: 'base';
        $englishOnly = self::englishOnly() && self::languageCode() === 'en';
        $englishCapable = in_array($model, ['tiny', 'base', 'small', 'medium'], true);

        $file = 'ggml-' . $model . ($englishOnly && $englishCapable ? '.en' : '') . '.bin';
        return rtrim(self::modelsDir(), "/\\") . DIRECTORY_SEPARATOR . $file;
    }

    public static function modelUrl(string $model): string
    {
        return 'https://huggingface.co/ggerganov/whisper.cpp/resolve/main/' . basename(self::modelFile($model));
    }

    public static function defaultModel(): string
    {
        $cfg = AiConfig::load();
        $model = (string) ($cfg['whisper']['model'] ?? 'base');
        return in_array($model, Config::WHISPER_MODELS, true) ? $model : 'base';
    }

    /**
     * Model used for speaking practice. Short mic clips must answer in a few
     * seconds, so this is configurable separately from the video pipeline.
     */
    public static function speechModel(): string
    {
        $cfg = AiConfig::load();
        $model = trim((string) Env::get('WHISPER_SPEECH_MODEL', (string) ($cfg['whisper']['speech_model'] ?? 'base')));
        return in_array($model, Config::WHISPER_MODELS, true) ? $model : 'base';
    }

    public static function ready(?string $model = null): bool
    {
        $engine = self::engine();
        if ($engine === 'whisper_cpp') {
            return self::cppReady($model);
        }
        if ($engine === 'openai_whisper') {
            return self::openAiBinary() !== null;
        }
        return false;
    }

    public static function missingStatus(?string $model = null): ?string
    {
        if (self::ready($model)) {
            return null;
        }

        $configured = self::configuredEngine();
        if ($configured === 'openai_whisper') {
            return 'whisper_missing';
        }
        if ($configured === 'whisper_cpp') {
            return self::cppBinary() !== null ? 'whisper_model_missing' : 'whisper_missing';
        }

        // auto
        return self::engine() === 'whisper_cpp' || self::cppBinary() !== null
            ? 'whisper_model_missing'
            : 'whisper_missing';
    }

    public static function activeBinary(): ?string
    {
        return match (self::engine()) {
            'openai_whisper' => self::openAiBinary(),
            'whisper_cpp' => self::cppBinary(),
            default => self::cppBinary() ?? self::openAiBinary(),
        };
    }

    public static function activeModelPath(?string $model = null): string
    {
        $model = $model ?: self::defaultModel();
        return self::engine() === 'openai_whisper'
            ? self::openAiModelFile($model)
            : self::modelFile($model);
    }

    public static function activeModelExists(?string $model = null): bool
    {
        $model = $model ?: self::defaultModel();
        return self::engine() === 'openai_whisper'
            ? self::openAiModelExists($model)
            : is_file(self::modelFile($model));
    }

    // ------------------------------------------------------------------ #
    // Transcription
    // ------------------------------------------------------------------ #
    public static function transcribe(string $wavPath, ?string $model = null, int $timeout = 7200, int $threads = 0): ?string
    {
        self::$lastError = null;
        $model = $model ?: self::defaultModel();
        $engine = self::engine();

        if ($engine === 'whisper_cpp') {
            return self::transcribeCpp($wavPath, $model, $timeout, $threads);
        }
        if ($engine === 'openai_whisper') {
            return self::transcribeOpenAi($wavPath, $model, $timeout);
        }

        self::$lastError = match (self::configuredEngine()) {
            'openai_whisper' => 'Se selecciono openai-whisper pero no se encontro su CLI. Configura WHISPER_PY_BIN o la ruta en Ajustes.',
            'whisper_cpp' => 'Se selecciono whisper.cpp pero no se encontro whisper-cli. Configura WHISPER_BIN o la ruta en Ajustes.',
            default => 'No hay motor Whisper disponible. Configura whisper.cpp (WHISPER_BIN + modelo ggml) '
                . 'o el CLI de openai-whisper (WHISPER_PY_BIN) en /settings.',
        };
        return null;
    }

    private static function transcribeCpp(string $wavPath, string $model, int $timeout, int $threads): ?string
    {
        $binary = self::cppBinary();
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
            '-l', self::languageCode(),
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
            self::$lastError = 'Whisper (whisper.cpp) excedio el tiempo limite de ' . $timeout . ' s.';
            @unlink($txt);
            return null;
        }

        $text = is_file($txt) ? trim((string) file_get_contents($txt)) : '';
        @unlink($txt);

        if ($result['exit'] !== 0 || $text === '') {
            $detail = trim($result['stderr']);
            self::$lastError = 'whisper.cpp no genero texto (exit ' . $result['exit'] . '). '
                . (strlen($detail) > 800 ? substr($detail, -800) : $detail);
            return null;
        }

        return $text;
    }

    private static function transcribeOpenAi(string $wavPath, string $model, int $timeout): ?string
    {
        $binary = self::openAiBinary();
        if ($binary === null) {
            self::$lastError = 'No se encontro el CLI de openai-whisper. Configura WHISPER_PY_BIN o la ruta en Ajustes.';
            return null;
        }
        if (!is_file($wavPath)) {
            self::$lastError = 'No existe el audio: ' . $wavPath;
            return null;
        }

        $outDir = Config::tmpDir() . DIRECTORY_SEPARATOR . 'whisper_py_' . md5($wavPath . microtime(true));
        Config::ensureDirectory($outDir);

        $arguments = [
            $binary,
            $wavPath,
            '--model', $model,
            '--language', self::language(),
            '--output_dir', $outDir,
            '--output_format', 'txt',
            '--verbose', 'False',
        ];

        $result = Shell::run($arguments, null, $timeout, Config::tmpDir());
        $txt = $outDir . DIRECTORY_SEPARATOR . pathinfo($wavPath, PATHINFO_FILENAME) . '.txt';
        $text = is_file($txt) ? trim((string) file_get_contents($txt)) : '';
        @unlink($txt);
        @rmdir($outDir);

        if ($result['timeout']) {
            self::$lastError = 'Whisper (openai-whisper) excedio el tiempo limite de ' . $timeout . ' s.';
            return null;
        }
        if ($result['exit'] !== 0 || $text === '') {
            $detail = trim($result['stderr']);
            self::$lastError = 'openai-whisper no genero texto (exit ' . $result['exit'] . '). '
                . (strlen($detail) > 800 ? substr($detail, -800) : $detail);
            return null;
        }

        return $text;
    }

    /**
     * Transcription for speaking practice: same engines and models as
     * transcribe(), but whisper.cpp is asked for its JSON output (-oj) so the
     * result also carries per-segment timing. When the installed build emits
     * per-word timings they are kept; otherwise word_timings is false and the
     * caller aligns at text level. The Python engine only offers plain text,
     * so it degrades to transcribe() transparently.
     *
     * @return array{text:string, engine:string, word_timings:bool, duration:float, segments:list<array{from:float, to:float, text:string}>}|null
     */
    public static function transcribeDetailed(string $audioPath, ?string $model = null, int $timeout = 120): ?array
    {
        self::$lastError = null;
        $model = $model ?: self::speechModel();
        $engine = self::engine();

        if ($engine === 'whisper_cpp') {
            return self::transcribeDetailedCpp($audioPath, $model, $timeout);
        }

        // openai-whisper (or no engine): plain text, no segment timing.
        $text = self::transcribe($audioPath, $model, $timeout);
        if ($text === null) {
            return null;
        }
        return [
            'text'         => $text,
            'engine'       => (string) $engine,
            'word_timings' => false,
            'duration'     => 0.0,
            'segments'     => [],
        ];
    }

    /**
     * @return array{text:string, engine:string, word_timings:bool, duration:float, segments:list<array{from:float, to:float, text:string}>}|null
     */
    private static function transcribeDetailedCpp(string $wavPath, string $model, int $timeout): ?array
    {
        $binary = self::cppBinary();
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

        $outBase = Config::tmpDir() . DIRECTORY_SEPARATOR . 'whisper_d_' . md5($wavPath . $model . microtime(true));
        $result = Shell::run(
            [$binary, '-m', $modelPath, '-f', $wavPath, '-l', self::languageCode(), '-oj', '-np', '-of', $outBase],
            null,
            $timeout,
            Config::tmpDir()
        );

        $jsonPath = $outBase . '.json';
        $raw = is_file($jsonPath) ? (string) file_get_contents($jsonPath) : '';
        @unlink($jsonPath);
        @unlink($outBase . '.txt');

        $decoded = json_decode($raw, true);
        if ($result['timeout']) {
            self::$lastError = 'Whisper (whisper.cpp) excedio el tiempo limite de ' . $timeout . ' s.';
            return null;
        }
        if (!is_array($decoded)) {
            $detail = trim($result['stderr']);
            self::$lastError = 'whisper.cpp no genero JSON utilizable (exit ' . $result['exit'] . '). '
                . (strlen($detail) > 800 ? substr($detail, -800) : $detail);
            return null;
        }

        // whisper.cpp keeps the segments at the top level ("transcription") or
        // nested under "result" depending on the build version.
        $entries = $decoded['transcription'] ?? ($decoded['result']['transcription'] ?? null);
        if (!is_array($entries)) {
            self::$lastError = 'El JSON de whisper.cpp no trae transcripcion.';
            return null;
        }

        $segments = [];
        $wordTimings = false;
        $texts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            [$from, $to] = self::segmentOffsets($entry);
            $text = trim((string) ($entry['text'] ?? ''));
            $text = trim($text, " \t\"");
            if ($text === '') {
                continue;
            }
            $segments[] = ['from' => $from, 'to' => $to, 'text' => $text];
            $texts[] = $text;
            if (is_array($entry['words'] ?? null) && $entry['words'] !== []) {
                $wordTimings = true;
            }
        }

        return [
            'text'         => trim(implode(' ', $texts)),
            'engine'       => 'whisper_cpp',
            'word_timings' => $wordTimings,
            'duration'     => $segments !== [] ? max(array_column($segments, 'to')) : 0.0,
            'segments'     => $segments,
        ];
    }

    /**
     * Seconds [from, to] for a JSON segment: prefers the numeric "offsets"
     * (milliseconds) and falls back to parsing "timestamps" ("00:00:12,760").
     *
     * @param array<string, mixed> $entry
     * @return array{0:float, 1:float}
     */
    private static function segmentOffsets(array $entry): array
    {
        $offsets = $entry['offsets'] ?? null;
        if (is_array($offsets) && isset($offsets['from'], $offsets['to'])) {
            return [((float) $offsets['from']) / 1000, ((float) $offsets['to']) / 1000];
        }

        $parse = static function (mixed $value): float {
            $value = (string) $value;
            if (preg_match('/(\d+):(\d{2}):(\d{2})[,.](\d{3})/', $value, $m) === 1) {
                return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3] + ((int) $m[4]) / 1000;
            }
            return 0.0;
        };

        $timestamps = is_array($entry['timestamps'] ?? null) ? $entry['timestamps'] : [];
        return [
            $parse($timestamps['from'] ?? null),
            $parse($timestamps['to'] ?? null),
        ];
    }

    /**
     * @return array{ok:bool, engine:string, engine_label:string, model:string, seconds:float, words:int, text:string, error:?string}
     */
    public static function test(string $model): array
    {
        $engine = self::engine();
        $audio = Config::tmpDir() . DIRECTORY_SEPARATOR . 'lesson_audio.wav';
        if (!is_file($audio)) {
            return [
                'ok'           => false,
                'engine'       => (string) $engine,
                'engine_label' => self::engineLabel($engine),
                'model'        => $model,
                'seconds'      => 0.0,
                'words'        => 0,
                'text'         => '',
                'error'        => 'No existe audio de prueba. Procesa un video primero (se guarda en storage/tmp/lesson_audio.wav).',
            ];
        }

        $started = microtime(true);
        $text = self::transcribe($audio, $model, 1800);
        $elapsed = round(microtime(true) - $started, 1);

        if ($text === null) {
            return [
                'ok'           => false,
                'engine'       => (string) ($engine ?? ''),
                'engine_label' => self::engineLabel($engine),
                'model'        => $model,
                'seconds'      => $elapsed,
                'words'        => 0,
                'text'         => '',
                'error'        => self::$lastError,
            ];
        }

        return [
            'ok'           => true,
            'engine'       => (string) $engine,
            'engine_label' => self::engineLabel($engine),
            'model'        => $model,
            'seconds'      => $elapsed,
            'words'        => str_word_count($text),
            'text'         => substr($text, 0, 600),
            'error'        => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function diagnostics(): array
    {
        $model = self::defaultModel();
        $engine = self::engine();
        $cppModel = self::modelFile($model);
        $pyModel = self::openAiModelFile($model);

        return [
            'configured_engine' => self::configuredEngine(),
            'engine'            => $engine,
            'engine_label'      => self::engineLabel($engine),
            'ready'             => self::ready($model),
            'binary'            => self::activeBinary(),
            'version'           => self::version(),
            'models_dir'        => self::modelsDir(),
            'model'             => $model,
            'speech_model'      => self::speechModel(),
            'model_file'        => self::activeModelPath($model),
            'model_exists'      => self::activeModelExists($model),
            'language'          => self::language(),
            'ffmpeg'            => Ffmpeg::binary(),
            'cpp'               => [
                'binary'       => self::cppBinary(),
                'version'      => self::version(),
                'model_file'   => $cppModel,
                'model_exists' => is_file($cppModel),
            ],
            'openai'            => [
                'binary'       => self::openAiBinary(),
                'model_file'   => $pyModel,
                'model_exists' => is_file($pyModel),
                'language'     => self::language(),
            ],
        ];
    }
}
