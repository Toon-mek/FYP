<?php
declare(strict_types=1);

function loadApiKeys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $configPath = __DIR__ . '/../../config/api_keys.php';
    if (!file_exists($configPath)) {
        return $keys = [];
    }

    $loaded = require $configPath;
    if (!is_array($loaded)) {
        return $keys = [];
    }

    return $keys = $loaded;
}

function resolveApiKey(string $group, string $key, string $fallbackEnv = ''): string
{
    $keys = loadApiKeys();
    $value = $keys[$group][$key] ?? null;
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if ($fallbackEnv !== '') {
        $env = getenv($fallbackEnv) ?: ($_ENV[$fallbackEnv] ?? ($_SERVER[$fallbackEnv] ?? ''));
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
    }

    return '';
}
