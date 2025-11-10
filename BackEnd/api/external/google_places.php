<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/api_keys.php';

const PLACES_V1_BASE = 'https://places.googleapis.com/v1';
const LEGACY_PLACES_BASE = 'https://maps.googleapis.com/maps/api/place';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'autocomplete')));
$apiKey = resolveApiKey('google', 'places', 'GOOGLE_PLACES_API_KEY');

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Google Places API key missing']);
    exit;
}

try {
    switch ($action) {
        case 'autocomplete':
            handleAutocomplete($apiKey);
            break;
        case 'details':
            handleDetails($apiKey);
            break;
        case 'textsearch':
            handleTextSearch($apiKey);
            break;
        case 'nearbysearch':
            handleNearbySearch($apiKey);
            break;
        case 'photo':
            handlePhoto($apiKey);
            break;
        case 'reverse_geocode':
            handleReverseGeocode();
            break;
        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Unsupported action',
                'allowed' => ['autocomplete', 'details', 'reverse_geocode', 'textsearch', 'nearbysearch', 'photo'],
            ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}

function handleAutocomplete(string $apiKey): void
{
    $input = trim((string)($_GET['input'] ?? ''));
    if ($input === '') {
        http_response_code(400);
        echo json_encode(['error' => 'input is required']);
        return;
    }

    $sessionToken = trim((string)($_GET['sessiontoken'] ?? bin2hex(random_bytes(8))));
    $language = trim((string)($_GET['language'] ?? 'en'));
    $components = trim((string)($_GET['components'] ?? ''));
    $regionParam = trim((string)($_GET['region'] ?? ''));
    $types = isset($_GET['types']) ? array_filter(array_map('trim', explode(',', (string)$_GET['types']))) : [];

    $body = [
        'input' => $input,
        'languageCode' => $language !== '' ? $language : 'en',
        'sessionToken' => $sessionToken,
    ];
    $regionCodes = [];
    if ($components !== '') {
        $parts = preg_split('/[|,]/', strtolower($components));
        foreach ($parts as $component) {
            $component = trim($component);
            if ($component === '') {
                continue;
            }
            if (strpos($component, 'country:') === 0) {
                $country = strtoupper(trim(substr($component, strlen('country:'))));
                if ($country !== '') {
                    $regionCodes[] = $country;
                }
            }
        }
    }
    if ($regionParam !== '') {
        $regionCodes[] = strtoupper($regionParam);
    }
    if (empty($regionCodes)) {
        $regionCodes[] = 'MY';
    }
    if (!empty($regionCodes)) {
        $body['includedRegionCodes'] = array_values(array_unique($regionCodes));
    }
    if (!empty($types)) {
        $body['includedPrimaryTypes'] = array_values($types);
    }
    if (isset($_GET['lat'], $_GET['lng'])) {
        $body['locationBias'] = [
            'circle' => [
                'center' => [
                    'latitude' => (float)$_GET['lat'],
                    'longitude' => (float)$_GET['lng'],
                ],
                'radius' => isset($_GET['radius']) ? (float)$_GET['radius'] : 5000,
            ],
        ];
    }

    $response = callPlacesJson('/places:autocomplete', $apiKey, 'POST', $body, 'suggestions.placePrediction');
    $predictions = array_values(
        array_filter(
            array_map('convertSuggestionToPrediction', $response['suggestions'] ?? [])
        )
    );

    echo json_encode([
        'sessionToken' => $sessionToken,
        'data' => [
            'predictions' => $predictions,
        ],
    ]);
}

function handleDetails(string $apiKey): void
{
    $placeId = trim((string)($_GET['placeId'] ?? ''));
    if ($placeId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'placeId is required']);
        return;
    }
    $language = trim((string)($_GET['language'] ?? 'en'));

    $fieldMask = implode(',', [
        'id',
        'displayName',
        'formattedAddress',
        'shortFormattedAddress',
        'internationalPhoneNumber',
        'websiteUri',
        'googleMapsUri',
        'rating',
        'userRatingCount',
        'priceLevel',
        'priceRange.startPrice',
        'priceRange.endPrice',
        'location',
        'photos',
        'regularOpeningHours',
        'types',
        'editorialSummary',
    ]);
    $path = '/places/' . rawurlencode($placeId);
    $response = callPlacesJson($path, $apiKey, 'GET', null, $fieldMask, $language);
    $converted = convertPlaceToLegacy($response);

    echo json_encode([
        'data' => $converted,
    ]);
}

function handleTextSearch(string $apiKey): void
{
    $query = trim((string)($_GET['query'] ?? ''));
    if ($query === '') {
        http_response_code(400);
        echo json_encode(['error' => 'query is required']);
        return;
    }
    $language = trim((string)($_GET['language'] ?? 'en'));
    $region = strtoupper(trim((string)($_GET['region'] ?? 'MY')));
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    $radius = isset($_GET['radius']) ? (float)$_GET['radius'] : 20000;
    $type = trim((string)($_GET['type'] ?? ''));

    $body = [
        'textQuery' => $query,
        'languageCode' => $language !== '' ? $language : 'en',
        'regionCode' => $region !== '' ? $region : 'MY',
    ];
    if ($lat !== null && $lng !== null) {
        $body['locationBias'] = [
            'circle' => [
                'center' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
                ],
                'radius' => max($radius, 1000),
            ],
        ];
    }
    if ($type !== '') {
        $body['includedType'] = $type;
    }

    $fieldMask = implode(',', [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.shortFormattedAddress',
        'places.location',
        'places.photos',
        'places.rating',
        'places.userRatingCount',
        'places.priceLevel',
        'places.priceRange.startPrice',
        'places.priceRange.endPrice',
        'places.types',
        'places.editorialSummary',
    ]);

    $response = callPlacesJson('/places:searchText', $apiKey, 'POST', $body, $fieldMask);
    $results = convertPlacesToLegacyResults($response['places'] ?? []);

    echo json_encode([
        'data' => [
            'results' => $results,
        ],
    ]);
}

function handleNearbySearch(string $apiKey): void
{
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    if ($lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['error' => 'lat and lng are required']);
        return;
    }
    $radius = isset($_GET['radius']) ? (float)$_GET['radius'] : 5000;
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $type = trim((string)($_GET['type'] ?? ''));
    $language = trim((string)($_GET['language'] ?? 'en'));

    $body = [
        'languageCode' => $language !== '' ? $language : 'en',
        'locationRestriction' => [
            'circle' => [
                'center' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
                ],
                'radius' => max($radius, 100),
            ],
        ],
        'maxResultCount' => 12,
    ];
    if ($keyword !== '') {
        $body['searchTerm'] = $keyword;
    }
    if ($type !== '') {
        $body['includedTypes'] = [$type];
    }

    $fieldMask = implode(',', [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.shortFormattedAddress',
        'places.location',
        'places.photos',
        'places.rating',
        'places.userRatingCount',
        'places.priceLevel',
        'places.priceRange.startPrice',
        'places.priceRange.endPrice',
        'places.types',
    ]);

    $response = callPlacesJson('/places:searchNearby', $apiKey, 'POST', $body, $fieldMask);
    $results = convertPlacesToLegacyResults($response['places'] ?? []);

    echo json_encode([
        'data' => [
            'results' => $results,
        ],
    ]);
}

function handlePhoto(string $apiKey): void
{
    $photoName = trim((string)($_GET['name'] ?? ''));
    $legacyRef = trim((string)($_GET['photoRef'] ?? $_GET['photoref'] ?? ''));
    $maxWidth = isset($_GET['maxwidth']) ? (int)$_GET['maxwidth'] : (isset($_GET['maxwidthpx']) ? (int)$_GET['maxwidthpx'] : 800);
    $maxHeight = isset($_GET['maxheight']) ? (int)$_GET['maxheight'] : (isset($_GET['maxheightpx']) ? (int)$_GET['maxheightpx'] : null);

    if ($photoName !== '') {
        $params = ['maxWidthPx' => $maxWidth];
        if ($maxHeight) {
            $params['maxHeightPx'] = $maxHeight;
        }
        $media = callPlacesMedia($photoName, $apiKey, $params);
        http_response_code($media['status']);
        header('Content-Type: ' . ($media['contentType'] ?? 'image/jpeg'));
        echo $media['body'];
        return;
    }

    if ($legacyRef === '') {
        http_response_code(400);
        echo json_encode(['error' => 'name or photoRef is required']);
        return;
    }

    $params = [
        'photoreference' => $legacyRef,
        'key' => $apiKey,
        'maxwidth' => $maxWidth,
    ];
    if ($maxHeight) {
        $params['maxheight'] = $maxHeight;
    }
    $url = LEGACY_PLACES_BASE . '/photo?' . http_build_query($params);
    $image = proxyGetRaw($url);
    http_response_code($image['status']);
    header('Content-Type: ' . ($image['contentType'] ?? 'image/jpeg'));
    echo $image['body'];
}

function handleReverseGeocode(): void
{
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
    if ($lat === null || $lng === null) {
        http_response_code(400);
        echo json_encode(['error' => 'lat and lng are required']);
        return;
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json';
    $params = [
        'latlng' => sprintf('%s,%s', $lat, $lng),
        'language' => $_GET['language'] ?? 'en',
        'region' => 'MY',
        'result_type' => 'locality|political',
        'key' => resolveApiKey('google', 'geocode', 'GOOGLE_MAPS_GEOCODE_KEY') ?: resolveApiKey('google', 'places', 'GOOGLE_PLACES_API_KEY'),
    ];

    $response = proxyGet($url, $params);
    http_response_code($response['status']);
    echo json_encode([
        'data' => $response['body'],
    ]);
}

function callPlacesJson(
    string $path,
    string $apiKey,
    string $method = 'GET',
    ?array $body = null,
    string $fieldMask = '',
    string $languageCode = ''
): array {
    $url = PLACES_V1_BASE . $path;
    $headers = [
        'Content-Type: application/json',
        'X-Goog-Api-Key: ' . $apiKey,
    ];
    if ($fieldMask !== '') {
        $headers[] = 'X-Goog-FieldMask: ' . $fieldMask;
    }
    if ($languageCode !== '') {
        $headers[] = 'Accept-Language: ' . $languageCode;
    }
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException($error ?: 'Places API request failed');
    }
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from Places API');
    }
    if ($status >= 400) {
        $message = $decoded['error']['message'] ?? 'Places API error';
        throw new RuntimeException($message, $status);
    }
    return $decoded;
}

function callPlacesMedia(string $photoName, string $apiKey, array $params = []): array
{
    $path = '/' . ltrim($photoName, '/');
    $url = PLACES_V1_BASE . $path . '/media';
    $headers = [
        'X-Goog-Api-Key: ' . $apiKey,
        'X-Goog-FieldMask: *',
    ];
    $queryParams = $params;
    $queryParams['key'] = $apiKey;
    $query = http_build_query($queryParams);
    if ($query !== '') {
        $url .= '?' . $query;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException($error ?: 'Photo request failed');
    }
    $headersRaw = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $contentType = null;
    foreach (explode("\r\n", $headersRaw) as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            break;
        }
    }
    if ($contentType !== null && stripos($contentType, 'application/json') === 0) {
        $decoded = json_decode($body, true);
        if (isset($decoded['photoUri'])) {
            $media = proxyGetRaw($decoded['photoUri']);
            return [
                'status' => $media['status'],
                'contentType' => $media['contentType'],
                'body' => $media['body'],
            ];
        }
    }
    return [
        'status' => $status ?: 200,
        'contentType' => $contentType,
        'body' => $body,
    ];
}

function convertSuggestionToPrediction(array $suggestion): ?array
{
    $prediction = $suggestion['placePrediction'] ?? null;
    if (!$prediction) {
        return null;
    }
    $structured = $prediction['structuredFormat'] ?? [];
    $mainText = extractLocalizedText($structured['mainText'] ?? ($prediction['text']['text'] ?? ''));
    $secondaryText = extractLocalizedText($structured['secondaryText'] ?? '');
    $description = trim($mainText . ' ' . $secondaryText);

    return [
        'description' => $description,
        'place_id' => $prediction['placeId'] ?? '',
        'structured_formatting' => [
            'main_text' => $mainText,
            'secondary_text' => $secondaryText,
        ],
        'distance_meters' => $prediction['distanceMeters'] ?? null,
        'types' => $prediction['types'] ?? [],
    ];
}

function convertPlacesToLegacyResults(array $places): array
{
    return array_values(
        array_filter(
            array_map('convertPlaceToLegacy', $places)
        )
    );
}

function convertPlaceToLegacy(array $place): ?array
{
    if (empty($place['id'])) {
        return null;
    }
    $geometry = null;
    if (isset($place['location']['latitude'], $place['location']['longitude'])) {
        $geometry = [
            'location' => [
                'lat' => $place['location']['latitude'],
                'lng' => $place['location']['longitude'],
            ],
        ];
    }
    $photos = [];
    if (!empty($place['photos']) && is_array($place['photos'])) {
        foreach ($place['photos'] as $photo) {
            if (!isset($photo['name'])) {
                continue;
            }
            $photos[] = [
                'photo_reference' => $photo['name'],
                'width' => $photo['widthPx'] ?? null,
                'height' => $photo['heightPx'] ?? null,
            ];
        }
    }
    $priceRange = buildPriceRangeSummary($place['priceRange'] ?? null);
    return [
        'name' => $place['displayName']['text'] ?? ($place['displayName'] ?? ''),
        'formatted_address' => $place['formattedAddress'] ?? '',
        'vicinity' => $place['shortFormattedAddress'] ?? '',
        'place_id' => $place['id'],
        'geometry' => $geometry,
        'photos' => $photos,
        'rating' => $place['rating'] ?? null,
        'user_ratings_total' => $place['userRatingCount'] ?? null,
        'price_level' => mapPriceLevel($place['priceLevel'] ?? null),
        'price_text' => $priceRange['text'] ?? null,
        'price_range' => $priceRange ? [
            'start' => $priceRange['start_value'],
            'end' => $priceRange['end_value'],
            'currency' => $priceRange['currency'],
        ] : null,
        'types' => $place['types'] ?? [],
        'editorial_summary' => $place['editorialSummary']['text'] ?? null,
        'international_phone_number' => $place['internationalPhoneNumber'] ?? null,
        'website' => $place['websiteUri'] ?? null,
        'google_maps_uri' => $place['googleMapsUri'] ?? null,
        'regular_opening_hours' => $place['regularOpeningHours'] ?? null,
    ];
}

function mapPriceLevel(?string $level): ?int
{
    static $map = [
        'PRICE_LEVEL_FREE' => 0,
        'PRICE_LEVEL_INEXPENSIVE' => 1,
        'PRICE_LEVEL_MODERATE' => 2,
        'PRICE_LEVEL_EXPENSIVE' => 3,
        'PRICE_LEVEL_VERY_EXPENSIVE' => 4,
    ];
    if ($level === null || $level === '') {
        return null;
    }
    return $map[$level] ?? null;
}

function buildPriceRangeSummary($priceRange): ?array
{
    if (empty($priceRange) || !is_array($priceRange)) {
        return null;
    }
    $start = extractMoneyValue($priceRange['startPrice'] ?? null);
    $end = extractMoneyValue($priceRange['endPrice'] ?? null);
    if (!$start && !$end) {
        return null;
    }
    $currency = $start['currency'] ?? $end['currency'] ?? 'MYR';
    $text = null;
    if ($start && $end) {
        $text = formatMoneyDisplay($start['value'], $currency) . ' – ' . formatMoneyDisplay($end['value'], $currency) . ' / night';
    } elseif ($start) {
        $text = formatMoneyDisplay($start['value'], $currency) . ' / night';
    } elseif ($end) {
        $text = formatMoneyDisplay($end['value'], $currency) . ' / night';
    }
    return [
        'text' => $text,
        'start_value' => $start['value'] ?? null,
        'end_value' => $end['value'] ?? null,
        'currency' => $currency,
    ];
}

function extractMoneyValue($money): ?array
{
    if (!is_array($money)) {
        return null;
    }
    $units = isset($money['units']) ? (float)$money['units'] : 0.0;
    $nanos = isset($money['nanos']) ? ((float)$money['nanos']) / 1_000_000_000 : 0.0;
    $value = $units + $nanos;
    if (!is_finite($value) || $value <= 0) {
        return null;
    }
    $currency = isset($money['currencyCode']) && is_string($money['currencyCode'])
        ? strtoupper(trim($money['currencyCode']))
        : 'MYR';
    return [
        'value' => $value,
        'currency' => $currency,
    ];
}

function formatMoneyDisplay(float $amount, string $currency): string
{
    $prefix = strtoupper($currency) === 'MYR' ? 'RM' : strtoupper($currency);
    return $prefix . ' ' . number_format($amount, 0);
}

function proxyGet(string $url, array $params): array
{
    $finalUrl = $url . '?' . http_build_query($params);
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $finalUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false) {
        return [
            'status' => 502,
            'body' => ['error' => 'Failed contacting Google API', 'detail' => $error],
        ];
    }
    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 502,
            'body' => ['error' => 'Invalid JSON response', 'raw' => $body],
        ];
    }
    return [
        'status' => $status ?: 200,
        'body' => $decoded,
    ];
}

function proxyGetRaw(string $url): array
{
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    if ($response === false) {
        return [
            'status' => 502,
            'body' => $error ?: 'Unknown cURL error',
        ];
    }
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $contentType = null;
    foreach (explode("\r\n", $headers) as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') === 0) {
            $contentType = trim(substr($headerLine, strlen('Content-Type:')));
            break;
        }
    }
    return [
        'status' => $status ?: 200,
        'body' => $body,
        'contentType' => $contentType,
    ];
}

function extractLocalizedText($value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (is_array($value)) {
        if (isset($value['text']) && is_string($value['text'])) {
            return $value['text'];
        }
        if (isset($value['value']) && is_string($value['value'])) {
            return $value['value'];
        }
    }
    if (is_object($value) && isset($value->text) && is_string($value->text)) {
        return $value->text;
    }
    return '';
}
