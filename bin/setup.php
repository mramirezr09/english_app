<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Core\Model;

try {
    $db = Model::connection();
    $db->exec('CREATE TABLE IF NOT EXISTS lessons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name TEXT UNIQUE,
        title TEXT,
        transcription TEXT,
        explanation TEXT,
        exercises TEXT,
        completed INTEGER DEFAULT 0
    )');
    echo 'Base de datos y tabla creadas correctamente.' . PHP_EOL;
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
