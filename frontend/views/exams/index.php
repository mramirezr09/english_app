<?php
/** @var array $lessons */
/** @var array $levels */
/** @var int $totalQuestions */
/** @var array $attempts */
/** @var array $attemptStats */
/** @var int $passingScore */
/** @var string $status */

$scopeLabel = static function (array $attempt): string {
    return match ((string) $attempt['scope']) {
        'lesson' => 'Leccion #' . (string) $attempt['scope_ref'],
        'level'  => 'Nivel ' . (string) $attempt['scope_ref'],
        default  => 'Mixto',
    };
};
?>
<a href="/" style="text-decoration: none; color: #3498db;">&larr; Volver al indice</a>
<h1>&#128221; Examenes</h1>

<?php if ($status): ?><p class="success" style="text-align:center;"><?php echo htmlspecialchars($status); ?></p><?php endif; ?>

<div class="content-section" style="text-align:center;">
    <h2>Resumen</h2>
    <p style="font-size:1.1em;">
        Preguntas: <strong><?php echo (int) $totalQuestions; ?></strong> |
        Intentos: <strong><?php echo (int) $attemptStats['total']; ?></strong> |
        Aprobados: <strong style="color:#2ecc71;"><?php echo (int) $attemptStats['passed']; ?></strong> |
        Promedio: <strong><?php echo htmlspecialchars((string) $attemptStats['average']); ?>%</strong> |
        Umbral: <strong><?php echo (int) $passingScore; ?>%</strong>
    </p>
    <div class="actions">
        <a href="/exams/new?scope=mixed" class="btn" style="background:#e67e22">&#127922; Examen mixto</a>
        <a href="/exams/generate?scope=all" class="btn" style="background:#16a085" onclick="return confirm('Generar preguntas con IA para todas las lecciones con transcripcion?')">&#129302; Generar preguntas (todas)</a>
    </div>
</div>

<div class="content-section">
    <h2>Por nivel</h2>
    <?php if ($levels): ?>
        <div class="actions">
            <?php foreach ($levels as $level => $count): ?>
                <a href="/exams/new?scope=level&amp;ref=<?php echo urlencode((string) $level); ?>" class="btn">
                    Nivel <?php echo htmlspecialchars((string) $level); ?> (<?php echo (int) $count; ?>)
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>Aun no hay preguntas generadas. Usa los botones de abajo o "Generar preguntas".</p>
    <?php endif; ?>
</div>

<div class="content-section">
    <h2>Por leccion</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Leccion</th><th>Preguntas</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($lessons as $lesson): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $lesson['title']); ?></td>
                        <td><?php echo (int) $lesson['question_count']; ?></td>
                        <td>
                            <?php if ((int) $lesson['question_count'] > 0): ?>
                                <a href="/exams/new?scope=lesson&amp;ref=<?php echo (int) $lesson['id']; ?>" class="btn" style="padding:5px 10px; font-size:0.8em; background:#2980b9;">Rendir</a>
                                <a href="/exams/generate?scope=lesson&amp;ref=<?php echo (int) $lesson['id']; ?>&amp;force=1" class="btn" style="padding:5px 10px; font-size:0.8em; background:#8e44ad;" onclick="return confirm('Regenerar las preguntas de esta leccion?')">Regenerar</a>
                            <?php else: ?>
                                <a href="/exams/generate?scope=lesson&amp;ref=<?php echo (int) $lesson['id']; ?>" class="btn" style="padding:5px 10px; font-size:0.8em; background:#16a085;" onclick="return confirm('Generar preguntas con IA para esta leccion?')">Generar</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="content-section">
    <h2>Ultimos intentos</h2>
    <?php if ($attempts): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>#</th><th>Alcance</th><th>Puntaje</th><th>Estado</th><th>Fecha</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                        <tr>
                            <td><?php echo (int) $attempt['id']; ?></td>
                            <td><?php echo htmlspecialchars($scopeLabel($attempt)); ?></td>
                            <td><?php echo htmlspecialchars((string) $attempt['score']); ?>%</td>
                            <td><?php echo !empty($attempt['passed']) ? '&#9989; Aprobado' : '&#10060; Reprobado'; ?></td>
                            <td><?php echo htmlspecialchars((string) $attempt['finished_at']); ?></td>
                            <td><a href="/exams/results/<?php echo (int) $attempt['id']; ?>" class="btn" style="padding:5px 10px; font-size:0.8em;">Ver</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>Todavia no rendiste ningun examen.</p>
    <?php endif; ?>
</div>
