<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../helpers/listing_history.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
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
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
    ensureListingRemovalHistoryTable($pdo);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$history = loadRemovalHistory($pdo, $operatorId);

echo json_encode([
    'ok' => true,
    'history' => $history,
]);

function loadRemovalHistory(PDO $pdo, int $operatorId): array
{
    $stmt = $pdo->prepare(
        'SELECT lr.*, adm.fullName AS adminName
         FROM ListingRemovalHistory lr
         LEFT JOIN Administrator adm ON adm.adminID = lr.removedBy
         WHERE lr.operatorID = :operatorId
         ORDER BY lr.removedAt DESC
         LIMIT 100'
    );
    $stmt->execute([':operatorId' => $operatorId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $snapshot = decodeHistorySnapshot($row['snapshot'] ?? null);

        return [
            'id' => (int) ($row['removalID'] ?? 0),
            'listingId' => (int) ($row['listingID'] ?? 0),
            'businessName' => $row['businessName'] ?? 'Listing',
            'category' => $row['categoryName'] ?? 'Uncategorised',
            'location' => $row['location'] ?? ($snapshot['location'] ?? null),
            'priceRange' => $row['priceRange'] ?? ($snapshot['priceRange'] ?? null),
            'status' => $row['status'] ?? 'Removed',
            'visibility' => $row['visibilityState'] ?? 'Hidden',
            'removalReason' => $row['removalReason'] ?? '',
            'removedAt' => formatHistoryDate($row['removedAt'] ?? null),
            'removedBy' => $row['adminName'] ?? null,
            'details' => [
                'description' => $snapshot['description'] ?? null,
                'address' => $snapshot['location'] ?? null,
                'priceRange' => $snapshot['priceRange'] ?? null,
                'submittedDate' => formatHistoryDate($snapshot['submittedDate'] ?? null),
            ],
        ];
    }, $rows);
}

function decodeHistorySnapshot(?string $payload): ?array
{
    if ($payload === null || $payload === '') {
        return null;
    }

    try {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable) {
        return null;
    }
}

function formatHistoryDate(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        $timezone = resolveAppTimezone();
        $dt = new DateTimeImmutable($value, $timezone);
        return $dt->setTimezone($timezone)->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        return $value;
    }
}

function resolveAppTimezone(): DateTimeZone
{
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $configured = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? '';
    $name = is_string($configured) && trim($configured) !== '' ? $configured : 'Asia/Kuala_Lumpur';

    try {
        $timezone = new DateTimeZone($name);
    } catch (Throwable) {
        $timezone = new DateTimeZone('UTC');
    }

    return $timezone;
}
