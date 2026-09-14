<?php
$db_path = __DIR__ . '/data/lessons.db';
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $transcription = "Hello everyone! Today we are starting with Rule Number 1. In English, we use the word 'The' when we talk about something specific. For example: 'The car' refers to a specific car, not any car in general. Practice this: The book, The house, The dog.";
    
    $explanation = "En esta lección, el video se enfoca en el Artículo Definido (Definite Article): 'THE'.\n\n1. ¿Cuándo usarlo?\nUsamos 'the' cuando hablamos de algo específico, algo que tanto el hablante como el oyente ya conocen o que ha sido mencionado antes.\n\n2. Comparación:\n- A car -> Un coche (cualquiera, no importa cuál).\n- The car -> El coche (uno en particular).";
    
    $exercises = json_encode([
        ['question' => 'Traduce: El perro', 'answer' => 'The dog'],
        ['question' => 'Traduce: La casa', 'answer' => 'The house'],
        ['question' => 'Traduce: El libro', 'answer' => 'The book'],
        ['question' => '¿Cuál indica algo específico: "A book" o "The book"?', 'answer' => 'The book'],
    ]);

    $stmt = $db->prepare("UPDATE lessons SET transcription = ?, explanation = ?, exercises = ? WHERE file_name = ?");
    $stmt->execute([$transcription, $explanation, $exercises, 'Regla_1.mp4']);

    echo "Regla 1 actualizada exitosamente en la base de datos.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>