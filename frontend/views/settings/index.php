<?php
/** @var array $cfg */
/** @var string[] $models */
/** @var string $currentModel */
/** @var string|null $message */
/** @var string|null $error */
?>
<a href="/" style="text-decoration: none; color: #3498db;">&larr; Volver al indice</a>
<h1>&#9881;&#65039; Ajustes</h1>

<?php if ($message): ?><p class="success"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>

<form method="post" action="/settings" class="content-section">
    <h2>Motor de IA</h2>
    <label class="field">Backend:
        <select name="backend" class="form-control">
            <option value="ollama" <?php echo $cfg['backend'] === 'ollama' ? 'selected' : ''; ?>>Ollama (local)</option>
            <option value="openclaw" <?php echo $cfg['backend'] === 'openclaw' ? 'selected' : ''; ?>>OpenClaw CLI</option>
        </select>
    </label>

    <h3>Ollama</h3>
    <label class="field">Endpoint: <input type="text" name="ollama_endpoint" class="form-control" value="<?php echo htmlspecialchars($cfg['ollama']['endpoint']); ?>"></label>
    <label class="field">Modelo:
        <select name="ollama_model" class="form-control">
            <?php if (!$models): ?><option value="<?php echo htmlspecialchars($currentModel); ?>"><?php echo htmlspecialchars($currentModel); ?></option><?php endif; ?>
            <?php foreach ($models as $m): ?>
                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $m === $currentModel ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">Otro modelo (opcional, tiene prioridad):
        <input type="text" name="ollama_model_custom" class="form-control" placeholder="ej: gemma4:31b-cloud">
    </label>
    <label class="field">Timeout (s): <input type="number" name="ollama_timeout" class="form-control" value="<?php echo (int) $cfg['ollama']['timeout']; ?>" min="10"></label>

    <h3>OpenClaw</h3>
    <label class="field">Binario: <input type="text" name="openclaw_binary" class="form-control" value="<?php echo htmlspecialchars($cfg['openclaw']['binary']); ?>"></label>
    <label class="field">Timeout (s): <input type="number" name="openclaw_timeout" class="form-control" value="<?php echo (int) $cfg['openclaw']['timeout']; ?>" min="10"></label>

    <h3>Whisper</h3>
    <label class="field">Modelo:
        <select name="whisper_model" class="form-control">
            <?php foreach ($whisperModels as $w): ?>
                <option value="<?php echo $w; ?>" <?php echo $w === $cfg['whisper']['model'] ? 'selected' : ''; ?>><?php echo $w; ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <h3>Examenes</h3>
    <label class="field">Preguntas por examen:
        <input type="number" name="exam_questions" class="form-control" min="3" max="50" value="<?php echo (int) ($cfg['exam']['questions'] ?? 12); ?>">
    </label>
    <label class="field">Puntaje minimo para aprobar (%):
        <input type="number" name="exam_passing_score" class="form-control" min="1" max="100" value="<?php echo (int) ($cfg['exam']['passing_score'] ?? 80); ?>">
    </label>

    <button type="submit" class="btn">Guardar</button>
</form>

<div class="content-section">
    <h2>Pruebas</h2>
    <div class="actions">
        <button class="btn" type="button" onclick="refreshModels()">Listar modelos Ollama</button>
        <button class="btn" style="background:#8e44ad" type="button" onclick="testAi()">Probar IA</button>
        <button class="btn" style="background:#16a085" type="button" onclick="testWhisper()">Probar Whisper</button>
    </div>
    <pre id="result" style="background:#f4f4f4; padding:15px; border-radius:8px; white-space:pre-wrap; min-height:60px;">Sin resultados todavia.</pre>
</div>
<script src="/assets/js/settings.js"></script>
