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
$email = trim((string)($payload['email'] ?? ''));
$newPassword = (string)($payload['newPassword'] ?? '');
$passwordLastDigit = isset($payload['passwordLastDigit']) ? (string)$payload['passwordLastDigit'] : '';

if (!in_array($accountType, ['traveler', 'operator', 'admin'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported account type']);
    exit;
}

if ($email === '' || $newPassword === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email and newPassword are required']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'New password must be at least 6 characters']);
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
    case 'traveler':
        $table = 'Traveler';
        $idField = 'travelerID';
        break;
    case 'operator':
        $table = 'TourismOperator';
        $idField = 'operatorID';
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

$fetch = $pdo->prepare(
    sprintf('SELECT %s AS id, password FROM %s WHERE email = :email LIMIT 1', $idField, $table)
);
$fetch->execute([':email' => $email]);
$account = $fetch->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    http_response_code(404);
    echo json_encode(['error' => 'Account not found']);
    exit;
}

$accountId = (int)($account['id'] ?? 0);

$digit = trim($passwordLastDigit);
if ($digit === '' || !ctype_digit($digit) || strlen($digit) !== 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide the last digit of the previous password to continue']);
    exit;
}

if (!verifyPasswordLastDigit($pdo, $accountType, $accountId, $digit)) {
    http_response_code(401);
    echo json_encode(['error' => 'Verification failed. Last digit did not match our records.']);
    exit;
}

$update = $pdo->prepare(
    sprintf('UPDATE %s SET password = :password WHERE %s = :id LIMIT 1', $table, $idField)
);
$update->execute([
    ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
    ':id' => $accountId,
]);

storePasswordLastDigit($pdo, $accountType, $accountId, $newPassword);

echo json_encode(['ok' => true, 'message' => 'Password updated successfully.']);
