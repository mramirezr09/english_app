<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/bootstrap.php';

use App\Core\Model;

$level = trim((string) ($argv[1] ?? ''));
if ($level === '') {
    fwrite(STDERR, "Uso: php bin/migrate_levels.php <nivel>  (ej: 4A)\n");
    exit(1);
}
$level = trim(str_replace('\\', '/', $level), '/');

try {
    $db = Model::connection();

    $rows = $db->query("SELECT * FROM lessons WHERE file_name NOT LIKE '%/%' ORDER BY id")->fetchAll();
    if (!$rows) {
        echo "No hay filas sin nivel que migrar." . PHP_EOL;
        exit(0);
    }

    $find = $db->prepare('SELECT * FROM lessons WHERE file_name = ?');
    $updateMerge = $db->prepare(
        'UPDATE lessons SET title = ?, transcription = ?, explanation = ?, exercises = ?, completed = ? WHERE id = ?'
    );
    $delete = $db->prepare('DELETE FROM lessons WHERE id = ?');
    $rename = $db->prepare('UPDATE lessons SET file_name = ? WHERE id = ?');

    $renamed = 0;
    $merged = 0;

    foreach ($rows as $row) {
        $target = $level . '/' . $row['file_name'];
        $find->execute([$target]);
        $existing = $find->fetch();

        if ($existing) {
            // A scan already created the leveled row: merge data into it, drop the flat one.
            $updateMerge->execute([
                $row['title'] ?: $existing['title'],
                $row['transcription'] ?: $existing['transcription'],
                $row['explanation'] ?: $existing['explanation'],
                $row['exercises'] ?: $existing['exercises'],
                $row['completed'] ?: $existing['completed'],
                $existing['id'],
            ]);
            $delete->execute([$row['id']]);
            $merged++;
        } else {
            $rename->execute([$target, $row['id']]);
            $renamed++;
        }
    }

    echo "Renombradas: {$renamed} | Fusionadas: {$merged}" . PHP_EOL;
    foreach ($db->query('SELECT id, file_name FROM lessons ORDER BY file_name') as $r) {
        echo "  #{$r['id']} {$r['file_name']}" . PHP_EOL;
    }
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
