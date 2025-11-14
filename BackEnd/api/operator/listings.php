<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/listing_history.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '[]', true);

if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid JSON payload']);
  exit;
}

try {
  /** @var PDO $pdo */
  $pdo = require __DIR__ . '/../../config/db.php';
  ensureVisibilityStateColumn($pdo);
  ensureListingRemovalHistoryTable($pdo);
  ensureListingUpdatedAtColumn($pdo);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable']);
  exit;
}

function respond(int $status, array $body): void
{
  http_response_code($status);
  echo json_encode($body);
  exit;
}

function formatDateTime(?string $value): ?string
{
  if ($value === null || $value === '') {
    return null;
  }

  try {
    $tz = resolveAppTimezone();
    $dt = new DateTimeImmutable($value, $tz);
    return $dt->setTimezone($tz)->format(DateTimeInterface::ATOM);
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

  return $statusLower === 'hidden' ? 'Hidden' : 'Hidden';
}

function resolveAppTimezone(): DateTimeZone
{
  static $timezone = null;
  if ($timezone instanceof DateTimeZone) {
    return $timezone;
  }

  $configured = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? '';
  $name = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'Asia/Kuala_Lumpur';

  try {
    $timezone = new DateTimeZone($name);
  } catch (Throwable) {
    $timezone = new DateTimeZone('UTC');
  }

  return $timezone;
}

function currentAppDate(): string
{
  $timezone = resolveAppTimezone();
  return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
}
function normalisePriceRangeValue($value): ?string
{
  if (is_array($value)) {
    $min = $value['min'] ?? $value[0] ?? null;
    $max = $value['max'] ?? $value[1] ?? null;
  } elseif (is_object($value)) {
    $min = $value->min ?? null;
    $max = $value->max ?? null;
  } elseif (is_string($value)) {
    $trim = trim($value);
    return $trim === '' ? null : $trim;
  } else {
    return null;
  }

  if (!is_numeric($min) || !is_numeric($max)) {
    return null;
  }

  $minValue = max(0, (float) $min);
  $maxValue = max($minValue, (float) $max);

  $formatCurrency = static function (float $amount): string {
    if (abs($amount - round($amount)) < 0.01) {
      return number_format(round($amount), 0);
    }
    return number_format($amount, 2);
  };

  return sprintf('RM %s - RM %s', $formatCurrency($minValue), $formatCurrency($maxValue));
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
      // Column addition failed; follow-up queries will surface the problem.
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
    // Normalisation is best-effort; ignore failures.
  }
}

function ensureListingUpdatedAtColumn(PDO $pdo): void
{
  static $ensured = false;
  if ($ensured) {
    return;
  }

  try {
    $pdo->query('SELECT updatedAt FROM BusinessListing LIMIT 1');
    $ensured = true;
    return;
  } catch (Throwable) {
    // column missing
  }

  try {
    $pdo->exec(
      "ALTER TABLE BusinessListing
       ADD COLUMN updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );
  } catch (Throwable) {
    // ignore if unable to add
  }

  $ensured = true;
}

function fetchListingSnapshot(PDO $pdo, int $operatorId, int $listingId): ?array
{
  $stmt = $pdo->prepare(
    'SELECT bl.*, cat.categoryName
     FROM BusinessListing bl
     LEFT JOIN ListingCategory cat ON cat.categoryID = bl.categoryID
     WHERE bl.listingID = :listingId AND bl.operatorID = :operatorId
     LIMIT 1'
  );
  $stmt->execute([':listingId' => $listingId, ':operatorId' => $operatorId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    return null;
  }

  return [
    'listingID' => (int) $row['listingID'],
    'operatorID' => (int) $row['operatorID'],
    'businessName' => $row['businessName'],
    'categoryName' => $row['categoryName'],
    'location' => $row['location'],
    'priceRange' => $row['priceRange'],
    'status' => $row['status'],
    'visibilityState' => $row['visibilityState'],
    'description' => $row['description'],
    'submittedDate' => $row['submittedDate'],
  ];
}

function fetchListingImagesForHistory(PDO $pdo, int $listingId): array
{
  $stmt = $pdo->prepare(
    'SELECT imageID, imageURL, caption, uploadedDate
     FROM ListingImage
     WHERE listingID = :listingId
     ORDER BY uploadedDate DESC, imageID DESC'
  );
  $stmt->execute([':listingId' => $listingId]);

  $images = [];
  while ($row = $stmt->fetch()) {
    $images[] = [
      'id' => isset($row['imageID']) ? (int) $row['imageID'] : null,
      'url' => $row['imageURL'] ?? null,
      'caption' => $row['caption'] ?? null,
      'uploadedDate' => $row['uploadedDate'] ?? null,
    ];
  }

  return $images;
}

function fetchOperator(PDO $pdo, int $operatorId): ?array
{
  $stmt = $pdo->prepare(
    'SELECT operatorID, username, email, fullName, contactNumber, businessType
     FROM TourismOperator
     WHERE operatorID = :id
     LIMIT 1'
  );
  $stmt->execute([':id' => $operatorId]);
  $row = $stmt->fetch();

  if (!$row) {
    return null;
  }

  return [
    'id' => (int) $row['operatorID'],
    'username' => $row['username'],
    'email' => $row['email'],
    'fullName' => $row['fullName'],
    'contactNumber' => $row['contactNumber'],
    'businessType' => $row['businessType'],
  ];
}

function resolveCategoryId(PDO $pdo, string $categoryName): int
{
  $name = trim($categoryName) !== '' ? trim($categoryName) : 'Others';

  $stmt = $pdo->prepare('SELECT categoryID FROM ListingCategory WHERE categoryName = :name LIMIT 1');
  $stmt->execute([':name' => $name]);
  $row = $stmt->fetch();

  if ($row) {
    return (int) $row['categoryID'];
  }

  $insert = $pdo->prepare(
    'INSERT INTO ListingCategory (categoryName, description) VALUES (:name, :description)'
  );
  $insert->execute([
    ':name' => $name,
    ':description' => 'Generated automatically from operator submission.',
  ]);

  return (int) $pdo->lastInsertId();
}

function fetchListing(PDO $pdo, int $operatorId, int $listingId, array $operatorProfile): ?array
{
  $stmt = $pdo->prepare(
    "SELECT
      bl.listingID,
      bl.businessName,
      bl.description,
      bl.location,
      bl.status,
      bl.visibilityState,
      bl.submittedDate,
      bl.updatedAt,
      bl.priceRange,
      lc.categoryName
    FROM BusinessListing bl
    LEFT JOIN ListingCategory lc ON bl.categoryID = lc.categoryID
    WHERE bl.listingID = :listingId
      AND bl.operatorID = :operatorId
    LIMIT 1"
  );
  $stmt->execute([':listingId' => $listingId, ':operatorId' => $operatorId]);
  $row = $stmt->fetch();

  if (!$row) {
    return null;
  }

  return [
    'id' => sprintf('LST-%04d', (int) $row['listingID']),
    'listingId' => (int) $row['listingID'],
    'name' => $row['businessName'],
    'category' => $row['categoryName'] ?? 'Uncategorised',
    'type' => $operatorProfile['businessType'] ?? 'Business',
    'status' => $row['status'] ?? 'Pending Review',
    'visibility' => normaliseVisibility($row['status'] ?? null, $row['visibilityState'] ?? null),
    'lastUpdated' => formatDateTime($row['updatedAt'] ?? $row['submittedDate']),
    'submittedDate' => formatDateTime($row['submittedDate']),
    'contact' => [
      'phone' => $operatorProfile['contactNumber'] ?? '',
      'email' => $operatorProfile['email'] ?? '',
    ],
    'address' => $row['location'],
    'highlight' => $row['description'] ?? '',
    'reviewNotes' => 'Awaiting administrator verification.',
  ];
}

function updateOperatorContact(PDO $pdo, int $operatorId, ?string $phone, ?string $email): void
{
  $fields = [];
  $params = [':operatorId' => $operatorId];

  if ($phone !== null && $phone !== '') {
    $fields[] = 'contactNumber = :contactNumber';
    $params[':contactNumber'] = $phone;
  }

  if ($email !== null && $email !== '') {
    $fields[] = 'email = :email';
    $params[':email'] = $email;
  }

  if (!$fields) {
    return;
  }

  $sql = 'UPDATE TourismOperator SET ' . implode(', ', $fields) . ' WHERE operatorID = :operatorId';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
}

function ensureUniqueListingName(PDO $pdo, string $businessName, int $operatorId, ?int $listingIdToIgnore = null): void
{
  $stmt = $pdo->prepare(
    'SELECT listingID, operatorID FROM BusinessListing WHERE LOWER(businessName) = LOWER(:businessName) LIMIT 1'
  );
  $stmt->execute([':businessName' => $businessName]);
  $existing = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$existing) {
    return;
  }

  $existingListingId = (int) ($existing['listingID'] ?? 0);
  if ($listingIdToIgnore !== null && $existingListingId === $listingIdToIgnore) {
    return;
  }

  $existingOperatorId = (int) ($existing['operatorID'] ?? 0);
  if ($existingOperatorId === $operatorId) {
    respond(409, ['error' => 'You already have a listing with this business name. Please enter a different name.']);
  }

  respond(409, ['error' => 'Another operator already uses this business name. Please enter a different name.']);
}

function handleCreate(PDO $pdo, array $payload): void
{
  $operatorId = (int) ($payload['operatorId'] ?? 0);
  $name = trim((string) ($payload['name'] ?? ''));
  $category = trim((string) ($payload['category'] ?? ''));
  $address = trim((string) ($payload['address'] ?? ''));
  $description = trim((string) ($payload['description'] ?? ''));
  $phone = trim((string) ($payload['phone'] ?? ''));
  $email = trim((string) ($payload['email'] ?? ''));
  $priceRange = normalisePriceRangeValue($payload['priceRange'] ?? null);

  if ($operatorId <= 0 || $name === '' || $category === '' || $address === '' || $email === '' || $phone === '' || $priceRange === null) {
    respond(400, ['error' => 'operatorId, name, category, address, email, phone, and priceRange are required.']);
  }

  $operatorProfile = fetchOperator($pdo, $operatorId);
  if (!$operatorProfile) {
    respond(404, ['error' => 'Operator not found.']);
  }

  ensureUniqueListingName($pdo, $name, $operatorId);

  $pdo->beginTransaction();

  try {
    updateOperatorContact($pdo, $operatorId, $phone, $email);
    $categoryId = resolveCategoryId($pdo, $category);

    $stmt = $pdo->prepare(
      'INSERT INTO BusinessListing (operatorID, businessName, description, categoryID, location, priceRange, visibilityState, status, submittedDate)
       VALUES (:operatorId, :businessName, :description, :categoryId, :location, :priceRange, :visibilityState, :status, :submittedDate)'
    );

    $stmt->execute([
      ':operatorId' => $operatorId,
      ':businessName' => $name,
      ':description' => $description,
      ':categoryId' => $categoryId,
      ':location' => $address,
      ':priceRange' => $priceRange,
      ':visibilityState' => 'Hidden',
      ':status' => 'Pending Review',
      ':submittedDate' => currentAppDate(),
    ]);

    $listingId = (int) $pdo->lastInsertId();
    $pdo->commit();

    $operatorProfile['contactNumber'] = $phone;
    $operatorProfile['email'] = $email;

    $listing = fetchListing($pdo, $operatorId, $listingId, $operatorProfile);
    if (!$listing) {
      respond(500, ['error' => 'Failed to load created listing.']);
    }

    respond(200, [
      'ok' => true,
      'message' => 'Listing submitted successfully.',
      'listing' => $listing,
      'operator' => $operatorProfile,
    ]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    respond(500, ['error' => 'Failed to create listing.']);
  }
}

function handleUpdate(PDO $pdo, array $payload): void
{
  $operatorId = (int) ($payload['operatorId'] ?? 0);
  $listingId = (int) ($payload['listingId'] ?? 0);

  if ($operatorId <= 0 || $listingId <= 0) {
    respond(400, ['error' => 'operatorId and listingId are required.']);
  }

  $operatorProfile = fetchOperator($pdo, $operatorId);
  if (!$operatorProfile) {
    respond(404, ['error' => 'Operator not found.']);
  }

  $stmt = $pdo->prepare(
    'SELECT listingID FROM BusinessListing WHERE listingID = :listingId AND operatorID = :operatorId LIMIT 1'
  );
  $stmt->execute([':listingId' => $listingId, ':operatorId' => $operatorId]);

  if (!$stmt->fetch()) {
    respond(404, ['error' => 'Listing not found for this operator.']);
  }

  $fields = [];
  $params = [
    ':listingId' => $listingId,
    ':operatorId' => $operatorId,
  ];

  if (isset($payload['name'])) {
    $fields[] = 'businessName = :businessName';
    $newName = trim((string) $payload['name']);
    if ($newName === '') {
      respond(400, ['error' => 'Business name cannot be blank.']);
    }
    ensureUniqueListingName($pdo, $newName, $operatorId, $listingId);
    $params[':businessName'] = $newName;
  }

  if (isset($payload['description'])) {
    $fields[] = 'description = :description';
    $params[':description'] = trim((string) $payload['description']);
  }

  if (isset($payload['address'])) {
    $fields[] = 'location = :location';
    $params[':location'] = trim((string) $payload['address']);
  }

  if (isset($payload['status'])) {
    $trimmedStatus = trim((string) $payload['status']);
    if ($trimmedStatus !== '') {
      $allowedStatuses = ['Pending Review', 'Approved', 'Rejected', 'Active'];
      if (in_array($trimmedStatus, $allowedStatuses, true)) {
        $fields[] = 'status = :status';
        $params[':status'] = $trimmedStatus;
      }
    }
  }

  if (isset($payload['visibility'])) {
    $visibility = strtolower(trim((string) $payload['visibility'])) === 'visible' ? 'Visible' : 'Hidden';
    $fields[] = 'visibilityState = :visibilityState';
    $params[':visibilityState'] = $visibility;
  }

  if (isset($payload['category'])) {
    $categoryId = resolveCategoryId($pdo, (string) $payload['category']);
    $fields[] = 'categoryID = :categoryId';
    $params[':categoryId'] = $categoryId;
  }

  if (array_key_exists('priceRange', $payload)) {
    $priceRangeValue = normalisePriceRangeValue($payload['priceRange']);
    if ($priceRangeValue === null) {
      respond(400, ['error' => 'Invalid price range provided.']);
    }
    $fields[] = 'priceRange = :priceRange';
    $params[':priceRange'] = $priceRangeValue;
  }

  if ($fields) {
    $sql = 'UPDATE BusinessListing SET ' . implode(', ', $fields) . ' WHERE listingID = :listingId AND operatorID = :operatorId';
    $updateStmt = $pdo->prepare($sql);
    $updateStmt->execute($params);
  }

  updateOperatorContact(
    $pdo,
    $operatorId,
    isset($payload['phone']) ? trim((string) $payload['phone']) : null,
    isset($payload['email']) ? trim((string) $payload['email']) : null,
  );

  $updatedOperator = fetchOperator($pdo, $operatorId) ?? $operatorProfile;

  $listing = fetchListing($pdo, $operatorId, $listingId, $updatedOperator);
  if (!$listing) {
    respond(500, ['error' => 'Failed to load updated listing.']);
  }

  $message = 'Listing updated successfully.';
  if (isset($payload['visibility'])) {
    $message = 'Listing visibility updated.';
  }

  respond(200, [
    'ok' => true,
    'message' => $message,
    'listing' => $listing,
    'operator' => $updatedOperator,
  ]);
}

function handleDelete(PDO $pdo, array $payload): void
{
  $operatorId = (int) ($payload['operatorId'] ?? 0);
  $listingId = (int) ($payload['listingId'] ?? 0);

  if ($operatorId <= 0 || $listingId <= 0) {
    respond(400, ['error' => 'operatorId and listingId are required.']);
  }

  $snapshot = fetchListingSnapshot($pdo, $operatorId, $listingId);
  $images = fetchListingImagesForHistory($pdo, $listingId);

  $stmt = $pdo->prepare(
    'DELETE FROM BusinessListing WHERE listingID = :listingId AND operatorID = :operatorId'
  );
  $stmt->execute([':listingId' => $listingId, ':operatorId' => $operatorId]);

  if ($stmt->rowCount() === 0) {
    respond(404, ['error' => 'Listing not found for this operator.']);
  }

  if ($snapshot) {
    archiveListingRemoval($pdo, $snapshot, null, 'Removed by operator', $images);
  }

  respond(200, ['ok' => true, 'deleted' => true, 'message' => 'Listing removed from the platform.']);
}

switch ($method) {
  case 'POST':
    handleCreate($pdo, $payload);
    break;
  case 'PUT':
  case 'PATCH':
    handleUpdate($pdo, $payload);
    break;
  case 'DELETE':
    handleDelete($pdo, $payload);
    break;
}
