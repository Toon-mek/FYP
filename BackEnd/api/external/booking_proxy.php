<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

const RAPIDAPI_BOOKING_FALLBACK_KEY = '';

function resolveRapidApiKey(): string
{
    $sources = [
        $_ENV['RAPIDAPI_BOOKING_KEY'] ?? null,
        $_ENV['BOOKING_RAPIDAPI_KEY'] ?? null,
        getenv('RAPIDAPI_BOOKING_KEY') ?: null,
        getenv('BOOKING_RAPIDAPI_KEY') ?: null,
        $_SERVER['RAPIDAPI_BOOKING_KEY'] ?? null,
        $_SERVER['BOOKING_RAPIDAPI_KEY'] ?? null,
        RAPIDAPI_BOOKING_FALLBACK_KEY,
    ];

    foreach ($sources as $value) {
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

if ($requestMethod !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/** @var array<string, string> $resourceMap */
$resourceMap = [
    'destinations' => '/api/v1/hotels/searchDestination',
    'hotels' => '/api/v1/hotels/searchHotels',
    'hotels-by-coordinates' => '/api/v1/hotels/searchHotelsByCoordinates',
    'hotel-details' => '/api/v1/hotels/getHotelDetails',
    'hotel-photos' => '/api/v1/hotels/getHotelPhotos',
    'hotel-description' => '/api/v1/hotels/getDescriptionAndInfo',
];

$resourceKey = isset($_GET['resource']) ? strtolower(trim((string) $_GET['resource'])) : 'destinations';

if (!isset($resourceMap[$resourceKey])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid resource',
        'allowed' => array_keys($resourceMap),
    ]);
    exit;
}

$apiKey = resolveRapidApiKey();

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode([
        'error' => 'RapidAPI key missing',
        'hint' => 'Set RAPIDAPI_BOOKING_KEY in your environment before calling this endpoint.',
    ]);
    exit;
}

$queryParams = $_GET;
unset($queryParams['resource']);

$queryString = http_build_query($queryParams);
$baseUrl = 'https://booking-com15.p.rapidapi.com';
$url = $baseUrl . $resourceMap[$resourceKey] . ($queryString !== '' ? '?' . $queryString : '');

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
        'x-rapidapi-key: ' . $apiKey,
        'x-rapidapi-host: booking-com15.p.rapidapi.com',
        'Accept: application/json',
    ],
]);

$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

curl_close($curl);

if ($responseBody === false) {
    http_response_code(502);
    echo json_encode([
        'error' => 'Failed to contact Booking.com proxy',
        'detail' => $curlError !== '' ? $curlError : 'Unknown cURL error',
    ]);
    exit;
}

if (!is_int($statusCode) || $statusCode <= 0) {
    $statusCode = 200;
}

$decoded = json_decode($responseBody, true);

if (json_last_error() === JSON_ERROR_NONE) {
    if ($statusCode >= 400) {
        http_response_code($statusCode);
        echo json_encode([
            'error' => 'Upstream request failed',
            'resource' => $resourceKey,
            'upstreamStatus' => $statusCode,
            'details' => $decoded,
        ]);
        exit;
    }

    http_response_code($statusCode);
    echo json_encode([
        'resource' => $resourceKey,
        'upstreamStatus' => $statusCode,
        'data' => $decoded,
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'error' => 'Invalid response from Booking.com proxy',
    'upstreamStatus' => $statusCode,
    'raw' => $responseBody,
]);
