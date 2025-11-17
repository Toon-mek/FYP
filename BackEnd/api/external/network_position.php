<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $payload = locateByNetwork();
    echo json_encode($payload);
} catch (Throwable $exception) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Network location lookup failed',
        'details' => $exception->getMessage(),
    ]);
}

/**
 * @throws RuntimeException
 */
function locateByNetwork(): array
{
    $clientIp = resolveClientIp();
    $providers = [
        'ipapi' => static fn(?string $ip) => $ip ? "https://ipapi.co/{$ip}/json/" : 'https://ipapi.co/json/',
        'ipinfo' => static fn(?string $ip) => $ip ? "https://ipinfo.io/{$ip}/json" : 'https://ipinfo.io/json',
        'ipwhois' => static fn(?string $ip) => $ip ? "https://ipwho.is/{$ip}" : 'https://ipwho.is/',
    ];
    foreach ($providers as $provider => $resolver) {
        $url = is_callable($resolver) ? $resolver($clientIp) : $resolver;
        $response = httpGetJson($url);
        if (!$response) {
            continue;
        }
        $normalised = normaliseProviderPayload($provider, $response);
        if ($normalised) {
            return $normalised;
        }
    }

    throw new RuntimeException('All providers returned empty results.');
}

function resolveClientIp(): ?string
{
    $candidates = [
        $_SERVER['HTTP_CLIENT_IP'] ?? null,
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    $fallback = null;
    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        $parts = explode(',', $candidate);
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            if ($fallback === null && filter_var($ip, FILTER_VALIDATE_IP)) {
                $fallback = $ip;
            }
        }
    }
    return $fallback;
}

function httpGetJson(string $url): ?array
{
    $curl = curl_init($url);
    if ($curl === false) {
        return null;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $status >= 400) {
        return null;
    }
    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function normaliseProviderPayload(string $provider, array $payload): ?array
{
    switch ($provider) {
        case 'ipapi':
            $lat = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
            $lng = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
            $accuracy = isset($payload['accuracy']) ? (float)$payload['accuracy'] : null;
            $labelParts = array_filter([$payload['city'] ?? null, $payload['region'] ?? null]);
            break;
        case 'ipinfo':
            $loc = isset($payload['loc']) ? explode(',', (string)$payload['loc']) : null;
            $lat = isset($loc[0]) ? (float)$loc[0] : null;
            $lng = isset($loc[1]) ? (float)$loc[1] : null;
            $accuracy = isset($payload['accuracy']) ? (float)$payload['accuracy'] : null;
            $labelParts = array_filter([$payload['city'] ?? null, $payload['region'] ?? null]);
            break;
        case 'ipwhois':
            $lat = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
            $lng = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
            $accuracy = isset($payload['connection']['radius']) ? (float)$payload['connection']['radius'] : null;
            $labelParts = array_filter([$payload['city'] ?? null, $payload['region'] ?? null]);
            break;
        default:
            return null;
    }

    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }

    return [
        'coords' => [
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $accuracy,
        ],
        'meta' => [
            'source' => 'network',
            'provider' => $provider,
            'fallbackLabel' => implode(', ', $labelParts) ?: null,
        ],
    ];
}
