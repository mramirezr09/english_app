<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Helpers to extract JSON from model replies (ports of the Python workers).
 */
final class AiJson
{
    /**
     * Decode the first JSON object/array found in a reply that may contain
     * markdown fences or surrounding prose.
     */
    public static function decode(string $text): mixed
    {
        $cleaned = trim(str_replace(['```json', '```JSON', '```'], '', $text));
        if ($cleaned === '') {
            return null;
        }

        $direct = json_decode($cleaned, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $direct;
        }

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($cleaned, $open);
            if ($start === false) {
                continue;
            }
            $end = self::balancedEnd($cleaned, $start, $open, $close);
            if ($end === null) {
                continue;
            }
            $candidate = substr($cleaned, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Turn a decoded payload into a list, looking for known keys first.
     *
     * @param string[] $keys
     * @return array<int, mixed>
     */
    public static function toList(mixed $data, array $keys = []): array
    {
        if (!is_array($data)) {
            return [];
        }
        if (array_is_list($data)) {
            return $data;
        }

        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        // A single object shaped like a question/exercise.
        if (isset($data['question']) || isset($data['pregunta']) || isset($data['prompt'])) {
            return [$data];
        }

        return [];
    }

    private static function balancedEnd(string $text, int $start, string $open, string $close): ?int
    {
        $depth = 0;
        $length = strlen($text);
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Small JSON schema description used inside prompts (the CLI has no native
     * response_format).
     */
    public static function schemaPrompt(array $schema): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return "Devuelve SOLO un objeto JSON valido (sin markdown ni texto adicional) que cumpla exactamente este esquema:\n"
            . $json;
    }
}
