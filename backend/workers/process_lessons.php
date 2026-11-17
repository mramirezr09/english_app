<?php

declare(strict_types=1);

/**
 * Extract audio, transcribe with whisper.cpp and enrich lessons with AI.
 *
 * Usage:
 *   php backend/workers/process_lessons.php
 *   php backend/workers/process_lessons.php Regla_3.mp4
 *   php backend/workers/process_lessons.php Regla_3.mp4 --whisper-model small
 *   php backend/workers/process_lessons.php Regla_3.mp4 --skip-enrich --force
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Config;
use App\Services\AiConfig;
use App\Services\LessonPipeline;

[$file, $options] = worker_parse_args($argv, ['whisper-model']);

if (isset($options['help'])) {
    echo "Uso: php process_lessons.php [archivo.mp4] [--whisper-model base] [--skip-enrich] [--force]\n";
    exit(0);
}

$config = AiConfig::load();
$whisperModel = (string) ($options['whisper-model'] ?? ($config['whisper']['model'] ?? 'base'));
if (!in_array($whisperModel, Config::WHISPER_MODELS, true)) {
    $whisperModel = 'base';
}

$pipeline = new LessonPipeline();
$force = isset($options['force']);
$doEnrich = !isset($options['skip-enrich']);

if ($file !== null && $file !== '') {
    $ok = $pipeline->processFile($file, $whisperModel, $doEnrich, $force, 'worker_log');
    worker_log('RESULTADO: ' . $file . ' ' . ($ok ? 'procesado' : 'fallo') . '.');
    exit($ok ? 0 : 1);
}

$result = $pipeline->processAll($whisperModel, $doEnrich, $force, 'worker_log');
exit($result['pending'] === 0 || $result['processed'] === $result['pending'] ? 0 : 1);
