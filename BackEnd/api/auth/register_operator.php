<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/password_hint.php';
require_once __DIR__ . '/../helpers/profile_image.php';

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

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    http_response_code(415);
    echo json_encode(['error' => 'Only JSON payloads are accepted for operator registration']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$companyName = trim((string)($data['companyName'] ?? ''));
$contactPerson = trim((string)($data['contactPerson'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$password = (string)($data['password'] ?? '');
$profileImageData = isset($data['profileImageData']) ? (string)$data['profileImageData'] : null;

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
} catch (Throwable $e) {
    try {
        $suffix = (string)random_int(1000, 9999);
    } catch (Throwable $nested) {
        $suffix = (string)mt_rand(1000, 9999);
    }
}
$username = substr($username, 0, 24) . '_' . $suffix;

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$profileImagePath = null;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO TourismOperator (username, email, password, fullName, contactNumber, registeredDate, accountStatus, businessType)
         VALUES (:username, :email, :password, :fullName, :contactNumber, CURDATE(), "Pending", :businessType)'
    );
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password' => $hashedPassword,
        ':fullName' => $contactPerson,
        ':contactNumber' => $phone !== '' ? $phone : null,
        ':businessType' => $companyName,
    ]);

    $operatorId = (int)$pdo->lastInsertId();
    if ($operatorId <= 0) {
        throw new RuntimeException('Unable to determine operator identifier.');
    }

    if ($profileImageData !== null && $profileImageData !== '') {
        $profileImagePath = saveProfileImageFromData('operator', $operatorId, $profileImageData);
        $updateImage = $pdo->prepare(
            'UPDATE TourismOperator SET profileImage = :profileImage WHERE operatorID = :id LIMIT 1'
        );
        $updateImage->execute([
            ':profileImage' => $profileImagePath,
            ':id' => $operatorId,
        ]);
    }

    $pdo->commit();

    try {
        storePasswordLastDigit($pdo, 'operator', $operatorId, $password);
    } catch (Throwable $hintError) {
        error_log('[register_operator] Failed to update password digit hint: ' . $hintError->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Application submitted.',
        'profileImage' => $profileImagePath,
        'operatorId' => $operatorId,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($profileImagePath) {
        deleteProfileImage($profileImagePath);
    }
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['error' => 'Email is already registered']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($profileImagePath) {
        deleteProfileImage($profileImagePath);
    }
    $status = $e instanceof InvalidArgumentException ? 400 : 500;
    http_response_code($status);
    echo json_encode(['error' => $e->getMessage()]);
}
