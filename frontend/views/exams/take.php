<?php
/** @var string $scope */
/** @var string|null $ref */
/** @var array $paper */
/** @var string $heading */

$attemptId = (int) $paper['attempt_id'];
$total = (int) $paper['total'];
$questions = $paper['questions'];
?>
<a href="/exams" style="text-decoration: none; color: #3498db;">&larr; Volver a examenes</a>
<h1><?php echo htmlspecialchars($heading); ?></h1>
<p style="text-align:center; color:#666;">
    <?php echo count($questions); ?> preguntas &middot; <?php echo $total; ?> puntos
</p>

<form id="exam-form" data-attempt-id="<?php echo $attemptId; ?>" class="content-section">
    <?php foreach ($questions as $index => $q): ?>
        <?php
        $qid = (int) $q['qid'];
        $type = (string) $q['type'];
        ?>
        <div class="exam-question" data-qid="<?php echo $qid; ?>">
            <p class="exam-prompt">
                <strong><?php echo ($index + 1) . '. '; ?></strong><?php echo htmlspecialchars((string) $q['prompt']); ?>
            </p>

            <?php if ($type === 'mcq'): ?>
                <div class="exam-options">
                    <?php foreach ($q['options'] as $optionIndex => $option): ?>
                        <label class="exam-option">
                            <input type="radio" name="q_<?php echo $qid; ?>" value="<?php echo (int) $optionIndex; ?>">
                            <span><?php echo htmlspecialchars((string) $option); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($type === 'truefalse'): ?>
                <div class="exam-options">
                    <label class="exam-option"><input type="radio" name="q_<?php echo $qid; ?>" value="true"> <span>Verdadero</span></label>
                    <label class="exam-option"><input type="radio" name="q_<?php echo $qid; ?>" value="false"> <span>Falso</span></label>
                </div>
            <?php elseif ($type === 'order'): ?>
                <p class="exam-hint">Palabras: <?php echo htmlspecialchars(implode(' &middot; ', array_map('strval', $q['options']))); ?></p>
                <input type="text" class="form-control" name="q_<?php echo $qid; ?>" placeholder="Escribe la oracion ordenada...">
            <?php elseif ($type === 'open'): ?>
                <textarea class="form-control" name="q_<?php echo $qid; ?>" rows="3" placeholder="Escribe tu respuesta..."></textarea>
            <?php else: ?>
                <input type="text" class="form-control" name="q_<?php echo $qid; ?>" placeholder="Tu respuesta...">
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="actions">
        <button type="submit" class="btn" style="background:#2ecc71" id="exam-submit">Corregir examen</button>
        <a href="/exams" class="btn">Cancelar</a>
    </div>
</form>
<p id="exam-status" class="success" style="text-align:center;"></p>
<script src="/assets/js/exam.js"></script>
