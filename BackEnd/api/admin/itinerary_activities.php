<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$limit = max(1, min(100, $limit));

try {
    // Fetch itinerary creations
    $itineraryStmt = $pdo->prepare("
        SELECT 
            i.itineraryID,
            i.travelerID,
            i.title,
            i.destination,
            i.startDate as createdAt,
            COALESCE(t.fullName, t.username, CONCAT('Traveler #', t.travelerID)) as travelerName,
            'itinerary' as activityType
        FROM itinerary i
        LEFT JOIN Traveler t ON i.travelerID = t.travelerID
        ORDER BY i.itineraryID DESC
        LIMIT :limit
    ");
    $itineraryStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $itineraryStmt->execute();
    $itineraries = $itineraryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch payment/booking activities
    $paymentStmt = $pdo->prepare("
        SELECT 
            ps.sessionID,
            ps.travelerID,
            ps.bookingRef,
            ps.amount,
            ps.currency,
            ps.status,
            ps.createdAt,
            COALESCE(t.fullName, t.username, CONCAT('Traveler #', t.travelerID)) as travelerName,
            'payment' as activityType
        FROM payment_session ps
        LEFT JOIN Traveler t ON ps.travelerID = t.travelerID
        WHERE ps.status = 'authorized'
        ORDER BY ps.sessionID DESC
        LIMIT :limit
    ");
    $paymentStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $paymentStmt->execute();
    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'itineraries' => $itineraries,
        'payments' => $payments
    ]);
    
} catch (Throwable $e) {
    error_log('Itinerary activities error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to load itinerary activities'
    ]);
}
