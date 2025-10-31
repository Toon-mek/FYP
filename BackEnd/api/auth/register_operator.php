<?php
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
    echo json_encode(['error' => 'Only POST is allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$companyName = trim($data['companyName'] ?? '');
$contactPerson = trim($data['contactPerson'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$website = trim($data['website'] ?? '');
$password = (string)($data['password'] ?? '');

if ($companyName === '' || $contactPerson === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Company name, contact person, email, and password are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please use a valid email']);
    exit;
}

try {
    $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    return;
}

$username = strstr($email, '@', true) ?: $email;
$username = strtolower(preg_replace('/[^a-z0-9]/i', '', $username));
if ($username === '') {
    $username = 'operator';
}

try {
    $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
} catch (Exception $e) {
    try {
        $suffix = (string)random_int(1000, 9999);
    } catch (Exception $nested) {
        $suffix = (string)mt_rand(1000, 9999);
    }
}
$username = substr($username, 0, 24) . '_' . $suffix;

$hashed = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('INSERT INTO TourismOperator (username, email, password, fullName, contactNumber, registeredDate, accountStatus, businessType)
        VALUES (:username, :email, :password, :fullName, :contactNumber, CURDATE(), "Pending", :businessType)');
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password' => $hashed,
        ':fullName' => $contactPerson,
        ':contactNumber' => $phone !== '' ? $phone : null,
        ':businessType' => $companyName,
    ]);

    $operatorId = (int)$pdo->lastInsertId();
    if ($operatorId > 0) {
        storePasswordLastDigit($pdo, 'operator', $operatorId, $password);
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Application submitted. We will get in touch soon.',
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['error' => 'Email is already registered']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
}
