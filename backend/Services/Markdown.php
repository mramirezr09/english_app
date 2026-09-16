<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Minimal dependency-free Markdown renderer.
 * Supports headings, bold/italic, inline code, code fences, lists,
 * blockquotes, links and horizontal rules.
 */
final class Markdown
{
    public static function render(string $text): string
    {
        $text = self::normalizeMath(str_replace("\r\n", "\n", $text));
        $lines = explode("\n", $text);

        $html = '';
        $para = [];
        $inCode = false;
        $code = '';
        $listType = '';
        $inQuote = false;

        $closeList = static function () use (&$html, &$listType): void {
            if ($listType !== '') {
                $html .= "</$listType>\n";
                $listType = '';
            }
        };
        $closeQuote = static function () use (&$html, &$inQuote): void {
            if ($inQuote) {
                $html .= "</blockquote>\n";
                $inQuote = false;
            }
        };
        $flushPara = static function () use (&$html, &$para): void {
            if ($para) {
                $html .= '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . "</p>\n";
                $para = [];
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                if ($inCode) {
                    $html .= '<pre><code>' . self::escape($code) . "</code></pre>\n";
                    $code = '';
                    $inCode = false;
                } else {
                    $flushPara();
                    $closeList();
                    $closeQuote();
                    $inCode = true;
                }
                continue;
            }

            if ($inCode) {
                $code .= $line . "\n";
                continue;
            }

            if (trim($line) === '') {
                $flushPara();
                $closeList();
                $closeQuote();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $flushPara();
                $closeList();
                $closeQuote();
                $level = strlen($m[1]);
                $html .= "<h$level>" . self::inline($m[2]) . "</h$level>\n";
                continue;
            }

            if (preg_match('/^\s*([-*_])\s*(\1\s*){2,}$/', $line)) {
                $flushPara();
                $closeList();
                $closeQuote();
                $html .= "<hr>\n";
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushPara();
                $closeList();
                if (!$inQuote) {
                    $html .= "<blockquote>\n";
                    $inQuote = true;
                }
                $html .= self::inline($m[1]) . "<br>\n";
                continue;
            }
            $closeQuote();

            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
                $flushPara();
                if ($listType !== 'ul') {
                    $closeList();
                    $html .= "<ul>\n";
                    $listType = 'ul';
                }
                $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
                $flushPara();
                if ($listType !== 'ol') {
                    $closeList();
                    $html .= "<ol>\n";
                    $listType = 'ol';
                }
                $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                continue;
            }

            $closeList();
            $para[] = $line;
        }

        if ($inCode) {
            $html .= '<pre><code>' . self::escape($code) . "</code></pre>\n";
        }
        $flushPara();
        $closeList();
        $closeQuote();

        return $html;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function normalizeMath(string $text): string
    {
        return str_replace(
            ['$\\rightarrow$', '\\rightarrow', '$\\to$', '\\to', '$\\times$', '\\times', '$\\Rightarrow$', '\\Rightarrow', '$\\leftarrow$', '\\leftarrow'],
            ['→', '→', '→', '→', '×', '×', '⇒', '⇒', '←', '←'],
            $text
        );
    }

    private static function inline(string $text): string
    {
        $text = self::normalizeMath($text);
        $text = self::escape($text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
        return $text;
    }
}
