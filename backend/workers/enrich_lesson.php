<?php

declare(strict_types=1);

/**
 * Generate the Spanish explanation and exercises for one lesson.
 *
 * Usage:
 *   php backend/workers/enrich_lesson.php <lesson_id> [--force]
 */

require_once __DIR__ . '/bootstrap.php';

use App\Models\Lesson;
use App\Services\Enricher;

[$positional, $options] = worker_parse_args($argv);

if ($positional === null || !ctype_digit($positional)) {
    worker_log('Uso: php enrich_lesson.php <lesson_id> [--force]');
    exit(1);
}

$lessonId = (int) $positional;
$lesson = (new Lesson())->find($lessonId);
if ($lesson === null || trim((string) ($lesson['transcription'] ?? '')) === '') {
    worker_log('La leccion ' . $lessonId . ' no tiene transcripcion todavia.');
    exit(1);
}

$ok = (new Enricher())->enrich($lessonId, isset($options['force']), 'worker_log');
exit($ok ? 0 : 1);
