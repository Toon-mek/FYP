<?php
declare(strict_types=1);

// Suppress error display to prevent HTML output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set timezone to match Malaysia/Singapore
date_default_timezone_set('Asia/Kuala_Lumpur');

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
$travelerId = isset($_GET['travelerId']) ? (int) $_GET['travelerId'] : 0;

if ($method === 'POST') {
    handlePost($pdo);
    exit;
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Show all approved/active listings that are visible
// Visibility logic: if status is Approved/Active, it's visible unless visibilityState is explicitly 'Hidden'
$sql = <<<SQL
SELECT
    bl.listingID,
    bl.businessName,
    bl.description,
    bl.location,
    bl.priceRange,
    bl.status,
    bl.visibilityState,
    cat.categoryName,
    op.operatorID,
    op.fullName AS operatorName,
    op.email AS operatorEmail,
    op.contactNumber AS operatorPhone,
    op.businessType AS operatorBusinessType
FROM BusinessListing bl
LEFT JOIN ListingCategory cat ON cat.categoryID = bl.categoryID
LEFT JOIN TourismOperator op ON op.operatorID = bl.operatorID
WHERE bl.status IN ('Approved', 'Active')
  AND (bl.visibilityState IS NULL OR bl.visibilityState = '' OR bl.visibilityState = 'Visible')
ORDER BY bl.submittedDate DESC, bl.listingID DESC
SQL;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch listings']);
    exit;
}

$listings = [];
foreach ($rows as $row) {
    try {
        $listingId = (int) $row['listingID'];
        $images = fetchImages($pdo, $listingId);
        $coverImage = !empty($images) ? $images[0]['url'] : null;
        $reviewSummary = fetchReviewSummary($pdo, $listingId);
        $isSaved = $travelerId > 0 ? checkIfSaved($pdo, $listingId, $travelerId) : false;

        $listings[] = [
            'id' => $listingId,
            'businessName' => $row['businessName'] ?? '',
            'description' => $row['description'] ?? '',
            'location' => $row['location'] ?? '',
            'priceRange' => $row['priceRange'] ?? '',
            'category' => $row['categoryName'] ?? '',
            'coverImage' => $coverImage,
            'images' => $images,
            'reviewSummary' => $reviewSummary,
            'isSaved' => $isSaved,
            'operator' => [
                'id' => (int) ($row['operatorID'] ?? 0),
                'operatorID' => (int) ($row['operatorID'] ?? 0),
                'name' => $row['operatorName'] ?? '',
                'email' => $row['operatorEmail'] ?? '',
                'phone' => $row['operatorPhone'] ?? '',
                'businessType' => $row['operatorBusinessType'] ?? '',
            ],
        ];
    } catch (Throwable $e) {
        // Skip this listing if there's an error processing it
        continue;
    }
}

echo json_encode([
    'listings' => $listings,
]);

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
            'caption' => $row['caption'] ?? '',
            'uploadedDate' => $row['uploadedDate'] ?? '',
        ];
    }

    return $images;
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

function handlePost(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $action = trim((string) ($payload['action'] ?? ''));
    $listingId = isset($payload['listingId']) ? (int) $payload['listingId'] : 0;
    $travelerId = isset($payload['travelerId']) ? (int) $payload['travelerId'] : 0;

    if ($listingId <= 0 || $travelerId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'listingId and travelerId are required']);
        return;
    }

    ensureListingReviewTable($pdo);
    ensureListingSaveTable($pdo);

    if ($action === 'toggle-save') {
        handleToggleSave($pdo, $listingId, $travelerId);
    } elseif ($action === 'add-review') {
        handleAddReview($pdo, $listingId, $travelerId, $payload);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
}

function handleToggleSave(PDO $pdo, int $listingId, int $travelerId): void
{
    $pdo->beginTransaction();
    try {
        $existsStmt = $pdo->prepare(
            'SELECT 1 FROM ListingSave WHERE listingID = :listingId AND travelerID = :travelerId LIMIT 1'
        );
        $existsStmt->execute([':listingId' => $listingId, ':travelerId' => $travelerId]);
        $saved = (bool) $existsStmt->fetchColumn();

        if ($saved) {
            $delete = $pdo->prepare(
                'DELETE FROM ListingSave WHERE listingID = :listingId AND travelerID = :travelerId'
            );
            $delete->execute([':listingId' => $listingId, ':travelerId' => $travelerId]);
            $saved = false;
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO ListingSave (listingID, travelerID, savedAt) VALUES (:listingId, :travelerId, NOW())'
            );
            $insert->execute([':listingId' => $listingId, ':travelerId' => $travelerId]);
            $saved = true;
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'saved' => $saved]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update save status']);
    }
}

function handleAddReview(PDO $pdo, int $listingId, int $travelerId, array $payload): void
{
    try {
        $content = trim((string) ($payload['content'] ?? ''));
        $ratingRaw = $payload['rating'] ?? null;
        $rating = null;
        if ($ratingRaw !== null && $ratingRaw !== '') {
            $rating = (float) $ratingRaw;
            // Allow ratings from 0.5 to 5 in 0.5 increments
            if ($rating < 0.5 || $rating > 5) {
                $rating = null;
            } else {
                // Round to nearest 0.5
                $rating = round($rating * 2) / 2;
            }
        }

        if ($content === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Review content is required']);
            return;
        }

        ensureListingReviewTable($pdo);

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO ListingReview (listingID, travelerID, content, rating, createdAt)
                 VALUES (:listingId, :travelerId, :content, :rating, NOW())'
            );
            $insert->execute([
                ':listingId' => $listingId,
                ':travelerId' => $travelerId,
                ':content' => $content,
                ':rating' => $rating,
            ]);

            $reviewId = (int) $pdo->lastInsertId();
            $pdo->commit();

            $review = fetchReview($pdo, $reviewId);
            if ($review) {
                echo json_encode(['ok' => true, 'review' => $review]);
            } else {
                // Review was created but couldn't fetch it - return basic info
                echo json_encode([
                    'ok' => true,
                    'review' => [
                        'id' => $reviewId,
                        'listingId' => $listingId,
                        'travelerId' => $travelerId,
                        'authorName' => 'You',
                        'authorUsername' => '',
                        'authorInitials' => 'YO',
                        'content' => $content,
                        'rating' => $rating,
                        'createdAt' => date('Y-m-d H:i:s'),
                        'createdAtLabel' => 'Just now',
                    ],
                ]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'error' => 'Failed to add review',
                'errorDetails' => $e->getMessage(),
            ]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to process review',
            'errorDetails' => $e->getMessage(),
        ]);
    }
}

function fetchReviewSummary(PDO $pdo, int $listingId): array
{
    ensureListingReviewTable($pdo);
    
    $stmt = $pdo->prepare(
        'SELECT 
            COUNT(*) as totalReviews,
            AVG(rating) as averageRating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as rating5,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as rating4,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as rating3,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as rating2,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as rating1
         FROM ListingReview
         WHERE listingID = :listingId AND rating IS NOT NULL'
    );
    $stmt->execute([':listingId' => $listingId]);
    $row = $stmt->fetch();

    $total = (int) ($row['totalReviews'] ?? 0);
    $avg = $total > 0 ? round((float) ($row['averageRating'] ?? 0), 1) : 0;

    return [
        'totalReviews' => $total,
        'averageRating' => $avg,
        'distribution' => [
            5 => (int) ($row['rating5'] ?? 0),
            4 => (int) ($row['rating4'] ?? 0),
            3 => (int) ($row['rating3'] ?? 0),
            2 => (int) ($row['rating2'] ?? 0),
            1 => (int) ($row['rating1'] ?? 0),
        ],
    ];
}

function checkIfSaved(PDO $pdo, int $listingId, int $travelerId): bool
{
    ensureListingSaveTable($pdo);
    
    $stmt = $pdo->prepare(
        'SELECT 1 FROM ListingSave WHERE listingID = :listingId AND travelerID = :travelerId LIMIT 1'
    );
    $stmt->execute([':listingId' => $listingId, ':travelerId' => $travelerId]);
    return (bool) $stmt->fetchColumn();
}

function fetchReview(PDO $pdo, int $reviewId): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT r.reviewID, r.listingID, r.travelerID, r.content, r.rating, r.createdAt,
                    COALESCE(t.username, \'\') as username, COALESCE(t.fullName, \'\') as fullName
             FROM ListingReview r
             LEFT JOIN Traveler t ON t.travelerID = r.travelerID
             WHERE r.reviewID = :reviewId'
        );
        $stmt->execute([':reviewId' => $reviewId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $authorName = !empty($row['fullName']) ? $row['fullName'] : (!empty($row['username']) ? $row['username'] : 'Traveler');
        return [
            'id' => (int) $row['reviewID'],
            'listingId' => (int) $row['listingID'],
            'travelerId' => (int) $row['travelerID'],
            'authorName' => $authorName,
            'authorUsername' => $row['username'] ?? '',
            'authorInitials' => computeInitials($authorName),
            'content' => $row['content'] ?? '',
            'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
            'createdAt' => $row['createdAt'] ?? date('Y-m-d H:i:s'),
            'createdAtLabel' => formatRelativeTime($row['createdAt'] ?? date('Y-m-d H:i:s')),
        ];
    } catch (Throwable $e) {
        error_log('fetchReview error: ' . $e->getMessage());
        return null;
    }
}

function computeInitials(string $name): string
{
    $parts = array_filter(explode(' ', trim($name)));
    if (empty($parts)) {
        return 'TR';
    }
    $initials = '';
    foreach ($parts as $part) {
        if (strlen($part) > 0) {
            $initials .= strtoupper($part[0]);
        }
    }
    return substr($initials, 0, 2) ?: 'TR';
}

function formatRelativeTime(string $datetime): string
{
    try {
        $date = new DateTimeImmutable($datetime);
        $now = new DateTimeImmutable();
        $diff = $now->diff($date);

        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        }
        if ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        }
        if ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        }
        if ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        }
        if ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }
        return 'Just now';
    } catch (Throwable) {
        return $datetime;
    }
}

function ensureListingReviewTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        // Check if table exists
        $pdo->query('SELECT 1 FROM ListingReview LIMIT 1');
        
        // Table exists, try to alter rating column to support decimals
        try {
            $pdo->exec('ALTER TABLE ListingReview MODIFY COLUMN rating DECIMAL(2,1) DEFAULT NULL');
        } catch (Throwable) {
            // Column might already be DECIMAL or alter failed
        }
        
        $ensured = true;
        return;
    } catch (Throwable) {
        // Table doesn't exist, create it
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ListingReview (
    reviewID INT AUTO_INCREMENT PRIMARY KEY,
    listingID INT NOT NULL,
    travelerID INT NOT NULL,
    content TEXT NOT NULL,
    rating DECIMAL(2,1) DEFAULT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_listing (listingID),
    INDEX idx_traveler (travelerID),
    INDEX idx_created (createdAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Table creation failed - might already exist or permission issue
        error_log('Failed to create ListingReview table: ' . $e->getMessage());
    }
    $ensured = true;
}

function ensureListingSaveTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ListingSave (
    saveID INT AUTO_INCREMENT PRIMARY KEY,
    listingID INT NOT NULL,
    travelerID INT NOT NULL,
    savedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_save (listingID, travelerID),
    INDEX idx_listing (listingID),
    INDEX idx_traveler (travelerID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;

    try {
        $pdo->exec($sql);
    } catch (Throwable) {
        // Table might already exist
    }
    $ensured = true;
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
            // Column addition failed
        }
    }
}

