<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/**
 * Generates the Spanish explanation and the 5 exercises for a lesson.
 * Port of backend/workers/enrich_lesson.py.
 */
final class Enricher
{
    private const EXERCISES_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'exercises' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'answer' => ['type' => 'string'],
                    ],
                    'required' => ['question', 'answer'],
                ],
            ],
        ],
        'required' => ['exercises'],
    ];

    public function enrich(int $lessonId, bool $force, callable $log): bool
    {
        $lesson = (new Lesson())->find($lessonId);
        if ($lesson === null) {
            $log('Error: no existe la leccion ' . $lessonId . '.');
            return false;
        }

        if (!$force && !empty($lesson['explanation']) && !empty($lesson['exercises'])) {
            $log('La leccion ' . $lessonId . ' ya esta enriquecida. Usa --force para regenerar.');
            return true;
        }

        $transcription = trim((string) ($lesson['transcription'] ?? ''));
        if ($transcription === '') {
            $log('Error: la leccion ' . $lessonId . ' no tiene transcripcion.');
            return false;
        }

        $log('Enriqueciendo leccion ' . $lessonId . '...');
        $explanation = $this->generateExplanation($transcription, $log);
        $exercises = $this->generateExercises($transcription, $log);

        if ($explanation === null && $exercises === []) {
            $log('Error: la IA no devolvio ni explicacion ni ejercicios.');
            return false;
        }

        (new Lesson())->saveEnrichment(
            $lessonId,
            $explanation,
            $exercises !== [] ? $exercises : null
        );

        $log('Leccion ' . $lessonId . ' enriquecida: '
            . ($explanation !== null ? 'explicacion OK' : 'sin explicacion')
            . ', ' . count($exercises) . ' ejercicios.');

        return true;
    }

    private function generateExplanation(string $transcription, callable $log): ?string
    {
        $prompt = 'Eres un profesor de ingles experto que ensena a hispanohablantes. '
            . 'A partir de la transcripcion de la clase, redacta una explicacion '
            . 'pedagogica clara Y EN ESPANOL sobre la regla gramatical o el vocabulario '
            . 'del video. Usa ejemplos tomados de la transcripcion y separa el contenido '
            . 'en secciones cortas. No inventes contenido que no aparezca en la clase. '
            . 'Formatea la respuesta en Markdown simple: encabezados con "###", negritas '
            . 'con "**texto**" y listas con "- ". No uses LaTeX ni el simbolo "$"; para '
            . 'flechas escribe el caracter "->".'
            . "\n\nTRANSCRIPCION:\n" . $transcription;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $reply = OpenCode::chat($prompt);
            if ($reply !== null && trim($reply) !== '') {
                return trim($reply);
            }
            $log('Intento ' . $attempt . ': explicacion vacia ('
                . (OpenCode::lastError() ?? 'sin detalle') . '), reintentando...');
        }

        $log('Aviso: no se pudo generar la explicacion.');
        return null;
    }

    /**
     * @return array<int, array{question:string, answer:string}>
     */
    private function generateExercises(string $transcription, callable $log): array
    {
        $prompt = 'Eres un profesor de ingles experto. A partir de la transcripcion, crea '
            . 'exactamente 5 ejercicios para practicar la regla de la clase. '
            . 'Devuelve un objeto JSON con la clave "exercises", cuyo valor sea un arreglo '
            . 'de 5 objetos. Cada objeto debe tener las claves "question" (en espanol) y '
            . '"answer" (en ingles). Usa comillas dobles.'
            . "\n\n" . AiJson::schemaPrompt(self::EXERCISES_SCHEMA)
            . "\n\nTRANSCRIPCION:\n" . $transcription;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $raw = OpenCode::chat($prompt, ['json_mode' => true]);
            $decoded = AiJson::decode((string) $raw);
            $exercises = $this->normalize(AiJson::toList($decoded, ['exercises']));

            if (count($exercises) >= 3) {
                return $exercises;
            }

            $log('Intento ' . $attempt . ': solo ' . count($exercises) . ' ejercicios validos ('
                . (OpenCode::lastError() ?? 'respuesta invalida') . '), reintentando...');
        }

        $log('Aviso: no se pudieron generar ejercicios validos.');
        return [];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array{question:string, answer:string}>
     */
    private function normalize(array $items): array
    {
        $exercises = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = $item['question'] ?? $item['pregunta'] ?? null;
            $answer = $item['answer'] ?? $item['respuesta'] ?? null;
            if ($question !== null && $answer !== null && trim((string) $question) !== '') {
                $exercises[] = [
                    'question' => trim((string) $question),
                    'answer'   => trim((string) $answer),
                ];
            }
        }
        return $exercises;
    }
}
