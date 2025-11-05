<?php
declare(strict_types=1);

/**
 * Normalise the recipient type and return the canonical value stored in the database.
 */
function normaliseRecipientType(string $type): ?string
{
    $value = strtolower(trim($type));

    return match ($value) {
        'admin', 'administrator' => 'Admin',
        'operator', 'business', 'business operator' => 'Operator',
        'traveler', 'traveller', 'user' => 'Traveler',
        default => null,
    };
}

/**
 * Record a notification for the given recipient.
 */
function recordNotification(PDO $pdo, string $recipientType, int $recipientId, string $title, string $message): void
{
    $type = normaliseRecipientType($recipientType);
    if ($type === null || $recipientId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO Notification (recipientType, recipientID, title, message, createdAt, isRead)
         VALUES (:recipientType, :recipientID, :title, :message, :createdAt, 0)'
    );

    $stmt->execute([
        ':recipientType' => $type,
        ':recipientID' => $recipientId,
        ':title' => mb_substr($title, 0, 120),
        ':message' => $message,
        ':createdAt' => resolveNotificationTimestamp(),
    ]);
}

/**
 * Fetch notifications for a recipient.
 *
 * @return array<int, array<string, mixed>>
 */
function fetchNotifications(
    PDO $pdo,
    string $recipientType,
    int $recipientId,
    int $limit = 25,
    bool $unreadOnly = false
): array {
    $type = normaliseRecipientType($recipientType);
    if ($type === null || $recipientId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 100));

    $sql = 'SELECT notificationID AS id, title, message, createdAt, isRead
            FROM Notification
            WHERE recipientType = :recipientType AND recipientID = :recipientID';
    if ($unreadOnly) {
        $sql .= ' AND isRead = 0';
    }
    $sql .= ' ORDER BY createdAt DESC, notificationID DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':recipientType', $type);
    $stmt->bindValue(':recipientID', $recipientId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
            'isRead' => isset($row['isRead']) ? (bool) (int) $row['isRead'] : false,
        ];
    }

    return $notifications;
}

/**
 * Mark selected notifications as read for the recipient.
 */
function markNotificationsRead(PDO $pdo, string $recipientType, int $recipientId, array $notificationIds): int
{
    $type = normaliseRecipientType($recipientType);
    if ($type === null || $recipientId <= 0) {
        return 0;
    }

    $ids = array_values(
        array_unique(
            array_filter(
                array_map(static fn($value): int => (int) $value, $notificationIds),
                static fn(int $value): bool => $value > 0
            )
        )
    );

    if (!$ids) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = sprintf(
        'UPDATE Notification
         SET isRead = 1
         WHERE recipientType = ? AND recipientID = ? AND notificationID IN (%s)',
        $placeholders
    );

    $params = [$type, $recipientId];
    foreach ($ids as $id) {
        $params[] = $id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

function resolveNotificationTimestamp(): string
{
    static $timezone = null;
    if ($timezone === null) {
        $configured = $_ENV['APP_TIMEZONE'] ?? 'Asia/Kuala_Lumpur';
        try {
            $timezone = new DateTimeZone($configured);
        } catch (Throwable) {
            $timezone = new DateTimeZone('UTC');
        }
    }

    return (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
}
