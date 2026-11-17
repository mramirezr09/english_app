<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDO;

/**
 * Data access for the exam question bank and attempts.
 */
final class Exam extends Model
{
    /**
     * @return array<int, int> lesson_id => question count
     */
    public function questionCountsByLesson(): array
    {
        $rows = $this->db->query(
            'SELECT lesson_id, COUNT(*) AS total FROM exam_questions WHERE lesson_id IS NOT NULL GROUP BY lesson_id'
        )->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['lesson_id']] = (int) $row['total'];
        }
        return $counts;
    }

    /**
     * @return array<string, int> level => question count
     */
    public function questionCountsByLevel(): array
    {
        $rows = $this->db->query(
            "SELECT level, COUNT(*) AS total FROM exam_questions WHERE level IS NOT NULL AND level <> '' GROUP BY level ORDER BY level"
        )->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['level']] = (int) $row['total'];
        }
        return $counts;
    }

    public function totalQuestions(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM exam_questions')->fetchColumn();
    }

    public function countForLesson(int $lessonId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM exam_questions WHERE lesson_id = ?');
        $stmt->execute([$lessonId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Replace the AI-generated questions for a lesson.
     *
     * @param array<int, array{type:string, prompt:string, options:array<int,string>, answer:string, alt:array<int,string>, explanation:string, difficulty:int}> $questions
     */
    public function replaceAiQuestions(int $lessonId, ?string $level, array $questions): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare("DELETE FROM exam_questions WHERE lesson_id = ? AND source = 'ai'");
            $delete->execute([$lessonId]);

            $insert = $this->db->prepare(
                'INSERT INTO exam_questions '
                . '(lesson_id, level, type, prompt, options, answer, alt_answers, explanation, difficulty, source, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $now = date('Y-m-d H:i:s');
            foreach ($questions as $question) {
                $insert->execute([
                    $lessonId,
                    $level,
                    $question['type'],
                    $question['prompt'],
                    $question['options'] !== [] ? json_encode($question['options'], JSON_UNESCAPED_UNICODE) : null,
                    $question['answer'],
                    $question['alt'] !== [] ? json_encode($question['alt'], JSON_UNESCAPED_UNICODE) : null,
                    $question['explanation'],
                    $question['difficulty'],
                    'ai',
                    $now,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function countForLevel(string $level): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM exam_questions WHERE level = ?');
        $stmt->execute([$level]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array> question rows
     */
    public function pickForLesson(int $lessonId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM exam_questions WHERE lesson_id = ? ORDER BY RANDOM() LIMIT ?'
        );
        $stmt->bindValue(1, $lessonId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array> question rows
     */
    public function pickForLevel(string $level, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM exam_questions WHERE level = ? ORDER BY RANDOM() LIMIT ?'
        );
        $stmt->bindValue(1, $level);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array> question rows
     */
    public function pickMixed(int $limit): array
    {
        $stmt = $this->db->prepare('SELECT * FROM exam_questions ORDER BY RANDOM() LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findQuestion(int|string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM exam_questions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function saveAttempt(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO exam_attempts (scope, scope_ref, status, total, paper, started_at)
             VALUES (:scope, :scope_ref, :status, :total, :paper, :started_at)'
        );
        $stmt->execute([
            ':scope'      => $data['scope'],
            ':scope_ref'  => $data['scope_ref'],
            ':status'     => $data['status'] ?? 'in_progress',
            ':total'      => $data['total'],
            ':paper'      => $data['paper'],
            ':started_at' => $data['started_at'] ?? date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function finishAttempt(int $id, float $score, bool $passed, string $details): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE exam_attempts
             SET status = 'finished', score = ?, passed = ?, details = ?, finished_at = ?
             WHERE id = ?"
        );
        $stmt->execute([$score, $passed ? 1 : 0, $details, date('Y-m-d H:i:s'), $id]);
        return $stmt->rowCount() > 0;
    }

    public function findAttempt(int|string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM exam_attempts WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, array>
     */
    public function recentAttempts(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM exam_attempts WHERE status = 'finished' ORDER BY finished_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @return array{total:int, passed:int, average:float}
     */
    public function attemptStats(): array
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(passed), 0) AS passed, COALESCE(AVG(score), 0) AS average
             FROM exam_attempts WHERE status = 'finished'"
        )->fetch();

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'passed'  => (int) ($row['passed'] ?? 0),
            'average' => round((float) ($row['average'] ?? 0), 1),
        ];
    }
}
