<?php
/** @var string $content */
/** @var string|null $title */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($title ?? 'Repaso de Ingles'); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <div class="container">
        <?php echo $content; ?>
    </div>
</body>
</html>
