<?php
/** @var array $lesson */
/** @var array $exercises */
/** @var string $video_url */
?>
<div id="lesson-page" data-lesson-id="<?php echo (int) $lesson['id']; ?>">
    <a href="/" style="text-decoration: none; color: #3498db;">&larr; Volver al indice</a>
    <h1><?php echo htmlspecialchars($lesson['title']); ?></h1>

    <div style="text-align:center; margin-bottom:10px;">
        <button id="complete-btn" class="btn" style="background:#2ecc71; <?php echo $lesson['completed'] ? 'display:none;' : ''; ?>" onclick="markComplete()">Marcar como completada</button>
        <span id="complete-status" class="<?php echo $lesson['completed'] ? 'success' : ''; ?>">
            <?php echo $lesson['completed'] ? '&#9989; Completada' : ''; ?>
        </span>
    </div>

    <div class="video-container">
        <video controls style="width: 100%; max-width: 800px;">
            <source src="/videos/<?php echo $video_url; ?>" type="video/mp4">
            Tu navegador no soporta videos.
        </video>
    </div>

    <div class="content-section">
        <h2>&#128214; Transcripcion</h2>
        <p><?php echo nl2br(htmlspecialchars($lesson['transcription'] ?? 'Pendiente de procesar...')); ?></p>
        <h2>&#128161; Explicacion</h2>
        <div class="markdown">
            <?php echo $lesson['explanation'] ? $explanation_html : '<p>Pendiente de procesar...</p>'; ?>
        </div>
    </div>

    <div class="content-section">
        <h2>&#129302; Tutor de Ingles</h2>
        <p>Practica la leccion con el tutor especializado.</p>
        <button class="btn" style="background:#8e44ad" onclick="startTutorChat()">Hablar con el Tutor</button>
        <div id="tutor-chat" style="display:none; margin-top:20px; border:1px solid #ddd; padding:15px; border-radius:10px; background:#fefefe;">
            <div id="chat-messages" style="height:200px; overflow-y:auto; margin-bottom:10px; display:flex; flex-direction:column; gap:10px;"></div>
            <div style="display:flex; gap:10px;">
                <input type="text" id="tutor-input" style="flex:1" placeholder="Escribe en ingles...">
                <button class="btn" onclick="sendMessage()">Enviar</button>
            </div>
        </div>
    </div>

    <div class="content-section">
        <h2>&#9997;&#65039; Ejercicios</h2>
        <div class="exercise-box">
            <?php if ($exercises): ?>
                <?php foreach ($exercises as $index => $ex): ?>
                    <div class="exercise" data-answer="<?php echo htmlspecialchars($ex['answer'] ?? ''); ?>">
                        <p><?php echo ($index + 1) . '. ' . htmlspecialchars($ex['question'] ?? ''); ?></p>
                        <input type="text" id="ex_<?php echo $index; ?>" placeholder="Tu respuesta...">
                        <button class="btn" onclick="checkAnswer(<?php echo $index; ?>)">Verificar</button>
                        <span id="res_<?php echo $index; ?>"></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay ejercicios disponibles para esta regla todavia.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="/assets/js/lesson.js"></script>
