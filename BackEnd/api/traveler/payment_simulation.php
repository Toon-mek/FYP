<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
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

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strtolower($_GET['action'] ?? $_POST['action'] ?? '');

try {
    ensurePaymentTables($pdo);
    seedPaymentMethods($pdo);

    if ($method === 'GET') {
        switch ($action) {
            case 'methods':
                handleListMethods($pdo);
                break;
            case 'session':
                handleGetSession($pdo);
                break;
            case 'history':
                handleHistory($pdo);
                break;
            case 'bookings':
                handleBookings($pdo);
                break;
            default:
                respond(400, ['error' => 'Unknown GET action']);
        }
    } elseif ($method === 'POST') {
        switch ($action) {
            case 'start':
                handleStartSession($pdo);
                break;
            case 'authorize':
                handleAuthorizeSession($pdo);
                break;
            case 'retry':
                handleRetrySession($pdo);
                break;
            default:
                respond(400, ['error' => 'Unknown POST action']);
        }
    } else {
        respond(405, ['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'error' => 'Payment simulation failed',
        'details' => $e->getMessage(),
    ]);
}

function handleListMethods(PDO $pdo): void
{
    $methods = $pdo->query('SELECT * FROM payment_method_catalog WHERE isActive = 1 ORDER BY sortOrder ASC, methodID ASC')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $payload = array_map('normaliseMethodRecord', $methods);
    respond(200, ['methods' => $payload]);
}

function handleGetSession(PDO $pdo): void
{
    $sessionId = (int)($_GET['sessionId'] ?? 0);
    if ($sessionId <= 0) {
        respond(400, ['error' => 'sessionId is required']);
        return;
    }
    $session = fetchSession($pdo, $sessionId);
    if (!$session) {
        respond(404, ['error' => 'Session not found']);
        return;
    }
    $events = fetchSessionEvents($pdo, $sessionId);
    $receipt = fetchReceipt($pdo, $sessionId);
    respond(200, ['session' => $session, 'events' => $events, 'receipt' => $receipt]);
}

function handleHistory(PDO $pdo): void
{
    $travelerId = (int)($_GET['travelerId'] ?? 0);
    if ($travelerId <= 0) {
        respond(400, ['error' => 'travelerId is required']);
        return;
    }
    $limit = (int)($_GET['limit'] ?? 5);
    if ($limit <= 0 || $limit > 50) {
        $limit = 5;
    }
    $stmt = $pdo->prepare(
        'SELECT ps.*, pr.receiptNo, pr.paidAt
         FROM payment_session ps
         LEFT JOIN payment_receipt_detail pr ON pr.sessionID = ps.sessionID
         WHERE ps.travelerID = :travelerId
         ORDER BY ps.createdAt DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':travelerId', $travelerId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $sessions = array_map(static function ($row) {
        $session = normaliseSessionRecord($row);
        if (!empty($row['receiptNo'])) {
            $session['receiptNo'] = $row['receiptNo'];
            $session['paidAt'] = $row['paidAt'];
        }
        return $session;
    }, $rows);
    respond(200, ['sessions' => $sessions]);
}

function handleBookings(PDO $pdo): void
{
    $travelerId = (int)($_GET['travelerId'] ?? 0);
    if ($travelerId <= 0) {
        respond(200, ['bookings' => []]);
        return;
    }

    $bookings = [];

    try {
        $stmtHistory = $pdo->prepare(
            'SELECT h.*,
                    COALESCE(pr.receiptNo, h.receiptNo) AS effectiveReceiptNo,
                    COALESCE(pr.paidAt, h.paidAt) AS effectivePaidAt
             FROM traveler_booking_history h
             LEFT JOIN payment_receipt_detail pr ON pr.receiptID = h.receiptID
             WHERE h.travelerID = :travelerId
             ORDER BY h.paidAt DESC, h.createdAt DESC'
        );
        $stmtHistory->execute([':travelerId' => $travelerId]);
        $rows = $stmtHistory->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bookings = array_map('normaliseBookingRecord', $rows);
    } catch (Throwable $e) {
        // swallow and fall back to live sessions
    }

    try {
        $stmtSessions = $pdo->prepare(
            'SELECT ps.*, pr.receiptID, pr.receiptNo, pr.paidAt,
                    pkg.title AS packageTitle,
                    pkg.destination AS packageDestination,
                    pkg.summary AS packageSummary,
                    pkg.selections AS packageSelections
             FROM payment_session ps
             LEFT JOIN payment_receipt_detail pr ON pr.sessionID = ps.sessionID
             LEFT JOIN traveler_booking_history h ON h.sessionID = ps.sessionID
             LEFT JOIN traveler_saved_place_package pkg ON pkg.packageID = ps.packageID
             WHERE ps.travelerID = :travelerId
               AND ps.status = \'authorized\'
               AND h.historyID IS NULL
             ORDER BY COALESCE(pr.paidAt, ps.updatedAt, ps.createdAt) DESC'
        );
        $stmtSessions->execute([':travelerId' => $travelerId]);
        $sessionRows = $stmtSessions->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fallbackBookings = array_map('normaliseBookingRecordFromSession', $sessionRows);
        $bookings = array_merge($bookings, $fallbackBookings);
    } catch (Throwable $e) {
        // ignore fallback errors
    }

    respond(200, ['bookings' => $bookings]);
}

function handleStartSession(PDO $pdo): void
{
    $payload = readJsonPayload();
    $travelerId = (int)($payload['travelerId'] ?? 0);
    $packageId = (int)($payload['packageId'] ?? 0);
    $amount = (float)($payload['amount'] ?? 0);
    $currency = strtoupper((string)($payload['currency'] ?? 'MYR'));
    $methodCode = trim((string)($payload['methodCode'] ?? ''));
    $customer = $payload['customer'] ?? [];
    $fields = $payload['fields'] ?? [];
    $clientContext = $payload['clientContext'] ?? null;
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($travelerId <= 0 || $packageId <= 0) {
        respond(400, ['error' => 'travelerId and packageId are required']);
        return;
    }
    if ($amount <= 0) {
        respond(400, ['error' => 'A valid amount is required for simulation']);
        return;
    }
    if ($methodCode === '') {
        respond(400, ['error' => 'methodCode is required']);
        return;
    }
    $method = fetchMethodByCode($pdo, $methodCode);
    if (!$method) {
        respond(404, ['error' => 'Payment method not found']);
        return;
    }
    $package = fetchPackageSummary($pdo, $travelerId, $packageId);
    if (!$package) {
        respond(404, ['error' => 'Package not found']);
        return;
    }

    $sessionFields = normaliseFieldValues($method['fields'] ?? [], $fields);
    $snapshot = [
        'methodId' => $method['methodId'],
        'code' => $method['code'],
        'displayName' => $method['displayName'],
        'category' => $method['category'],
        'logoPath' => $method['logoPath'],
        'tagline' => $method['tagline'],
        'processingTime' => $method['processingTime'],
        'feeLabel' => $method['feeLabel'],
        'accentColor' => $method['accentColor'],
        'fieldsCaptured' => $sessionFields,
    ];

    $bookingRef = generateBookingReference($travelerId);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO payment_session
                (travelerID, packageID, bookingRef, amount, currency, methodID, methodSnapshot, status, clientContext, createdAt, updatedAt)
             VALUES
                (:travelerId, :packageId, :bookingRef, :amount, :currency, :methodId, :snapshot, :status, :context, NOW(), NOW())'
        );
        $status = 'awaiting_authorization';
        $insert->execute([
            ':travelerId' => $travelerId,
            ':packageId' => $packageId,
            ':bookingRef' => $bookingRef,
            ':amount' => $amount,
            ':currency' => $currency,
            ':methodId' => $method['methodId'],
            ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => $status,
            ':context' => $clientContext ? json_encode($clientContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
        $sessionId = (int)$pdo->lastInsertId();

        recordSessionEvent($pdo, $sessionId, 'created', [
            'bookingRef' => $bookingRef,
            'amount' => $amount,
            'currency' => $currency,
            'notes' => $notes,
        ]);
        recordSessionEvent($pdo, $sessionId, 'method_selected', [
            'methodCode' => $method['code'],
            'displayName' => $method['displayName'],
        ]);
        recordSessionEvent($pdo, $sessionId, 'authorization_started', [
            'channel' => $method['category'],
            'fields' => $sessionFields,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $session = fetchSession($pdo, $sessionId);
    $events = fetchSessionEvents($pdo, $sessionId);

    respond(201, [
        'session' => $session,
        'events' => $events,
        'receipt' => null,
        'package' => $package,
    ]);
}

function handleAuthorizeSession(PDO $pdo): void
{
    $payload = readJsonPayload();
    $sessionId = (int)($payload['sessionId'] ?? 0);
    $outcome = strtolower((string)($payload['outcome'] ?? 'success'));
    $remarks = trim((string)($payload['remarks'] ?? ''));
    $proofCode = trim((string)($payload['proofCode'] ?? ''));
    $device = trim((string)($payload['device'] ?? ''));
    $fallbackPayer = $payload['payer'] ?? [];
    $fields = $payload['fields'] ?? [];

    if ($sessionId <= 0) {
        respond(400, ['error' => 'sessionId is required']);
        return;
    }
    $session = fetchSessionRow($pdo, $sessionId);
    if (!$session) {
        respond(404, ['error' => 'Session not found']);
        return;
    }
    if (!in_array($outcome, ['success', 'failed'], true)) {
        respond(400, ['error' => 'Invalid outcome']);
        return;
    }
    if ($session['status'] === 'authorized') {
        $response = [
            'session' => normaliseSessionRecord($session),
            'events' => fetchSessionEvents($pdo, $sessionId),
            'receipt' => fetchReceipt($pdo, $sessionId),
        ];
        respond(200, $response);
        return;
    }

    $methodSnapshot = json_decode($session['methodSnapshot'] ?? '', true) ?: [];
    $methodFields = $methodSnapshot['fieldsCaptured'] ?? [];
    $mergedFields = $methodFields;
    if (is_array($fields)) {
        foreach ($fields as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value)) {
                $mergedFields[$key] = trim($value);
            } else {
                $mergedFields[$key] = $value;
            }
        }
    }

    $packageSnapshot = null;
    if (!empty($session['packageID'])) {
        $packageSnapshot = fetchPackageSummary(
            $pdo,
            (int)$session['travelerID'],
            (int)$session['packageID']
        );
    }

    $pdo->beginTransaction();
    try {
        if ($outcome === 'success') {
            $update = $pdo->prepare('UPDATE payment_session SET status = :status, failureReason = NULL WHERE sessionID = :sessionId');
            $update->execute([':status' => 'authorized', ':sessionId' => $sessionId]);

            recordSessionEvent($pdo, $sessionId, 'authorization_completed', [
                'proofCode' => $proofCode ?: generateAuthorizationCode(),
                'device' => $device ?: 'web-sim',
            ]);

            $receipt = createReceipt($pdo, $session, $fallbackPayer, $mergedFields);
            recordSessionEvent($pdo, $sessionId, 'receipt_generated', [
                'receiptNo' => $receipt['receiptNo'],
                'paidAt' => $receipt['paidAt'],
            ]);

            try {
                recordBookingHistory($pdo, $session, $receipt, $packageSnapshot);
            } catch (Throwable $historyError) {
                recordSessionEvent($pdo, $sessionId, 'booking_history_failed', [
                    'reason' => $historyError->getMessage(),
                ]);
            }
            if (!empty($session['packageID'])) {
                try {
                    archiveSavedPackage($pdo, (int)$session['packageID'], (int)$session['travelerID']);
                } catch (Throwable $archiveError) {
                    recordSessionEvent($pdo, $sessionId, 'saved_package_archive_failed', [
                        'reason' => $archiveError->getMessage(),
                    ]);
                }
            }
        } else {
            $update = $pdo->prepare('UPDATE payment_session SET status = :status, failureReason = :reason WHERE sessionID = :sessionId');
            $update->execute([
                ':status' => 'failed',
                ':reason' => $remarks ?: 'Authorization declined',
                ':sessionId' => $sessionId,
            ]);
            recordSessionEvent($pdo, $sessionId, 'authorization_failed', [
                'remarks' => $remarks ?: 'Authorization declined',
                'device' => $device ?: 'web-sim',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $session = fetchSession($pdo, $sessionId);
    $events = fetchSessionEvents($pdo, $sessionId);
    $receipt = fetchReceipt($pdo, $sessionId);

    respond(200, [
        'session' => $session,
        'events' => $events,
        'receipt' => $receipt,
    ]);
}

function handleRetrySession(PDO $pdo): void
{
    $payload = readJsonPayload();
    $sessionId = (int)($payload['sessionId'] ?? 0);
    if ($sessionId <= 0) {
        respond(400, ['error' => 'sessionId is required']);
        return;
    }
    $session = fetchSessionRow($pdo, $sessionId);
    if (!$session) {
        respond(404, ['error' => 'Session not found']);
        return;
    }

    if ($session['status'] === 'authorized') {
        respond(400, ['error' => 'Authorized sessions cannot be retried']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE payment_session SET status = :status, failureReason = NULL WHERE sessionID = :sessionId');
        $update->execute([
            ':status' => 'awaiting_authorization',
            ':sessionId' => $sessionId,
        ]);
        recordSessionEvent($pdo, $sessionId, 'retry_requested', [
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $session = fetchSession($pdo, $sessionId);
    $events = fetchSessionEvents($pdo, $sessionId);
    respond(200, ['session' => $session, 'events' => $events, 'receipt' => fetchReceipt($pdo, $sessionId)]);
}

function ensurePaymentTables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payment_method_catalog (
            methodID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            displayName VARCHAR(80) NOT NULL,
            category ENUM(\'fpx\', \'card\', \'ewallet\') NOT NULL,
            logoPath VARCHAR(255) DEFAULT NULL,
            isActive TINYINT(1) NOT NULL DEFAULT 1,
            sortOrder TINYINT UNSIGNED NOT NULL DEFAULT 0,
            createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payment_session (
            sessionID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            travelerID INT NOT NULL,
            packageID INT DEFAULT NULL,
            bookingRef VARCHAR(32) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT \'MYR\',
            methodID INT UNSIGNED NOT NULL,
            methodSnapshot JSON NOT NULL,
            status ENUM(\'initiated\', \'awaiting_authorization\', \'authorized\', \'failed\', \'expired\', \'refunded\') NOT NULL DEFAULT \'initiated\',
            failureReason VARCHAR(255) DEFAULT NULL,
            clientContext JSON DEFAULT NULL,
            createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_ps_traveler FOREIGN KEY (travelerID) REFERENCES traveler(travelerID),
            CONSTRAINT fk_ps_package FOREIGN KEY (packageID) REFERENCES traveler_saved_place_package(packageID) ON DELETE SET NULL,
            CONSTRAINT fk_ps_method FOREIGN KEY (methodID) REFERENCES payment_method_catalog(methodID),
            UNIQUE KEY ux_ps_bookingRef (bookingRef),
            INDEX idx_ps_traveler (travelerID, createdAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payment_session_event (
            eventID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sessionID BIGINT UNSIGNED NOT NULL,
            eventType ENUM(\'created\', \'method_selected\', \'authorization_started\', \'authorization_completed\', \'authorization_failed\', \'receipt_generated\', \'retry_requested\') NOT NULL,
            payload JSON DEFAULT NULL,
            createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pse_session FOREIGN KEY (sessionID) REFERENCES payment_session(sessionID) ON DELETE CASCADE,
            INDEX idx_pse_session (sessionID, createdAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payment_receipt_detail (
            receiptID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sessionID BIGINT UNSIGNED NOT NULL,
            receiptNo VARCHAR(40) NOT NULL UNIQUE,
            payerName VARCHAR(120) NOT NULL,
            payerEmail VARCHAR(120) DEFAULT NULL,
            payerPhone VARCHAR(30) DEFAULT NULL,
            paymentChannel VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT \'MYR\',
            paidAt DATETIME NOT NULL,
            metadata JSON DEFAULT NULL,
            CONSTRAINT fk_prd_session FOREIGN KEY (sessionID) REFERENCES payment_session(sessionID) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS traveler_booking_history (
            historyID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            travelerID INT NOT NULL,
            packageID INT DEFAULT NULL,
            sessionID BIGINT UNSIGNED NOT NULL,
            receiptID BIGINT UNSIGNED DEFAULT NULL,
            receiptNo VARCHAR(40) DEFAULT NULL,
            bookingRef VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL,
            destination VARCHAR(255) DEFAULT NULL,
            summary JSON DEFAULT NULL,
            selections JSON DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT \'MYR\',
            status ENUM(\'confirmed\', \'refunded\', \'cancelled\') NOT NULL DEFAULT \'confirmed\',
            paidAt DATETIME NOT NULL,
            createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_tbh_traveler FOREIGN KEY (travelerID) REFERENCES traveler(travelerID),
            CONSTRAINT fk_tbh_session FOREIGN KEY (sessionID) REFERENCES payment_session(sessionID) ON DELETE CASCADE,
            CONSTRAINT fk_tbh_receipt FOREIGN KEY (receiptID) REFERENCES payment_receipt_detail(receiptID) ON DELETE SET NULL,
            CONSTRAINT fk_tbh_package FOREIGN KEY (packageID) REFERENCES traveler_saved_place_package(packageID) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Ensure legacy schemas have the fields required by the simulator.
    $pdo->exec(
        'ALTER TABLE traveler_booking_history
            ADD COLUMN IF NOT EXISTS receiptNo VARCHAR(40) DEFAULT NULL AFTER receiptID'
    );
    $pdo->exec(
        'ALTER TABLE traveler_booking_history
            ADD COLUMN IF NOT EXISTS bookingRef VARCHAR(32) NOT NULL DEFAULT \'\' AFTER receiptNo'
    );
}

function seedPaymentMethods(PDO $pdo): void
{
    $defaults = [
        ['code' => 'fpx_maybank', 'displayName' => 'FPX • Maybank2u', 'category' => 'fpx', 'logoPath' => null, 'sortOrder' => 5],
        ['code' => 'fpx_cimb', 'displayName' => 'FPX • CIMB Clicks', 'category' => 'fpx', 'logoPath' => null, 'sortOrder' => 10],
        ['code' => 'fpx_rhb', 'displayName' => 'FPX • RHB Now', 'category' => 'fpx', 'logoPath' => null, 'sortOrder' => 12],
        ['code' => 'fpx_publicbank', 'displayName' => 'FPX • PBe / PB engage', 'category' => 'fpx', 'logoPath' => null, 'sortOrder' => 14],
        ['code' => 'fpx_hongleong', 'displayName' => 'FPX • Hong Leong Connect', 'category' => 'fpx', 'logoPath' => null, 'sortOrder' => 16],
        ['code' => 'fpx_bankislam', 'displayName' => 'FPX • Bank Islam', 'category' => 'fpx', 'logoPath' => null, 'sortOrder' => 18],
        ['code' => 'card_visa_master', 'displayName' => 'Visa / Mastercard', 'category' => 'card', 'logoPath' => null, 'sortOrder' => 15],
        ['code' => 'card_amex', 'displayName' => 'American Express', 'category' => 'card', 'logoPath' => null, 'sortOrder' => 20],
        ['code' => 'ewallet_tng', 'displayName' => 'Touch \'n Go eWallet', 'category' => 'ewallet', 'logoPath' => null, 'sortOrder' => 25],
        ['code' => 'ewallet_boost', 'displayName' => 'Boost Wallet', 'category' => 'ewallet', 'logoPath' => null, 'sortOrder' => 30],
        ['code' => 'ewallet_grabpay', 'displayName' => 'GrabPay Wallet', 'category' => 'ewallet', 'logoPath' => null, 'sortOrder' => 32],
        ['code' => 'ewallet_shopeepay', 'displayName' => 'ShopeePay Wallet', 'category' => 'ewallet', 'logoPath' => null, 'sortOrder' => 34],
    ];

    $insert = $pdo->prepare(
        'INSERT INTO payment_method_catalog (code, displayName, category, logoPath, sortOrder)
         VALUES (:code, :displayName, :category, :logoPath, :sortOrder)'
    );
    foreach ($defaults as $method) {
        $exists = $pdo->prepare('SELECT methodID FROM payment_method_catalog WHERE code = :code LIMIT 1');
        $exists->execute([':code' => $method['code']]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $insert->execute([
            ':code' => $method['code'],
            ':displayName' => $method['displayName'],
            ':category' => $method['category'],
            ':logoPath' => $method['logoPath'],
            ':sortOrder' => $method['sortOrder'],
        ]);
    }
}

function fetchMethodByCode(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM payment_method_catalog WHERE code = :code AND isActive = 1 LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? normaliseMethodRecord($row) : null;
}

function fetchPackageSummary(PDO $pdo, int $travelerId, int $packageId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT packageID, travelerID, title, destination, summary, selections, createdAt
         FROM traveler_saved_place_package
         WHERE packageID = :packageId AND travelerID = :travelerId
         LIMIT 1'
    );
    $stmt->execute([':packageId' => $packageId, ':travelerId' => $travelerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'packageId' => (int)$row['packageID'],
        'travelerId' => (int)$row['travelerID'],
        'title' => $row['title'],
        'destination' => $row['destination'] ?? '',
        'summary' => decodeJson($row['summary']),
        'selections' => decodeJson($row['selections']),
        'createdAt' => $row['createdAt'],
    ];
}

function recordSessionEvent(PDO $pdo, int $sessionId, string $type, array $payload = []): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO payment_session_event (sessionID, eventType, payload, createdAt)
         VALUES (:sessionId, :type, :payload, NOW())'
    );
    $stmt->execute([
        ':sessionId' => $sessionId,
        ':type' => $type,
        ':payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function fetchSessionRow(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM payment_session WHERE sessionID = :sessionId LIMIT 1');
    $stmt->execute([':sessionId' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchSession(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT ps.*, pm.code, pm.displayName, pm.category, pm.logoPath
         FROM payment_session ps
         INNER JOIN payment_method_catalog pm ON pm.methodID = ps.methodID
         WHERE ps.sessionID = :sessionId
         LIMIT 1'
    );
    $stmt->execute([':sessionId' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? normaliseSessionRecord($row) : null;
}

function fetchSessionEvents(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare(
        'SELECT eventID, eventType, payload, createdAt
         FROM payment_session_event
         WHERE sessionID = :sessionId
         ORDER BY createdAt ASC, eventID ASC'
    );
    $stmt->execute([':sessionId' => $sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static function ($row) {
        return [
            'eventId' => (int)$row['eventID'],
            'type' => $row['eventType'],
            'payload' => decodeJson($row['payload']),
            'createdAt' => $row['createdAt'],
        ];
    }, $rows);
}

function fetchReceipt(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM payment_receipt_detail WHERE sessionID = :sessionId LIMIT 1');
    $stmt->execute([':sessionId' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'receiptId' => (int)$row['receiptID'],
        'sessionId' => (int)$row['sessionID'],
        'receiptNo' => $row['receiptNo'],
        'payerName' => $row['payerName'],
        'payerEmail' => $row['payerEmail'],
        'payerPhone' => $row['payerPhone'],
        'paymentChannel' => $row['paymentChannel'],
        'amount' => (float)$row['amount'],
        'currency' => $row['currency'],
        'paidAt' => $row['paidAt'],
        'metadata' => decodeJson($row['metadata']),
    ];
}

function createReceipt(PDO $pdo, array $sessionRow, array $fallbackPayer, array $fields): array
{
    $sessionId = (int)$sessionRow['sessionID'];
    $existing = fetchReceipt($pdo, $sessionId);
    if ($existing) {
        return $existing;
    }
    $methodSnapshot = json_decode($sessionRow['methodSnapshot'] ?? '', true) ?: [];
    $payerName = trim((string)($fallbackPayer['name'] ?? 'Traveler'));
    if (!$payerName && isset($fields['accountName'])) {
        $payerName = (string)$fields['accountName'];
    }
    if (!$payerName) {
        $payerName = 'Traveler';
    }
    $receiptNo = sprintf('RC%s%s', date('ymdHis'), strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
    $metadata = [
        'method' => $methodSnapshot,
        'fields' => $fields,
    ];
    $stmt = $pdo->prepare(
        'INSERT INTO payment_receipt_detail
            (sessionID, receiptNo, payerName, payerEmail, payerPhone, paymentChannel, amount, currency, paidAt, metadata)
         VALUES
            (:sessionId, :receiptNo, :payerName, :payerEmail, :payerPhone, :channel, :amount, :currency, NOW(), :metadata)'
    );
    $stmt->execute([
        ':sessionId' => $sessionId,
        ':receiptNo' => $receiptNo,
        ':payerName' => $payerName,
        ':payerEmail' => trim((string)($fallbackPayer['email'] ?? '')),
        ':payerPhone' => trim((string)($fallbackPayer['phone'] ?? '')),
        ':channel' => $methodSnapshot['displayName'] ?? 'Simulated Channel',
        ':amount' => $sessionRow['amount'],
        ':currency' => $sessionRow['currency'],
        ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return fetchReceipt($pdo, $sessionId);
}

function recordBookingHistory(PDO $pdo, array $sessionRow, ?array $receipt, ?array $packageSnapshot = null): void
{
    $sessionId = (int)$sessionRow['sessionID'];
    $check = $pdo->prepare('SELECT historyID FROM traveler_booking_history WHERE sessionID = :sessionId LIMIT 1');
    $check->execute([':sessionId' => $sessionId]);
    if ($check->fetchColumn()) {
        return;
    }

    $summaryData = is_array($packageSnapshot) ? ($packageSnapshot['summary'] ?? null) : null;
    $selectionsData = is_array($packageSnapshot) ? ($packageSnapshot['selections'] ?? null) : null;
    $title = is_array($packageSnapshot) && !empty($packageSnapshot['title'])
        ? $packageSnapshot['title']
        : ($summaryData['title'] ?? 'Confirmed journey');
    $destination = is_array($packageSnapshot) && !empty($packageSnapshot['destination'])
        ? $packageSnapshot['destination']
        : ($summaryData['destination'] ?? null);

    $stmt = $pdo->prepare(
        'INSERT INTO traveler_booking_history
            (travelerID, packageID, sessionID, receiptID, receiptNo, bookingRef, title, destination, summary, selections, amount, currency, status, paidAt)
         VALUES
            (:travelerId, :packageId, :sessionId, :receiptId, :receiptNo, :bookingRef, :title, :destination, :summary, :selections, :amount, :currency, :status, :paidAt)'
    );
    $stmt->execute([
        ':travelerId' => (int)$sessionRow['travelerID'],
        ':packageId' => $packageSnapshot['packageId'] ?? ($sessionRow['packageID'] ?? null),
        ':sessionId' => $sessionId,
        ':receiptId' => $receipt['receiptId'] ?? null,
        ':receiptNo' => $receipt['receiptNo'] ?? null,
        ':bookingRef' => $sessionRow['bookingRef'],
        ':title' => $title,
        ':destination' => $destination,
        ':summary' => $summaryData ? json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':selections' => $selectionsData ? json_encode($selectionsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':amount' => $sessionRow['amount'],
        ':currency' => $sessionRow['currency'],
        ':status' => 'confirmed',
        ':paidAt' => $receipt['paidAt'] ?? ($sessionRow['updatedAt'] ?? date('Y-m-d H:i:s')),
    ]);
}

function archiveSavedPackage(PDO $pdo, int $packageId, int $travelerId): void
{
    detachPackageReferences($pdo, $travelerId, $packageId);
    $stmt = $pdo->prepare(
        'DELETE FROM traveler_saved_place_package WHERE packageID = :packageId AND travelerID = :travelerId'
    );
    $stmt->execute([':packageId' => $packageId, ':travelerId' => $travelerId]);
}

function detachPackageReferences(PDO $pdo, int $travelerId, int $packageId): void
{
    $queries = [
        'UPDATE payment_session SET packageID = NULL WHERE packageID = :packageId AND travelerID = :travelerId',
        'UPDATE traveler_booking_history SET packageID = NULL WHERE packageID = :packageId AND travelerID = :travelerId',
    ];

    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':packageId' => $packageId, ':travelerId' => $travelerId]);
        } catch (PDOException $exception) {
            // Ignore missing table errors (42S02) so legacy schemas don't break payment flow.
            if ($exception->getCode() !== '42S02') {
                throw $exception;
            }
        }
    }
}

function generateBookingReference(int $travelerId): string
{
    $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    return sprintf('ST-%06d-%s', $travelerId, $random);
}

function generateAuthorizationCode(): string
{
    return sprintf('FPX%s', strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));
}

function normaliseMethodRecord(array $row): array
{
    $presentation = getMethodPresentation($row['code']);
    return [
        'methodId' => (int)$row['methodID'],
        'code' => $row['code'],
        'displayName' => $row['displayName'],
        'category' => $row['category'],
        'logoPath' => $row['logoPath'],
        'isActive' => (bool)$row['isActive'],
        'sortOrder' => (int)$row['sortOrder'],
        'tagline' => $presentation['tagline'] ?? 'Instant confirmation gateway',
        'processingTime' => $presentation['processingTime'] ?? 'Instant',
        'feeLabel' => $presentation['feeLabel'] ?? 'No processing fee',
        'accentColor' => $presentation['accentColor'] ?? '#0f172a',
        'fields' => array_map(static function ($field) {
            return [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'] ?? 'text',
                'placeholder' => $field['placeholder'] ?? '',
                'mask' => $field['mask'] ?? null,
                'length' => isset($field['length']) ? (int)$field['length'] : null,
                'required' => (bool)($field['required'] ?? false),
            ];
        }, $presentation['fields'] ?? []),
    ];
}

function normaliseSessionRecord(array $row): array
{
    return [
        'sessionId' => (int)$row['sessionID'],
        'travelerId' => (int)$row['travelerID'],
        'packageId' => $row['packageID'] !== null ? (int)$row['packageID'] : null,
        'bookingRef' => $row['bookingRef'],
        'amount' => (float)$row['amount'],
        'currency' => $row['currency'],
        'status' => $row['status'],
        'failureReason' => $row['failureReason'],
        'method' => [
            'methodId' => (int)$row['methodID'],
            'code' => $row['code'] ?? null,
            'displayName' => $row['displayName'] ?? null,
            'category' => $row['category'] ?? null,
            'logoPath' => $row['logoPath'] ?? null,
        ],
        'methodSnapshot' => decodeJson($row['methodSnapshot']),
        'clientContext' => decodeJson($row['clientContext']),
        'createdAt' => $row['createdAt'],
        'updatedAt' => $row['updatedAt'],
    ];
}

function normaliseBookingRecord(array $row): array
{
    return [
        'historyId' => isset($row['historyID']) ? (int)$row['historyID'] : null,
        'sessionId' => isset($row['sessionID']) ? (int)$row['sessionID'] : null,
        'travelerId' => isset($row['travelerID']) ? (int)$row['travelerID'] : null,
        'packageId' => isset($row['packageID']) && $row['packageID'] !== null ? (int)$row['packageID'] : null,
        'bookingRef' => $row['bookingRef'] ?? null,
        'packageTitle' => $row['packageTitle'] ?? ($row['title'] ?? ''),
        'packageDestination' => $row['packageDestination'] ?? ($row['destination'] ?? ''),
        'packageSummary' => decodeJson($row['packageSummary'] ?? $row['summary'] ?? null),
        'packageSelections' => decodeJson($row['packageSelections'] ?? $row['selections'] ?? null),
        'amount' => isset($row['amount']) ? (float)$row['amount'] : 0,
        'currency' => $row['currency'] ?? 'MYR',
        'status' => $row['status'] ?? 'confirmed',
        'receiptNo' => $row['effectiveReceiptNo'] ?? $row['receiptNo'] ?? null,
        'paidAt' => $row['effectivePaidAt'] ?? $row['paidAt'] ?? null,
        'createdAt' => $row['createdAt'] ?? null,
    ];
}

function normaliseBookingRecordFromSession(array $row): array
{
    $summary = decodeJson($row['packageSummary'] ?? null);
    $selections = decodeJson($row['packageSelections'] ?? null);
    return [
        'historyId' => null,
        'sessionId' => isset($row['sessionID']) ? (int)$row['sessionID'] : null,
        'travelerId' => isset($row['travelerID']) ? (int)$row['travelerID'] : null,
        'packageId' => isset($row['packageID']) && $row['packageID'] !== null ? (int)$row['packageID'] : null,
        'bookingRef' => $row['bookingRef'] ?? null,
        'packageTitle' => $row['packageTitle'] ?? ($summary['title'] ?? 'Confirmed journey'),
        'packageDestination' => $row['packageDestination'] ?? ($summary['destination'] ?? ''),
        'packageSummary' => $summary,
        'packageSelections' => $selections,
        'amount' => isset($row['amount']) ? (float)$row['amount'] : 0,
        'currency' => $row['currency'] ?? 'MYR',
        'status' => 'confirmed',
        'receiptNo' => $row['receiptNo'] ?? null,
        'paidAt' => $row['paidAt'] ?? ($row['updatedAt'] ?? $row['createdAt'] ?? null),
        'createdAt' => $row['createdAt'] ?? null,
    ];
}

function getMethodPresentation(string $code): array
{
    $templates = [
        'fpx_maybank' => [
            'tagline' => 'Authorize seamlessly with Maybank2u FPX',
            'processingTime' => 'Instant confirmation',
            'feeLabel' => 'RM 0.00 FPX fee',
            'accentColor' => '#f5a524',
            'fields' => [
                ['key' => 'accountName', 'label' => 'Account holder name', 'placeholder' => 'As per NRIC / passport', 'required' => true],
                ['key' => 'bankUserId', 'label' => 'Maybank2u login ID', 'placeholder' => 'Enter user ID', 'required' => true],
                ['key' => 'otp', 'label' => 'FPX OTP (Simulation)', 'placeholder' => '6-digit code', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'fpx_cimb' => [
            'tagline' => 'CIMB Clicks FPX experience',
            'processingTime' => 'Instant',
            'feeLabel' => 'RM 0.00 FPX fee',
            'accentColor' => '#ff5a5f',
            'fields' => [
                ['key' => 'accountName', 'label' => 'Account holder name', 'placeholder' => 'Registered full name', 'required' => true],
                ['key' => 'bankUserId', 'label' => 'CIMB Clicks ID', 'placeholder' => 'Enter user ID', 'required' => true],
                ['key' => 'securePhrase', 'label' => 'Secure phrase', 'placeholder' => 'For simulation only', 'required' => false],
                ['key' => 'otp', 'label' => 'FPX OTP', 'placeholder' => '6-digit', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'fpx_rhb' => [
            'tagline' => 'RHB Now secure FPX checkout',
            'processingTime' => 'Instant',
            'feeLabel' => 'RM 0.00 FPX fee',
            'accentColor' => '#2563eb',
            'fields' => [
                ['key' => 'accountName', 'label' => 'Account holder name', 'placeholder' => 'Registered full name', 'required' => true],
                ['key' => 'bankUserId', 'label' => 'RHB Now ID', 'placeholder' => 'Enter user ID', 'required' => true],
                ['key' => 'otp', 'label' => 'FPX OTP', 'placeholder' => '6-digit', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'fpx_publicbank' => [
            'tagline' => 'PBe / PB engage FPX',
            'processingTime' => 'Instant',
            'feeLabel' => 'RM 0.00 FPX fee',
            'accentColor' => '#f97316',
            'fields' => [
                ['key' => 'accountName', 'label' => 'Account holder name', 'placeholder' => 'Registered full name', 'required' => true],
                ['key' => 'bankUserId', 'label' => 'PBe user ID', 'placeholder' => 'Enter user ID', 'required' => true],
                ['key' => 'securePhrase', 'label' => 'Secure phrase', 'placeholder' => 'Optional for simulation', 'required' => false],
                ['key' => 'otp', 'label' => 'FPX OTP', 'placeholder' => '6-digit', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'fpx_hongleong' => [
            'tagline' => 'Hong Leong Connect FPX',
            'processingTime' => 'Instant',
            'feeLabel' => 'RM 0.00 FPX fee',
            'accentColor' => '#0ea5e9',
            'fields' => [
                ['key' => 'accountName', 'label' => 'Account holder name', 'placeholder' => 'Registered full name', 'required' => true],
                ['key' => 'bankUserId', 'label' => 'HLB Connect ID', 'placeholder' => 'Enter user ID', 'required' => true],
                ['key' => 'otp', 'label' => 'FPX OTP', 'placeholder' => '6-digit', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'fpx_bankislam' => [
            'tagline' => 'Bank Islam FPX service',
            'processingTime' => 'Instant',
            'feeLabel' => 'RM 0.00 FPX fee',
            'accentColor' => '#0f766e',
            'fields' => [
                ['key' => 'accountName', 'label' => 'Account holder name', 'placeholder' => 'Registered full name', 'required' => true],
                ['key' => 'bankUserId', 'label' => 'Bank Islam Internet Banking ID', 'placeholder' => 'Enter user ID', 'required' => true],
                ['key' => 'otp', 'label' => 'FPX OTP', 'placeholder' => '6-digit', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'card_visa_master' => [
            'tagline' => 'Visa / Mastercard checkout',
            'processingTime' => 'Instant',
            'feeLabel' => 'FX inclusive • RM 0.00 fee',
            'accentColor' => '#2563eb',
            'fields' => [
                ['key' => 'cardHolder', 'label' => 'Cardholder name', 'placeholder' => 'As printed on card', 'required' => true],
                ['key' => 'cardNumber', 'label' => 'Card number', 'placeholder' => '4111 1111 1111 1111', 'required' => true],
                ['key' => 'expiry', 'label' => 'Expiry (MM/YY)', 'placeholder' => '08/28', 'required' => true],
                ['key' => 'cvv', 'label' => 'CVV', 'placeholder' => '3 digits', 'length' => 3, 'type' => 'password', 'required' => true],
            ],
        ],
        'card_amex' => [
            'tagline' => 'American Express Privilege',
            'processingTime' => 'Instant',
            'feeLabel' => 'RM 0.00 + rewards eligible',
            'accentColor' => '#0ea5e9',
            'fields' => [
                ['key' => 'cardHolder', 'label' => 'Cardholder name', 'placeholder' => 'As printed on card', 'required' => true],
                ['key' => 'cardNumber', 'label' => 'Card number', 'placeholder' => '3712 000000 00000', 'required' => true],
                ['key' => 'expiry', 'label' => 'Expiry (MM/YY)', 'placeholder' => '09/27', 'required' => true],
                ['key' => 'cvv', 'label' => 'CID', 'placeholder' => '4 digits', 'length' => 4, 'type' => 'password', 'required' => true],
            ],
        ],
        'ewallet_tng' => [
            'tagline' => 'Touch \'n Go eWallet',
            'processingTime' => 'Instant QR confirm',
            'feeLabel' => 'RM 0.00 reload fee',
            'accentColor' => '#0ea5e9',
            'fields' => [
                ['key' => 'walletId', 'label' => 'Wallet ID / Mobile', 'placeholder' => 'e.g. +6012 345 6789', 'required' => true],
                ['key' => 'pin', 'label' => '6-digit PIN', 'placeholder' => 'Simulation PIN', 'length' => 6, 'type' => 'password', 'required' => true],
            ],
        ],
        'ewallet_boost' => [
            'tagline' => 'Boost Wallet checkout',
            'processingTime' => 'Instant (QR)',
            'feeLabel' => 'RM 0.00',
            'accentColor' => '#ef4444',
            'fields' => [
                ['key' => 'walletId', 'label' => 'Boost ID / Mobile', 'placeholder' => 'e.g. +6017 888 1111', 'required' => true],
                ['key' => 'pin', 'label' => 'Boost PIN', 'placeholder' => '6 digits', 'length' => 6, 'type' => 'password', 'required' => true],
            ],
        ],
        'ewallet_grabpay' => [
            'tagline' => 'GrabPay cashless checkout',
            'processingTime' => 'Instant (OTP)',
            'feeLabel' => 'RM 0.00 reload fee',
            'accentColor' => '#22c55e',
            'fields' => [
                ['key' => 'walletId', 'label' => 'Grab account (mobile/email)', 'placeholder' => 'e.g. +6013 888 9999', 'required' => true],
                ['key' => 'otp', 'label' => 'GrabPay OTP', 'placeholder' => '6 digits', 'length' => 6, 'type' => 'otp', 'required' => true],
            ],
        ],
        'ewallet_shopeepay' => [
            'tagline' => 'ShopeePay secure QR',
            'processingTime' => 'Instant QR confirm',
            'feeLabel' => 'RM 0.00 service fee',
            'accentColor' => '#fb923c',
            'fields' => [
                ['key' => 'walletId', 'label' => 'Shopee account email / mobile', 'placeholder' => 'e.g. user@email.com', 'required' => true],
                ['key' => 'pin', 'label' => 'ShopeePay PIN', 'placeholder' => '6 digits', 'length' => 6, 'type' => 'password', 'required' => true],
            ],
        ],
    ];

    return $templates[$code] ?? [
        'tagline' => 'Instant confirmation gateway',
        'processingTime' => 'Instant',
        'feeLabel' => 'No processing fee',
        'accentColor' => '#0f172a',
        'fields' => [],
    ];
}

function decodeJson($value)
{
    if ($value === null || $value === '' || !is_string($value)) {
        return null;
    }
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function normaliseFieldValues(array $fieldSchema, array $payload): array
{
    $result = [];
    foreach ($fieldSchema as $field) {
        $key = $field['key'] ?? null;
        if (!$key) {
            continue;
        }
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = $payload[$key];
        if (is_string($value)) {
            $value = trim($value);
        }
        $result[$key] = $value;
    }
    return $result;
}

function readJsonPayload(): array
{
    $body = file_get_contents('php://input');
    if (!$body) {
        return [];
    }
    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
