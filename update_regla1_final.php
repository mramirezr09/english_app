<?php
$db_path = __DIR__ . '/data/lessons.db';
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $transcription = "In this lesson, we learn about the negative form of the verb 'to be'. To make a sentence negative, we add the word 'not' after the verb to be. For example: 'He is not happy' or 'They are not students'. This is the basic way to express negation in English using the verb to be.";
    
    $explanation = "La Forma Negativa del Verbo 'To Be'\n\nPara negar en inglés usando el verbo to be (am, is, are), simplemente añadimos la palabra 'NOT' inmediatamente después del verbo.\n\nEjemplos:\n- I am NOT tired.\n- She IS NOT (isn't) a doctor.\n- We ARE NOT (aren't) at home.\n\nRecuerda que 'not' es la clave para transformar una afirmación en una negación.";
    
    $exercises = json_encode([
        ['question' => 'Traduce: Él no está feliz', 'answer' => 'He is not happy'],
        ['question' => 'Traduce: Yo no soy un estudiante', 'answer' => 'I am not a student'],
        ['question' => '¿Qué palabra se añade después del verbo to be para negar?', 'answer' => 'not'],
        ['question' => 'Corrige la frase: "She not is happy"', 'answer' => 'She is not happy'],
        ['question' => 'Traduce: Nosotros no estamos en casa', 'answer' => 'We are not at home'],
    ]);

    $stmt = $db->prepare("UPDATE lessons SET transcription = ?, explanation = ?, exercises = ? WHERE file_name = ?");
    $stmt->execute([$transcription, $explanation, $exercises, 'Regla_1.mp4']);

    echo "Regla 1 actualizada con contenido REAL exitosamente.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>