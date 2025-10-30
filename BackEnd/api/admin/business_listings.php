<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
    ensureVisibilityStateColumn($pdo);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        handleGet($pdo);
        break;
    case 'DELETE':
        handleDelete($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function handleGet(PDO $pdo): void
{
    $listingId = isset($_GET['listingId']) ? (int) $_GET['listingId'] : null;

    if ($listingId !== null && $listingId > 0) {
        $listing = loadListing($pdo, $listingId);
        if ($listing === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Listing not found']);
            return;
        }

        echo json_encode(['listing' => $listing]);
        return;
    }

    $statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : 'all';
    $visibilityFilter = isset($_GET['visibility']) ? (string) $_GET['visibility'] : 'all';
    $categoryFilter = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
    $searchTerm = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

    $where = [];
    $params = [];

    $statuses = normaliseStatusFilter($statusFilter);
    if ($statuses !== null && count($statuses) > 0) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "bl.status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }

    $visibility = normaliseVisibilityFilter($visibilityFilter);
    if ($visibility !== null) {
        $where[] = 'bl.visibilityState = ?';
        $params[] = $visibility;
    }

    if ($categoryFilter !== '') {
        $where[] = 'cat.categoryName = ?';
        $params[] = $categoryFilter;
    }

    if ($searchTerm !== '') {
        $where[] = "(bl.businessName LIKE ? OR op.fullName LIKE ? OR op.email LIKE ? OR bl.location LIKE ?)";
        $like = '%' . $searchTerm . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql = <<<SQL
SELECT
    bl.listingID,
    bl.businessName,
    bl.description,
    bl.status,
    bl.visibilityState,
    bl.submittedDate,
    bl.location,
    bl.priceRange,
    cat.categoryName,
    op.operatorID,
    op.fullName AS operatorName,
    op.email AS operatorEmail,
    op.contactNumber AS operatorPhone,
    op.businessType AS operatorBusinessType,
    lv.verificationStatus,
    lv.remarks AS verificationRemarks,
    lv.verifiedDate,
    lv.adminID,
    adm.fullName AS adminName,
    (
        SELECT COUNT(*) FROM ListingImage li WHERE li.listingID = bl.listingID
    ) AS imageCount
FROM BusinessListing bl
LEFT JOIN ListingCategory cat ON cat.categoryID = bl.categoryID
LEFT JOIN TourismOperator op ON op.operatorID = bl.operatorID
LEFT JOIN (
    SELECT lv1.listingID, lv1.verificationStatus, lv1.remarks, lv1.verifiedDate, lv1.adminID
    FROM ListingVerification lv1
    INNER JOIN (
        SELECT listingID, MAX(verifiedDate) AS latestDate, MAX(verificationID) AS latestId
        FROM ListingVerification
        GROUP BY listingID
    ) latest ON latest.listingID = lv1.listingID
    WHERE lv1.verifiedDate = latest.latestDate
) lv ON lv.listingID = bl.listingID
LEFT JOIN Administrator adm ON adm.adminID = lv.adminID
SQL;

    if ($where) {
        $sql .= "\nWHERE " . implode(' AND ', $where);
    }

    $sql .= "\nORDER BY bl.submittedDate DESC, bl.listingID DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $listings = array_map(static fn(array $row): array => formatListingRow($row), $rows);
    $summary = buildSummary($listings);

    echo json_encode([
        'listings' => $listings,
        'summary' => $summary,
        'filters' => [
            'categories' => array_values(array_unique(array_filter(array_map(
                static fn(array $item): ?string => $item['category'] ?? null,
                $listings
            )))),
        ],
    ]);
}

function handleDelete(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $listingId = isset($payload['listingId']) ? (int) $payload['listingId'] : 0;
    $adminId = isset($payload['adminId']) ? (int) $payload['adminId'] : 0;
    $reason = trim((string) ($payload['reason'] ?? ''));

    if ($listingId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'listingId is required']);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT listingID, operatorID, businessName FROM BusinessListing WHERE listingID = :listingId LIMIT 1'
    );
    $stmt->execute([':listingId' => $listingId]);
    $listing = $stmt->fetch();

    if (!$listing) {
        http_response_code(404);
        echo json_encode(['error' => 'Listing not found']);
        return;
    }

    try {
        $pdo->beginTransaction();

        $delete = $pdo->prepare('DELETE FROM BusinessListing WHERE listingID = :listingId');
        $delete->execute([':listingId' => $listingId]);

        if ($adminId > 0) {
            recordRemovalNotification(
                $pdo,
                (int) $listing['operatorID'],
                (string) $listing['businessName'],
                $reason
            );
        }

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete listing']);
        return;
    }

    echo json_encode([
        'ok' => true,
        'deleted' => true,
        'message' => 'Listing removed from the platform.',
    ]);
}

function normaliseStatusFilter(string $filter): ?array
{
    $filter = strtolower(trim($filter));

    return match ($filter) {
        '', 'all' => null,
        'pending' => ['Pending Review', 'Pending', 'Submitted', 'Under Review'],
        'approved' => ['Approved', 'Active'],
        'rejected' => ['Rejected'],
        'hidden' => ['Hidden'],
        'active' => ['Active', 'Approved'],
        default => null,
    };
}

function normaliseVisibilityFilter(string $filter): ?string
{
    $filter = strtolower(trim($filter));

    return match ($filter) {
        'visible' => 'Visible',
        'hidden' => 'Hidden',
        'all', '' => null,
        default => null,
    };
}

function computeVisibility(?string $status, ?string $visibilityState): string
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
    if (in_array($statusLower, ['approved', 'active', 'published'], true)) {
        return 'Visible';
    }

    return 'Hidden';
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
            // ignore
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
        // ignore
    }
}

function formatDateString(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
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

function formatListingRow(array $row): array
{
    return [
        'id' => (int) $row['listingID'],
        'businessName' => $row['businessName'],
        'description' => $row['description'],
        'status' => $row['status'],
        'visibility' => computeVisibility($row['status'] ?? null, $row['visibilityState'] ?? null),
        'submittedDate' => formatDateString($row['submittedDate']),
        'location' => $row['location'],
        'priceRange' => $row['priceRange'],
        'category' => $row['categoryName'],
        'operator' => [
            'id' => (int) $row['operatorID'],
            'name' => $row['operatorName'],
            'email' => $row['operatorEmail'],
            'phone' => $row['operatorPhone'],
            'businessType' => $row['operatorBusinessType'],
        ],
        'latestVerification' => [
            'status' => $row['verificationStatus'],
            'remarks' => $row['verificationRemarks'],
            'verifiedDate' => formatDateString($row['verifiedDate']),
            'adminId' => $row['adminID'] !== null ? (int) $row['adminID'] : null,
            'adminName' => $row['adminName'],
        ],
        'imageCount' => (int) $row['imageCount'],
    ];
}

function loadListing(PDO $pdo, int $listingId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            bl.listingID,
            bl.businessName,
            bl.description,
            bl.status,
            bl.visibilityState,
            bl.submittedDate,
            bl.location,
            bl.priceRange,
            cat.categoryName,
            cat.description AS categoryDescription,
            op.operatorID,
            op.fullName AS operatorName,
            op.email AS operatorEmail,
            op.contactNumber AS operatorPhone,
            op.businessType AS operatorBusinessType
         FROM BusinessListing bl
         LEFT JOIN ListingCategory cat ON cat.categoryID = bl.categoryID
         JOIN TourismOperator op ON op.operatorID = bl.operatorID
         WHERE bl.listingID = :listingId
         LIMIT 1'
    );
    $stmt->execute([':listingId' => $listingId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $images = fetchImages($pdo, $listingId);
    $tags = fetchListingTags($pdo, $listingId);
    $history = fetchVerificationHistory($pdo, $listingId);

    return [
        'id' => (int) $row['listingID'],
        'businessName' => $row['businessName'],
        'description' => $row['description'],
        'status' => $row['status'],
        'visibility' => computeVisibility($row['status'] ?? null, $row['visibilityState'] ?? null),
        'submittedDate' => formatDateString($row['submittedDate']),
        'location' => $row['location'],
        'priceRange' => $row['priceRange'],
        'category' => [
            'name' => $row['categoryName'],
            'description' => $row['categoryDescription'],
        ],
        'operator' => [
            'id' => (int) $row['operatorID'],
            'name' => $row['operatorName'],
            'email' => $row['operatorEmail'],
            'phone' => $row['operatorPhone'],
            'businessType' => $row['operatorBusinessType'],
        ],
        'images' => $images,
        'tags' => $tags,
        'history' => $history,
    ];
}

function fetchImages(PDO $pdo, int $listingId): array
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
            'id' => (int) $row['imageID'],
            'url' => buildAssetUrl((string) $row['imageURL']),
            'caption' => $row['caption'],
            'uploadedDate' => formatDateString($row['uploadedDate']),
        ];
    }

    return $images;
}

function fetchListingTags(PDO $pdo, int $listingId): array
{
    $stmt = $pdo->prepare(
        'SELECT st.tagID, st.tagName, st.description
         FROM ListingSustainabilityTag lst
         JOIN SustainabilityTag st ON st.tagID = lst.tagID
         WHERE lst.listingID = :listingId'
    );
    $stmt->execute([':listingId' => $listingId]);

    $tags = [];
    while ($row = $stmt->fetch()) {
        $tags[] = [
            'id' => (int) $row['tagID'],
            'name' => $row['tagName'],
            'description' => $row['description'],
        ];
    }

    return $tags;
}

function fetchVerificationHistory(PDO $pdo, int $listingId): array
{
    $stmt = $pdo->prepare(
        'SELECT lv.verificationID, lv.verificationStatus, lv.remarks, lv.verifiedDate, lv.adminID, adm.fullName
         FROM ListingVerification lv
         LEFT JOIN Administrator adm ON adm.adminID = lv.adminID
         WHERE lv.listingID = :listingId
         ORDER BY lv.verifiedDate DESC, lv.verificationID DESC'
    );
    $stmt->execute([':listingId' => $listingId]);

    $history = [];
    while ($row = $stmt->fetch()) {
        $history[] = [
            'id' => (int) $row['verificationID'],
            'status' => $row['verificationStatus'],
            'remarks' => $row['remarks'],
            'verifiedDate' => formatDateString($row['verifiedDate']),
            'adminId' => $row['adminID'] !== null ? (int) $row['adminID'] : null,
            'adminName' => $row['fullName'],
        ];
    }

    return $history;
}

function buildSummary(array $listings): array
{
    $total = count($listings);
    $byStatus = [
        'Pending Review' => 0,
        'Approved' => 0,
        'Rejected' => 0,
        'Active' => 0,
    ];
    $visible = 0;
    $hidden = 0;

    foreach ($listings as $listing) {
        $status = $listing['status'] ?? 'Pending Review';
        if (isset($byStatus[$status])) {
            $byStatus[$status]++;
        }

        if (($listing['visibility'] ?? 'Hidden') === 'Visible') {
            $visible++;
        } else {
            $hidden++;
        }
    }

    return [
        'total' => $total,
        'status' => $byStatus,
        'visibility' => [
            'Visible' => $visible,
            'Hidden' => $hidden,
        ],
    ];
}

function recordRemovalNotification(PDO $pdo, int $operatorId, string $listingName, string $reason): void
{
    $title = 'Listing removed from marketplace';
    $messageParts = [
        sprintf('Your listing "%s" has been removed by the admin team.', $listingName),
    ];

    if ($reason !== '') {
        $messageParts[] = 'Reason: ' . $reason;
    }

    $message = implode(' ', $messageParts);

    $stmt = $pdo->prepare(
        'INSERT INTO Notification (recipientType, recipientID, title, message, createdAt, isRead)
         VALUES (\'Operator\', :recipientID, :title, :message, :createdAt, 0)'
    );
    $stmt->execute([
        ':recipientID' => $operatorId,
        ':title' => $title,
        ':message' => $message,
        ':createdAt' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
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
