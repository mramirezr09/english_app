<?php
$db_path = __DIR__ . '/data/lessons.db';
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $query = "CREATE TABLE IF NOT EXISTS lessons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name TEXT UNIQUE,
        title TEXT,
        transcription TEXT,
        explanation TEXT,
        exercises TEXT,
        completed INTEGER DEFAULT 0
    )";
    
    $db->exec($query);
    echo "Database and table created successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>