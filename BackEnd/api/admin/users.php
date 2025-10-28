<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getUsers($pdo);
        break;
    case 'POST':
        saveUser($pdo);
        break;
    case 'DELETE':
        deleteUser($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getUsers(PDO $pdo): void
{
    $users = [];

    $travelerStmt = $pdo->query('SELECT travelerID, fullName, email, contactNumber, accountStatus FROM Traveler ORDER BY travelerID DESC');
    while ($row = $travelerStmt->fetch()) {
        $users[] = [
            'id' => (int) $row['travelerID'],
            'type' => 'Traveler',
            'name' => $row['fullName'] ?? 'Traveler',
            'email' => $row['email'] ?? '',
            'role' => 'Traveler',
            'status' => $row['accountStatus'] ?? 'Pending',
            'phone' => $row['contactNumber'] ?? '',
            'businessType' => null,
        ];
    }

    $operatorStmt = $pdo->query('SELECT operatorID, fullName, email, contactNumber, businessType, accountStatus FROM TourismOperator ORDER BY operatorID DESC');
    while ($row = $operatorStmt->fetch()) {
        $users[] = [
            'id' => (int) $row['operatorID'],
            'type' => 'Operator',
            'name' => $row['fullName'] ?? 'Operator',
            'email' => $row['email'] ?? '',
            'role' => 'Operator',
            'status' => $row['accountStatus'] ?? 'Pending',
            'phone' => $row['contactNumber'] ?? '',
            'businessType' => $row['businessType'] ?? '',
        ];
    }

    $adminStmt = $pdo->query(
        'SELECT a.adminID, a.fullName, a.email, a.status, ur.roleName
         FROM Administrator a
         LEFT JOIN UserRole ur ON ur.roleID = a.roleID
         ORDER BY a.adminID DESC'
    );
    while ($row = $adminStmt->fetch()) {
        $users[] = [
            'id' => (int) $row['adminID'],
            'type' => 'Admin',
            'name' => $row['fullName'] ?? 'Administrator',
            'email' => $row['email'] ?? '',
            'role' => $row['roleName'] ?? 'Admin',
            'status' => $row['status'] ?? 'Active',
            'phone' => null,
            'businessType' => null,
        ];
    }

    echo json_encode(['users' => $users]);
}

function saveUser(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $id = isset($payload['id']) ? (int) $payload['id'] : null;
    $type = $payload['type'] ?? '';
    $status = $payload['status'] ?? '';
    $roleName = $payload['role'] ?? null;
    $name = trim((string) ($payload['name'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));
    $businessType = trim((string) ($payload['businessType'] ?? ''));
    $passwordInput = trim((string) ($payload['password'] ?? ''));

    if ($id === null) {
        switch ($type) {
            case 'Traveler':
                createTraveler($pdo, $name, $email, $status, $phone, $passwordInput);
                return;
            case 'Operator':
                createOperator($pdo, $name, $email, $status, $phone, $businessType, $passwordInput);
                return;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Only traveler or operator accounts can be created here']);
                return;
        }
    }

    switch ($type) {
        case 'Traveler':
            if ($name === '' || $email === '' || $status === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Name, email, and status are required']);
                return;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Enter a valid email address']);
                return;
            }
            $currentStatusStmt = $pdo->prepare('SELECT accountStatus FROM Traveler WHERE travelerID = :id');
            $currentStatusStmt->execute([':id' => $id]);
            $currentStatus = $currentStatusStmt->fetchColumn();
            if ($currentStatus === false) {
                http_response_code(404);
                echo json_encode(['error' => 'Traveler not found']);
                return;
            }
            $allowedStatuses = ['Active', 'Suspended'];
            if (!in_array($status, array_merge(['Pending'], $allowedStatuses), true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status value']);
                return;
            }
            if ($status === 'Pending' && strcasecmp((string) $currentStatus, 'Pending') !== 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Traveler status can no longer be set to Pending']);
                return;
            }
            $duplicate = $pdo->prepare('SELECT travelerID FROM Traveler WHERE email = :email AND travelerID != :id LIMIT 1');
            $duplicate->execute([':email' => $email, ':id' => $id]);
            if ($duplicate->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already exists for another traveler']);
                return;
            }
            $stmt = $pdo->prepare('UPDATE Traveler SET fullName = :fullName, email = :email, accountStatus = :status, contactNumber = :contactNumber WHERE travelerID = :id');
            $stmt->execute([
                ':fullName' => $name,
                ':email' => $email,
                ':status' => $status,
                ':contactNumber' => $phone !== '' ? $phone : null,
                ':id' => $id,
            ]);
            if ($passwordInput !== '') {
                if (strlen($passwordInput) < 6) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Password must be at least 6 characters']);
                    return;
                }
                $pdo->prepare('UPDATE Traveler SET password = :password WHERE travelerID = :id')
                    ->execute([':password' => password_hash($passwordInput, PASSWORD_DEFAULT), ':id' => $id]);
            }
            echo json_encode(['ok' => true]);
            return;

        case 'Operator':
            if ($name === '' || $email === '' || $status === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Name, email, and status are required']);
                return;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Enter a valid email address']);
                return;
            }
            $currentStatusStmt = $pdo->prepare('SELECT accountStatus FROM TourismOperator WHERE operatorID = :id');
            $currentStatusStmt->execute([':id' => $id]);
            $currentStatus = $currentStatusStmt->fetchColumn();
            if ($currentStatus === false) {
                http_response_code(404);
                echo json_encode(['error' => 'Operator not found']);
                return;
            }
            $allowedOperatorStatuses = ['Active', 'Suspended'];
            if (!in_array($status, array_merge(['Pending'], $allowedOperatorStatuses), true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status value']);
                return;
            }
            if ($status === 'Pending' && strcasecmp((string) $currentStatus, 'Pending') !== 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Operator status can no longer be set to Pending']);
                return;
            }
            $duplicate = $pdo->prepare('SELECT operatorID FROM TourismOperator WHERE email = :email AND operatorID != :id LIMIT 1');
            $duplicate->execute([':email' => $email, ':id' => $id]);
            if ($duplicate->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Email already exists for another operator']);
                return;
            }
            $stmt = $pdo->prepare(
                'UPDATE TourismOperator
                 SET fullName = :fullName,
                     email = :email,
                     accountStatus = :status,
                     contactNumber = :contactNumber,
                     businessType = :businessType
                 WHERE operatorID = :id'
            );
            $stmt->execute([
                ':fullName' => $name,
                ':email' => $email,
                ':status' => $status,
                ':contactNumber' => $phone !== '' ? $phone : null,
                ':businessType' => $businessType !== '' ? $businessType : null,
                ':id' => $id,
            ]);
            if ($passwordInput !== '') {
                if (strlen($passwordInput) < 6) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Password must be at least 6 characters']);
                    return;
                }
                $pdo->prepare('UPDATE TourismOperator SET password = :password WHERE operatorID = :id')
                    ->execute([':password' => password_hash($passwordInput, PASSWORD_DEFAULT), ':id' => $id]);
            }
            echo json_encode(['ok' => true]);
            return;

        case 'Admin':
            if ($name === '' || $email === '' || $status === '' || !$roleName) {
                http_response_code(400);
                echo json_encode(['error' => 'Name, email, status, and role are required']);
                return;
            }

            $roleID = lookupRoleId($pdo, $roleName);
            if ($roleID === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Role not found']);
                return;
            }

            $stmt = $pdo->prepare(
                'UPDATE Administrator
                 SET fullName = :fullName, email = :email, status = :status, roleID = :roleID
                 WHERE adminID = :id'
            );
            $stmt->execute([
                ':fullName' => $name,
                ':email' => $email,
                ':status' => $status,
                ':roleID' => $roleID,
                ':id' => $id,
            ]);

            echo json_encode(['ok' => true]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported user type']);
            return;
    }
}

function deleteUser(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $id = isset($payload['id']) ? (int) $payload['id'] : null;
    $type = $payload['type'] ?? '';

    if ($id === null) {
        http_response_code(400);
        echo json_encode(['error' => 'User id is required']);
        return;
    }

    switch ($type) {
        case 'Traveler':
            $stmt = $pdo->prepare('DELETE FROM Traveler WHERE travelerID = :id');
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            return;

        case 'Operator':
            $stmt = $pdo->prepare('DELETE FROM TourismOperator WHERE operatorID = :id');
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            return;

        case 'Admin':
            $stmt = $pdo->prepare('DELETE FROM Administrator WHERE adminID = :id');
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
            return;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported user type']);
            return;
    }
}

function lookupRoleId(PDO $pdo, string $roleName): ?int
{
    $stmt = $pdo->prepare('SELECT roleID FROM UserRole WHERE roleName = :roleName LIMIT 1');
    $stmt->execute([':roleName' => $roleName]);
    $roleID = $stmt->fetchColumn();

    return $roleID !== false ? (int) $roleID : null;
}

function buildUsernameFromEmail(PDO $pdo, string $email): string
{
    $base = strtolower(preg_replace('/[^a-z0-9_]/i', '', strstr($email, '@', true) ?: 'admin'));
    if ($base === '') {
        $base = 'admin';
    }

    $username = $base;
    $suffix = 1;

    $stmt = $pdo->prepare('SELECT 1 FROM Administrator WHERE username = :username LIMIT 1');
    while (true) {
        $stmt->execute([':username' => $username]);
        if (!$stmt->fetch()) {
            break;
        }
        $username = $base . $suffix++;
    }

    return $username;
}

function buildUniqueUsername(PDO $pdo, string $email, string $table, string $column): string
{
    $base = strtolower(preg_replace('/[^a-z0-9_]/i', '', strstr($email, '@', true) ?: 'user'));
    if ($base === '') {
        $base = 'user';
    }

    $username = $base;
    $suffix = 1;
    $sql = sprintf('SELECT 1 FROM %s WHERE %s = :username LIMIT 1', $table, $column);
    $stmt = $pdo->prepare($sql);

    while (true) {
        $stmt->execute([':username' => $username]);
        if (!$stmt->fetch()) {
            break;
        }
        $username = $base . $suffix++;
    }

    return $username;
}

function ensureUniqueEmail(PDO $pdo, string $table, string $column, string $email, ?int $ignoreId = null, string $idColumn = ''): bool
{
    if ($ignoreId === null || $idColumn === '') {
        $stmt = $pdo->prepare(sprintf('SELECT 1 FROM %s WHERE %s = :email LIMIT 1', $table, $column));
        $stmt->execute([':email' => $email]);
        return !$stmt->fetch();
    }

    $stmt = $pdo->prepare(sprintf('SELECT 1 FROM %s WHERE %s = :email AND %s != :id LIMIT 1', $table, $column, $idColumn));
    $stmt->execute([':email' => $email, ':id' => $ignoreId]);
    return !$stmt->fetch();
}

function createTraveler(PDO $pdo, string $name, string $email, string $status, string $phone, string $passwordInput): void
{
    if ($name === '' || $email === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Name and email are required']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid email address']);
        return;
    }
    if (!ensureUniqueEmail($pdo, 'Traveler', 'email', $email)) {
        http_response_code(409);
        echo json_encode(['error' => 'Email already exists']);
        return;
    }

    $username = buildUniqueUsername($pdo, $email, 'Traveler', 'username');
    $temporaryPassword = $passwordInput !== '' ? $passwordInput : bin2hex(random_bytes(4));
    if (strlen($temporaryPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        return;
    }
    $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $accountStatus = $status !== '' ? $status : 'Pending';

    $stmt = $pdo->prepare(
        'INSERT INTO Traveler (username, email, password, fullName, contactNumber, registeredDate, accountStatus)
         VALUES (:username, :email, :password, :fullName, :contactNumber, CURDATE(), :status)'
    );
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password' => $hash,
        ':fullName' => $name,
        ':contactNumber' => $phone !== '' ? $phone : null,
        ':status' => $accountStatus,
    ]);

    $response = ['ok' => true];
    if ($passwordInput === '') {
        $response['temporaryPassword'] = $temporaryPassword;
    }
    echo json_encode($response);
}

function createOperator(PDO $pdo, string $name, string $email, string $status, string $phone, string $businessType, string $passwordInput): void
{
    if ($name === '' || $email === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Name and email are required']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid email address']);
        return;
    }
    if (!ensureUniqueEmail($pdo, 'TourismOperator', 'email', $email)) {
        http_response_code(409);
        echo json_encode(['error' => 'Email already exists']);
        return;
    }

    $username = buildUniqueUsername($pdo, $email, 'TourismOperator', 'username');
    $temporaryPassword = $passwordInput !== '' ? $passwordInput : bin2hex(random_bytes(4));
    if (strlen($temporaryPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        return;
    }
    $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $accountStatus = $status !== '' ? $status : 'Pending';

    $stmt = $pdo->prepare(
        'INSERT INTO TourismOperator (username, email, password, fullName, contactNumber, registeredDate, accountStatus, businessType)
         VALUES (:username, :email, :password, :fullName, :contactNumber, CURDATE(), :status, :businessType)'
    );
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password' => $hash,
        ':fullName' => $name,
        ':contactNumber' => $phone !== '' ? $phone : null,
        ':status' => $accountStatus,
        ':businessType' => $businessType !== '' ? $businessType : null,
    ]);

    $response = ['ok' => true];
    if ($passwordInput === '') {
        $response['temporaryPassword'] = $temporaryPassword;
    }
    echo json_encode($response);
}
