<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers/notifications.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../config/db.php';
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($method) {
    case 'GET':
        handleGet($pdo);
        break;
    case 'POST':
        handlePost($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function handleGet(PDO $pdo): void
{
    $scopeParam = strtolower(trim((string) ($_GET['scope'] ?? ($_GET['mode'] ?? 'inbox'))));
    if (in_array($scopeParam, ['sent', 'outbox', 'all'], true)) {
        handleGetSent($pdo);
        return;
    }

    handleGetByRecipient($pdo);
}

function handleGetByRecipient(PDO $pdo): void
{
    $recipientType = $_GET['recipientType'] ?? ($_GET['recipient_type'] ?? '');
    $recipientId = isset($_GET['recipientId']) ? (int) $_GET['recipientId'] : (int) ($_GET['recipient_id'] ?? 0);
    $limit = isset($_GET['limit']) ? clampLimit((int) $_GET['limit']) : 25;
    $unreadOnly = isset($_GET['unreadOnly']) ? filter_var($_GET['unreadOnly'], FILTER_VALIDATE_BOOLEAN) : false;

    if ($recipientId <= 0 || normaliseRecipientType($recipientType) === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid recipient information']);
        return;
    }

    $notifications = fetchNotifications($pdo, $recipientType, $recipientId, $limit, $unreadOnly);
    $unreadCount = countUnreadNotifications($pdo, $recipientType, $recipientId);

    echo json_encode([
        'notifications' => $notifications,
        'meta' => [
            'unreadCount' => $unreadCount,
            'limit' => $limit,
            'recipientType' => normaliseRecipientType($recipientType),
            'recipientId' => $recipientId,
        ],
    ]);
}

function handlePost(PDO $pdo): void
{
    $raw = file_get_contents('php://input') ?: '[]';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $action = strtolower(trim((string) ($payload['action'] ?? 'mark-read')));
    $recipientType = (string) ($payload['recipientType'] ?? $payload['recipient_type'] ?? '');
    $recipientId = isset($payload['recipientId']) ? (int) $payload['recipientId'] : (int) ($payload['recipient_id'] ?? 0);
    $notificationIds = $payload['notificationIds'] ?? $payload['notification_ids'] ?? [];

    if ($recipientId <= 0 || normaliseRecipientType($recipientType) === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid recipient information']);
        return;
    }

    if ($action !== 'mark-read') {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported action']);
        return;
    }

    if (!is_array($notificationIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'notificationIds must be an array']);
        return;
    }

    $updated = markNotificationsRead($pdo, $recipientType, $recipientId, $notificationIds);
    $unreadCount = countUnreadNotifications($pdo, $recipientType, $recipientId);

    echo json_encode([
        'ok' => true,
        'updated' => $updated,
        'meta' => [
            'unreadCount' => $unreadCount,
        ],
    ]);
}

function countUnreadNotifications(PDO $pdo, string $recipientType, int $recipientId): int
{
    $type = normaliseRecipientType($recipientType);
    if ($type === null || $recipientId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM Notification WHERE recipientType = :recipientType AND recipientID = :recipientID AND isRead = 0'
    );
    $stmt->execute([
        ':recipientType' => $type,
        ':recipientID' => $recipientId,
    ]);

    return (int) $stmt->fetchColumn();
}

function handleGetSent(PDO $pdo): void
{
    $limit = isset($_GET['limit']) ? clampLimit((int) $_GET['limit']) : 50;
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $recipientTypeParam = $_GET['recipientType'] ?? ($_GET['recipient_type'] ?? ($_GET['audience'] ?? ''));
    $recipientType = null;
    if (is_string($recipientTypeParam)) {
        $recipientTypeParam = trim($recipientTypeParam);
        if ($recipientTypeParam !== '' && strcasecmp($recipientTypeParam, 'all') !== 0) {
            $recipientType = normaliseRecipientType($recipientTypeParam);
            if ($recipientType === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Unsupported recipient type filter']);
                return;
            }
        }
    }

    $whereClause = '';
    $params = [];
    if ($recipientType !== null) {
        $whereClause = 'WHERE n.recipientType = :recipientType';
        $params[':recipientType'] = $recipientType;
    }

    $total = fetchSentNotificationsTotal($pdo, $whereClause, $params);

    $sql = <<<SQL
SELECT
    n.notificationID AS id,
    n.recipientType,
    n.recipientID,
    n.title,
    n.message,
    n.createdAt,
    n.isRead,
    CASE n.recipientType
        WHEN 'Traveler' THEN COALESCE(t.fullName, t.username, CONCAT('Traveler #', n.recipientID))
        WHEN 'Operator' THEN COALESCE(o.fullName, o.username, CONCAT('Operator #', n.recipientID))
        WHEN 'Admin' THEN COALESCE(a.fullName, a.email, CONCAT('Admin #', n.recipientID))
        ELSE CONCAT('User #', n.recipientID)
    END AS recipientName,
    CASE n.recipientType
        WHEN 'Traveler' THEN t.email
        WHEN 'Operator' THEN o.email
        WHEN 'Admin' THEN a.email
        ELSE NULL
    END AS recipientEmail
FROM Notification n
LEFT JOIN Traveler t ON n.recipientType = 'Traveler' AND t.travelerID = n.recipientID
LEFT JOIN TourismOperator o ON n.recipientType = 'Operator' AND o.operatorID = n.recipientID
LEFT JOIN Administrator a ON n.recipientType = 'Admin' AND a.adminID = n.recipientID
{$whereClause}
ORDER BY n.createdAt DESC, n.notificationID DESC
LIMIT :limit OFFSET :offset
SQL;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => (int) ($row['id'] ?? 0),
            'recipientType' => (string) ($row['recipientType'] ?? ''),
            'recipientId' => (int) ($row['recipientID'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
            'isRead' => isset($row['isRead']) ? (bool) (int) $row['isRead'] : false,
            'recipient' => [
                'name' => (string) ($row['recipientName'] ?? ''),
                'email' => $row['recipientEmail'] ?? null,
            ],
        ];
    }

    echo json_encode([
        'notifications' => $notifications,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'recipientType' => $recipientType,
        ],
    ]);
}

function fetchSentNotificationsTotal(PDO $pdo, string $whereClause, array $params): int
{
    $sql = "SELECT COUNT(*) FROM Notification n {$whereClause}";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function clampLimit(int $limit): int
{
    if ($limit <= 0) {
        return 25;
    }
    if ($limit > 200) {
        return 200;
    }
    return $limit;
}
