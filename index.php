<?php
$db_path = __DIR__ . '/data/lessons.db';
$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$lessons = $db->query("SELECT * FROM lessons ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Repaso de Inglés</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <h1>🇬🇧 Mis Lecciones de Inglés</h1>
        <div style="text-align: center;">
            <a href="sync.php" class="btn">🔄 Sincronizar Nuevos Videos</a>
        </div>
        <div class="grid">
            <?php foreach ($lessons as $lesson): ?>
                <a href="lesson.php?id=<?php echo $lesson['id']; ?>" class="card <?php echo $lesson['completed'] ? 'completed' : ''; ?>">
                    <h3><?php echo htmlspecialchars($lesson['title']); ?></h3>
                    <p><?php echo $lesson['completed'] ? '✅ Completada' : '⏳ Pendiente'; ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>