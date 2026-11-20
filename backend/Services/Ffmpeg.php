<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Env;

/**
 * ffmpeg wrapper used to extract 16 kHz mono WAV audio from lesson videos.
 */
final class Ffmpeg
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
        $configured = trim((string) Env::get('FFMPEG_BIN', (string) ($cfg['whisper']['ffmpeg'] ?? '')));
        $names = Shell::isWindows() ? ['ffmpeg.exe', 'ffmpeg'] : ['ffmpeg'];

        $known = [
            Config::root() . '/storage/bin/ffmpeg/bin/ffmpeg' . (Shell::isWindows() ? '.exe' : ''),
            Config::root() . '/storage/bin/ffmpeg' . (Shell::isWindows() ? '.exe' : ''),
        ];
        if (Shell::isWindows()) {
            $known[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
            $local = getenv('LOCALAPPDATA') ?: '';
            if ($local !== '') {
                $known[] = rtrim($local, '\\') . '\\Microsoft\\WinGet\\Links\\ffmpeg.exe';
            }
        } else {
            $known[] = '/usr/bin/ffmpeg';
            $known[] = '/usr/local/bin/ffmpeg';
            $known[] = '/snap/bin/ffmpeg';
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
        $result = Shell::run([$binary, '-version'], null, 15);
        $line = strtok(trim($result['stdout']), "\n");
        return $line !== false ? trim($line) : null;
    }

    public static function extractAudio(string $videoPath, string $wavPath, int $timeout = 3600): bool
    {
        return self::toWav($videoPath, $wavPath, 'video', $timeout);
    }

    /**
     * Convert a browser audio upload (webm/ogg/m4a/mp3...) to the 16 kHz mono
     * WAV that Whisper expects. Same pipeline as extractAudio() but from audio
     * instead of video, so short clips finish in well under a second.
     */
    public static function audioToWav(string $audioPath, string $wavPath, int $timeout = 120): bool
    {
        return self::toWav($audioPath, $wavPath, 'audio', $timeout);
    }

    private static function toWav(string $srcPath, string $wavPath, string $kindLabel, int $timeout): bool
    {
        self::$lastError = null;

        $binary = self::binary();
        if ($binary === null) {
            self::$lastError = 'No se encontro ffmpeg. Configura la ruta en Ajustes o en FFMPEG_BIN.';
            return false;
        }
        if (!is_file($srcPath)) {
            self::$lastError = 'No existe el ' . $kindLabel . ': ' . $srcPath;
            return false;
        }

        Config::ensureDirectory(dirname($wavPath));

        $result = Shell::run([
            $binary, '-y', '-i', $srcPath, '-vn',
            '-acodec', 'pcm_s16le', '-ar', '16000', '-ac', '1',
            $wavPath,
        ], null, $timeout);

        if ($result['timeout']) {
            self::$lastError = 'ffmpeg excedio el tiempo limite.';
            return false;
        }
        if ($result['exit'] !== 0 || !is_file($wavPath) || filesize($wavPath) === 0) {
            $detail = trim($result['stderr']);
            self::$lastError = 'ffmpeg fallo: ' . (strlen($detail) > 800 ? substr($detail, -800) : $detail);
            return false;
        }

        return true;
    }
}
