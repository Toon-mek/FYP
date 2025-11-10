<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/notifications.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

handleCreateAnnouncement($pdo);

function handleCreateAnnouncement(PDO $pdo): void
{
    $raw = file_get_contents('php://input') ?: '{}';
    $payload = json_decode($raw, true);
    
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        return;
    }

    $title = trim((string) ($payload['title'] ?? ''));
    $message = trim((string) ($payload['message'] ?? ''));
    $adminId = isset($payload['adminId']) ? (int) $payload['adminId'] : 0;

    if (empty($title)) {
        http_response_code(400);
        echo json_encode(['error' => 'Title is required']);
        return;
    }

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['error' => 'Message is required']);
        return;
    }

    if ($adminId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid admin ID']);
        return;
    }

    // Truncate title to 120 characters (matching notification helper)
    $title = mb_substr($title, 0, 120);

    try {
        $pdo->beginTransaction();

        // Get all Travelers
        $travelerStmt = $pdo->query('SELECT travelerID FROM Traveler');
        $travelerIds = [];
        while ($row = $travelerStmt->fetch(PDO::FETCH_ASSOC)) {
            $travelerIds[] = (int) $row['travelerID'];
        }

        // Get all Operators
        $operatorStmt = $pdo->query('SELECT operatorID FROM TourismOperator');
        $operatorIds = [];
        while ($row = $operatorStmt->fetch(PDO::FETCH_ASSOC)) {
            $operatorIds[] = (int) $row['operatorID'];
        }

        $totalNotifications = 0;

        // Format notification title for Travelers and Operators
        $userNotificationTitle = sprintf('Announcement: %s', $title);
        // Ensure title doesn't exceed 120 characters (notification helper limit)
        if (mb_strlen($userNotificationTitle) > 120) {
            $userNotificationTitle = 'Announcement: ' . mb_substr($title, 0, 120 - mb_strlen('Announcement: '));
        }

        // Send notifications to all Travelers
        foreach ($travelerIds as $travelerId) {
            recordNotification($pdo, 'Traveler', $travelerId, $userNotificationTitle, $message);
            $totalNotifications++;
        }

        // Send notifications to all Operators
        foreach ($operatorIds as $operatorId) {
            recordNotification($pdo, 'Operator', $operatorId, $userNotificationTitle, $message);
            $totalNotifications++;
        }

        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'message' => 'Announcement created successfully',
            'stats' => [
                'travelersNotified' => count($travelerIds),
                'operatorsNotified' => count($operatorIds),
                'totalNotifications' => $totalNotifications,
            ],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error creating announcement: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create announcement']);
    }
}

