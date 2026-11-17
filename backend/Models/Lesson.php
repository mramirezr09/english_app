<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * Data access for the `lessons` table.
 */
final class Lesson extends Model
{
    public function all(): array
    {
        return $this->db->query('SELECT * FROM lessons ORDER BY id ASC')->fetchAll();
    }

    public function allByFileName(): array
    {
        return $this->db->query('SELECT * FROM lessons ORDER BY file_name ASC')->fetchAll();
    }

    public function find(int|string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM lessons WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByFileName(string $fileName): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM lessons WHERE file_name = ?');
        $stmt->execute([$fileName]);
        return $stmt->fetch() ?: null;
    }

    public function saveTranscription(string $fileName, string $text): void
    {
        $stmt = $this->db->prepare('UPDATE lessons SET transcription = ? WHERE file_name = ?');
        $stmt->execute([$text, $fileName]);
    }

    public function saveEnrichment(int|string $id, ?string $explanation, ?array $exercises): void
    {
        if ($explanation !== null && $exercises !== null) {
            $stmt = $this->db->prepare('UPDATE lessons SET explanation = ?, exercises = ? WHERE id = ?');
            $stmt->execute([$explanation, json_encode($exercises, JSON_UNESCAPED_UNICODE), $id]);
        } elseif ($explanation !== null) {
            $stmt = $this->db->prepare('UPDATE lessons SET explanation = ? WHERE id = ?');
            $stmt->execute([$explanation, $id]);
        } elseif ($exercises !== null) {
            $stmt = $this->db->prepare('UPDATE lessons SET exercises = ? WHERE id = ?');
            $stmt->execute([json_encode($exercises, JSON_UNESCAPED_UNICODE), $id]);
        }
    }

    public function withTranscription(): array
    {
        return $this->db->query(
            "SELECT * FROM lessons WHERE transcription IS NOT NULL AND transcription <> '' ORDER BY id ASC"
        )->fetchAll();
    }

    public function random(): ?array
    {
        $row = $this->db->query('SELECT * FROM lessons ORDER BY RANDOM() LIMIT 1')->fetch();
        return $row ?: null;
    }

    public function insertIfMissing(string $fileName, string $title): void
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO lessons (file_name, title) VALUES (?, ?)');
        $stmt->execute([$fileName, $title]);
    }

    public function markComplete(int|string $id): bool
    {
        $stmt = $this->db->prepare('UPDATE lessons SET completed = 1 WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function reset(int|string $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE lessons SET transcription = NULL, explanation = NULL, exercises = NULL, completed = 0 WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Aggregate counters used by the index and sync dashboards.
     *
     * @return array{total:int, processed:int, enriched:int, completed:int, pending:int}
     */
    public function stats(): array
    {
        $lessons = $this->all();
        $processed = 0;
        $enriched = 0;
        $completed = 0;
        foreach ($lessons as $lesson) {
            if (!empty($lesson['transcription'])) {
                $processed++;
            }
            if (!empty($lesson['explanation'])) {
                $enriched++;
            }
            if (!empty($lesson['completed'])) {
                $completed++;
            }
        }

        return [
            'total'     => count($lessons),
            'processed' => $processed,
            'enriched'  => $enriched,
            'completed' => $completed,
            'pending'   => count($lessons) - $processed,
        ];
    }

    public function exercises(array $lesson): array
    {
        if (empty($lesson['exercises'])) {
            return [];
        }
        $decoded = json_decode((string) $lesson['exercises'], true);
        return is_array($decoded) ? $decoded : [];
    }
}
