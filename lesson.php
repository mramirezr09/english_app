<?php
$db_path = __DIR__ . '/data/lessons.db';
$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: index.php"); exit; }

$stmt = $db->prepare("SELECT * FROM lessons WHERE id = ?");
$stmt->execute([$id]);
$lesson = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) { echo "Lección no encontrada"; exit; }

$video_path = '/home/mauricio/.openclaw/workspace/ingles/4A/' . $lesson['file_name'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($lesson['title']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <a href="index.php" style="text-decoration: none; color: #3498db;">← Volver al índice</a>
        <h1><?php echo htmlspecialchars($lesson['title']); ?></h1>

        <div class="video-container">
            <video controls style="width: 100%; max-width: 800px;">
                <source src="videos/<?php echo $lesson['file_name']; ?>" type="video/mp4">
                Tu navegador no soporta videos.
            </video>
        </div>

        <div class="content-section">
            <h2>📖 Transcripción</h2>
            <p><?php echo nl2br(htmlspecialchars($lesson['transcription'] ?? 'Pendiente de procesar...')); ?></p>
            <h2>💡 Explicación</h2>
            <p><?php echo nl2br(htmlspecialchars($lesson['explanation'] ?? 'Pendiente de procesar...')); ?></p>
        </div>

        <div class="content-section">
            <h2>🤖 Tutor de Inglés</h2>
            <p>Practica la lección con el tutor especializado.</p>
            <button class="btn" style="background:#8e44ad" onclick="startTutorChat()">Hablar con el Tutor</button>
            <div id="tutor-chat" style="display:none; margin-top:20px; border:1px solid #ddd; padding:15px; border-radius:10px; background:#fefefe;">
                <div id="chat-messages" style="height:200px; overflow-y:auto; margin-bottom:10px; display:flex; flex-direction:column; gap:10px;"></div>
                <div style="display:flex; gap:10px;">
                    <input type="text" id="tutor-input" style="flex:1" placeholder="Escribe en inglés...">
                    <button class="btn" onclick="sendMessage()">Enviar</button>
                </div>
            </div>
        </div>

        <div class="content-section">
            <h2>✍️ Ejercicios</h2>
            <div class="exercise-box">
                <?php if ($lesson['exercises']): 
                    $exs = json_decode($lesson['exercises'], true);
                    if (is_array($exs)) {
                        foreach ($exs as $index => $ex): ?>
                            <p><?php echo ($index+1) . ". " . htmlspecialchars($ex['question']); ?></p>
                            <input type="text" id="ex_<?php echo $index; ?>" placeholder="Tu respuesta...">
                            <button class="btn" onclick="checkAnswer(<?php echo $index; ?>, '<?php echo addslashes($ex['answer']); ?>')">Verificar</button>
                            <span id="res_<?php echo $index; ?>"></span><br><br>
                        <?php endforeach; 
                    } else { echo "Error en el formato de ejercicios."; }
                else: ?>
                    <p>No hay ejercicios disponibles para esta regla todavía.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function startTutorChat() {
        document.getElementById('tutor-chat').style.display = 'block';
        appendMessage('Tutor', 'Hello! I am your English Tutor. I know everything about this lesson. Ask me anything!');
    }

    async function sendMessage() {
        const input = document.getElementById('tutor-input');
        const msg = input.value.trim();
        if (!msg) return;
        appendMessage('You', msg);
        input.value = '';
        try {
            const res = await fetch('tutor_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message: msg, lesson_id: <?php echo $id; ?>})
            });
            const data = await res.json();
            appendMessage('Tutor', data.response || data.error);
        } catch (e) { appendMessage('System', 'Error connecting to tutor.'); }
    }

    function appendMessage(sender, text) {
        const chat = document.getElementById('chat-messages');
        const div = document.createElement('div');
        div.innerHTML = `<strong>${sender}:</strong> ${text}`;
        div.style.padding = '8px'; div.style.borderRadius = '5px';
        div.style.background = sender === 'You' ? '#e1f5fe' : '#f5f5f5';
        chat.appendChild(div);
        chat.scrollTop = chat.scrollHeight;
    }
    </script>
</body>
</html>
