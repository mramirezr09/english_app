<?php
/** @var array $lessons */
/** @var array $stats */
/** @var array $config */
/** @var string[] $whisperModels */
/** @var string|null $status */
/** @var array $logs */
/** @var array $prerequisites */
?>
<h1>&#9881;&#65039; Panel de Sincronizacion</h1>
<div class="content-section" style="text-align: center;">
    <h2 style="margin-bottom:10px;">Estado General</h2>
    <?php if (!empty($status)): ?>
        <p class="success"><?php echo htmlspecialchars($status); ?></p>
    <?php endif; ?>
    <p style="font-size: 1.2em; margin-bottom:20px;">
        Videos: <strong><?php echo $stats['total']; ?></strong> |
        Transcritos: <strong style="color:green;"><?php echo $stats['processed']; ?></strong> |
        Con IA: <strong style="color:#8e44ad;"><?php echo $stats['enriched']; ?></strong> |
        Pendientes: <strong style="color:red;"><?php echo $stats['pending']; ?></strong>
    </p>
    <p style="margin-bottom:10px; color:#666;">
        Suelta los nuevos <strong>.mp4</strong> en la carpeta de videos y recarga esta pagina: se detectan automaticamente. El boton procesa solo lo pendiente.
    </p>
    <p style="margin-bottom:15px; color:#666; font-size:0.9em;">
        Requisitos:
        ffmpeg <?php echo $prerequisites['ffmpeg'] ? '&#9989;' : '&#10060;'; ?> /
        whisper-cli <?php echo $prerequisites['whisper'] ? '&#9989;' : '&#10060;'; ?> /
        modelo <code><?php echo htmlspecialchars(basename((string) $prerequisites['model'])); ?></code>
        <?php echo is_file((string) $prerequisites['model']) ? '&#9989;' : '&#10060;'; ?>
        <?php if (!$prerequisites['ready']): ?>
            <br><a href="/settings">Configurar en Ajustes</a>
        <?php endif; ?>
    </p>
    <label class="field" style="text-align:left;">Modelo Whisper:
        <select id="whisper" class="form-control">
            <?php foreach ($whisperModels as $w): ?>
                <option value="<?php echo $w; ?>" <?php echo $w === $config['whisper']['model'] ? 'selected' : ''; ?>><?php echo $w; ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="actions">
        <button class="btn" style="background:#2ecc71" onclick="go('process')">Procesar Videos Pendientes</button>
        <a href="/sync" class="btn">Buscar Videos Nuevos</a>
        <a href="/" class="btn">Volver al Indice</a>
        <a href="/settings" class="btn" style="background:#8e44ad">Ajustes</a>
    </div>
</div>
<div class="content-section">
    <h2 style="margin-bottom:20px;">Lista de Archivos</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Archivo</th>
                    <th>Estado</th>
                    <th>Procesar</th>
                    <th>Gestion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lessons as $lesson): ?>
                <tr>
                    <td><?php echo htmlspecialchars($lesson['file_name']); ?></td>
                    <td>
                        <?php echo !empty($lesson['transcription']) ? '&#9989; Transcrito' : '&#8987; Pendiente'; ?>
                        <?php echo !empty($lesson['explanation']) ? ' / &#129302; IA' : ''; ?>
                    </td>
                    <td>
                        <button class="btn" style="padding:5px 10px; font-size:0.8em; background:#8e44ad;" onclick="live(<?php echo htmlspecialchars(json_encode($lesson['file_name']), ENT_QUOTES); ?>)">Procesar en Vivo</button>
                    </td>
                    <td>
                        <a href="/sync/re-enrich/<?php echo (int) $lesson['id']; ?>" class="btn" style="padding:5px 10px; font-size:0.8em; background:#2980b9;" onclick="return confirm('Regenerar explicacion y ejercicios con IA?')">Regenerar IA</a>
                        <a href="/lessons/<?php echo (int) $lesson['id']; ?>/reset" class="btn" style="padding:5px 10px; font-size:0.8em; background:#e74c3c;" onclick="return confirm('Resetear esta leccion? Se borraran transcripcion, explicacion y ejercicios.')">Resetear</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="content-section">
    <h2>Registro de procesos</h2>
    <?php if ($logs): ?>
        <?php foreach ($logs as $logEntry): ?>
            <details class="log-entry" <?php echo $logEntry['state'] === 'error' ? 'open' : ''; ?>>
                <summary>
                    <span class="badge <?php echo htmlspecialchars((string) $logEntry['state']); ?>"><?php echo htmlspecialchars((string) $logEntry['state']); ?></span>
                    <?php echo htmlspecialchars((string) $logEntry['name']); ?>
                    <small><?php echo htmlspecialchars((string) $logEntry['modified']); ?></small>
                </summary>
                <pre class="log-tail"><?php echo htmlspecialchars((string) $logEntry['tail']); ?></pre>
                <a href="/exams/log?name=<?php echo urlencode((string) $logEntry['name']); ?>" target="_blank" class="btn" style="padding:4px 10px; font-size:0.8em;">Ver log completo</a>
            </details>
        <?php endforeach; ?>
    <?php else: ?>
        <p>Sin procesos registrados todavia.</p>
    <?php endif; ?>
</div>
<script src="/assets/js/sync.js"></script>
