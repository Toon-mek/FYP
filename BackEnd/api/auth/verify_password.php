<?php
declare(strict_types=1);

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
$password = (string)($payload['password'] ?? '');

if (!in_array($accountType, ['operator', 'traveler', 'admin'], true) || $accountId <= 0 || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'accountType, accountId, and password are required']);
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

switch ($accountType) {
    case 'operator':
        $table = 'TourismOperator';
        $idField = 'operatorID';
        break;
    case 'traveler':
        $table = 'Traveler';
        $idField = 'travelerID';
        break;
    case 'admin':
        $table = 'Administrator';
        $idField = 'adminID';
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported account type']);
        exit;
}

$stmt = $pdo->prepare("SELECT password FROM {$table} WHERE {$idField} = :id LIMIT 1");
$stmt->execute([':id' => $accountId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    http_response_code(404);
    echo json_encode(['error' => 'Account not found']);
    exit;
}

$hash = $record['password'] ?? '';
if (!$hash || !password_verify($password, $hash)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid password']);
    exit;
}

echo json_encode(['ok' => true]);
