<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$operatorId = isset($_GET['operatorId']) ? (int) $_GET['operatorId'] : 0;
if ($operatorId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'operatorId query parameter is required']);
  exit;
}

try {
  $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable']);
  exit;
}

function formatDateTime(?string $value): ?string
{
  if ($value === null || $value === '') {
    return null;
  }

  try {
    $dt = new DateTimeImmutable($value);
    return $dt->format(DateTimeInterface::ATOM);
  } catch (Throwable) {
    return null;
  }
}

function normaliseVisibility(?string $status): string
{
  $statusLower = strtolower((string) $status);
  if (in_array($statusLower, ['active', 'approved', 'published'], true)) {
    return 'Visible';
  }

  if ($statusLower === 'pending review') {
    return 'Hidden';
  }

  return 'Hidden';
}

function inferMimeType(?string $fileName): ?string
{
  if (!$fileName) {
    return null;
  }

  $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
  return match ($extension) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    default => null,
  };
}

// Fetch operator profile
$operatorStmt = $pdo->prepare(
  'SELECT operatorID, username, email, fullName, contactNumber, registeredDate, accountStatus, businessType
   FROM TourismOperator
   WHERE operatorID = :id
   LIMIT 1'
);
$operatorStmt->execute([':id' => $operatorId]);
$operatorRow = $operatorStmt->fetch();

if (!$operatorRow) {
  http_response_code(404);
  echo json_encode(['error' => 'Operator not found']);
  exit;
}

$operatorProfile = [
  'id' => (int) $operatorRow['operatorID'],
  'username' => $operatorRow['username'],
  'email' => $operatorRow['email'],
  'fullName' => $operatorRow['fullName'],
  'contactNumber' => $operatorRow['contactNumber'],
  'registeredDate' => formatDateTime($operatorRow['registeredDate']),
  'accountStatus' => $operatorRow['accountStatus'],
  'businessType' => $operatorRow['businessType'],
];

// Fetch listings with latest verification details
$listingSql = "
  SELECT
    bl.listingID,
    bl.businessName,
    bl.description,
    bl.location,
    bl.status,
    bl.submittedDate,
    bl.priceRange,
    lc.categoryName,
    lv.verificationStatus,
    lv.remarks,
    lv.verifiedDate
  FROM BusinessListing bl
  LEFT JOIN ListingCategory lc ON bl.categoryID = lc.categoryID
  LEFT JOIN (
    SELECT lv1.listingID, lv1.verificationStatus, lv1.remarks, lv1.verifiedDate
    FROM ListingVerification lv1
    INNER JOIN (
      SELECT listingID, MAX(verifiedDate) AS latestVerified
      FROM ListingVerification
      GROUP BY listingID
    ) lv2
    ON lv1.listingID = lv2.listingID AND lv1.verifiedDate = lv2.latestVerified
  ) lv ON lv.listingID = bl.listingID
  WHERE bl.operatorID = :operatorId
  ORDER BY bl.submittedDate DESC, bl.listingID DESC
";

$listingStmt = $pdo->prepare($listingSql);
$listingStmt->execute([':operatorId' => $operatorId]);
$listingRows = $listingStmt->fetchAll();

$listings = [];
foreach ($listingRows as $row) {
  $listings[] = [
    'id' => sprintf('LST-%04d', (int) $row['listingID']),
    'listingId' => (int) $row['listingID'],
    'name' => $row['businessName'],
    'category' => $row['categoryName'] ?? 'Uncategorised',
    'type' => $operatorProfile['businessType'] ?? 'Business',
    'status' => $row['status'] ?? 'Pending Review',
    'visibility' => normaliseVisibility($row['status'] ?? null),
    'lastUpdated' => formatDateTime($row['verifiedDate'] ?? $row['submittedDate']),
    'contact' => [
      'phone' => $operatorProfile['contactNumber'] ?? null,
      'email' => $operatorProfile['email'] ?? null,
    ],
    'address' => $row['location'],
    'highlight' => $row['description'] ?? '',
    'reviewNotes' => $row['remarks'] ?: 'Awaiting administrator review.',
    'priceRange' => $row['priceRange'],
  ];
}

// Fetch media assets for the operator's listings
$mediaSql = "
  SELECT
    li.imageID,
    li.listingID,
    li.imageURL,
    li.uploadedDate,
    li.caption,
    bl.businessName
  FROM ListingImage li
  INNER JOIN BusinessListing bl ON bl.listingID = li.listingID
  WHERE bl.operatorID = :operatorId
  ORDER BY li.uploadedDate DESC, li.imageID DESC
";

$mediaStmt = $pdo->prepare($mediaSql);
$mediaStmt->execute([':operatorId' => $operatorId]);
$mediaRows = $mediaStmt->fetchAll();

$mediaAssets = [];
$primaryByListing = [];
foreach ($mediaRows as $row) {
  $fileName = $row['imageURL'] ? basename($row['imageURL']) : null;
  $listingId = (int) $row['listingID'];
  $isPrimary = false;

  if (!isset($primaryByListing[$listingId])) {
    $isPrimary = true;
    $primaryByListing[$listingId] = true;
  }

  $mediaAssets[] = [
    'id' => sprintf('MED-%04d', (int) $row['imageID']),
    'mediaId' => (int) $row['imageID'],
    'listingId' => $listingId,
    'listingName' => $row['businessName'],
    'label' => $row['caption'] ?: ($fileName ?? 'Media asset'),
    'type' => 'Image',
    'status' => 'Published',
    'isPrimary' => $isPrimary,
    'lastUpdated' => formatDateTime($row['uploadedDate']),
    'fileName' => $fileName,
    'mimeType' => inferMimeType($fileName),
    'fileSize' => null,
    'url' => $row['imageURL'],
  ];
}

echo json_encode([
  'ok' => true,
  'operator' => $operatorProfile,
  'listings' => $listings,
  'mediaAssets' => $mediaAssets,
]);
