<?php

declare(strict_types=1);

/**
 * Generate a bank of exam questions with AI.
 *
 * Usage:
 *   php backend/workers/generate_exam.php --lesson 3
 *   php backend/workers/generate_exam.php --lesson 3 --force
 *   php backend/workers/generate_exam.php --all [--force]
 *   php backend/workers/generate_exam.php --lesson 3 --count 15
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\AiConfig;
use App\Services\ExamGenerator;

[$positional, $options] = worker_parse_args($argv, ['lesson', 'count']);

if (isset($options['help'])) {
    echo "Uso: php generate_exam.php --lesson <id> | --all [--force] [--count 12]\n";
    exit(0);
}

$config = AiConfig::load();
$count = (int) ($options['count'] ?? ($config['exam']['questions'] ?? 12));
$count = max(3, min(50, $count));
$force = isset($options['force']);
$generator = new ExamGenerator();

if (isset($options['lesson']) && ctype_digit((string) $options['lesson'])) {
    $ok = $generator->generateForLesson((int) $options['lesson'], $count, $force, 'worker_log');
    exit($ok ? 0 : 1);
}

if (isset($options['all'])) {
    $ok = $generator->generateAll($count, $force, 'worker_log');
    worker_log('Lecciones con preguntas generadas: ' . $ok);
    exit(0);
}

worker_log('Uso: php generate_exam.php --lesson <id> | --all [--force] [--count 12]');
exit(1);
