<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/login_logger.php';

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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$accountType = strtolower(trim((string) ($input['accountType'] ?? '')));
$accountId = (int) ($input['accountId'] ?? 0);
$logId = isset($input['logId']) ? (int) $input['logId'] : null;

if (!in_array($accountType, ['traveler', 'operator', 'admin'], true) || $accountId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'accountType and accountId are required']);
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

$updatedLogId = close_active_login($pdo, $accountType, $accountId, $logId);

echo json_encode([
    'ok' => true,
    'updatedLogId' => $updatedLogId,
]);

