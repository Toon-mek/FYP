<?php
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

$accountType = strtolower(trim($input['accountType'] ?? ''));
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!in_array($accountType, ['traveler', 'operator'], true) || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'accountType, email, and password are required']);
    exit;
}

try {
    $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$table = $accountType === 'traveler' ? 'Traveler' : 'TourismOperator';
$idField = $accountType === 'traveler' ? 'travelerID' : 'operatorID';

$sql = "SELECT {$idField} AS id, username, email, password, fullName FROM {$table} WHERE email = :email LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

unset($user['password']);

echo json_encode([
    'ok' => true,
    'accountType' => $accountType,
    'user' => $user,
]);
