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
        <a href="/exams/new?scope=lesson&amp;ref=<?php echo (int) $lesson['id']; ?>" class="btn" style="background:#c0392b">&#128221; Examen de esta lecci&oacute;n</a>
    </div>

    <div class="video-container">
        <video controls>
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
            <div class="chat-row">
                <input type="text" id="tutor-input" placeholder="Escribe en ingles...">
                <button class="btn" onclick="sendMessage()">Enviar</button>
            </div>
        </div>
    </div>

    <div class="content-section">
        <h2>&#127908; Practica Oral</h2>
        <p>La IA te hace preguntas sobre la leccion. Responde con el microfono y te dira
           que se entendio bien, que palabras corregir y como.</p>
        <label style="display:flex; align-items:center; gap:6px; margin:6px 0;">
            <input type="checkbox" id="speech-tts" checked>
            <span>Leer las preguntas en voz alta</span>
        </label>
        <div id="speech-warning" class="error" style="display:none; margin:10px 0;"></div>
        <div class="actions">
            <button class="btn" style="background:#e67e22" onclick="SpeechPractice.startConversation()">&#127908; Iniciar conversacion</button>
            <button class="btn" id="speaking-skip" style="background:#34495e; display:none" onclick="SpeechPractice.skipQuestion()">Otra pregunta</button>
        </div>
        <div id="speaking-log" style="margin-top:15px; border:1px solid #ddd; padding:15px; border-radius:10px; background:#fefefe; max-height:420px; overflow-y:auto;" hidden></div>
        <div id="speaking-controls" style="display:none; align-items:center; gap:10px; margin-top:10px;">
            <button class="btn mic-btn" id="speaking-mic" onclick="SpeechPractice.talkMic(this)">&#127908; Responder</button>
            <span id="speaking-status" style="color:#666;"></span>
        </div>
    </div>

    <div class="content-section">
        <h2>&#9997;&#65039; Ejercicios</h2>
        <div class="exercise-box">
            <?php if ($exercises): ?>
                <?php foreach ($exercises as $index => $ex): ?>
                    <div class="exercise" data-answer="<?php echo htmlspecialchars($ex['answer'] ?? ''); ?>" data-question="<?php echo htmlspecialchars($ex['question'] ?? ''); ?>">
                        <p><?php echo ($index + 1) . '. ' . htmlspecialchars($ex['question'] ?? ''); ?></p>
                        <input type="text" id="ex_<?php echo $index; ?>" placeholder="Tu respuesta...">
                        <button class="btn" onclick="checkAnswer(<?php echo $index; ?>)">Verificar</button>
                        <button type="button" class="btn mic-btn" style="background:#e67e22" onclick="SpeechPractice.exerciseMic(this, <?php echo $index; ?>)">&#127908; Responder con voz</button>
                        <span id="res_<?php echo $index; ?>"></span>
                        <div class="speech-result" id="speech-result-<?php echo $index; ?>" hidden></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay ejercicios disponibles para esta regla todavia.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="/assets/js/lesson.js"></script>
<script src="/assets/js/speech.js"></script>
