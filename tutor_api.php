<?php
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$message = $input['message'] ?? '';
$lesson_id = $input['lesson_id'] ?? null;

if (!$message || !$lesson_id) {
    echo json_encode(['error' => 'Missing data']);
    exit;
}

try {
    $db_path = __DIR__ . '/data/lessons.db';
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("SELECT transcription, explanation FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lesson) {
        echo json_encode(['error' => 'Lesson not found']);
        exit;
    }

    $transcription = $lesson['transcription'] ?? 'No transcription available.';
    $explanation = $lesson['explanation'] ?? 'No explanation available.';

    // Construimos el prompt para el Tutor
    $prompt = "You are a friendly and professional English Tutor. Your goal is to help the student understand the current lesson. \n\n";
    $prompt .= "CONTEXT OF THE LESSON:\n";
    $prompt .= "Transcription: $transcription\n";
    $prompt .= "Teacher's Explanation: $explanation\n\n";
    $prompt .= "STUDENT MESSAGE: $message\n\n";
    $prompt .= "Please respond to the student in English, but if they ask for a clarification in Spanish, you can provide it. Be encouraging and focus on the grammar/vocabulary in the context provided.";

    // Llamada a la IA a través de la CLI de OpenClaw
    $binary_path = '/home/mauricio/.openclaw/tmp/agent-cli/openclaw';
    $cmd = "$binary_path ask " . $escaped_prompt;
    $response = shell_exec($cmd);

    echo json_encode(['response' => trim($response)]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>