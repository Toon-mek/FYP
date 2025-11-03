<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

const GOOGLE_TRANSLATE_FALLBACK_KEY = '';

function loadGoogleTranslateConfigKey(): ?string
{
    static $loaded = false;
    static $configKey = null;

    if ($loaded) {
        return $configKey;
    }

    $loaded = true;

    $configPath = dirname(__DIR__, 2) . '/config/google_translate.php';
    if (!is_file($configPath)) {
        return null;
    }

    /** @var mixed $config */
    $config = require $configPath;
    if (!is_array($config) || !isset($config['apiKey'])) {
        return null;
    }

    $value = $config['apiKey'];
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $configKey = $trimmed;
    return $configKey;
}

function resolveGoogleTranslateKey(): string
{
    $candidates = [
        loadGoogleTranslateConfigKey(),
        $_ENV['GOOGLE_TRANSLATE_API_KEY'] ?? null,
        $_ENV['GOOGLE_CLOUD_TRANSLATE_KEY'] ?? null,
        getenv('GOOGLE_TRANSLATE_API_KEY') ?: null,
        getenv('GOOGLE_CLOUD_TRANSLATE_KEY') ?: null,
        $_SERVER['GOOGLE_TRANSLATE_API_KEY'] ?? null,
        $_SERVER['GOOGLE_CLOUD_TRANSLATE_KEY'] ?? null,
        GOOGLE_TRANSLATE_FALLBACK_KEY,
    ];

    foreach ($candidates as $value) {
        if (!is_string($value)) {
            continue;
        }
        $trimmed = trim($value);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return '';
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$apiKey = resolveGoogleTranslateKey();
if ($apiKey === '') {
    http_response_code(500);
    echo json_encode([
        'error' => 'Google Translate API key missing',
        'hint' => 'Set GOOGLE_TRANSLATE_API_KEY in your environment before calling this endpoint.',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$body = json_decode($rawBody, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$target = isset($body['target']) ? strtolower(trim((string) $body['target'])) : '';
if ($target === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Target language is required (example: "zh", "ms", "ta")']);
    exit;
}

$source = isset($body['source']) ? strtolower(trim((string) $body['source'])) : null;
if ($source === '') {
    $source = null;
}

$format = isset($body['format']) ? strtolower(trim((string) $body['format'])) : 'text';
if ($format !== 'text' && $format !== 'html') {
    $format = 'text';
}

$texts = [];

if (array_key_exists('texts', $body)) {
    $texts = $body['texts'];
} elseif (array_key_exists('q', $body)) {
    $texts = $body['q'];
} elseif (array_key_exists('text', $body)) {
    $texts = $body['text'];
}

if (is_string($texts)) {
    $texts = [$texts];
}

if (!is_array($texts)) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide a "texts" array or string to translate']);
    exit;
}

$payloadTexts = [];
foreach ($texts as $text) {
    if (!is_scalar($text)) {
        continue;
    }
    $stringText = (string) $text;
    if (trim($stringText) === '') {
        continue;
    }
    $payloadTexts[] = $stringText;
}

if ($payloadTexts === []) {
    http_response_code(400);
    echo json_encode(['error' => 'No translatable text provided']);
    exit;
}

$requestPayload = [
    'q' => $payloadTexts,
    'target' => $target,
    'format' => $format,
];

if ($source !== null) {
    $requestPayload['source'] = $source;
}

$model = isset($body['model']) ? trim((string) $body['model']) : null;
if ($model !== null && $model !== '') {
    $requestPayload['model'] = $model;
}

$apiUrl = 'https://translation.googleapis.com/language/translate/v2?key=' . urlencode($apiKey);

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($requestPayload),
    CURLOPT_TIMEOUT => 20,
]);

$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

curl_close($curl);

if ($responseBody === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Failed to contact Google Translate API',
        'detail' => $curlError !== '' ? $curlError : 'Unknown cURL error',
    ]);
    exit;
}

$decoded = json_decode($responseBody, true);

if (!is_array($decoded)) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Unexpected response from Google Translate API',
        'raw' => $responseBody,
    ]);
    exit;
}

if ($statusCode >= 400) {
    http_response_code($statusCode);
    echo json_encode([
        'error' => 'Google Translate API error',
        'status' => $statusCode,
        'details' => $decoded,
    ]);
    exit;
}

$translations = $decoded['data']['translations'] ?? null;
if (!is_array($translations)) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Invalid translation payload received',
        'raw' => $decoded,
    ]);
    exit;
}

$results = [];
foreach ($translations as $index => $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $translatedText = isset($entry['translatedText']) ? (string) $entry['translatedText'] : '';
    $detectedSource = isset($entry['detectedSourceLanguage']) ? (string) $entry['detectedSourceLanguage'] : null;
    $results[] = [
        'input' => $payloadTexts[$index] ?? null,
        'translatedText' => $translatedText,
        'detectedSourceLanguage' => $detectedSource,
    ];
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'target' => $target,
    'source' => $source,
    'translations' => $results,
]);
