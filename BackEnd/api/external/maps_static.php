<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/api_keys.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$apiKey = resolveApiKey('google', 'maps_js', 'GOOGLE_MAPS_JS_KEY');
if ($apiKey === '') {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Google Maps key missing']);
    exit;
}

$center = trim((string)($_GET['center'] ?? '3.1390,101.6869'));
$zoom = (int)($_GET['zoom'] ?? 5);
$size = trim((string)($_GET['size'] ?? '640x640'));
$markersParam = $_GET['markers'] ?? '';
$maptype = $_GET['maptype'] ?? 'roadmap';

$baseUrl = 'https://maps.googleapis.com/maps/api/staticmap';
$query = [
    'center' => $center,
    'zoom' => $zoom,
    'size' => $size,
    'region' => 'MY',
    'maptype' => $maptype,
    'scale' => 2,
    'key' => $apiKey,
];

$markers = [];
if (is_array($markersParam)) {
    $markers = $markersParam;
} elseif (is_string($markersParam) && $markersParam !== '') {
    $markers = explode('|', $markersParam);
}

$markerEntries = [];
foreach ($markers as $marker) {
    $marker = trim((string)$marker);
    if ($marker === '') {
        continue;
    }
    $markerEntries[] = 'color:0x1d4ed8ff|' . $marker;
}

$queryString = http_build_query($query);
if ($markerEntries) {
    foreach ($markerEntries as $entry) {
        $queryString .= '&markers=' . urlencode($entry);
    }
}

$url = $baseUrl . '?' . $queryString;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$imageData = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/png';
$error = curl_error($ch);
curl_close($ch);

if ($imageData === false || $status >= 400) {
    header('Content-Type: application/json');
    http_response_code($status ?: 502);
    echo json_encode(['error' => 'Failed to fetch static map', 'detail' => $error]);
    exit;
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=300');
echo $imageData;
