<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/password_hint.php';

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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$accountType = strtolower((string)($payload['accountType'] ?? ''));
$accountId = (int)($payload['accountId'] ?? 0);
$digit = isset($payload['digit']) ? (string) $payload['digit'] : '';

if (!in_array($accountType, ['operator', 'traveler', 'admin'], true) || $accountId <= 0 || $digit === '') {
    http_response_code(400);
    echo json_encode(['error' => 'accountType, accountId, and digit are required']);
    exit;
}

if (!ctype_digit($digit) || strlen($digit) !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Digit must be a single number between 0 and 9']);
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

if (!verifyPasswordLastDigit($pdo, $accountType, $accountId, $digit)) {
    http_response_code(401);
    echo json_encode(['error' => 'Last digit did not match our records']);
    exit;
}

echo json_encode(['ok' => true]);
