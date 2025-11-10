<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/api_keys.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'travel_insights')));
$matrixKey = resolveApiKey('google', 'distance_matrix', 'GOOGLE_DISTANCE_MATRIX_KEY');
$directionsKey = $matrixKey !== '' ? $matrixKey : resolveApiKey('google', 'maps_js', 'GOOGLE_MAPS_KEY');

if ($matrixKey === '' || $directionsKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Google routing keys missing.']);
    exit;
}

switch ($action) {
    case 'distance_matrix':
        proxyDistanceMatrix($matrixKey);
        break;
    case 'directions':
        proxyDirections($directionsKey);
        break;
    case 'travel_insights':
    default:
        handleTravelInsights($matrixKey, $directionsKey);
        break;
}

function handleTravelInsights(string $matrixKey, string $directionsKey): void
{
    [$originPair, $originCoords] = resolveCoordinatePair('origin');
    [$destinationPair, $destinationCoords] = resolveCoordinatePair('destination');
    $mode = normalizeMode($_GET['mode'] ?? 'driving');

    if ($originPair === null || $destinationPair === null) {
        http_response_code(400);
        echo json_encode(['error' => 'originLat/originLng and destinationLat/destinationLng are required']);
        return;
    }

    $distance = callGoogleApi(
        'https://maps.googleapis.com/maps/api/distancematrix/json',
        [
            'origins' => $originPair,
            'destinations' => $destinationPair,
            'mode' => $mode,
            'language' => 'en',
            'units' => 'metric',
            'key' => $matrixKey,
        ],
    );

    if ($distance['status'] >= 400) {
        http_response_code($distance['status']);
        echo json_encode(['error' => 'Distance Matrix lookup failed', 'details' => $distance['body']]);
        return;
    }

    $directions = callGoogleApi(
        'https://maps.googleapis.com/maps/api/directions/json',
        [
            'origin' => $originPair,
            'destination' => $destinationPair,
            'mode' => $mode,
            'language' => 'en',
            'units' => 'metric',
            'key' => $directionsKey,
        ],
    );

    if ($directions['status'] >= 400) {
        http_response_code($directions['status']);
        echo json_encode(['error' => 'Directions lookup failed', 'details' => $directions['body']]);
        return;
    }

    $distanceElement = $distance['body']['rows'][0]['elements'][0] ?? null;
    if (!$distanceElement || ($distanceElement['status'] ?? '') !== 'OK') {
        http_response_code(502);
        echo json_encode(['error' => 'Distance Matrix returned no results', 'details' => $distance['body']]);
        return;
    }

    $route = $directions['body']['routes'][0] ?? null;
    if (!$route) {
        http_response_code(502);
        echo json_encode(['error' => 'Directions returned no routes', 'details' => $directions['body']]);
        return;
    }

    $legs = [];
    foreach ($route['legs'] ?? [] as $leg) {
        $steps = [];
        foreach ($leg['steps'] ?? [] as $step) {
            $steps[] = [
                'distanceText' => $step['distance']['text'] ?? null,
                'distanceMeters' => $step['distance']['value'] ?? null,
                'durationText' => $step['duration']['text'] ?? null,
                'durationSeconds' => $step['duration']['value'] ?? null,
                'instruction' => strip_tags($step['html_instructions'] ?? ''),
            ];
        }
        $legs[] = [
            'startAddress' => $leg['start_address'] ?? '',
            'endAddress' => $leg['end_address'] ?? '',
            'distanceText' => $leg['distance']['text'] ?? null,
            'distanceMeters' => $leg['distance']['value'] ?? null,
            'durationText' => $leg['duration']['text'] ?? null,
            'durationSeconds' => $leg['duration']['value'] ?? null,
            'steps' => $steps,
        ];
    }

    echo json_encode([
        'distanceText' => $distanceElement['distance']['text'] ?? null,
        'distanceMeters' => $distanceElement['distance']['value'] ?? null,
        'durationText' => $distanceElement['duration']['text'] ?? null,
        'durationSeconds' => $distanceElement['duration']['value'] ?? null,
        'mode' => $mode,
        'origin' => $originCoords,
        'destination' => $destinationCoords,
        'route' => [
            'summary' => $route['summary'] ?? '',
            'warnings' => $route['warnings'] ?? [],
            'polyline' => $route['overview_polyline']['points'] ?? '',
            'bounds' => $route['bounds'] ?? null,
            'legs' => $legs,
        ],
        'raw' => [
            'distanceMatrix' => $distance['body'],
            'directions' => $directions['body'],
        ],
    ]);
}

function proxyDistanceMatrix(string $apiKey): void
{
    [$origins] = resolveCoordinatePair('origin');
    [$destinations] = resolveCoordinatePair('destination');
    $mode = normalizeMode($_GET['mode'] ?? 'driving');

    if ($origins === null || $destinations === null) {
        http_response_code(400);
        echo json_encode(['error' => 'originLat/originLng and destinationLat/destinationLng are required']);
        return;
    }

    $response = callGoogleApi(
        'https://maps.googleapis.com/maps/api/distancematrix/json',
        [
            'origins' => $origins,
            'destinations' => $destinations,
            'mode' => $mode,
            'language' => 'en',
            'units' => 'metric',
            'key' => $apiKey,
        ],
    );

    http_response_code($response['status']);
    echo json_encode($response['body']);
}

function proxyDirections(string $apiKey): void
{
    [$origin] = resolveCoordinatePair('origin');
    [$destination] = resolveCoordinatePair('destination');
    $mode = normalizeMode($_GET['mode'] ?? 'driving');

    if ($origin === null || $destination === null) {
        http_response_code(400);
        echo json_encode(['error' => 'originLat/originLng and destinationLat/destinationLng are required']);
        return;
    }

    $response = callGoogleApi(
        'https://maps.googleapis.com/maps/api/directions/json',
        [
            'origin' => $origin,
            'destination' => $destination,
            'mode' => $mode,
            'language' => 'en',
            'units' => 'metric',
            'key' => $apiKey,
        ],
    );

    http_response_code($response['status']);
    echo json_encode($response['body']);
}

function resolveCoordinatePair(string $prefix): array
{
    $latKey = $prefix . 'Lat';
    $lngKey = $prefix . 'Lng';
    $lat = isset($_GET[$latKey]) ? (float)$_GET[$latKey] : null;
    $lng = isset($_GET[$lngKey]) ? (float)$_GET[$lngKey] : null;

    if ($lat === null || $lng === null) {
        return [null, null];
    }

    $pair = sprintf('%.7f,%.7f', $lat, $lng);
    return [$pair, ['lat' => $lat, 'lng' => $lng]];
}

function normalizeMode(string $mode): string
{
    $allowed = ['driving', 'walking', 'bicycling', 'transit'];
    $mode = strtolower(trim($mode));
    return in_array($mode, $allowed, true) ? $mode : 'driving';
}

function callGoogleApi(string $url, array $params): array
{
    $finalUrl = $url . '?' . http_build_query($params);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $finalUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        return [
            'status' => 502,
            'body' => ['error' => $error ?: 'Failed contacting Google Directions service.'],
        ];
    }

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 502,
            'body' => ['error' => 'Invalid JSON from Google service', 'raw' => $body],
        ];
    }

    return [
        'status' => $status ?: 200,
        'body' => $decoded,
    ];
}
