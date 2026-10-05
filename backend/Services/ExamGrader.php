<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Grades a single exam answer server-side. Never trusts the client, and never
 * exposes the expected answer before scoring.
 */
final class ExamGrader
{
    /**
     * @param array $question  Full question row (with answer/alt_answers/explanation).
     * @param array $paperItem Paper entry built by ExamBuilder (qid/type/points/options).
     * @param mixed $response  Raw answer submitted by the client.
     * @return array{awarded:float, max:float, correct:bool, graded:bool, expected:string, response:string, feedback:string, type:string}
     */
    public function grade(array $question, array $paperItem, mixed $response): array
    {
        $type = (string) $question['type'];
        $max = (float) ($paperItem['points'] ?? 1);
        $expected = (string) $question['answer'];
        $feedback = (string) ($question['explanation'] ?? '');

        if ($type === 'open') {
            return $this->gradeOpen($question, $paperItem, is_scalar($response) ? (string) $response : '');
        }

        $given = is_scalar($response) ? (string) $response : '';

        if ($type === 'mcq') {
            $options = is_array($paperItem['options'] ?? null) ? $paperItem['options'] : [];
            $chosen = is_numeric($given) ? (string) ($options[(int) $given] ?? '') : $given;
            $correct = $this->matches($chosen, [$expected]);
            $given = $chosen;
        } elseif ($type === 'truefalse') {
            $correct = $this->boolValue($given) !== null
                && $this->boolValue($given) === $this->boolValue($expected);
        } else {
            $accepted = array_merge([$expected], $this->decodeList($question['alt_answers'] ?? null));
            $correct = $given !== '' && $this->matches($given, $accepted);
        }

        return [
            'awarded'  => $correct ? $max : 0.0,
            'max'      => $max,
            'correct'  => $correct,
            'graded'   => true,
            'expected' => $expected,
            'response' => $given,
            'feedback' => $feedback,
            'type'     => $type,
        ];
    }

    private function gradeOpen(array $question, array $paperItem, string $response): array
    {
        $max = (float) ($paperItem['points'] ?? 2);
        $expected = (string) $question['answer'];

        if (trim($response) === '') {
            return [
                'awarded' => 0.0, 'max' => $max, 'correct' => false, 'graded' => true,
                'expected' => $expected, 'response' => '', 'feedback' => 'Sin respuesta.', 'type' => 'open',
            ];
        }

        $prompt = "Eres un profesor de ingles evaluando la respuesta escrita de un estudiante.\n\n"
            . 'PREGUNTA: ' . $question['prompt'] . "\n"
            . 'RESPUESTA ESPERADA (referencia): ' . $expected . "\n"
            . 'RESPUESTA DEL ESTUDIANTE: ' . $response . "\n\n"
            . 'Evalua de 0 a 100 considerando correccion gramatical, vocabulario y adecuacion. '
            . 'Devuelve SOLO JSON valido con las claves "score" (numero 0-100) y "feedback" (breve, en espanol).';

        $data = $this->extractJson(AiService::ask($prompt));

        if ($data === null || !isset($data['score']) || !is_numeric($data['score'])) {
            return [
                'awarded' => 0.0, 'max' => $max, 'correct' => false, 'graded' => false,
                'expected' => $expected, 'response' => $response,
                'feedback' => 'No se pudo corregir automaticamente (IA no disponible).', 'type' => 'open',
            ];
        }

        $score = max(0.0, min(100.0, (float) $data['score']));
        return [
            'awarded'  => round($max * $score / 100, 2),
            'max'      => $max,
            'correct'  => $score >= 60,
            'graded'   => true,
            'expected' => $expected,
            'response' => $response,
            'feedback' => (string) ($data['feedback'] ?? ''),
            'type'     => 'open',
        ];
    }

    /**
     * @param array<int, string> $accepted
     */
    private function matches(string $given, array $accepted): bool
    {
        $normalized = $this->normalize($given);
        if ($normalized === '') {
            return false;
        }
        foreach ($accepted as $candidate) {
            if ($this->normalize((string) $candidate) === $normalized) {
                return true;
            }
        }
        return false;
    }

    private function boolValue(string $value): ?bool
    {
        $value = $this->normalize($value);
        if (in_array($value, ['true', 't', 'verdadero', 'si', 'yes', 'v'], true)) {
            return true;
        }
        if (in_array($value, ['false', 'f', 'falso', 'no', 'n'], true)) {
            return false;
        }
        return null;
    }

    private function normalize(string $text): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $stripped = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        if ($stripped !== null) {
            $text = $stripped;
        }
        $collapsed = preg_replace('/\s+/', ' ', $text);
        if ($collapsed !== null) {
            $text = $collapsed;
        }
        return trim($text);
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

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $cleaned = trim(str_replace(['```json', '```'], '', $text));
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $data = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
