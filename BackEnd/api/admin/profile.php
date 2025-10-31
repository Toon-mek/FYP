<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/password_hint.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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

$adminId = (int)($payload['adminId'] ?? 0);
if ($adminId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'adminId is required']);
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

$currentStmt = $pdo->prepare(
    'SELECT password FROM Administrator WHERE adminID = :id LIMIT 1'
);
$currentStmt->execute([':id' => $adminId]);
$currentAccount = $currentStmt->fetch(PDO::FETCH_ASSOC);
if (!$currentAccount) {
    http_response_code(404);
    echo json_encode(['error' => 'Administrator not found']);
    exit;
}
$currentPasswordHash = $currentAccount['password'] ?? null;

$fields = [];
$params = [':id' => $adminId];

if (array_key_exists('fullName', $payload)) {
    $fullName = trim((string)$payload['fullName']);
    if ($fullName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Full name cannot be empty']);
        exit;
    }
    $fields[] = 'fullName = :fullName';
    $params[':fullName'] = $fullName;
}

if (array_key_exists('email', $payload)) {
    $email = trim((string)$payload['email']);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Provide a valid email address']);
        exit;
    }
    $checkEmail = $pdo->prepare('SELECT adminID FROM Administrator WHERE email = :email AND adminID <> :id LIMIT 1');
    $checkEmail->execute([':email' => $email, ':id' => $adminId]);
    if ($checkEmail->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Email is already in use']);
        exit;
    }
    $fields[] = 'email = :email';
    $params[':email'] = $email;
}

if (array_key_exists('username', $payload)) {
    $username = trim((string)$payload['username']);
    if ($username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Username cannot be empty']);
        exit;
    }
    $checkUsername = $pdo->prepare('SELECT adminID FROM Administrator WHERE username = :username AND adminID <> :id LIMIT 1');
    $checkUsername->execute([':username' => $username, ':id' => $adminId]);
    if ($checkUsername->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Username is already taken']);
        exit;
    }
    $fields[] = 'username = :username';
    $params[':username'] = $username;
}

if (array_key_exists('status', $payload)) {
    $status = trim((string)$payload['status']);
    if ($status === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Status cannot be empty']);
        exit;
    }
    $fields[] = 'status = :status';
    $params[':status'] = $status;
}

if (array_key_exists('password', $payload)) {
    $password = (string)$payload['password'];
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        exit;
    }

    $verificationMethod = strtolower((string)($payload['passwordVerificationMethod'] ?? ''));
    if ($verificationMethod === 'current-password') {
        $currentPassword = (string)($payload['currentPassword'] ?? '');
        if ($currentPassword === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Current password is required to change your password']);
            exit;
        }
        if (!$currentPasswordHash || !password_verify($currentPassword, $currentPasswordHash)) {
            http_response_code(401);
            echo json_encode(['error' => 'Current password is incorrect']);
            exit;
        }
    } elseif ($verificationMethod === 'last-digit') {
        $passwordLastDigit = trim((string)($payload['passwordLastDigit'] ?? ''));
        if ($passwordLastDigit === '' || !ctype_digit($passwordLastDigit) || strlen($passwordLastDigit) !== 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Provide the last digit of your password to continue']);
            exit;
        }
        if (!verifyPasswordLastDigit($pdo, 'admin', $adminId, $passwordLastDigit)) {
            http_response_code(401);
            echo json_encode(['error' => 'Last digit did not match our records']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Password verification method is required']);
        exit;
    }

    $fields[] = 'password = :password';
    $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
    storePasswordLastDigit($pdo, 'admin', $adminId, $password);
}

if (!$fields) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid changes provided']);
    exit;
}

$sql = 'UPDATE Administrator SET ' . implode(', ', $fields) . ' WHERE adminID = :id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$fetch = $pdo->prepare(
    'SELECT adminID AS id, username, email, fullName, status
     FROM Administrator
     WHERE adminID = :id
     LIMIT 1'
);
$fetch->execute([':id' => $adminId]);
$updated = $fetch->fetch();

if (!$updated) {
    http_response_code(404);
    echo json_encode(['error' => 'Administrator not found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'admin' => $updated,
]);
