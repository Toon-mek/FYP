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
    echo json_encode(['error' => 'Only JSON payloads are accepted for traveler registration']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$fullName = trim((string)($data['fullName'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$password = (string)($data['password'] ?? '');
$profileImageData = isset($data['profileImageData']) ? (string)$data['profileImageData'] : null;

if ($fullName === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Full name, email, and password are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please use a valid email']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 6 characters']);
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
    $username = 'traveler';
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

$hash = password_hash($password, PASSWORD_DEFAULT);
$profileImagePath = null;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO Traveler (username, email, password, fullName, registeredDate, accountStatus)
         VALUES (:username, :email, :password, :fullName, CURDATE(), "Active")'
    );
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password' => $hash,
        ':fullName' => $fullName,
    ]);

    $travelerId = (int)$pdo->lastInsertId();
    if ($travelerId <= 0) {
        throw new RuntimeException('Failed to determine traveler account identifier.');
    }

    if ($profileImageData !== null && $profileImageData !== '') {
        $profileImagePath = saveProfileImageFromData('traveler', $travelerId, $profileImageData);
        $updateImage = $pdo->prepare(
            'UPDATE Traveler SET profileImage = :profileImage WHERE travelerID = :id LIMIT 1'
        );
        $updateImage->execute([
            ':profileImage' => $profileImagePath,
            ':id' => $travelerId,
        ]);
    }

    $pdo->commit();

    try {
        storePasswordLastDigit($pdo, 'traveler', $travelerId, $password);
    } catch (Throwable $hintError) {
        error_log('[register_traveler] Failed to update password digit hint: ' . $hintError->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Traveler account created',
        'profileImage' => $profileImagePath,
        'travelerId' => $travelerId,
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
