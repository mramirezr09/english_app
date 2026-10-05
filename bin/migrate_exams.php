<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Core\Model;

try {
    $db = Model::connection();

    $db->exec('CREATE TABLE IF NOT EXISTS exam_questions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        lesson_id INTEGER,
        level TEXT,
        type TEXT NOT NULL,
        prompt TEXT NOT NULL,
        options TEXT,
        answer TEXT NOT NULL,
        alt_answers TEXT,
        explanation TEXT,
        difficulty INTEGER DEFAULT 1,
        source TEXT DEFAULT \'ai\',
        created_at TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_exam_questions_lesson ON exam_questions (lesson_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_exam_questions_level ON exam_questions (level)');

    $db->exec('CREATE TABLE IF NOT EXISTS exam_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL,
        scope_ref TEXT,
        status TEXT DEFAULT \'in_progress\',
        total INTEGER NOT NULL,
        score REAL,
        passed INTEGER DEFAULT 0,
        paper TEXT,
        details TEXT,
        started_at TEXT,
        finished_at TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_exam_attempts_status ON exam_attempts (status)');

    echo 'Tablas de examen creadas correctamente.' . PHP_EOL;
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
