<?php
$db_path = __DIR__ . '/data/lessons.db';
$video_dir = '/home/mauricio/.openclaw/workspace/ingles/4A/';

try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (is_dir($video_dir)) {
        $files = scandir($video_dir);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'mp4') {
                $stmt = $db->prepare("INSERT OR IGNORE INTO lessons (file_name, title) VALUES (?, ?)");
                $stmt->execute([$file, str_replace('.mp4', '', $file)]);
            }
        }
    }

    if (isset($_GET['action'])) {
        if ($_GET['action'] === 'process_all') {
            shell_exec('python3 /home/mauricio/.openclaw/workspace/english_app/process_lessons.py > /dev/null 2>&1 &');
            echo "<script>alert('🚀 Procesando todos los videos en segundo plano...\\n\\nRecarga la página en unos minutos para ver los resultados.'); window.location.href='sync.php';</script>";
        } elseif ($_GET['action'] === 'process_one' && isset($_GET['file'])) {
            $file = escapeshellarg($_GET['file']);
            shell_exec("python3 /home/mauricio/.openclaw/workspace/english_app/process_lessons.py $file > /dev/null 2>&1 &");
            echo "<script>alert('⏳ Procesando $file...\\n\\nEsto puede tardar un minuto. Recarga la página pronto para ver la transcripción.'); window.location.href='sync.php';</script>";
        }
    }

    $stmt = $db->query("SELECT * FROM lessons ORDER BY file_name ASC");
    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($lessons);
    $processed = 0;
    foreach($lessons as $l) if(!empty($l['transcription'])) $processed++;

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Lecciones</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <h1>⚙️ Panel de Sincronización</h1>
        <div class="content-section" style="text-align: center;">
            <h2>Estado General</h2>
            <p style="font-size: 1.2em;">
                Videos encontrados: <strong><?php echo $total; ?></strong> | 
                Procesados: <strong style="color:green;"><?php echo $processed; ?></strong> | 
                Pendientes: <strong style="color:red;"><?php echo $total - $processed; ?></strong>
            </p>
            <a href="?action=process_all" class="btn" style="background:#2ecc71">🚀 Procesar Todos los Videos</a>
            <a href="index.php" class="btn">Volver al Índice</a>
        </div>
        <div class="content-section">
            <h2>Lista de Archivos</h2>
            <table style="width:100%; border-collapse:collapse; margin-top:20px;">
                <thead>
                    <tr style="background:#eee; text-align:left;">
                        <th style="padding:10px; border:1px solid #ddd;">Archivo</th>
                        <th style="padding:10px; border:1px solid #ddd;">Estado</th>
                        <th style="padding:10px; border:1px solid #ddd;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lessons as $lesson): ?>
                    <tr>
                        <td style="padding:10px; border:1px solid #ddd;"><?php echo htmlspecialchars($lesson['file_name']); ?></td>
                        <td style="padding:10px; border:1px solid #ddd;">
                            <?php echo !empty($lesson['transcription']) ? '✅ Procesado' : '⏳ Pendiente'; ?>
                        </td>
                        <td style="padding:10px; border:1px solid #ddd;">
                            <a href="process_live.php?file=<?php echo urlencode($lesson['file_name']); ?>" target="_blank" class="btn" style="padding:5px 10px; font-size:0.8em; display:inline-block; background:#8e44ad; color:white; text-decoration:none; border-radius:5px;">Procesar en Vivo ⚡</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
