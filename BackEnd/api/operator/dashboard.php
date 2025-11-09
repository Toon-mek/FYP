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
  ensureVisibilityStateColumn($pdo);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable']);
  exit;
}

function resolveAppTimezone(): DateTimeZone
{
  static $timezone = null;
  if ($timezone instanceof DateTimeZone) {
    return $timezone;
  }

  $name = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? 'Asia/Kuala_Lumpur';
  try {
    $timezone = new DateTimeZone($name);
  } catch (Throwable) {
    $timezone = new DateTimeZone('UTC');
  }

  return $timezone;
}

function formatDateTime(?string $value): ?string
{
  if ($value === null || $value === '') {
    return null;
  }

  try {
    $appTimezone = resolveAppTimezone();
    $dt = new DateTimeImmutable($value, $appTimezone);
    return $dt->setTimezone($appTimezone)->format(DateTimeInterface::ATOM);
  } catch (Throwable) {
    return null;
  }
}

function normaliseVisibility(?string $status, ?string $visibilityState = null): string
{
  if ($visibilityState !== null && $visibilityState !== '') {
    $state = strtolower($visibilityState);
    if ($state === 'visible') {
      return 'Visible';
    }
    if ($state === 'hidden') {
      return 'Hidden';
    }
  }

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

function encodePathSegments(string $path): string
{
  if ($path === '') {
    return '';
  }

  $segments = array_map(
    static fn(string $segment): string => rawurlencode($segment),
    array_filter(
      explode('/', str_replace('\\', '/', $path)),
      static fn(string $segment): bool => $segment !== ''
    )
  );

  return implode('/', $segments);
}

function buildAssetUrl(string $relativePath): string
{
  if ($relativePath === '') {
    return $relativePath;
  }

  if (preg_match('#^https?://#i', $relativePath)) {
    return $relativePath;
  }

  $relativePath = trim($relativePath, '/');
  $encodedRelative = encodePathSegments($relativePath);

  $configuredBase = $_ENV['PUBLIC_ASSETS_BASE_URL'] ?? null;
  if (is_string($configuredBase) && $configuredBase !== '') {
    return rtrim($configuredBase, '/') . '/' . $encodedRelative;
  }

  $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
  $scriptDir = $scriptName !== '' ? str_replace('\\', '/', dirname($scriptName)) : '';
  if ($scriptDir === '.' || $scriptDir === '/') {
    $scriptDir = '';
  }
  if ($scriptDir !== '' && $scriptDir[0] !== '/') {
    $scriptDir = '/' . $scriptDir;
  }

  $rootPath = $scriptDir !== '' ? preg_replace('#/api(?:/.*)?$#', '', $scriptDir) : '';
  if ($rootPath === '/') {
    $rootPath = '';
  }

  $publicPath = ($rootPath !== '' ? rtrim($rootPath, '/') : '') . '/public_assets';
  $encodedPublic = encodePathSegments(trim($publicPath, '/'));
  $fullPath = '/' . ltrim($encodedPublic . '/' . $encodedRelative, '/');

  $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';

  if ($host !== '') {
    return sprintf('%s://%s%s', $scheme, $host, $fullPath);
  }

  return $fullPath;
}

function resolveAssetFilesystemPath(?string $relativePath): ?string
{
  if ($relativePath === null || $relativePath === '') {
    return null;
  }

  $rootDir = realpath(__DIR__ . '/../../public_assets');
  if ($rootDir === false) {
    return null;
  }

  $normalised = trim(str_replace('\\', '/', $relativePath), '/');
  if ($normalised === '') {
    return null;
  }

  $fullPath = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalised);
  return is_file($fullPath) ? $fullPath : null;
}

function deriveMediaTimestamp(?string $dbValue, ?string $relativePath): ?string
{
  $hasTimeComponent = is_string($dbValue) && preg_match('/\d{2}:\d{2}:\d{2}/', $dbValue);
  if ($hasTimeComponent) {
    return formatDateTime($dbValue);
  }

  $formatted = formatDateTime($dbValue);
  $absolutePath = resolveAssetFilesystemPath($relativePath);

  if ($absolutePath) {
    $mtime = @filemtime($absolutePath);
    if ($mtime !== false) {
      $timezone = resolveAppTimezone();
      return (new DateTimeImmutable('@' . $mtime))->setTimezone($timezone)->format(DateTimeInterface::ATOM);
    }
  }

  return $formatted;
}

function ensureVisibilityStateColumn(PDO $pdo): void
{
  static $checked = false;
  if ($checked) {
    return;
  }

  $checked = true;

  try {
    $pdo->query('SELECT visibilityState FROM BusinessListing LIMIT 1');
  } catch (Throwable $e) {
    try {
      $pdo->exec("ALTER TABLE BusinessListing ADD COLUMN visibilityState VARCHAR(20) NOT NULL DEFAULT 'Hidden'");
      $pdo->exec(
        "UPDATE BusinessListing
         SET visibilityState = CASE
           WHEN status IN ('Approved', 'Active', 'Published') THEN 'Visible'
           ELSE 'Hidden'
         END"
      );
    } catch (Throwable) {
      // Column addition failed; follow-up queries will surface the issue.
    }
  }

  try {
    $pdo->exec(
      "UPDATE BusinessListing bl
       SET status = CASE
         WHEN bl.status IN ('Visible', 'Active') THEN 'Approved'
         WHEN bl.status = 'Hidden' AND EXISTS (
           SELECT 1 FROM ListingVerification lv
           WHERE lv.listingID = bl.listingID AND lv.verificationStatus = 'Approved'
         ) THEN 'Approved'
         WHEN bl.status = 'Hidden' THEN 'Pending Review'
         ELSE bl.status
       END"
    );
  } catch (Throwable) {
    // Normalisation attempts should not break the request if they fail.
  }
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
    bl.visibilityState,
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
    'visibility' => normaliseVisibility($row['status'] ?? null, $row['visibilityState'] ?? null),
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
    'lastUpdated' => deriveMediaTimestamp($row['uploadedDate'], $row['imageURL']),
    'fileName' => $fileName,
    'mimeType' => inferMimeType($fileName),
    'fileSize' => null,
    'url' => $row['imageURL'],
    'relativeUrl' => $row['imageURL'],
    'absoluteUrl' => buildAssetUrl((string) $row['imageURL']),
  ];
}

echo json_encode([
  'ok' => true,
  'operator' => $operatorProfile,
  'listings' => $listings,
  'mediaAssets' => $mediaAssets,
]);
