<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    case 'POST':
        handleDecision($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function handleGet(PDO $pdo): void
{
    $listingId = isset($_GET['listingId']) ? (int) $_GET['listingId'] : null;
    $statusFilter = $_GET['status'] ?? 'pending';

    if ($listingId !== null && $listingId > 0) {
        $listing = loadListing($pdo, $listingId);
        if ($listing === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Listing not found']);
            return;
        }

        echo json_encode([
            'listing' => $listing,
        ]);
        return;
    }

    $statuses = normaliseStatusFilter($statusFilter);
    $params = [];
    $whereClause = '';

    if ($statuses !== null) {
        if (count($statuses) === 0) {
            echo json_encode(['listings' => []]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $whereClause = "WHERE bl.status IN ($placeholders)";
        $params = $statuses;
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
    bl.operatorID,
    op.username AS operatorUsername,
    op.fullName AS operatorName,
    op.email AS operatorEmail,
    op.contactNumber AS operatorPhone,
    op.businessType AS operatorBusinessType,
    cat.categoryName,
    (
        SELECT lv.verificationStatus
        FROM ListingVerification lv
        WHERE lv.listingID = bl.listingID
        ORDER BY lv.verifiedDate DESC, lv.verificationID DESC
        LIMIT 1
    ) AS lastVerificationStatus,
    (
        SELECT lv.remarks
        FROM ListingVerification lv
        WHERE lv.listingID = bl.listingID
        ORDER BY lv.verifiedDate DESC, lv.verificationID DESC
        LIMIT 1
    ) AS lastVerificationRemarks,
    (
        SELECT lv.verifiedDate
        FROM ListingVerification lv
        WHERE lv.listingID = bl.listingID
        ORDER BY lv.verifiedDate DESC, lv.verificationID DESC
        LIMIT 1
    ) AS lastVerifiedDate,
    (
        SELECT lv.adminID
        FROM ListingVerification lv
        WHERE lv.listingID = bl.listingID
        ORDER BY lv.verifiedDate DESC, lv.verificationID DESC
        LIMIT 1
    ) AS lastVerifiedById,
    (
        SELECT adm.fullName
        FROM ListingVerification lv
        JOIN Administrator adm ON adm.adminID = lv.adminID
        WHERE lv.listingID = bl.listingID
        ORDER BY lv.verifiedDate DESC, lv.verificationID DESC
        LIMIT 1
    ) AS lastVerifiedByName
FROM BusinessListing bl
JOIN TourismOperator op ON op.operatorID = bl.operatorID
LEFT JOIN ListingCategory cat ON cat.categoryID = bl.categoryID
SQL;

    if ($whereClause !== '') {
        $sql .= "\n" . $whereClause;
    }

    $sql .= "\nORDER BY bl.submittedDate ASC, bl.listingID ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        echo json_encode(['listings' => []]);
        return;
    }

    $listingIds = array_map(static fn(array $row): int => (int) $row['listingID'], $rows);
    $imagesByListing = fetchImages($pdo, $listingIds);

    $listings = array_map(
        static fn(array $row): array => formatListingRow($row, $imagesByListing),
        $rows
    );

    echo json_encode(['listings' => $listings]);
}

function handleDecision(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $listingId = isset($payload['listingId']) ? (int) $payload['listingId'] : 0;
    $adminId = isset($payload['adminId']) ? (int) $payload['adminId'] : 0;
    $decision = strtolower(trim((string) ($payload['decision'] ?? '')));
    $remarks = trim((string) ($payload['remarks'] ?? ''));
    $notifyOperator = array_key_exists('notifyOperator', $payload) ? (bool) $payload['notifyOperator'] : true;

    if ($listingId <= 0 || $adminId <= 0) {
        $adminId = resolveDefaultAdminId($pdo);
        if ($listingId <= 0 || $adminId === null) {
            http_response_code(400);
            echo json_encode(['error' => 'listingId and adminId are required']);
            return;
        }
    } else {
        $adminId = resolveAdminId($pdo, $adminId) ?? resolveDefaultAdminId($pdo);
        if ($adminId === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Admin not found']);
            return;
        }
    }

    $finalStatus = mapDecisionToStatus($decision);
    if ($finalStatus === null) {
        http_response_code(400);
        echo json_encode(['error' => 'decision must be approve or reject']);
        return;
    }

    if ($finalStatus === 'Rejected' && $remarks === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please provide remarks when rejecting a listing']);
        return;
    }

    $listingRow = fetchListingRow($pdo, $listingId);
    if ($listingRow === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Listing not found']);
        return;
    }

    $visibilityState = null;
    if ($finalStatus === 'Approved') {
        $visibilityState = 'Visible';
    } elseif ($finalStatus === 'Rejected') {
        $visibilityState = 'Hidden';
    }

    try {
        $pdo->beginTransaction();

        updateListingStatus($pdo, $listingId, $finalStatus, $visibilityState);
        recordVerification($pdo, $adminId, $listingId, $finalStatus, $remarks);

        if ($notifyOperator) {
            notifyOperator(
                $pdo,
                (int) $listingRow['operatorID'],
                $listingRow['businessName'] ?? 'Your listing',
                $finalStatus,
                $remarks
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update listing status', 'details' => $e->getMessage()]);
        return;
    }

    $listing = loadListing($pdo, $listingId);
    echo json_encode([
        'ok' => true,
        'status' => $finalStatus,
        'listing' => $listing,
    ]);
}

function normaliseStatusFilter(string $filter): ?array
{
    $filter = strtolower(trim($filter));

    return match ($filter) {
        '', 'pending' => ['Pending Review', 'Pending', 'Submitted', 'Under Review'],
        'approved' => ['Approved'],
        'rejected' => ['Rejected'],
        'all' => null,
        default => ['Pending Review', 'Pending', 'Submitted', 'Under Review'],
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
    if ($statusLower === 'pending review') {
        return 'Hidden';
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
            // Column addition failed; subsequent queries will surface the issue.
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
        // Normalisation is best-effort.
    }
}

function fetchImages(PDO $pdo, array $listingIds): array
{
    if (count($listingIds) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT listingID, imageID, imageURL, caption, uploadedDate
         FROM ListingImage
         WHERE listingID IN ($placeholders)
         ORDER BY uploadedDate DESC, imageID DESC"
    );
    $stmt->execute($listingIds);

    $images = [];
    while ($row = $stmt->fetch()) {
        $lid = (int) $row['listingID'];
        if (!isset($images[$lid])) {
            $images[$lid] = [];
        }
        $relativePath = (string) $row['imageURL'];
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $type = 'image';
        $mime = null;
        if ($extension === 'pdf') {
            $type = 'pdf';
            $mime = 'application/pdf';
        } elseif (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true)) {
            $type = 'image';
            $mime = $extension === 'jpg' ? 'image/jpeg' : "image/{$extension}";
        } else {
            $type = 'file';
        }
        $filename = basename(str_replace('\\', '/', $relativePath));

        $images[$lid][] = [
            'id' => (int) $row['imageID'],
            'url' => buildAssetUrl($relativePath),
            'caption' => $row['caption'],
            'uploadedDate' => formatDateString($row['uploadedDate']),
            'type' => $type,
            'mime' => $mime,
            'filename' => $filename,
        ];
    }

    return $images;
}

function formatListingRow(array $row, array $imagesByListing): array
{
    $listingId = (int) $row['listingID'];

    return [
        'id' => $listingId,
        'businessName' => $row['businessName'],
        'description' => $row['description'],
        'status' => $row['status'],
        'visibility' => computeVisibility($row['status'] ?? null, $row['visibilityState'] ?? null),
        'visibility' => computeVisibility($row['status'] ?? null, $row['visibilityState'] ?? null),
        'submittedDate' => formatDateString($row['submittedDate']),
        'location' => $row['location'],
        'priceRange' => $row['priceRange'],
        'category' => $row['categoryName'],
        'operator' => [
            'id' => (int) $row['operatorID'],
            'username' => $row['operatorUsername'],
            'name' => $row['operatorName'],
            'email' => $row['operatorEmail'],
            'phone' => $row['operatorPhone'],
            'businessType' => $row['operatorBusinessType'],
        ],
        'lastVerification' => [
            'status' => $row['lastVerificationStatus'],
            'remarks' => $row['lastVerificationRemarks'],
            'verifiedDate' => formatDateString($row['lastVerifiedDate']),
            'adminId' => $row['lastVerifiedById'] !== null ? (int) $row['lastVerifiedById'] : null,
            'adminName' => $row['lastVerifiedByName'],
        ],
        'images' => $imagesByListing[$listingId] ?? [],
    ];
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
            bl.operatorID,
            op.username AS operatorUsername,
            op.fullName AS operatorName,
            op.email AS operatorEmail,
            op.contactNumber AS operatorPhone,
            op.businessType AS operatorBusinessType,
            cat.categoryName,
            cat.description AS categoryDescription
         FROM BusinessListing bl
         JOIN TourismOperator op ON op.operatorID = bl.operatorID
         LEFT JOIN ListingCategory cat ON cat.categoryID = bl.categoryID
         WHERE bl.listingID = :listingId
         LIMIT 1'
    );
    $stmt->execute([':listingId' => $listingId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $images = fetchImages($pdo, [$listingId]);

    return [
        'id' => (int) $row['listingID'],
        'businessName' => $row['businessName'],
        'description' => $row['description'],
        'status' => $row['status'],
        'submittedDate' => formatDateString($row['submittedDate']),
        'location' => $row['location'],
        'priceRange' => $row['priceRange'],
        'category' => [
            'name' => $row['categoryName'],
            'description' => $row['categoryDescription'],
        ],
        'operator' => [
            'id' => (int) $row['operatorID'],
            'username' => $row['operatorUsername'],
            'name' => $row['operatorName'],
            'email' => $row['operatorEmail'],
            'phone' => $row['operatorPhone'],
            'businessType' => $row['operatorBusinessType'],
        ],
        'images' => $images[(int) $row['listingID']] ?? [],
    ];
}

function fetchListingRow(PDO $pdo, int $listingId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT listingID, operatorID, businessName, status, visibilityState
         FROM BusinessListing
         WHERE listingID = :listingId
         LIMIT 1'
    );
    $stmt->execute([':listingId' => $listingId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function updateListingStatus(PDO $pdo, int $listingId, string $status, ?string $visibilityState = null): void
{
    $fields = ['status = :status'];
    $params = [
        ':status' => $status,
        ':listingId' => $listingId,
    ];

    if ($visibilityState !== null) {
        $fields[] = 'visibilityState = :visibilityState';
        $params[':visibilityState'] = $visibilityState;
    }

    $sql = 'UPDATE BusinessListing SET ' . implode(', ', $fields) . ' WHERE listingID = :listingId';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function recordVerification(PDO $pdo, int $adminId, int $listingId, string $status, string $remarks): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ListingVerification (listingID, adminID, verificationStatus, remarks, verifiedDate)
         VALUES (:listingId, :adminId, :status, :remarks, CURDATE())'
    );
    $stmt->execute([
        ':listingId' => $listingId,
        ':adminId' => $adminId,
        ':status' => $status,
        ':remarks' => $remarks !== '' ? $remarks : null,
    ]);
}

function notifyOperator(PDO $pdo, int $operatorId, string $listingName, string $status, string $remarks): void
{
    $title = $status === 'Approved'
        ? 'Listing approved'
        : 'Listing review update';

    $messageParts = [];
    if ($status === 'Approved') {
        $messageParts[] = sprintf(
            'Great news! Your listing "%s" has been approved and is now visible to travelers.',
            $listingName
        );
    } else {
        $messageParts[] = sprintf(
            'Your listing "%s" was not approved this time.',
            $listingName
        );
    }

    if ($remarks !== '') {
        $messageParts[] = 'Notes from the admin: ' . $remarks;
    }

    $message = implode(' ', $messageParts);

    $stmt = $pdo->prepare(
        'INSERT INTO Notification (recipientType, recipientID, title, message, createdAt, isRead)
         VALUES (\'Operator\', :recipientId, :title, :message, :createdAt, 0)'
    );
    $stmt->execute([
        ':recipientId' => $operatorId,
        ':title' => $title,
        ':message' => $message,
        ':createdAt' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    ]);
}

function mapDecisionToStatus(string $decision): ?string
{
    return match ($decision) {
        'approve', 'approved' => 'Approved',
        'reject', 'rejected' => 'Rejected',
        default => null,
    };
}

function resolveAdminId(PDO $pdo, int $adminId): ?int
{
    $stmt = $pdo->prepare('SELECT adminID FROM Administrator WHERE adminID = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['adminID'] : null;
}

function resolveDefaultAdminId(PDO $pdo): ?int
{
    $stmt = $pdo->query('SELECT adminID FROM Administrator ORDER BY adminID ASC LIMIT 1');
    $row = $stmt->fetch();
    return $row ? (int) $row['adminID'] : null;
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
    $encodedPublicPath = encodePathSegments(trim($publicPath, '/'));
    $fullPath = '/' . ltrim($encodedPublicPath . '/' . $encodedRelative, '/');

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
