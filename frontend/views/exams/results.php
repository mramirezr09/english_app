<?php
/** @var array $attempt */
/** @var array $details */
/** @var int $passingScore */

$score = (float) $attempt['score'];
$passed = !empty($attempt['passed']);

$retryUrl = '/exams/new?scope=' . urlencode((string) $attempt['scope']);
if ($attempt['scope'] !== 'mixed' && $attempt['scope_ref'] !== null && $attempt['scope_ref'] !== '') {
    $retryUrl .= '&ref=' . urlencode((string) $attempt['scope_ref']);
}
?>
<a href="/exams" style="text-decoration: none; color: #3498db;">&larr; Volver a examenes</a>
<h1>Resultado del examen</h1>

<div class="content-section" style="text-align:center;">
    <p class="exam-score <?php echo $passed ? 'success' : 'error'; ?>"><?php echo htmlspecialchars((string) $score); ?>%</p>
    <p>
        <?php if ($passed): ?>
            &#9989; Aprobado (minimo <?php echo (int) $passingScore; ?>%)
        <?php else: ?>
            &#10060; No aprobado (minimo <?php echo (int) $passingScore; ?>%)
        <?php endif; ?>
    </p>
    <div class="actions">
        <a href="<?php echo htmlspecialchars($retryUrl); ?>" class="btn" style="background:#e67e22">&#128260; Reintentar</a>
        <a href="/exams" class="btn">Volver a examenes</a>
    </div>
</div>

<div class="content-section">
    <h2>Detalle</h2>
    <?php foreach ($details as $index => $detail): ?>
        <div class="exam-question <?php echo !empty($detail['correct']) ? 'ok' : 'ko'; ?>">
            <p class="exam-prompt">
                <strong><?php echo ($index + 1) . '. '; ?></strong><?php echo htmlspecialchars((string) $detail['prompt']); ?>
            </p>
            <p>
                Tu respuesta:
                <em><?php echo htmlspecialchars((string) $detail['response']); ?></em>
                <?php echo empty($detail['graded']) ? ' (no corregida automaticamente)' : ''; ?>
            </p>
            <?php if (empty($detail['correct']) && !empty($detail['expected'])): ?>
                <p>Respuesta esperada: <strong><?php echo htmlspecialchars((string) $detail['expected']); ?></strong></p>
            <?php endif; ?>
            <?php if (!empty($detail['feedback'])): ?>
                <p class="exam-feedback"><?php echo htmlspecialchars((string) $detail['feedback']); ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
