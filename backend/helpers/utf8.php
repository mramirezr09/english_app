<?php

declare(strict_types=1);

if (!function_exists('str_limit')) {
    /**
     * UTF-8 safe truncation that does not require the mbstring extension.
     */
    function str_limit(string $text, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($text, 0, $limit, 'UTF-8');
            if ($result !== false) {
                return $result;
            }
        }
        if (strlen($text) <= $limit) {
            return $text;
        }
        $cut = substr($text, 0, $limit);
        while ($cut !== '' && !preg_match('//u', $cut)) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }
}
