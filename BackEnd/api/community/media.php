<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  http_response_code(405);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$requestedPath = (string) ($_GET['path'] ?? '');
$requestedPath = trim($requestedPath);

if ($requestedPath === '') {
  http_response_code(400);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Missing path parameter']);
  exit;
}

$requestedPath = str_replace(["\0", '\\'], ['', '/'], $requestedPath);

$baseDirectory = realpath(__DIR__ . '/../../public_assets');
if ($baseDirectory === false) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Public assets directory not available']);
  exit;
}

$targetAbsolute = realpath($baseDirectory . DIRECTORY_SEPARATOR . ltrim($requestedPath, '/'));
if ($targetAbsolute === false || strpos($targetAbsolute, $baseDirectory) !== 0) {
  http_response_code(404);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'File not found']);
  exit;
}

$mimeType = mime_content_type($targetAbsolute) ?: 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($targetAbsolute));

readfile($targetAbsolute);
