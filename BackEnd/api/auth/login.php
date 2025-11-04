<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/password_hint.php';
require_once __DIR__ . '/../helpers/profile_image.php';
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

$accountType = strtolower(trim((string)($input['accountType'] ?? '')));
$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');
if (!in_array($accountType, ['traveler', 'operator', 'admin'], true) || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'accountType, email, and password are required']);
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
        $select = 'travelerID AS id, username, email, password, fullName, contactNumber, registeredDate, accountStatus, profileImage';
        break;
    case 'operator':
        $table = 'TourismOperator';
        $idField = 'operatorID';
        $select = 'operatorID AS id, username, email, password, fullName, contactNumber, registeredDate, accountStatus, businessType, profileImage';
        break;
    case 'admin':
        $table = 'Administrator';
        $idField = 'adminID';
        $select = 'adminID AS id, username, email, password, fullName, status, roleID, profileImage';
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported account type']);
        exit;
}

$sql = sprintf('SELECT %s FROM %s WHERE email = :email LIMIT 1', $select, $table);
$stmt = $pdo->prepare($sql);
$stmt->execute([':email' => $email]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record || !password_verify($password, (string)($record['password'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit;
}

$accountId = (int)($record['id'] ?? 0);

if ($accountId > 0) {
    storePasswordLastDigit($pdo, $accountType, $accountId, $password);
}

$profileImageRelative = $record['profileImage'] ?? $record['profile_image'] ?? null;
$record['profileImageUrl'] = profileImagePublicUrl($profileImageRelative);
if (!isset($record['profileImage']) && $profileImageRelative !== null) {
    $record['profileImage'] = $profileImageRelative;
}

unset($record['password']);

$ipAddress = resolveClientIp();
$deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? null;
$loginLogId = record_login_event($pdo, $accountType, $accountId, 'Success', $ipAddress, $deviceInfo);

echo json_encode([
    'ok' => true,
    'accountType' => $accountType,
    'user' => $record,
    'loginLogId' => $loginLogId,
]);
function resolveClientIp(): ?string
{
    $headerCandidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_VIA',
    ];

    foreach ($headerCandidates as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }
        $raw = (string) $_SERVER[$header];
        $parts = explode(',', $raw);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote && filter_var($remote, FILTER_VALIDATE_IP)) {
        if ($remote === '::1' || $remote === '127.0.0.1') {
            $hostIp = getHostByName(gethostname());
            if ($hostIp && filter_var($hostIp, FILTER_VALIDATE_IP) && $hostIp !== $remote) {
                return $hostIp;
            }
            return $remote === '::1' ? '127.0.0.1' : $remote;
        }
        return $remote;
    }

    return null;
}
