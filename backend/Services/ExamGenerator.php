<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Exam;
use App\Models\Lesson;

/**
 * Generates the exam question bank for a lesson.
 * Port of backend/workers/generate_exam.py.
 */
final class ExamGenerator
{
    private const ALLOWED_TYPES = ['mcq', 'truefalse', 'cloze', 'order', 'translation', 'open'];

    private const QUESTIONS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'questions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => self::ALLOWED_TYPES],
                        'prompt' => ['type' => 'string'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'answer' => ['type' => 'string'],
                        'alt_answers' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'explanation' => ['type' => 'string'],
                        'difficulty' => ['type' => 'integer'],
                    ],
                    'required' => ['type', 'prompt', 'answer'],
                ],
            ],
        ],
        'required' => ['questions'],
    ];

    public function generateForLesson(int|string $lessonId, int $count, bool $force, callable $log): bool
    {
        $lessonId = (int) $lessonId;
        $lesson = (new Lesson())->find($lessonId);
        if ($lesson === null || trim((string) ($lesson['transcription'] ?? '')) === '') {
            $log('La leccion ' . $lessonId . ' no tiene transcripcion todavia.');
            return false;
        }

        $title = trim((string) ($lesson['title'] ?? '')) ?: ('Lesson ' . $lessonId);
        $level = $this->lessonLevel((string) ($lesson['file_name'] ?? ''));
        $transcription = (string) $lesson['transcription'];

        $exam = new Exam();
        if (!$force && $exam->countForLesson($lessonId) > 0) {
            $log('La leccion ' . $lessonId . ' ya tiene preguntas. Usa --force para regenerar.');
            return true;
        }

        $log('Generando ' . $count . ' preguntas para la leccion ' . $lessonId . ' (' . $title . ')...');
        $prompt = $this->buildPrompt($title, $transcription, $count);

        $questions = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = OpenCode::chat($prompt, ['json_mode' => true]);
            $items = $this->extractQuestions((string) $raw);
            $questions = [];
            foreach ($items as $item) {
                $normalized = $this->normalizeQuestion($item);
                if ($normalized !== null) {
                    $questions[] = $normalized;
                }
            }

            if (count($questions) >= max(3, intdiv($count, 2))) {
                break;
            }

            $log('Intento ' . $attempt . ': solo ' . count($questions) . ' preguntas validas ('
                . (OpenCode::lastError() ?? 'respuesta invalida') . '), reintentando...');
        }

        if ($questions === []) {
            $log('Error: la IA no devolvio preguntas validas para la leccion ' . $lessonId . '.');
            return false;
        }

        $exam->replaceAiQuestions($lessonId, $level, $questions);
        $log('Leccion ' . $lessonId . ': ' . count($questions) . ' preguntas guardadas (nivel: ' . ($level ?? 'sin nivel') . ').');

        return true;
    }

    public function generateAll(int $count, bool $force, callable $log): int
    {
        $lessons = (new Lesson())->withTranscription();
        $ok = 0;
        foreach ($lessons as $lesson) {
            if ($this->generateForLesson((int) $lesson['id'], $count, $force, $log)) {
                $ok++;
            }
        }
        return $ok;
    }

    private function buildPrompt(string $title, string $transcription, int $count): string
    {
        $prompt = 'Eres un profesor de ingles experto que evalua a hispanohablantes. '
            . 'A partir de la transcripcion de la clase, crea exactamente ' . $count . ' preguntas '
            . 'variadas para evaluar la regla gramatical y el vocabulario del video.' . "\n\n"
            . "Distribucion sugerida de tipos:\n"
            . "- \"mcq\": opcion multiple con 4 opciones en \"options\"; \"answer\" debe ser la opcion correcta.\n"
            . "- \"truefalse\": verdadero/falso; \"answer\" debe ser \"true\" o \"false\".\n"
            . "- \"cloze\": completar una oracion; \"prompt\" incluye un espacio en blanco identificado con \"____\".\n"
            . "- \"order\": ordenar palabras; incluye las palabras en \"options\" y la oracion correcta en \"answer\".\n"
            . "- \"translation\": traducir una frase; \"prompt\" en espanol y \"answer\" en ingles.\n"
            . "- \"open\": pregunta abierta de produccion escrita; \"answer\" es una respuesta de referencia.\n\n"
            . 'Reglas: una sola respuesta correcta, sin pistas en las opciones, sin preguntas trampa. '
            . 'Agrega "explanation" breve en espanol que justifique la respuesta. '
            . 'Usa "alt_answers" para variantes validas cuando aplique. '
            . 'Devuelve un objeto JSON con la clave "questions".'
            . "\n\n" . AiJson::schemaPrompt(self::QUESTIONS_SCHEMA)
            . "\n\nTITULO: " . $title
            . "\n\nTRANSCRIPCION:\n" . $transcription;

        return $prompt;
    }

    /**
     * @return array<int, mixed>
     */
    private function extractQuestions(string $raw): array
    {
        $decoded = AiJson::decode($raw);
        return AiJson::toList($decoded, ['questions', 'preguntas']);
    }

    /**
     * @return array{type:string, prompt:string, options:array<int,string>, answer:string, alt:array<int,string>, explanation:string, difficulty:int}|null
     */
    private function normalizeQuestion(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $type = strtolower(trim((string) ($item['type'] ?? '')));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return null;
        }

        $prompt = trim((string) ($item['prompt'] ?? ''));
        $answer = $item['answer'] ?? null;
        if ($prompt === '' || $answer === null) {
            return null;
        }
        $answer = trim((string) $answer);
        if ($answer === '') {
            return null;
        }

        $rawOptions = is_array($item['options'] ?? null) ? $item['options'] : [];
        $options = [];
        foreach ($rawOptions as $option) {
            $option = trim((string) $option);
            if ($option !== '') {
                $options[] = $option;
            }
        }

        $rawAlt = is_array($item['alt_answers'] ?? null) ? $item['alt_answers'] : [];
        $alt = [];
        foreach ($rawAlt as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                $alt[] = $candidate;
            }
        }

        $explanation = trim((string) ($item['explanation'] ?? ''));
        $difficulty = (int) ($item['difficulty'] ?? 1);
        $difficulty = max(1, min(3, $difficulty));

        if ($type === 'mcq') {
            if (count($options) < 2) {
                return null;
            }
            $matches = false;
            foreach ($options as $option) {
                if (strcasecmp($answer, $option) === 0) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return null;
            }
        } elseif ($type === 'truefalse') {
            $low = strtolower($answer);
            if (in_array($low, ['true', 't', 'verdadero', 'si', 'yes'], true)) {
                $answer = 'true';
            } elseif (in_array($low, ['false', 'f', 'falso', 'no'], true)) {
                $answer = 'false';
            } else {
                return null;
            }
            $options = [];
        } elseif ($type === 'order') {
            if ($options === []) {
                $options = preg_split('/\s+/', $answer) ?: [];
            }
            if (count($options) < 2) {
                return null;
            }
        } else {
            $options = [];
        }

        return [
            'type'        => $type,
            'prompt'      => $prompt,
            'options'     => $options,
            'answer'      => $answer,
            'alt'         => $alt,
            'explanation' => $explanation,
            'difficulty'  => $difficulty,
        ];
    }

    private function lessonLevel(string $fileName): ?string
    {
        $level = trim(str_replace('\\', '/', dirname($fileName)), '/');
        return $level !== '' && $level !== '.' ? $level : null;
    }
}
