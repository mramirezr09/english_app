<?php
$db_path = '/home/mauricio/.openclaw/workspace/english_app/data/lessons.db';
try {
    $db = new PDO("sqlite:$db_path");
    $stmt = $db->query("SELECT file_name, transcription FROM lessons");
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "--- Estado Actual de Transcripciones ---\\n";
    foreach($lessons as $l) {
        $status = !empty($l['transcription']) ? "✅ Procesado" : "⏳ Pendiente";
        echo "{$l['file_name']}: $status\\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>