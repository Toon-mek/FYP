<?php
declare(strict_types=1);

if (!function_exists('loadEnvFromFile')) {
    /**
     * Minimal .env loader that populates $_ENV/$_SERVER/putenv.
     */
    function loadEnvFromFile(string $path): void
    {
        static $loaded = [];
        $realPath = realpath($path) ?: $path;

        if (isset($loaded[$realPath]) || !is_file($realPath) || !is_readable($realPath)) {
            return;
        }
        $loaded[$realPath] = true;

        $lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            if (strpos($trimmed, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (substr($value, -1) === $quote) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
