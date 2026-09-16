<?php
/** @var array $lessons */
/** @var array $stats */
?>
<h1>&#127468;&#127463; Mis Lecciones de Ingles</h1>
<p style="text-align:center; color:#666;">
    Completadas: <strong style="color:#2ecc71;"><?php echo $stats['completed']; ?></strong> /
    <?php echo $stats['total']; ?>
</p>
<div style="text-align: center;">
    <a href="/random" class="btn" style="background:#e67e22">&#127922; Lecci&oacute;n Aleatoria</a>
    <a href="/sync" class="btn">&#128260; Sincronizar Nuevos Videos</a>
    <a href="/settings" class="btn" style="background:#8e44ad">&#9881;&#65039; Ajustes</a>
</div>
<div class="grid">
    <?php foreach ($lessons as $lesson): ?>
        <a href="/lessons/<?php echo (int) $lesson['id']; ?>" class="card <?php echo $lesson['completed'] ? 'completed' : ''; ?>">
            <h3><?php echo htmlspecialchars($lesson['title']); ?></h3>
            <p><?php echo $lesson['completed'] ? '&#9989; Completada' : '&#8987; Pendiente'; ?></p>
        </a>
    <?php endforeach; ?>
</div>
