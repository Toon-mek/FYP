<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        case 'PUT':
            handlePut($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['error' => 'Trip planner request failed', 'details' => $e->getMessage()]);
}

function handleGet(PDO $pdo): void
{
    $travelerId = (int)($_GET['travelerId'] ?? 0);
    if ($travelerId <= 0) {
        respond(400, ['error' => 'travelerId is required']);
        return;
    }

    $itineraryId = isset($_GET['itineraryId']) ? (int)$_GET['itineraryId'] : null;

    if ($itineraryId) {
        $itinerary = fetchItinerary($pdo, $travelerId, $itineraryId);
        if (!$itinerary) {
            respond(404, ['error' => 'Itinerary not found']);
            return;
        }
        respond(200, ['itinerary' => $itinerary]);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT i.itineraryID, i.travelerID, i.title, i.startDate, i.endDate, i.visibility,
                i.origin, i.destination, i.summary, i.aiPlan, i.metadata, i.totalDays, i.totalBudget,
                ip.preferences
         FROM itinerary i
         LEFT JOIN itinerary_preferences ip ON ip.itineraryID = i.itineraryID
         WHERE i.travelerID = :travelerId
         ORDER BY i.startDate DESC'
    );
    $stmt->execute([':travelerId' => $travelerId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$records) {
        respond(200, ['itineraries' => []]);
        return;
    }

    $ids = array_map(static fn($row) => (int)$row['itineraryID'], $records);
    $itemsByItinerary = fetchItemsForItineraries($pdo, $ids);

    $payload = array_map(static function (array $record) use ($itemsByItinerary) {
        $itineraryId = (int)$record['itineraryID'];
        $items = $itemsByItinerary[$itineraryId] ?? [];
        return buildItineraryPayload($record, $items);
    }, $records);

    respond(200, ['itineraries' => $payload]);
}

function handlePost(PDO $pdo): void
{
    $payload = readJsonPayload();
    $travelerId = (int)($payload['travelerId'] ?? 0);
    $title = trim((string)($payload['title'] ?? ''));
    $startDate = normaliseDate($payload['startDate'] ?? null);
    $endDate = normaliseDate($payload['endDate'] ?? null);
    $visibility = normaliseVisibility($payload['visibility'] ?? 'Private');
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

    if ($travelerId <= 0) {
        respond(400, ['error' => 'travelerId is required']);
        return;
    }
    if ($title === '') {
        respond(400, ['error' => 'Title is required']);
        return;
    }

    $origin = $payload['origin'] ?? null;
    $destination = $payload['destination'] ?? null;
    $summary = $payload['summary'] ?? null;
    $aiPlan = $payload['aiPlan'] ?? null;
    $metadata = $payload['metadata'] ?? null;
    $preferences = $payload['preferences'] ?? $metadata;
    if ($preferences !== null && !is_array($preferences)) {
        $preferences = null;
    }
    $totalDays = $payload['totalDays'] ?? null;
    $totalBudget = $payload['totalBudget'] ?? null;
    if (!$startDate || !$endDate) {
        respond(400, ['error' => 'Valid startDate and endDate are required']);
        return;
    }
    if ($startDate > $endDate) {
        respond(400, ['error' => 'startDate cannot be after endDate']);
        return;
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO itinerary (travelerID, title, startDate, endDate, visibility, origin, destination, summary, aiPlan, metadata, totalDays, totalBudget)
             VALUES (:travelerId, :title, :startDate, :endDate, :visibility, :origin, :destination, :summary, :aiPlan, :metadata, :totalDays, :totalBudget)'
        );
        $insert->execute([
            ':travelerId' => $travelerId,
            ':title' => $title,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ':visibility' => $visibility,
        ':origin' => $origin,
        ':destination' => $destination,
        ':summary' => $summary ? json_encode($summary) : null,
        ':aiPlan' => $aiPlan ? json_encode($aiPlan) : null,
        ':metadata' => $metadata ? json_encode($metadata) : null,
        ':totalDays' => $totalDays,
        ':totalBudget' => $totalBudget,
    ]);

        $itineraryId = (int)$pdo->lastInsertId();
        if ($items) {
            upsertItems($pdo, $itineraryId, $items, $startDate, $endDate);
        }
        saveItineraryPreferences($pdo, $itineraryId, $preferences);

        $pdo->commit();

        $itinerary = fetchItinerary($pdo, $travelerId, $itineraryId);
        respond(201, ['itinerary' => $itinerary, 'message' => 'Itinerary created']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handlePut(PDO $pdo): void
{
    $payload = readJsonPayload();
    $travelerId = (int)($payload['travelerId'] ?? 0);
    $itineraryId = (int)($payload['itineraryId'] ?? 0);

    if ($travelerId <= 0 || $itineraryId <= 0) {
        respond(400, ['error' => 'travelerId and itineraryId are required']);
        return;
    }

    $existing = fetchItineraryRecord($pdo, $travelerId, $itineraryId);
    if (!$existing) {
        respond(404, ['error' => 'Itinerary not found']);
        return;
    }

    $fields = [];
    $params = [':itineraryId' => $itineraryId];
    $preferencesPayload = null;
    $shouldUpdatePreferences = false;

    if (array_key_exists('title', $payload)) {
        $title = trim((string)$payload['title']);
        if ($title === '') {
            respond(400, ['error' => 'Title cannot be empty']);
            return;
        }
        $fields[] = 'title = :title';
        $params[':title'] = $title;
    }

    if (array_key_exists('startDate', $payload)) {
        $nextStart = normaliseDate($payload['startDate']);
        if (!$nextStart) {
            respond(400, ['error' => 'startDate is invalid']);
            return;
        }
        $fields[] = 'startDate = :startDate';
        $params[':startDate'] = $nextStart;
        $existing['startDate'] = $nextStart;
    }

    if (array_key_exists('endDate', $payload)) {
        $nextEnd = normaliseDate($payload['endDate']);
        if (!$nextEnd) {
            respond(400, ['error' => 'endDate is invalid']);
            return;
        }
        $fields[] = 'endDate = :endDate';
        $params[':endDate'] = $nextEnd;
        $existing['endDate'] = $nextEnd;
    }

    if (($existing['startDate'] ?? null) && ($existing['endDate'] ?? null) && $existing['startDate'] > $existing['endDate']) {
        respond(400, ['error' => 'startDate cannot be after endDate']);
        return;
    }

    if (array_key_exists('visibility', $payload)) {
        $fields[] = 'visibility = :visibility';
        $params[':visibility'] = normaliseVisibility($payload['visibility']);
    }

    if (array_key_exists('origin', $payload)) {
        $fields[] = 'origin = :origin';
        $params[':origin'] = $payload['origin'] ?: null;
    }
    if (array_key_exists('destination', $payload)) {
        $fields[] = 'destination = :destination';
        $params[':destination'] = $payload['destination'] ?: null;
    }
    if (array_key_exists('summary', $payload)) {
        $fields[] = 'summary = :summary';
        $params[':summary'] = $payload['summary'] ? json_encode($payload['summary']) : null;
    }
    if (array_key_exists('aiPlan', $payload)) {
        $fields[] = 'aiPlan = :aiPlan';
        $params[':aiPlan'] = $payload['aiPlan'] ? json_encode($payload['aiPlan']) : null;
    }
    if (array_key_exists('metadata', $payload)) {
        $fields[] = 'metadata = :metadata';
        $params[':metadata'] = $payload['metadata'] ? json_encode($payload['metadata']) : null;
        $preferencesPayload = is_array($payload['metadata']) ? $payload['metadata'] : null;
        $shouldUpdatePreferences = true;
    }

    if (array_key_exists('preferences', $payload)) {
        $preferencesPayload = is_array($payload['preferences']) ? $payload['preferences'] : null;
        $shouldUpdatePreferences = true;
    }
    if (array_key_exists('totalDays', $payload)) {
        $fields[] = 'totalDays = :totalDays';
        $params[':totalDays'] = $payload['totalDays'] ?? null;
    }
    if (array_key_exists('totalBudget', $payload)) {
        $fields[] = 'totalBudget = :totalBudget';
        $params[':totalBudget'] = $payload['totalBudget'] ?? null;
    }

    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $deleted = array_filter(
        array_map('intval', $payload['deletedItemIds'] ?? []),
        static fn($id) => $id > 0
    );

    $pdo->beginTransaction();
    try {
        if ($fields) {
            $sql = 'UPDATE itinerary SET ' . implode(', ', $fields) . ' WHERE itineraryID = :itineraryId';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        if ($items) {
            $start = $existing['startDate'];
            $end = $existing['endDate'];
            upsertItems($pdo, $itineraryId, $items, $start, $end);
        }

        if ($shouldUpdatePreferences) {
            saveItineraryPreferences($pdo, $itineraryId, $preferencesPayload);
        }

        if ($deleted) {
            $placeholders = implode(',', array_fill(0, count($deleted), '?'));
            $deleteStmt = $pdo->prepare(
                "DELETE FROM itineraryitem WHERE itineraryID = ? AND itemID IN ($placeholders)"
            );
            $deleteStmt->execute(array_merge([$itineraryId], $deleted));
        }

        $pdo->commit();

        $updated = fetchItinerary($pdo, $travelerId, $itineraryId);
        respond(200, ['itinerary' => $updated, 'message' => 'Itinerary updated']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function handleDelete(PDO $pdo): void
{
    $travelerId = null;
    $itineraryId = null;

    if (!empty($_GET)) {
        $travelerId = isset($_GET['travelerId']) ? (int)$_GET['travelerId'] : null;
        $itineraryId = isset($_GET['itineraryId']) ? (int)$_GET['itineraryId'] : null;
    }

    if (!$travelerId || !$itineraryId) {
        $payload = readJsonPayload(false);
        if ($payload) {
            $travelerId = $travelerId ?: (int)($payload['travelerId'] ?? 0);
            $itineraryId = $itineraryId ?: (int)($payload['itineraryId'] ?? 0);
        }
    }

    if (($travelerId ?? 0) <= 0 || ($itineraryId ?? 0) <= 0) {
        respond(400, ['error' => 'travelerId and itineraryId are required']);
        return;
    }

    $record = fetchItineraryRecord($pdo, $travelerId, $itineraryId);
    if (!$record) {
        respond(404, ['error' => 'Itinerary not found']);
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM itinerary WHERE itineraryID = :itineraryId');
    $stmt->execute([':itineraryId' => $itineraryId]);

    respond(200, ['ok' => true, 'message' => 'Itinerary deleted']);
}

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
}

function readJsonPayload(bool $require = true): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        if ($require) {
            respond(400, ['error' => 'JSON body required']);
            exit;
        }
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        if ($require) {
            respond(400, ['error' => 'Invalid JSON body']);
            exit;
        }
        return [];
    }
    return $decoded;
}

function fetchItinerary(PDO $pdo, int $travelerId, int $itineraryId): ?array
{
    $record = fetchItineraryRecord($pdo, $travelerId, $itineraryId);
    if (!$record) {
        return null;
    }
    $items = fetchItemsForItineraries($pdo, [$itineraryId])[$itineraryId] ?? [];
    return buildItineraryPayload($record, $items);
}

function fetchItineraryRecord(PDO $pdo, int $travelerId, int $itineraryId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.itineraryID, i.travelerID, i.title, i.startDate, i.endDate, i.visibility,
                i.origin, i.destination, i.summary, i.aiPlan, i.metadata, i.totalDays, i.totalBudget,
                ip.preferences
         FROM itinerary i
         LEFT JOIN itinerary_preferences ip ON ip.itineraryID = i.itineraryID
         WHERE i.itineraryID = :itineraryId AND i.travelerID = :travelerId
         LIMIT 1'
    );
    $stmt->execute([
        ':itineraryId' => $itineraryId,
        ':travelerId' => $travelerId,
    ]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    return $record ?: null;
}

function fetchItemsForItineraries(PDO $pdo, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT itemID, itineraryID, listingID, placeID, title, date, time, notes
         FROM itineraryitem
         WHERE itineraryID IN ($placeholders)
         ORDER BY date ASC, time ASC"
    );
    $stmt->execute($ids);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $itineraryId = (int)$row['itineraryID'];
        if (!isset($items[$itineraryId])) {
            $items[$itineraryId] = [];
        }
        $items[$itineraryId][] = buildItemPayload($row);
    }
    return $items;
}

function saveItineraryPreferences(PDO $pdo, int $itineraryId, ?array $preferences): void
{
    if ($preferences === null) {
        $stmt = $pdo->prepare('DELETE FROM itinerary_preferences WHERE itineraryID = :itineraryId');
        $stmt->execute([':itineraryId' => $itineraryId]);
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO itinerary_preferences (itineraryID, preferences)
         VALUES (:itineraryId, :preferences)
         ON DUPLICATE KEY UPDATE preferences = VALUES(preferences)'
    );
    $stmt->execute([
        ':itineraryId' => $itineraryId,
        ':preferences' => json_encode($preferences),
    ]);
}

function buildItineraryPayload(array $record, array $items): array
{
    $start = $record['startDate'];
    $end = $record['endDate'];
    $durationDays = calculateDurationDays($start, $end);
    $days = [];
    foreach ($items as $item) {
        $date = $item['date'];
        if (!isset($days[$date])) {
            $days[$date] = [
                'date' => $date,
                'items' => [],
            ];
        }
        $days[$date]['items'][] = $item;
    }
    ksort($days);

    return [
        'itineraryId' => (int)$record['itineraryID'],
        'travelerId' => (int)$record['travelerID'],
        'title' => $record['title'],
        'startDate' => $start,
        'endDate' => $end,
        'visibility' => $record['visibility'],
        'origin' => $record['origin'] ?? null,
        'destination' => $record['destination'] ?? null,
        'summary' => decodeMaybeJson($record['summary'] ?? null),
        'aiPlan' => decodeMaybeJson($record['aiPlan'] ?? null),
        'metadata' => decodeMaybeJson($record['metadata'] ?? null),
        'preferences' => decodeMaybeJson($record['preferences'] ?? null),
        'totalDays' => isset($record['totalDays']) ? (int)$record['totalDays'] : $durationDays,
        'totalBudget' => isset($record['totalBudget']) ? (float)$record['totalBudget'] : null,
        'durationDays' => $durationDays,
        'daySummaries' => array_values(array_map(static function ($day) {
            $day['activityCount'] = count($day['items']);
            return $day;
        }, $days)),
        'items' => $items,
    ];
}

function decodeMaybeJson($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function buildItemPayload(array $row): array
{
    return [
        'itemId' => (int)$row['itemID'],
        'itineraryId' => (int)$row['itineraryID'],
        'listingId' => isset($row['listingID']) ? (int)$row['listingID'] : null,
        'placeId' => isset($row['placeID']) ? (int)$row['placeID'] : null,
        'title' => $row['title'],
        'date' => $row['date'],
        'time' => $row['time'] ?? null,
        'notes' => $row['notes'] ?? null,
    ];
}

function upsertItems(PDO $pdo, int $itineraryId, array $rawItems, string $startDate, string $endDate): void
{
    $insert = $pdo->prepare(
        'INSERT INTO itineraryitem (itineraryID, listingID, placeID, title, date, time, notes)
         VALUES (:itineraryId, :listingId, :placeId, :title, :date, :time, :notes)'
    );
    $update = $pdo->prepare(
        'UPDATE itineraryitem
         SET listingID = :listingId,
             placeID = :placeId,
             title = :title,
             date = :date,
             time = :time,
             notes = :notes
         WHERE itemID = :itemId AND itineraryID = :itineraryId'
    );

    foreach ($rawItems as $raw) {
        $item = normaliseItem($raw, $startDate, $endDate);
        if (!$item) {
            continue;
        }
        $params = [
            ':itineraryId' => $itineraryId,
            ':listingId' => $item['listingId'],
            ':placeId' => $item['placeId'],
            ':title' => $item['title'],
            ':date' => $item['date'],
            ':time' => $item['time'],
            ':notes' => $item['notes'],
        ];

        if (!empty($item['itemId'])) {
            $update->execute($params + [':itemId' => $item['itemId']]);
        } else {
            $insert->execute($params);
        }
    }
}

function normaliseItem($raw, string $startDate, string $endDate): ?array
{
    if (!is_array($raw)) {
        return null;
    }
    $title = trim((string)($raw['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $date = normaliseDate($raw['date'] ?? null);
    if (!$date) {
        return null;
    }
    if ($date < $startDate || $date > $endDate) {
        $date = max($startDate, min($date, $endDate));
    }
    $time = normaliseTime($raw['time'] ?? null);
    $notes = isset($raw['notes']) ? trim((string)$raw['notes']) : null;
    $listingId = isset($raw['listingId']) ? (int)$raw['listingId'] : null;
    $placeId = isset($raw['placeId']) ? (int)$raw['placeId'] : null;
    return [
        'itemId' => isset($raw['itemId']) ? (int)$raw['itemId'] : null,
        'title' => $title,
        'date' => $date,
        'time' => $time,
        'notes' => $notes !== '' ? $notes : null,
        'listingId' => $listingId ?: null,
        'placeId' => $placeId ?: null,
    ];
}

function normaliseDate($value): ?string
{
    if (!$value) {
        return null;
    }
    try {
        $date = new DateTime((string)$value);
    } catch (Exception $e) {
        return null;
    }
    return $date->format('Y-m-d');
}

function normaliseTime($value): ?string
{
    if (!$value) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $formats = ['H:i', 'H:i:s', 'g:i A', 'g:i a'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('H:i:s');
        }
    }
    return null;
}

function normaliseVisibility($value): string
{
    $allowed = ['Private', 'Shared', 'Public'];
    $candidate = ucfirst(strtolower((string)$value));
    return in_array($candidate, $allowed, true) ? $candidate : 'Private';
}

function calculateDurationDays(string $start, string $end): int
{
    $startDate = new DateTimeImmutable($start);
    $endDate = new DateTimeImmutable($end);
    return $startDate->diff($endDate)->days + 1;
}
