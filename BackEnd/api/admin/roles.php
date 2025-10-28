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
        getRoles($pdo);
        break;
    case 'POST':
        saveRole($pdo);
        break;
    case 'DELETE':
        deleteRole($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function getRoles(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT ur.roleID, ur.roleName, ur.permissions
         FROM UserRole ur
         ORDER BY ur.roleID ASC'
    );

    $roles = [];
    while ($row = $stmt->fetch()) {
        $roleName = strtolower($row['roleName']);
        $members = 0;

        if ($roleName === 'traveler') {
            $countStmt = $pdo->query('SELECT COUNT(*) FROM Traveler');
            $members = (int) $countStmt->fetchColumn();
        } elseif ($roleName === 'operator') {
            $countStmt = $pdo->query('SELECT COUNT(*) FROM TourismOperator');
            $members = (int) $countStmt->fetchColumn();
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM Administrator WHERE roleID = :roleID');
            $countStmt->execute([':roleID' => $row['roleID']]);
            $members = (int) $countStmt->fetchColumn();
        }

        $roles[] = [
            'id' => (int) $row['roleID'],
            'name' => $row['roleName'],
            'description' => $row['permissions'] ?? '',
            'members' => $members,
        ];
    }

    echo json_encode(['roles' => $roles]);
}

function saveRole(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $id = isset($payload['roleID']) ? (int) $payload['roleID'] : null;
    $name = trim((string) ($payload['name'] ?? ''));
    $description = (string) ($payload['description'] ?? '');

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Role name is required']);
        return;
    }

    if ($id !== null) {
        $duplicate = $pdo->prepare('SELECT roleID FROM UserRole WHERE roleName = :name AND roleID != :id LIMIT 1');
        $duplicate->execute([':name' => $name, ':id' => $id]);
        if ($duplicate->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Role name already exists']);
            return;
        }

        $stmt = $pdo->prepare('UPDATE UserRole SET roleName = :name, permissions = :description WHERE roleID = :id');
        $stmt->execute([
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':id' => $id,
        ]);

        echo json_encode(['ok' => true]);
        return;
    }

    $duplicate = $pdo->prepare('SELECT roleID FROM UserRole WHERE roleName = :name LIMIT 1');
    $duplicate->execute([':name' => $name]);
    if ($duplicate->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Role name already exists']);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO UserRole (roleName, permissions) VALUES (:name, :description)');
    $stmt->execute([
        ':name' => $name,
        ':description' => $description !== '' ? $description : null,
    ]);

    echo json_encode(['ok' => true, 'roleID' => (int) $pdo->lastInsertId()]);
}

function deleteRole(PDO $pdo): void
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $id = isset($payload['roleID']) ? (int) $payload['roleID'] : null;
    if ($id === null) {
        http_response_code(400);
        echo json_encode(['error' => 'roleID is required']);
        return;
    }

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM Administrator WHERE roleID = :id');
    $inUse->execute([':id' => $id]);
    if ((int) $inUse->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Role is currently in use and cannot be deleted']);
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM UserRole WHERE roleID = :id');
    $stmt->execute([':id' => $id]);

    echo json_encode(['ok' => true]);
}
