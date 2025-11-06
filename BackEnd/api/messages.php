<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers/polyfills.php';
require_once __DIR__ . '/helpers/profile_image.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  $pdo = require __DIR__ . '/../config/db.php';
} catch (Throwable $exception) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable']);
  exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($method) {
  case 'GET':
    handleGetMessages($pdo);
    break;
  case 'POST':
    handleCreateMessage($pdo);
    break;
  default:
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    break;
}

function handleGetMessages(PDO $pdo): void
{
  $currentType = normaliseMessageAccountType($_GET['currentType'] ?? $_GET['current_type'] ?? '');
  $currentId = filter_var($_GET['currentId'] ?? $_GET['current_id'] ?? null, FILTER_VALIDATE_INT);
  $view = strtolower(trim((string) ($_GET['view'] ?? $_GET['mode'] ?? 'messages')));

  if (!$currentType || !$currentId || $currentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing current user context.']);
    return;
  }

  if ($view === 'threads') {
    handleGetMessageThreads($pdo, $currentType, $currentId);
    return;
  }

  $participantType = normaliseMessageAccountType($_GET['participantType'] ?? $_GET['participant_type'] ?? '');
  $participantId = filter_var($_GET['participantId'] ?? $_GET['participant_id'] ?? null, FILTER_VALIDATE_INT);
  $postId = isset($_GET['postId']) ? (int) $_GET['postId'] : null;

  if (!$participantType || !$participantId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing participant identifiers.']);
    return;
  }

  $filterByPost = $postId !== null;

  $sql = <<<SQL
    SELECT
      messageID,
      senderType,
      senderID,
      receiverType,
      receiverID,
      listingID,
      postID,
      content,
      sentAt,
      isRead
    FROM message
    WHERE
      (
        senderType = :senderTypeA
        AND senderID = :senderIdA
        AND receiverType = :receiverTypeA
        AND receiverID = :receiverIdA
      )
      OR
      (
        senderType = :senderTypeB
        AND senderID = :senderIdB
        AND receiverType = :receiverTypeB
        AND receiverID = :receiverIdB
      )
  SQL;

  if ($filterByPost) {
    $sql .= ' AND postID = :postFilter';
  }

  $sql .= ' ORDER BY sentAt ASC, messageID ASC';

  $stmt = $pdo->prepare($sql);
  $params = [
    ':senderTypeA' => $currentType,
    ':senderIdA' => $currentId,
    ':receiverTypeA' => $participantType,
    ':receiverIdA' => $participantId,
    ':senderTypeB' => $participantType,
    ':senderIdB' => $participantId,
    ':receiverTypeB' => $currentType,
    ':receiverIdB' => $currentId,
  ];

  if ($filterByPost) {
    $params[':postFilter'] = $postId;
  }

  $stmt->execute($params);

  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if ($rows) {
    markMessagesAsRead(
      $pdo,
      $currentType,
      $currentId,
      $participantType,
      $participantId,
      $filterByPost ? $postId : null
    );
    foreach ($rows as &$row) {
      $receiverType = normaliseMessageAccountType($row['receiverType'] ?? $row['receiver_type'] ?? '');
      $senderType = normaliseMessageAccountType($row['senderType'] ?? $row['sender_type'] ?? '');
      $receiverMatches = $receiverType === $currentType && (int) ($row['receiverID'] ?? $row['receiverId'] ?? 0) === $currentId;
      $senderMatches = $senderType === $participantType && (int) ($row['senderID'] ?? $row['senderId'] ?? 0) === $participantId;
      if ($receiverMatches && $senderMatches) {
        $row['isRead'] = 1;
      }
    }
    unset($row);
  }

  $participants = [
    formatParticipantProfile($pdo, $currentType, $currentId),
    formatParticipantProfile($pdo, $participantType, $participantId),
  ];

  $messages = array_map(
    static fn(array $row): array => [
      'id' => (int) ($row['messageID'] ?? 0),
      'senderType' => normaliseMessageAccountType($row['senderType'] ?? ''),
      'senderId' => (int) ($row['senderID'] ?? 0),
      'receiverType' => normaliseMessageAccountType($row['receiverType'] ?? ''),
      'receiverId' => (int) ($row['receiverID'] ?? 0),
      'listingId' => isset($row['listingID']) ? (int) $row['listingID'] : null,
      'postId' => isset($row['postID']) ? (int) $row['postID'] : null,
      'content' => $row['content'] ?? '',
      'sentAt' => $row['sentAt'] ?? null,
      'isRead' => (bool) ($row['isRead'] ?? false),
    ],
    $rows
  );

  echo json_encode([
    'messages' => $messages,
    'participants' => array_values(array_filter($participants)),
  ]);
}

function handleGetMessageThreads(PDO $pdo, string $currentType, int $currentId): void
{
  $sql = <<<SQL
    SELECT
      threads.counterpartType,
      threads.counterpartId,
      MAX(threads.sentAt) AS lastSentAt,
      SUM(
        CASE
          WHEN threads.receiverType = :currentType_unread
            AND threads.receiverID = :currentId_unread
            AND threads.isRead = 0
          THEN 1
          ELSE 0
        END
      ) AS unreadCount
    FROM (
      SELECT
        CASE
          WHEN senderType = :currentType_case1 AND senderID = :currentId_case1
            THEN receiverType
          ELSE senderType
        END AS counterpartType,
        CASE
          WHEN senderType = :currentType_case2 AND senderID = :currentId_case2
            THEN receiverID
          ELSE senderID
        END AS counterpartId,
        sentAt,
        receiverType,
        receiverID,
        isRead
      FROM message
      WHERE
        (senderType = :currentType_where1 AND senderID = :currentId_where1)
        OR (receiverType = :currentType_where2 AND receiverID = :currentId_where2)
    ) AS threads
    GROUP BY threads.counterpartType, threads.counterpartId
    ORDER BY lastSentAt DESC, threads.counterpartType ASC, threads.counterpartId ASC
  SQL;

  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':currentType_case1' => $currentType,
    ':currentId_case1' => $currentId,
    ':currentType_case2' => $currentType,
    ':currentId_case2' => $currentId,
    ':currentType_unread' => $currentType,
    ':currentId_unread' => $currentId,
    ':currentType_where1' => $currentType,
    ':currentId_where1' => $currentId,
    ':currentType_where2' => $currentType,
    ':currentId_where2' => $currentId,
  ]);

  $threads = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counterType = normaliseMessageAccountType($row['counterpartType'] ?? '');
    $counterId = (int) ($row['counterpartId'] ?? 0);
    if (!$counterType || $counterId <= 0) {
      continue;
    }

    $lastMessage = fetchLatestMessageBetween($pdo, $currentType, $currentId, $counterType, $counterId);
    $profile = formatParticipantProfile($pdo, $counterType, $counterId);

    $threads[] = [
      'participantId' => $counterId,
      'participantType' => $counterType,
      'participantName' => $profile['name'] ?? '',
      'participantUsername' => $profile['username'] ?? '',
      'avatar' => $profile['avatar'] ?? '',
      'avatarRelative' => $profile['avatarRelative'] ?? '',
      'lastMessage' => $lastMessage['content'] ?? '',
      'lastMessageSenderType' => $lastMessage['senderType'] ?? '',
      'lastMessageSenderId' => $lastMessage['senderId'] ?? 0,
      'lastSentAt' => $lastMessage['sentAt'] ?? $row['lastSentAt'] ?? null,
      'unreadCount' => (int) ($row['unreadCount'] ?? 0),
    ];
  }

  echo json_encode(['threads' => $threads]);
}

function handleCreateMessage(PDO $pdo): void
{
  $payload = decodeJsonBody();
  $senderType = normaliseMessageAccountType($payload['senderType'] ?? $payload['sender_type'] ?? '');
  $receiverType = normaliseMessageAccountType($payload['receiverType'] ?? $payload['receiver_type'] ?? '');
  $senderId = (int) ($payload['senderID'] ?? $payload['senderId'] ?? 0);
  $receiverId = (int) ($payload['receiverID'] ?? $payload['receiverId'] ?? 0);
  $listingId = isset($payload['listingID']) ? (int) $payload['listingID'] : null;
  $postId = isset($payload['postID']) ? (int) $payload['postID'] : null;
  $content = trim((string) ($payload['content'] ?? ''));

  if (!$senderType || !$receiverType || $senderId <= 0 || $receiverId <= 0 || $content === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid message payload.']);
    return;
  }

  if (mb_strlen($content) > 2000) {
    http_response_code(413);
    echo json_encode(['error' => 'Message too long (max 2000 characters).']);
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO message
      (senderType, senderID, receiverType, receiverID, listingID, postID, content, sentAt, isRead)
     VALUES
      (:senderType, :senderID, :receiverType, :receiverID, :listingID, :postID, :content, NOW(), 0)'
  );

  $stmt->execute([
    ':senderType' => $senderType,
    ':senderID' => $senderId,
    ':receiverType' => $receiverType,
    ':receiverID' => $receiverId,
    ':listingID' => $listingId,
    ':postID' => $postId,
    ':content' => $content,
  ]);

  $messageId = (int) $pdo->lastInsertId();
  $messageRow = fetchMessageById($pdo, $messageId);
  $messagePayload = $messageRow
    ? [
        'id' => (int) ($messageRow['messageID'] ?? $messageId),
        'messageID' => (int) ($messageRow['messageID'] ?? $messageId),
        'senderType' => $messageRow['senderType'] ?? $senderType,
        'senderId' => (int) ($messageRow['senderID'] ?? $senderId),
        'receiverType' => $messageRow['receiverType'] ?? $receiverType,
        'receiverId' => (int) ($messageRow['receiverID'] ?? $receiverId),
        'listingId' => isset($messageRow['listingID']) ? (int) $messageRow['listingID'] : null,
        'postId' => isset($messageRow['postID']) ? (int) $messageRow['postID'] : null,
        'content' => $messageRow['content'] ?? $content,
        'sentAt' => $messageRow['sentAt'] ?? null,
        'isRead' => (bool) ($messageRow['isRead'] ?? false),
      ]
    : [
        'id' => $messageId,
        'messageID' => $messageId,
        'senderType' => $senderType,
        'senderId' => $senderId,
        'receiverType' => $receiverType,
        'receiverId' => $receiverId,
        'listingId' => $listingId,
        'postId' => $postId,
        'content' => $content,
        'sentAt' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'isRead' => false,
      ];

  echo json_encode([
    'ok' => true,
    'message' => $messagePayload,
  ]);
}


function decodeJsonBody(): array
{
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    return [];
  }

  $data = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    return [];
  }

  return $data;
}

function normaliseMessageAccountType(string $value): string
{
  $map = [
    'traveler' => 'Traveler',
    'traveller' => 'Traveler',
    'operator' => 'Operator',
    'business' => 'Operator',
    'tourismoperator' => 'Operator',
    'admin' => 'Admin',
    'administrator' => 'Admin',
  ];

  $key = strtolower(trim($value));
  return $map[$key] ?? '';
}

function formatParticipantProfile(PDO $pdo, string $type, int $id): ?array
{
  if (!$type || $id <= 0) {
    return null;
  }

  $profile = fetchAccountProfile($pdo, $type, $id);
  if (!$profile) {
    return null;
  }

  $image = resolveProfileImageReference(strtolower($type), $id, $profile['profileImage'] ?? null);

  return [
    'id' => $id,
    'type' => $type,
    'name' => $profile['name'] ?? '',
    'username' => $profile['username'] ?? '',
    'avatar' => $image['public'],
    'avatarRelative' => $image['relative'],
  ];
}

function fetchAccountProfile(PDO $pdo, string $type, int $id): ?array
{
  switch (strtolower($type)) {
    case 'traveler':
      $stmt = $pdo->prepare('SELECT fullName AS name, username, profileImage FROM traveler WHERE travelerID = :id LIMIT 1');
      break;
    case 'operator':
      $stmt = $pdo->prepare('SELECT fullName AS name, username, profileImage FROM tourismoperator WHERE operatorID = :id LIMIT 1');
      break;
    case 'admin':
      $stmt = $pdo->prepare('SELECT fullName AS name, username, profileImage FROM administrator WHERE adminID = :id LIMIT 1');
      break;
    default:
      return null;
  }

  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    return null;
  }

  return $row;
}

function fetchLatestMessageBetween(PDO $pdo, string $typeA, int $idA, string $typeB, int $idB): array
{
  $stmt = $pdo->prepare(
    'SELECT messageID, senderType, senderID, content, sentAt
     FROM message
     WHERE
       (
         senderType = :typeA_sender AND senderID = :idA_sender
         AND receiverType = :typeB_receiver AND receiverID = :idB_receiver
       )
       OR
       (
         senderType = :typeB_sender AND senderID = :idB_sender
         AND receiverType = :typeA_receiver AND receiverID = :idA_receiver
       )
     ORDER BY sentAt DESC, messageID DESC
     LIMIT 1'
  );
  $stmt->execute([
    ':typeA_sender' => $typeA,
    ':idA_sender' => $idA,
    ':typeB_receiver' => $typeB,
    ':idB_receiver' => $idB,
    ':typeB_sender' => $typeB,
    ':idB_sender' => $idB,
    ':typeA_receiver' => $typeA,
    ':idA_receiver' => $idA,
  ]);

  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return [];
  }

  return [
    'id' => (int) ($row['messageID'] ?? 0),
    'senderType' => normaliseMessageAccountType($row['senderType'] ?? ''),
    'senderId' => (int) ($row['senderID'] ?? 0),
    'content' => $row['content'] ?? '',
    'sentAt' => $row['sentAt'] ?? null,
  ];
}

function markMessagesAsRead(PDO $pdo, string $receiverType, int $receiverId, string $senderType, int $senderId, ?int $postId = null): void
{
  if ($receiverId <= 0 || $senderId <= 0) {
    return;
  }

  $sql = 'UPDATE message SET isRead = 1 WHERE receiverType = :receiverType AND receiverID = :receiverId AND senderType = :senderType AND senderID = :senderId AND isRead = 0';
  $params = [
    ':receiverType' => $receiverType,
    ':receiverId' => $receiverId,
    ':senderType' => $senderType,
    ':senderId' => $senderId,
  ];

  if ($postId !== null) {
    $sql .= ' AND (postID = :postId OR :postId IS NULL)';
    $params[':postId'] = $postId;
  }

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
}

function fetchMessageById(PDO $pdo, int $messageId): ?array
{
  if ($messageId <= 0) {
    return null;
  }

  $stmt = $pdo->prepare(
    'SELECT messageID, senderType, senderID, receiverType, receiverID, listingID, postID, content, sentAt, isRead
     FROM message
     WHERE messageID = :id
     LIMIT 1'
  );
  $stmt->execute([':id' => $messageId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return $row ?: null;
}
