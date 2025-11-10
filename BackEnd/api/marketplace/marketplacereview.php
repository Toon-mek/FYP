<?php
declare(strict_types=1);

// error display 
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

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
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Database unavailable',
        'errorDetails' => $e->getMessage(),
    ]);
    exit;
}

$listingId = isset($_GET['listingId']) ? (int) $_GET['listingId'] : 0;
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

if ($listingId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'listingId is required']);
    exit;
}

try {
    ensureListingReviewTable($pdo);

    $stmt = $pdo->prepare(
        'SELECT r.reviewID, r.listingID, r.travelerID, r.content, r.rating, r.createdAt,
                COALESCE(t.username, \'\') AS username, COALESCE(t.fullName, \'\') AS fullName
         FROM ListingReview r
         LEFT JOIN Traveler t ON t.travelerID = r.travelerID
         WHERE r.listingID = :listingId
         ORDER BY r.createdAt DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':listingId', $listingId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ListingReview WHERE listingID = :listingId');
    $countStmt->execute([':listingId' => $listingId]);
    $total = (int) $countStmt->fetchColumn();

    $reviews = [];
    foreach ($rows as $row) {
        $authorName = !empty($row['fullName']) ? $row['fullName'] : (!empty($row['username']) ? $row['username'] : 'Traveler');
        $reviews[] = [
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
    }

    echo json_encode([
        'ok' => true,
        'reviews' => $reviews,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load reviews',
        'errorDetails' => $e->getMessage(),
    ]);
}

function ensureListingReviewTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
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
    } catch (Throwable) {
        // Table might already exist
    }
    $ensured = true;
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

