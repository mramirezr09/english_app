<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;

/**
 * Builds a randomized exam paper and stores the matching attempt.
 *
 * Only safe data (no answer/explanation) is returned to the view; the full
 * paper is persisted server-side so grading never trusts the client.
 */
final class ExamBuilder
{
    /**
     * @return array{attempt_id:int, total:int, questions:array<int, array>}|null
     */
    public function build(string $scope, ?string $ref, int $limit): ?array
    {
        $exam = new Exam();
        $rows = match ($scope) {
            'lesson' => $exam->pickForLesson((int) $ref, $limit),
            'level'  => $exam->pickForLevel((string) $ref, $limit),
            default  => $exam->pickMixed($limit),
        };

        if (!$rows) {
            return null;
        }

        $paper = [];
        $questions = [];
        $total = 0;

        foreach ($rows as $row) {
            $item = $this->paperItem($row);
            $paper[] = $item['paper'];
            $questions[] = $item['view'];
            $total += $item['paper']['points'];
        }

        $attemptId = $exam->saveAttempt([
            'scope'     => $scope,
            'scope_ref' => $ref,
            'total'     => $total,
            'paper'     => (string) json_encode($paper, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'attempt_id' => $attemptId,
            'total'      => $total,
            'questions'  => $questions,
        ];
    }

    /**
     * @return array{paper:array, view:array}
     */
    private function paperItem(array $row): array
    {
        $type = (string) $row['type'];
        $options = $this->decodeList($row['options'] ?? null);
        $points = $type === 'open' ? 2 : 1;
        $viewOptions = [];

        if ($type === 'mcq') {
            $viewOptions = $options;
            shuffle($viewOptions);
        } elseif ($type === 'order') {
            $words = $options ?: preg_split('/\s+/', (string) $row['answer']) ?: [];
            $viewOptions = $this->scramble($words);
        }

        return [
            'paper' => [
                'qid'     => (int) $row['id'],
                'type'    => $type,
                'points'  => $points,
                'options' => $viewOptions,
            ],
            'view' => [
                'qid'     => (int) $row['id'],
                'type'    => $type,
                'prompt'  => (string) $row['prompt'],
                'options' => $viewOptions,
                'points'  => $points,
            ],
        ];
    }

    /**
     * @param array<int, string> $words
     * @return array<int, string>
     */
    private function scramble(array $words): array
    {
        if (count($words) < 2) {
            return $words;
        }
        $shuffled = $words;
        for ($i = 0; $i < 6; $i++) {
            shuffle($shuffled);
            if ($shuffled !== $words) {
                break;
            }
        }
        return $shuffled;
    }

    /**
     * @return array<int, string>
     */
    private function decodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_map(static fn ($value): string => (string) $value, $data));
    }
}
