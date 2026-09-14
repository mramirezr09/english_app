<?php
$db_path = '/home/mauricio/.openclaw/workspace/english_app/data/lessons.db';
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("UPDATE lessons SET transcription = NULL, explanation = NULL, exercises = NULL WHERE file_name LIKE 'Regla_1%'");
    echo "Lesson 1 has been reset successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>