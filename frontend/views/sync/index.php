<?php
/** @var array $lessons */
/** @var array $stats */
/** @var array $config */
/** @var string[] $whisperModels */
/** @var string|null $status */
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
    <p style="margin-bottom:20px;">
        <label>Modelo Whisper:
            <select id="whisper">
                <?php foreach ($whisperModels as $w): ?>
                    <option value="<?php echo $w; ?>" <?php echo $w === $config['whisper']['model'] ? 'selected' : ''; ?>><?php echo $w; ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <div style="display:flex; gap:10px; justify-content:center;">
        <button class="btn" style="background:#2ecc71" onclick="go('process')">Procesar Videos Pendientes</button>
        <a href="/sync" class="btn">Buscar Videos Nuevos</a>
        <a href="/" class="btn">Volver al Indice</a>
        <a href="/settings" class="btn" style="background:#8e44ad">Ajustes</a>
    </div>
</div>
<div class="content-section">
    <h2 style="margin-bottom:20px;">Lista de Archivos</h2>
    <table style="width:100%; border-collapse:collapse; margin-top:20px;">
        <thead>
            <tr style="background:#eee; text-align:left;">
                <th style="padding:10px; border:1px solid #ddd;">Archivo</th>
                <th style="padding:10px; border:1px solid #ddd;">Estado</th>
                <th style="padding:10px; border:1px solid #ddd;">Procesar</th>
                <th style="padding:10px; border:1px solid #ddd;">Gestion</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lessons as $lesson): ?>
            <tr>
                <td style="padding:10px; border:1px solid #ddd;"><?php echo htmlspecialchars($lesson['file_name']); ?></td>
                <td style="padding:10px; border:1px solid #ddd;">
                    <?php echo !empty($lesson['transcription']) ? '&#9989; Transcrito' : '&#8987; Pendiente'; ?>
                    <?php echo !empty($lesson['explanation']) ? ' / &#129302; IA' : ''; ?>
                </td>
                <td style="padding:10px; border:1px solid #ddd;">
                    <button class="btn" style="padding:5px 10px; font-size:0.8em; background:#8e44ad;" onclick="live(<?php echo htmlspecialchars(json_encode($lesson['file_name']), ENT_QUOTES); ?>)">Procesar en Vivo</button>
                </td>
                <td style="padding:10px; border:1px solid #ddd;">
                    <a href="/sync/re-enrich/<?php echo (int) $lesson['id']; ?>" class="btn" style="padding:5px 10px; font-size:0.8em; background:#2980b9;" onclick="return confirm('Regenerar explicacion y ejercicios con IA?')">Regenerar IA</a>
                    <a href="/lessons/<?php echo (int) $lesson['id']; ?>/reset" class="btn" style="padding:5px 10px; font-size:0.8em; background:#e74c3c;" onclick="return confirm('Resetear esta leccion? Se borraran transcripcion, explicacion y ejercicios.')">Resetear</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="/assets/js/sync.js"></script>
