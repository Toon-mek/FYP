<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    ensurePackagesTableExists($pdo);
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['error' => 'Saved places request failed', 'details' => $e->getMessage()]);
}

function handleGet(PDO $pdo): void
{
    $travelerId = (int)($_GET['travelerId'] ?? 0);
    if ($travelerId <= 0) {
        respond(400, ['error' => 'travelerId is required']);
        return;
    }

    $packageId = isset($_GET['packageId']) ? (int)$_GET['packageId'] : null;

    if ($packageId) {
        $stmt = $pdo->prepare(
            'SELECT * FROM traveler_saved_place_package WHERE packageID = :packageId AND travelerID = :travelerId LIMIT 1'
        );
        $stmt->execute([':packageId' => $packageId, ':travelerId' => $travelerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            respond(404, ['error' => 'Package not found']);
            return;
        }
        respond(200, ['package' => normalisePackageRow($row)]);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM traveler_saved_place_package WHERE travelerID = :travelerId ORDER BY createdAt DESC'
    );
    $stmt->execute([':travelerId' => $travelerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $packages = array_map('normalisePackageRow', $rows);
    respond(200, ['packages' => $packages]);
}

function handlePost(PDO $pdo): void
{
    $payload = readJsonPayload();
    $travelerId = (int)($payload['travelerId'] ?? 0);
    $title = trim((string)($payload['title'] ?? ''));
    $destination = trim((string)($payload['destination'] ?? ''));
    $coverPhoto = trim((string)($payload['coverPhoto'] ?? ''));
    $summary = $payload['summary'] ?? null;
    $selections = $payload['selections'] ?? null;

    if ($travelerId <= 0) {
        respond(400, ['error' => 'travelerId is required']);
        return;
    }
    if ($title === '') {
        $title = 'Saved experience package';
    }
    if (!is_array($selections)) {
        respond(400, ['error' => 'Selections payload is required']);
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO traveler_saved_place_package
            (travelerID, title, destination, coverPhoto, summary, selections, createdAt)
         VALUES
            (:travelerId, :title, :destination, :coverPhoto, :summary, :selections, NOW())'
    );
    $insert->execute([
        ':travelerId' => $travelerId,
        ':title' => $title,
        ':destination' => $destination ?: null,
        ':coverPhoto' => $coverPhoto ?: null,
        ':summary' => $summary ? json_encode($summary) : null,
        ':selections' => json_encode($selections),
    ]);

    $packageId = (int)$pdo->lastInsertId();
    $record = fetchPackageById($pdo, $travelerId, $packageId);
    respond(201, ['package' => $record]);
}

function handleDelete(PDO $pdo): void
{
    $travelerId = (int)($_GET['travelerId'] ?? ($_POST['travelerId'] ?? 0));
    $packageId = (int)($_GET['packageId'] ?? ($_POST['packageId'] ?? 0));

    if ($travelerId <= 0 || $packageId <= 0) {
        respond(400, ['error' => 'travelerId and packageId are required']);
        return;
    }

    $stmt = $pdo->prepare(
        'DELETE FROM traveler_saved_place_package WHERE packageID = :packageId AND travelerID = :travelerId'
    );
    $stmt->execute([':packageId' => $packageId, ':travelerId' => $travelerId]);

    respond(200, ['ok' => true]);
}

function fetchPackageById(PDO $pdo, int $travelerId, int $packageId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM traveler_saved_place_package WHERE packageID = :packageId AND travelerID = :travelerId LIMIT 1'
    );
    $stmt->execute([':packageId' => $packageId, ':travelerId' => $travelerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? normalisePackageRow($row) : null;
}

function normalisePackageRow(array $row): array
{
    return [
        'packageId' => (int)$row['packageID'],
        'travelerId' => (int)$row['travelerID'],
        'title' => $row['title'],
        'destination' => $row['destination'] ?? '',
        'coverPhoto' => $row['coverPhoto'] ?? '',
        'summary' => decodeJsonField($row['summary']),
        'selections' => decodeJsonField($row['selections']) ?? ['experiences' => [], 'stays' => []],
        'createdAt' => $row['createdAt'] ?? null,
    ];
}

function decodeJsonField($value)
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function readJsonPayload(): array
{
    $body = file_get_contents('php://input');
    if (!$body) {
        return [];
    }
    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
}

function ensurePackagesTableExists(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS traveler_saved_place_package (
            packageID INT AUTO_INCREMENT PRIMARY KEY,
            travelerID INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            destination VARCHAR(255) DEFAULT NULL,
            coverPhoto VARCHAR(600) DEFAULT NULL,
            summary LONGTEXT DEFAULT NULL,
            selections LONGTEXT NOT NULL,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX traveler_idx (travelerID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
