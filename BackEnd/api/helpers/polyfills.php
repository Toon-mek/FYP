<?php
declare(strict_types=1);

if (!function_exists('mb_substr')) {
    /**
     * Basic multibyte substring polyfill for environments without the mbstring extension.
     */
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $result = $length === null ? substr($string, $start) : substr($string, $start, $length);
        return $result === false ? '' : $result;
    }
}

if (!function_exists('mb_strlen')) {
    /**
     * Basic multibyte strlen polyfill for environments without the mbstring extension.
     */
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}

if (!function_exists('mb_strtoupper')) {
    /**
     * Basic multibyte strtoupper polyfill for environments without the mbstring extension.
     */
    function mb_strtoupper(string $string, ?string $encoding = null): string
    {
        return strtoupper($string);
    }
}
