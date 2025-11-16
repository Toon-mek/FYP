<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/password_reset_tokens.php';

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
$email = strtolower(trim((string)($payload['email'] ?? '')));
$requestToken = trim((string)($payload['requestToken'] ?? ''));
$otp = trim((string)($payload['otp'] ?? ''));

if (!in_array($accountType, ['traveler', 'operator', 'admin'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported account type']);
    exit;
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide a valid email address']);
    exit;
}

if ($requestToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing OTP reference. Request a new code.']);
    exit;
}

if ($otp === '' || !ctype_digit($otp) || strlen($otp) !== 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter the 6-digit OTP sent to your email.']);
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

$stmt = $pdo->prepare(
    sprintf('SELECT %s AS id FROM %s WHERE email = :email LIMIT 1', $idField, $table)
);
$stmt->execute([':email' => $email]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    http_response_code(404);
    echo json_encode(['error' => 'Account not found']);
    exit;
}

$accountId = (int)$account['id'];

try {
    $verification = verifyPasswordResetOtp($pdo, $accountType, $accountId, $requestToken, $otp);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok' => true,
    'resetToken' => $verification['resetToken'],
    'resetTokenExpiresAt' => $verification['expiresAt'],
    'message' => 'OTP verified. You can now create a new password.',
]);
