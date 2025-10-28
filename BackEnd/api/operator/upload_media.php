<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$operatorId = isset($_POST['operatorId']) ? (int) $_POST['operatorId'] : 0;
$listingId = isset($_POST['listingId']) ? (int) $_POST['listingId'] : 0;
$title = trim($_POST['title'] ?? '');
$mediaType = trim($_POST['type'] ?? '');
$isPrimary = filter_var($_POST['isPrimary'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

if ($operatorId <= 0 || $listingId <= 0 || $title === '' || !isset($_FILES['file'])) {
  http_response_code(400);
  echo json_encode(['error' => 'operatorId, listingId, title, and a file upload are required']);
  exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo json_encode(['error' => 'File upload failed']);
  exit;
}

try {
  $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable']);
  exit;
}

// Ensure listing belongs to operator
$listingStmt = $pdo->prepare(
  'SELECT businessName FROM BusinessListing WHERE listingID = :listingId AND operatorID = :operatorId LIMIT 1'
);
$listingStmt->execute([':listingId' => $listingId, ':operatorId' => $operatorId]);
$listing = $listingStmt->fetch();

if (!$listing) {
  http_response_code(403);
  echo json_encode(['error' => 'Listing not found for this operator']);
  exit;
}

$uploadDir = realpath(__DIR__ . '/../../public_assets');
if ($uploadDir === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Upload directory unavailable']);
  exit;
}

$mediaDir = $uploadDir . DIRECTORY_SEPARATOR . 'operator_media';
if (!is_dir($mediaDir)) {
  if (!mkdir($mediaDir, 0755, true) && !is_dir($mediaDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create media directory']);
    exit;
  }
}

$originalName = $file['name'] ?? 'upload.bin';
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$safeExtension = $extension ? preg_replace('/[^a-z0-9]/', '', $extension) : '';
$basename = 'media_' . $operatorId . '_' . uniqid('', true);
$finalName = $basename . ($safeExtension ? '.' . $safeExtension : '');
$targetPath = $mediaDir . DIRECTORY_SEPARATOR . $finalName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
  http_response_code(500);
  echo json_encode(['error' => 'Failed to store uploaded file']);
  exit;
}

$relativePath = 'operator_media/' . $finalName;

$insertStmt = $pdo->prepare(
  'INSERT INTO ListingImage (listingID, imageURL, uploadedDate, caption)
   VALUES (:listingId, :imageURL, :uploadedDate, :caption)'
);
$insertStmt->execute([
  ':listingId' => $listingId,
  ':imageURL' => $relativePath,
  ':uploadedDate' => date('Y-m-d'),
  ':caption' => $title,
]);

$imageId = (int) $pdo->lastInsertId();
$mimeType = $file['type'] ?? null;
$fileSize = isset($file['size']) ? (int) $file['size'] : null;

$asset = [
  'id' => sprintf('MED-%04d', $imageId),
  'mediaId' => $imageId,
  'listingId' => $listingId,
  'listingName' => $listing['businessName'],
  'label' => $title,
  'type' => $mediaType ?: 'Image',
  'status' => 'Published',
  'isPrimary' => $isPrimary,
  'lastUpdated' => date(DateTimeInterface::ATOM),
  'fileName' => $finalName,
  'mimeType' => $mimeType,
  'fileSize' => $fileSize,
  'url' => $relativePath,
];

echo json_encode(['ok' => true, 'asset' => $asset]);
