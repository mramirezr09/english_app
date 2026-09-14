<?php
$db_path = __DIR__ . '/data/lessons.db';
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $count = $db->query("SELECT count(*) FROM lessons WHERE transcription IS NOT NULL AND transcription != ''")->fetchColumn();
    echo "Lecciones procesadas: " . $count;
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>