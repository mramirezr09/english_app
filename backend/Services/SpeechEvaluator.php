<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/**
 * Word-level alignment between the expected answer and Whisper's transcription
 * of the student's voice, plus the AI prompts that turn that comparison into
 * Spanish feedback (and the next conversation question).
 *
 * Whisper only reports what was *heard*; deciding whether that is correct is a
 * text-alignment (expected vs. heard) plus an AI interpretation task.
 */
final class SpeechEvaluator
{
    private const EVALUATE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'ok'          => ['type' => 'boolean'],
            'score'       => ['type' => 'integer'],
            'feedback_es' => ['type' => 'string'],
            'corrected'   => ['type' => 'string'],
            'issues'      => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'word'       => ['type' => 'string'],
                        'type'       => ['type' => 'string'],
                        'problem_es' => ['type' => 'string'],
                        'tip_es'     => ['type' => 'string'],
                    ],
                    'required' => ['word', 'problem_es', 'tip_es'],
                ],
            ],
            'next' => [
                'type' => 'object',
                'properties' => [
                    'question'     => ['type' => 'string'],
                    'model_answer' => ['type' => 'string'],
                ],
                'required' => ['question', 'model_answer'],
            ],
        ],
        'required' => ['ok', 'feedback_es'],
    ];

    private const QUESTION_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'question'     => ['type' => 'string'],
            'model_answer' => ['type' => 'string'],
        ],
        'required' => ['question', 'model_answer'],
    ];

    // ------------------------------------------------------------------ #
    // Word alignment (no AI, deterministic)
    // ------------------------------------------------------------------ #

    /**
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        preg_match_all("/[\p{L}\p{N}]+(?:['’-][\p{L}]+)*/u", trim($text), $matches);
        return $matches[0] ?? [];
    }

    /**
     * Words that must compare equal against their digit form, because Whisper
     * often transcribes spoken numbers as digits ("five" -> "5").
     */
    private const NUMBER_WORDS = [
        'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
        'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
        'ten' => '10', 'eleven' => '11', 'twelve' => '12', 'thirteen' => '13',
        'fourteen' => '14', 'fifteen' => '15', 'sixteen' => '16', 'seventeen' => '17',
        'eighteen' => '18', 'nineteen' => '19', 'twenty' => '20', 'thirty' => '30',
        'forty' => '40', 'fifty' => '50', 'hundred' => '100',
    ];

    private static function key(string $word): string
    {
        // mbstring is optional in this project (see helpers/utf8.php).
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($word, 'UTF-8')
            : strtolower($word);
        $lower = str_replace('’', "'", $lower);
        return self::NUMBER_WORDS[$lower] ?? $lower;
    }

    /**
     * Levenshtein alignment over word tokens with backtrace.
     *
     * @return array{chips: list<array{expected:?string, heard:?string, status:string}>, score:?int, expected_count:int}
     */
    public static function align(string $expected, string $heard): array
    {
        $a = self::tokenize($expected);
        $b = self::tokenize($heard);
        $n = count($a);
        $m = count($b);

        // Cost matrix (same rule as mb_strlen-based edit distance: sub/del/ins = 1).
        $d = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = self::key($a[$i - 1]) === self::key($b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            }
        }

        $chips = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            $same = $i > 0 && $j > 0 && self::key($a[$i - 1]) === self::key($b[$j - 1]);
            if ($i > 0 && $j > 0 && $d[$i][$j] === $d[$i - 1][$j - 1] + ($same ? 0 : 1)) {
                $chips[] = [
                    'expected' => $a[$i - 1],
                    'heard'    => $b[$j - 1],
                    'status'   => $same ? 'ok' : 'wrong',
                ];
                $i--;
                $j--;
            } elseif ($i > 0 && $d[$i][$j] === $d[$i - 1][$j] + 1) {
                $chips[] = ['expected' => $a[$i - 1], 'heard' => null, 'status' => 'missing'];
                $i--;
            } else {
                $chips[] = ['expected' => null, 'heard' => $b[$j - 1] ?? null, 'status' => 'extra'];
                $j--;
            }
        }
        $chips = array_reverse($chips);

        $expectedCount = $n;
        $ok = 0;
        foreach ($chips as $chip) {
            if ($chip['status'] === 'ok') {
                $ok++;
            }
        }

        return [
            'chips'          => $chips,
            'score'          => $expectedCount > 0 ? (int) round(100 * $ok / $expectedCount) : null,
            'expected_count' => $expectedCount,
        ];
    }

    /**
     * Compact human-readable diff of the alignment for the AI prompt.
     *
     * @param list<array{expected:?string, heard:?string, status:string}> $chips
     */
    public static function describeChips(array $chips): string
    {
        $lines = [];
        foreach ($chips as $chip) {
            $lines[] = match ($chip['status']) {
                'ok'      => sprintf('"%s" [bien]', $chip['expected']),
                'wrong'   => sprintf('se esperaba "%s" y se entendio "%s" [mal]', $chip['expected'], $chip['heard']),
                'missing' => sprintf('faltaba "%s" [no se entendio]', $chip['expected']),
                'extra'   => sprintf('sobra "%s" [no esperado]', $chip['heard']),
            };
        }
        return implode('; ', $lines);
    }

    // ------------------------------------------------------------------ #
    // AI evaluation
    // ------------------------------------------------------------------ #

    /**
     * Ask the AI to explain the result in Spanish and (optionally) generate the
     * next conversation question. Returns null when the AI is unavailable; the
     * caller should fall back to localResult().
     *
     * @param array{chips: list<array{expected:?string, heard:?string, status:string}>, score:?int, expected_count:int}|null $alignment
     * @return array{ok:bool, score:?int, feedback_es:string, corrected:string, issues:list<array<string,string>>, next:?array{question:string, model_answer:string}}|null
     */
    public static function evaluate(
        string $question,
        string $expected,
        string $heard,
        ?array $alignment,
        float $audioSeconds,
        bool $withNext,
        array $lesson
    ): ?array {
        $prompt = "Eres un profesor de ingles para hispanohablantes y estas evaluando la RESPUESTA ORAL "
            . "de un estudiante. La transcripcion la hizo Whisper: una palabra mal pronunciada suele "
            . "aparecer como otra palabra parecida o simplemente falta.\n\n";
        $prompt .= "PREGUNTA: " . ($question !== '' ? $question : '(respuesta libre)') . "\n";
        $prompt .= "RESPUESTA ESPERADA: " . ($expected !== '' ? $expected : '(sin respuesta fija, evalua fluidez y correccion general)') . "\n";
        $prompt .= "LO QUE SE ENTENDIO: " . ($heard !== '' ? $heard : '(nada: el audio estaba vacio o no se entendio ninguna palabra)') . "\n";

        if ($alignment !== null && $alignment['expected_count'] > 0) {
            $prompt .= "COMPARACION PALABRA POR PALABRA: " . self::describeChips($alignment['chips']) . "\n";
            $prompt .= "PUNTAJE DE SIMILITUD: " . (int) ($alignment['score'] ?? 0) . "/100\n";
        }
        if ($audioSeconds > 0) {
            $prompt .= sprintf("DURACION DEL AUDIO: ~%.1f s, PALABRAS DICHAS: %d\n", $audioSeconds, count(self::tokenize($heard)));
        }

        $prompt .= "\nREGLAS:\n";
        $prompt .= "- Escribe TODA la explicacion en espanol (feedback_es): si estuvo bien, que errores tuvo y como corregirlos, tono alentador y breve.\n";
        $prompt .= "- Si una palabra esperada se escucho como otra foneticamente parecida (ej. \"think\"->\"sink\", \"three\"->\"tree\"), tipo: pronunciation.\n";
        $prompt .= "- Si faltan articulos/verbos o la gramatica no cierra, tipo: grammar. Si hay una palabra real pero incorrecta, tipo: vocabulary. Si Whisper no capto una palabra, tipo: omission.\n";
        $prompt .= "- corrected: la respuesta correcta completa en ingles.\n";
        $prompt .= "- ok: true si la respuesta es comprensible y mayormente correcta.\n";
        $prompt .= "- score: 0-100 segun exactitud fonetica+gramatical.\n";
        if ($withNext) {
            $prompt .= "- next: la PROXIMA pregunta de la conversacion en ingles, corta y sobre el tema de la leccion, "
                . "ajustada a los errores que cometa el estudiante (si fallo pronunciation, otra pregunta con esas palabras). "
                . "Incluye model_answer: una respuesta modelo esperada en ingles.\n";
        } else {
            $prompt .= "- No incluyas la clave next.\n";
        }

        $lessonTitle = trim((string) ($lesson['title'] ?? ''));
        $lessonContext = str_limit(trim((string) ($lesson['explanation'] ?? '')) ?: trim((string) ($lesson['transcription'] ?? '')), 3000);
        if ($lessonTitle !== '' || $lessonContext !== '') {
            $prompt .= "\nCONTEXTO DE LA LECCION: " . $lessonTitle . "\n" . $lessonContext . "\n";
        }

        $prompt .= "\n" . AiJson::schemaPrompt(self::EVALUATE_SCHEMA);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = OpenCode::chat($prompt, ['json_mode' => true, 'timeout' => 120]);
            $decoded = AiJson::decode((string) $raw);
            $result = self::normalizeEvaluation($decoded, $expected);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Deterministic result built only from the alignment, used when the AI is
     * down (same spirit as the ExamGrader fallback).
     *
     * @param array{chips: list<array{expected:?string, heard:?string, status:string}>, score:?int, expected_count:int}|null $alignment
     * @return array{ok:bool, score:?int, feedback_es:string, corrected:string, issues:list<array<string,string>>, next:?array{question:string, model_answer:string}}|null
     */
    public static function localResult(?array $alignment, string $expected, string $heard): ?array
    {
        if ($alignment === null || $alignment['expected_count'] === 0) {
            return null;
        }

        $score = (int) ($alignment['score'] ?? 0);
        $issues = [];
        foreach ($alignment['chips'] as $chip) {
            if ($chip['status'] === 'ok' || $chip['status'] === 'extra') {
                continue;
            }
            $issues[] = [
                'word'       => (string) $chip['expected'],
                'type'       => $chip['status'] === 'missing' ? 'omission' : 'pronunciation',
                'problem_es' => $chip['status'] === 'missing'
                    ? 'No se entendio la palabra "' . $chip['expected'] . '".'
                    : 'Se esperaba "' . $chip['expected'] . '" pero se entendio "' . $chip['heard'] . '".',
                'tip_es'     => 'Pronuncial otra vez mirando la boca en el espejo o escucha la palabra en un diccionario de audio.',
            ];
        }

        if ($heard === '') {
            $feedback = 'No se entendio ninguna palabra. Revisa que el microfono funciona y habla un poco mas cerca y mas fuerte.';
        } elseif ($score >= 80) {
            $feedback = 'Muy bien: se entendio casi todo (' . $score . '/100).';
        } elseif ($score >= 50) {
            $feedback = 'Vas por buen camino (' . $score . '/100), pero hay palabras que no se entendieron bien.';
        } else {
            $feedback = 'Se entendio poco (' . $score . '/100). Vuelve a intentarlo hablando mas despacio y claro.';
        }
        $feedback .= ' (Correccion local: la IA no esta disponible para darte mas detalles.)';

        return [
            'ok'          => $score >= 70,
            'score'       => $score,
            'feedback_es' => $feedback,
            'corrected'   => $expected,
            'issues'      => $issues,
            'next'        => null,
        ];
    }

    /**
     * @param mixed $decoded
     * @return array{ok:bool, score:?int, feedback_es:string, corrected:string, issues:list<array<string,string>>, next:?array{question:string, model_answer:string}}|null
     */
    private static function normalizeEvaluation(mixed $decoded, string $expected): ?array
    {
        if (!is_array($decoded) || trim((string) ($decoded['feedback_es'] ?? '')) === '') {
            return null;
        }

        $score = isset($decoded['score']) ? max(0, min(100, (int) $decoded['score'])) : null;
        $ok = isset($decoded['ok']) ? (bool) $decoded['ok'] : ($score !== null && $score >= 70);

        $issues = [];
        foreach (is_array($decoded['issues'] ?? null) ? $decoded['issues'] : [] as $issue) {
            if (!is_array($issue) || trim((string) ($issue['word'] ?? '')) === '') {
                continue;
            }
            $issues[] = [
                'word'       => trim((string) $issue['word']),
                'type'       => in_array((string) ($issue['type'] ?? ''), ['pronunciation', 'grammar', 'vocabulary', 'omission', 'fluency'], true)
                    ? (string) $issue['type']
                    : 'other',
                'problem_es' => trim((string) ($issue['problem_es'] ?? '')),
                'tip_es'     => trim((string) ($issue['tip_es'] ?? '')),
            ];
        }

        $next = null;
        if (is_array($decoded['next'] ?? null) && trim((string) ($decoded['next']['question'] ?? '')) !== '') {
            $next = [
                'question'     => trim((string) $decoded['next']['question']),
                'model_answer' => trim((string) ($decoded['next']['model_answer'] ?? '')),
            ];
        }

        return [
            'ok'          => $ok,
            'score'       => $score,
            'feedback_es' => trim((string) $decoded['feedback_es']),
            'corrected'   => trim((string) ($decoded['corrected'] ?? '')) ?: $expected,
            'issues'      => $issues,
            'next'        => $next,
        ];
    }

    // ------------------------------------------------------------------ #
    // Conversation questions
    // ------------------------------------------------------------------ #

    /**
     * Generate the first question of an oral conversation about the lesson, or
     * a replacement one ("Otra pregunta").
     *
     * @param array<int, array{question:string, heard:string, score:?int}> $history
     * @return array{question:string, model_answer:string}|null
     */
    public static function generateQuestion(array $lesson, array $history): ?array
    {
        $title = trim((string) ($lesson['title'] ?? '')) ?: 'English lesson';
        $transcription = str_limit(trim((string) ($lesson['transcription'] ?? '')) ?: 'No transcription available.', 4000);
        $explanation = str_limit(trim((string) ($lesson['explanation'] ?? '')), 3000);

        $prompt = "You are an English conversation teacher for Spanish speakers. Make ONE short spoken "
            . "question about this lesson so the student can answer out loud in 1-3 sentences. "
            . "Prefer questions that practice the grammar/vocabulary of the lesson.\n\n";
        $prompt .= "LESSON TITLE: $title\n\nTRANSCRIPTION:\n$transcription\n\nTEACHER'S EXPLANATION:\n$explanation\n\n";

        if ($history !== []) {
            $prompt .= "PREGUNTAS YA HECHAS EN ESTA SESION (no las repitas):\n";
            foreach (array_slice($history, -8) as $turn) {
                $score = isset($turn['score']) && $turn['score'] !== null ? ' (puntaje ' . (int) $turn['score'] . ')' : '';
                $prompt .= '- ' . (string) ($turn['question'] ?? '') . $score . "\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "Devuelve question (en ingles, apta para responderse de viva voz) y "
            . "model_answer (respuesta modelo corta en ingles). "
            . AiJson::schemaPrompt(self::QUESTION_SCHEMA);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = OpenCode::chat($prompt, ['json_mode' => true, 'timeout' => 120]);
            $decoded = AiJson::decode((string) $raw);
            if (is_array($decoded) && trim((string) ($decoded['question'] ?? '')) !== '') {
                return [
                    'question'     => trim((string) $decoded['question']),
                    'model_answer' => trim((string) ($decoded['model_answer'] ?? '')),
                ];
            }
        }

        return null;
    }

    /**
     * Convenience: lesson row for the controller.
     */
    public static function lessonOrFail(int $lessonId): ?array
    {
        return (new Lesson())->find($lessonId);
    }
}
