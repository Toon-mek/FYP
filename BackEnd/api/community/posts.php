<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/polyfills.php';
require_once __DIR__ . '/../helpers/profile_image.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable']);
  exit;
}

const COMMUNITY_MAX_MEDIA = 10;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

switch ($method) {
  case 'GET': {
    $view = strtolower((string) ($_GET['view'] ?? ''));
    if ($view === 'comments') {
      handleGetComments($pdo);
    } else {
      handleGetPosts($pdo);
    }
    break;
  }
  case 'POST': {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $jsonPayload = null;
    if (stripos($contentType, 'application/json') !== false) {
      $jsonPayload = parseJsonInput();
    }

    $action = $_POST['action'] ?? ($jsonPayload['action'] ?? '');
    switch ($action) {
      case 'toggle-like':
        handleToggleReaction($pdo, $jsonPayload ?? []);
        break;
      case 'toggle-save':
        handleToggleSave($pdo, $jsonPayload ?? []);
        break;
      case 'add-comment':
        handleAddComment($pdo, $jsonPayload ?? []);
        break;
      default:
        handleCreatePost($pdo);
        break;
    }
    break;
  }
  case 'PUT':
    handleUpdatePost($pdo);
    break;
  case 'DELETE': {
    $action = strtolower((string) ($_GET['action'] ?? ''));
    if ($action === 'comment') {
      handleDeleteComment($pdo);
    } else {
      handleDeletePost($pdo);
    }
    break;
  }
  default:
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    break;
}

function handleGetPosts(PDO $pdo): void
{
  $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
  $offset = max(0, (int) ($_GET['offset'] ?? 0));
  $categoryFilter = trim((string) ($_GET['category'] ?? ''));
  $viewMode = strtolower((string) ($_GET['view'] ?? ''));
  $savedOnly = $viewMode === 'saved';
  $likedOnly = $viewMode === 'liked';
  $commentedOnly = $viewMode === 'commented';
  $viewerId = isset($_GET['travelerId']) ? (int) $_GET['travelerId'] : null;

  $params = [
    ':limit' => $limit,
    ':offset' => $offset,
  ];

  $categoryJoin = '';
  if ($categoryFilter !== '') {
    $categoryJoin = 'INNER JOIN community_story_category csc_filter
      ON csc_filter.storyId = cs.id AND csc_filter.category = :filterCategory';
    $params[':filterCategory'] = $categoryFilter;
  }

  if (($savedOnly || $likedOnly || $commentedOnly) && !$viewerId) {
    echo json_encode(['posts' => [], 'total' => 0]);
    return;
  }

  $joins = [];
  if ($categoryFilter !== '') {
    $joins[] = 'INNER JOIN community_story_category csc_filter
      ON csc_filter.storyId = cs.id AND csc_filter.category = :filterCategory';
    $params[':filterCategory'] = $categoryFilter;
  }
  if ($savedOnly) {
    $joins[] = 'INNER JOIN community_story_save css_filter
      ON css_filter.storyId = cs.id AND css_filter.travelerId = :viewerFilterTraveler';
  }
  if ($likedOnly) {
    $joins[] = 'INNER JOIN community_story_reaction csr_filter
      ON csr_filter.storyId = cs.id AND csr_filter.travelerId = :viewerFilterTraveler';
  }
  if ($commentedOnly) {
    $joins[] = 'INNER JOIN (
        SELECT DISTINCT storyId
        FROM community_story_comment
        WHERE travelerId = :viewerFilterTraveler
      ) csc_viewer ON csc_viewer.storyId = cs.id';
  }
  if ($savedOnly || $likedOnly || $commentedOnly) {
    $params[':viewerFilterTraveler'] = $viewerId;
  }

$joinSql = $joins ? "\n    " . implode("\n    ", $joins) : '';

  $sql = <<<SQL
    SELECT
      cs.id,
      cs.travelerID,
      cs.caption,
      cs.mediaType,
      cs.mediaPath,
      cs.location,
      cs.createdAt,
      cs.updatedAt,
      cs.likes,
      cs.comments,
      cs.saves,
      t.travelerID AS resolvedTravelerID,
      t.username AS travelerUsername,
      t.fullName AS travelerFullName,
      t.contactNumber,
      t.profileImage AS travelerProfileImage,
      op.operatorID,
      op.username AS operatorUsername,
      op.fullName AS operatorFullName,
      op.profileImage AS operatorProfileImage,
      COALESCE(t.fullName, op.fullName, t.username, op.username) AS authorDisplayName,
      COALESCE(t.username, op.username) AS authorHandle,
      COALESCE(t.profileImage, op.profileImage) AS authorProfileImage,
      CASE
        WHEN op.operatorID IS NOT NULL THEN 'operator'
        ELSE 'traveler'
      END AS authorType
    FROM community_story cs
    LEFT JOIN Traveler t ON t.travelerID = cs.travelerID
    LEFT JOIN TourismOperator op ON op.operatorID = cs.travelerID
    $categoryJoin
    $joinSql
    ORDER BY cs.createdAt DESC
    LIMIT :limit OFFSET :offset
  SQL;

  $stmt = $pdo->prepare($sql);
  $intParamKeys = [':limit', ':offset', ':viewerFilterTraveler'];
  foreach ($params as $key => $value) {
    if (in_array($key, $intParamKeys, true)) {
      $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
      continue;
    }
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
  }

  $stmt->execute();
  $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$stories) {
    echo json_encode(['posts' => [], 'total' => 0]);
    return;
  }

  $posts = mapStories($pdo, $stories, $viewerId);
  echo json_encode(['posts' => $posts, 'total' => count($posts)]);
}

function handleCreatePost(PDO $pdo): void
{
  if (!isset($_POST['travelerId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'travelerId is required']);
    return;
  }

  $travelerId = (int) $_POST['travelerId'];
  $caption = trim((string) ($_POST['caption'] ?? ''));
  $location = trim((string) ($_POST['location'] ?? ''));
  $tags = normaliseArrayInput($_POST['tags'] ?? null);
  $categories = normaliseArrayInput($_POST['categories'] ?? null);

  if ($travelerId <= 0 || $caption === '') {
    http_response_code(400);
    echo json_encode(['error' => 'travelerId and caption are required']);
    return;
  }

  $uploads = normaliseUploadFiles($_FILES['media'] ?? null);
  if (!$uploads) {
    http_response_code(400);
    echo json_encode(['error' => 'At least one media file is required']);
    return;
  }

  if (count($uploads) > COMMUNITY_MAX_MEDIA) {
    http_response_code(400);
    echo json_encode(['error' => 'You can upload at most ' . COMMUNITY_MAX_MEDIA . ' files per story']);
    return;
  }

  $travelerStmt = $pdo->prepare('SELECT travelerID, fullName, username FROM Traveler WHERE travelerID = :id LIMIT 1');
  $travelerStmt->execute([':id' => $travelerId]);
  $traveler = $travelerStmt->fetch(PDO::FETCH_ASSOC);

  if (!$traveler) {
    http_response_code(404);
    echo json_encode(['error' => 'Traveler not found']);
    return;
  }

  $storedMedia = [];
  foreach ($uploads as $index => $upload) {
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
      cleanupStoredMedia($storedMedia);
      http_response_code(400);
      echo json_encode(['error' => 'One of the media files failed to upload']);
      return;
    }

    $fileSize = (int) ($upload['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > 25 * 1024 * 1024) {
      cleanupStoredMedia($storedMedia);
      http_response_code(400);
      echo json_encode(['error' => 'Each media file must be less than 25MB']);
      return;
    }

    $mediaType = detectMediaType((string) ($upload['type'] ?? $upload['name'] ?? ''));
    if ($mediaType === null) {
      cleanupStoredMedia($storedMedia);
      http_response_code(400);
      echo json_encode(['error' => 'Unsupported media type detected']);
      return;
    }

    $relativePath = storeCommunityMedia($upload, $travelerId);
    if ($relativePath === null) {
      cleanupStoredMedia($storedMedia);
      http_response_code(500);
      echo json_encode(['error' => 'Failed to store media']);
      return;
    }

    $storedMedia[] = [
      'type' => $mediaType,
      'path' => $relativePath,
      'position' => $index,
    ];
  }

  $pdo->beginTransaction();
  try {
    $primaryMedia = $storedMedia[0];

    $insertStory = $pdo->prepare(
      'INSERT INTO community_story (travelerID, caption, mediaType, mediaPath, location)
       VALUES (:travelerId, :caption, :mediaType, :mediaPath, :location)'
    );
    $insertStory->execute([
      ':travelerId' => $travelerId,
      ':caption' => $caption,
      ':mediaType' => $primaryMedia['type'],
      ':mediaPath' => $primaryMedia['path'],
      ':location' => $location !== '' ? $location : null,
    ]);

    $storyId = (int) $pdo->lastInsertId();

    $mediaStmt = $pdo->prepare(
      'INSERT INTO community_story_media (storyId, mediaType, mediaPath, position)
       VALUES (:storyId, :mediaType, :mediaPath, :position)'
    );
    foreach ($storedMedia as $media) {
      $mediaStmt->execute([
        ':storyId' => $storyId,
        ':mediaType' => $media['type'],
        ':mediaPath' => $media['path'],
        ':position' => $media['position'],
      ]);
    }

    if ($tags) {
      $tagStmt = $pdo->prepare(
        'INSERT INTO community_story_tag (storyId, tag) VALUES (:storyId, :tag)'
      );
      foreach ($tags as $tag) {
        $tagStmt->execute([
          ':storyId' => $storyId,
          ':tag' => mb_substr($tag, 0, 64),
        ]);
      }
    }

    if ($categories) {
      $categoryStmt = $pdo->prepare(
        'INSERT INTO community_story_category (storyId, category) VALUES (:storyId, :category)'
      );
      foreach ($categories as $category) {
        $categoryStmt->execute([
          ':storyId' => $storyId,
          ':category' => mb_substr($category, 0, 64),
        ]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    cleanupStoredMedia($storedMedia);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to store story']);
    return;
  }

  $storyRow = fetchStoryRow($pdo, $storyId);
  $payload = mapStories($pdo, [$storyRow], $travelerId);
  echo json_encode(['ok' => true, 'post' => $payload[0]]);
}

function handleUpdatePost(PDO $pdo): void
{
  $payload = parseJsonInput();
  $storyId = (int) ($payload['id'] ?? 0);
  $travelerId = (int) ($payload['travelerId'] ?? 0);
  $caption = trim((string) ($payload['caption'] ?? ''));
  $location = trim((string) ($payload['location'] ?? ''));
  $tags = normaliseArrayInput($payload['tags'] ?? null);
  $categories = normaliseArrayInput($payload['categories'] ?? null);

  if ($storyId <= 0 || $travelerId <= 0 || $caption === '') {
    http_response_code(400);
    echo json_encode(['error' => 'id, travelerId and caption are required']);
    return;
  }

  $storyRow = fetchStoryRow($pdo, $storyId);
  if (!$storyRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Story not found']);
    return;
  }

  if ((int) $storyRow['travelerID'] !== $travelerId) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to modify this story']);
    return;
  }

  $pdo->beginTransaction();
  try {
    $updateStory = $pdo->prepare(
      'UPDATE community_story SET caption = :caption, location = :location WHERE id = :id'
    );
    $updateStory->execute([
      ':caption' => $caption,
      ':location' => $location !== '' ? $location : null,
      ':id' => $storyId,
    ]);

    $pdo->prepare('DELETE FROM community_story_tag WHERE storyId = :id')->execute([':id' => $storyId]);
    $pdo->prepare('DELETE FROM community_story_category WHERE storyId = :id')->execute([':id' => $storyId]);

    if ($tags) {
      $tagStmt = $pdo->prepare(
        'INSERT INTO community_story_tag (storyId, tag) VALUES (:storyId, :tag)'
      );
      foreach ($tags as $tag) {
        $tagStmt->execute([
          ':storyId' => $storyId,
          ':tag' => mb_substr($tag, 0, 64),
        ]);
      }
    }

    if ($categories) {
      $categoryStmt = $pdo->prepare(
        'INSERT INTO community_story_category (storyId, category) VALUES (:storyId, :category)'
      );
      foreach ($categories as $category) {
        $categoryStmt->execute([
          ':storyId' => $storyId,
          ':category' => mb_substr($category, 0, 64),
        ]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update story']);
    return;
  }

  $updatedRow = fetchStoryRow($pdo, $storyId);
  $payload = mapStories($pdo, [$updatedRow], $travelerId);
  echo json_encode(['ok' => true, 'post' => $payload[0]]);
}

function handleDeletePost(PDO $pdo): void
{
  $payload = parseJsonInput();
  $storyId = (int) ($payload['id'] ?? ($_GET['id'] ?? 0));
  $travelerId = (int) ($payload['travelerId'] ?? ($_GET['travelerId'] ?? 0));

  if ($storyId <= 0 || $travelerId <= 0) {
    echo json_encode([
      'ok' => false,
      'message' => 'Missing story or traveler information.',
    ]);
    return;
  }

  $storyRow = fetchStoryRow($pdo, $storyId);
  if (!$storyRow) {
    echo json_encode([
      'ok' => false,
      'message' => 'Story not found.',
    ]);
    return;
  }

  if ((int) $storyRow['travelerID'] !== $travelerId) {
    echo json_encode([
      'ok' => false,
      'message' => 'Story belongs to a different traveler.',
    ]);
    return;
  }

  $mediaPaths = fetchMediaPaths($pdo, $storyId);
  $mediaPaths[] = $storyRow['mediaPath'] ?? '';

  $pdo->beginTransaction();
  try {
    $delete = $pdo->prepare('DELETE FROM community_story WHERE id = :id AND travelerID = :travelerId');
    $delete->execute([
      ':id' => $storyId,
      ':travelerId' => $travelerId,
    ]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode([
      'ok' => false,
      'message' => 'Failed to delete story.',
    ]);
    return;
  }

  cleanupStoredMedia($mediaPaths);
  echo json_encode(['ok' => true]);
}

function handleToggleReaction(PDO $pdo, array $payload): void
{
  $storyId = (int) ($payload['storyId'] ?? ($_POST['storyId'] ?? 0));
  $travelerId = (int) ($payload['travelerId'] ?? ($_POST['travelerId'] ?? 0));

  if ($storyId <= 0 || $travelerId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing story or traveler information.']);
    return;
  }

  if (!fetchStoryRow($pdo, $storyId)) {
    echo json_encode(['ok' => false, 'message' => 'Story not found.']);
    return;
  }

  $pdo->beginTransaction();
  try {
    $existsStmt = $pdo->prepare(
      'SELECT 1 FROM community_story_reaction WHERE storyId = :storyId AND travelerId = :travelerId LIMIT 1'
    );
    $existsStmt->execute([':storyId' => $storyId, ':travelerId' => $travelerId]);
    $liked = (bool) $existsStmt->fetchColumn();

    if ($liked) {
      $delete = $pdo->prepare(
        'DELETE FROM community_story_reaction WHERE storyId = :storyId AND travelerId = :travelerId'
      );
      $delete->execute([':storyId' => $storyId, ':travelerId' => $travelerId]);
      $liked = false;
    } else {
      $insert = $pdo->prepare(
        'INSERT INTO community_story_reaction (storyId, travelerId) VALUES (:storyId, :travelerId)'
      );
      $insert->execute([':storyId' => $storyId, ':travelerId' => $travelerId]);
      $liked = true;
    }

    $likes = recalcStoryMetric($pdo, 'community_story_reaction', $storyId);
    updateStoryMetric($pdo, 'likes', $storyId, $likes);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Unable to update like status.']);
    return;
  }

  echo json_encode([
    'ok' => true,
    'liked' => $liked,
    'likes' => $likes,
  ]);
}

function handleToggleSave(PDO $pdo, array $payload): void
{
  $storyId = (int) ($payload['storyId'] ?? ($_POST['storyId'] ?? 0));
  $travelerId = (int) ($payload['travelerId'] ?? ($_POST['travelerId'] ?? 0));

  if ($storyId <= 0 || $travelerId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing story or traveler information.']);
    return;
  }

  if (!fetchStoryRow($pdo, $storyId)) {
    echo json_encode(['ok' => false, 'message' => 'Story not found.']);
    return;
  }

  $pdo->beginTransaction();
  try {
    $existsStmt = $pdo->prepare(
      'SELECT 1 FROM community_story_save WHERE storyId = :storyId AND travelerId = :travelerId LIMIT 1'
    );
    $existsStmt->execute([':storyId' => $storyId, ':travelerId' => $travelerId]);
    $saved = (bool) $existsStmt->fetchColumn();

    if ($saved) {
      $delete = $pdo->prepare(
        'DELETE FROM community_story_save WHERE storyId = :storyId AND travelerId = :travelerId'
      );
      $delete->execute([':storyId' => $storyId, ':travelerId' => $travelerId]);
      $saved = false;
    } else {
      $insert = $pdo->prepare(
        'INSERT INTO community_story_save (storyId, travelerId) VALUES (:storyId, :travelerId)'
      );
      $insert->execute([':storyId' => $storyId, ':travelerId' => $travelerId]);
      $saved = true;
    }

    $saves = recalcStoryMetric($pdo, 'community_story_save', $storyId);
    updateStoryMetric($pdo, 'saves', $storyId, $saves);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Unable to update saved state.']);
    return;
  }

  echo json_encode([
    'ok' => true,
    'saved' => $saved,
    'saves' => $saves,
  ]);
}

function handleGetComments(PDO $pdo): void
{
  $storyId = (int) ($_GET['storyId'] ?? 0);
  $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
  $offset = max(0, (int) ($_GET['offset'] ?? 0));

  if ($storyId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing storyId.']);
    return;
  }

  if (!fetchStoryRow($pdo, $storyId)) {
    echo json_encode(['ok' => false, 'message' => 'Story not found.']);
    return;
  }

  $results = fetchComments($pdo, $storyId, $limit, $offset);
  $comments = array_map('formatComment', $results['rows']);

  echo json_encode([
    'ok' => true,
    'storyId' => $storyId,
    'comments' => $comments,
    'total' => $results['total'],
    'limit' => $limit,
    'offset' => $offset,
  ]);
}

function handleAddComment(PDO $pdo, array $payload): void
{
  $storyId = (int) ($payload['storyId'] ?? ($_POST['storyId'] ?? 0));
  $travelerId = (int) ($payload['travelerId'] ?? ($_POST['travelerId'] ?? 0));
  $content = trim((string) ($payload['content'] ?? ($_POST['content'] ?? '')));
  $ratingRaw = $payload['rating'] ?? ($_POST['rating'] ?? null);
  $rating = null;
  if ($ratingRaw !== null && $ratingRaw !== '') {
    $rating = (int) $ratingRaw;
    if ($rating < 1 || $rating > 5) {
      $rating = null;
    }
  }

  if ($storyId <= 0 || $travelerId <= 0 || $content === '') {
    echo json_encode(['ok' => false, 'message' => 'Story, traveler and content are required.']);
    return;
  }

  if (!fetchStoryRow($pdo, $storyId)) {
    echo json_encode(['ok' => false, 'message' => 'Story not found.']);
    return;
  }

  $pdo->beginTransaction();
  try {
    $insert = $pdo->prepare(
      'INSERT INTO community_story_comment (storyId, travelerId, content, rating)
       VALUES (:storyId, :travelerId, :content, :rating)'
    );
    $insert->execute([
      ':storyId' => $storyId,
      ':travelerId' => $travelerId,
      ':content' => $content,
      ':rating' => $rating,
    ]);

    $commentId = (int) $pdo->lastInsertId();
    $commentRow = fetchCommentRow($pdo, $commentId);
    $commentCount = recalcStoryMetric($pdo, 'community_story_comment', $storyId);
    updateStoryMetric($pdo, 'comments', $storyId, $commentCount);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Failed to add comment.']);
    return;
  }

  echo json_encode([
    'ok' => true,
    'comment' => $commentRow ? formatComment($commentRow) : null,
    'commentCount' => $commentCount,
  ]);
}

function handleDeleteComment(PDO $pdo): void
{
  $commentId = (int) ($_GET['commentId'] ?? 0);
  $travelerId = (int) ($_GET['travelerId'] ?? 0);

  if ($commentId <= 0 || $travelerId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing comment or traveler information.']);
    return;
  }

  $commentRow = fetchCommentRow($pdo, $commentId);
  if (!$commentRow) {
    echo json_encode(['ok' => false, 'message' => 'Comment not found.']);
    return;
  }

  if ((int) $commentRow['travelerId'] !== $travelerId) {
    echo json_encode(['ok' => false, 'message' => 'Comment belongs to a different traveler.']);
    return;
  }

  $storyId = (int) $commentRow['storyId'];

  $pdo->beginTransaction();
  try {
    $delete = $pdo->prepare('DELETE FROM community_story_comment WHERE id = :id');
    $delete->execute([':id' => $commentId]);

    $commentCount = recalcStoryMetric($pdo, 'community_story_comment', $storyId);
    updateStoryMetric($pdo, 'comments', $storyId, $commentCount);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Failed to delete comment.']);
    return;
  }

  echo json_encode([
    'ok' => true,
    'commentId' => $commentId,
    'commentCount' => $commentCount,
  ]);
}

function mapStories(PDO $pdo, array $stories, ?int $viewerId = null): array
{
  if (!$stories) {
    return [];
  }

  $storyIds = array_map(static fn(array $row): int => (int) $row['id'], $stories);
  $tags = fetchTagsByStory($pdo, $storyIds);
  $categories = fetchCategoriesByStory($pdo, $storyIds);
  $mediaMap = fetchMediaByStory($pdo, $storyIds, $stories);
  $reactionMap = $viewerId ? fetchUserReactions($pdo, $storyIds, $viewerId) : [];
  $saveMap = $viewerId ? fetchUserSaves($pdo, $storyIds, $viewerId) : [];
  $commentMap = $viewerId ? fetchViewerComments($pdo, $storyIds, $viewerId) : [];

  return array_map(
    static function (array $story) use ($tags, $categories, $mediaMap, $reactionMap, $saveMap, $commentMap): array {
      $storyId = (int) $story['id'];
      $mediaItems = $mediaMap[$storyId] ?? buildLegacyMediaList($story);
      $cover = $mediaItems[0] ?? [
        'type' => $story['mediaType'] ?? 'image',
        'url' => buildAssetUrl($story['mediaPath'] ?? ''),
      ];
      $timeline = buildStoryTimelineMeta($story);

      $authorType = strtolower((string) ($story['authorType'] ?? 'traveler'));
      $travelerId = (int) ($story['resolvedTravelerID'] ?? $story['travelerID'] ?? 0);
      $operatorId = (int) ($story['operatorID'] ?? 0);
      if ($authorType === 'operator' && $travelerId > 0) {
        $authorType = 'traveler';
        $operatorId = 0;
      }
      $profile = resolveStoryAuthorProfileImage($story, $authorType, $travelerId, $operatorId);

      $authorName =
        $story['authorDisplayName']
        ?? $story['travelerFullName']
        ?? $story['operatorFullName']
        ?? $story['travelerUsername']
        ?? $story['operatorUsername']
        ?? 'Traveler';
      $authorUsername =
        $story['authorHandle']
        ?? $story['travelerUsername']
        ?? $story['operatorUsername']
        ?? '';

      $authorId = $authorType === 'operator' && $operatorId > 0 ? $operatorId : $travelerId;

      return [
        'id' => $storyId,
        'authorId' => $authorId,
        'authorType' => $authorType,
        'authorName' => $authorName,
        'authorUsername' => $authorUsername,
        'authorAvatar' => $profile['public'],
        'profileImage' => $profile['relative'],
        'authorInitials' => computeInitials($authorName ?: ($authorUsername ?: 'Traveler')),
        'location' => $story['location'] ?? '',
        'media' => $mediaItems,
        'mediaCount' => count($mediaItems),
        'mediaType' => $cover['type'] ?? 'image',
        'mediaUrl' => $cover['url'] ?? '',
        'caption' => $story['caption'],
        'postedAt' => $story['createdAt'],
        'postedAtLabel' => buildRelativeTimeLabel($story['createdAt']),
        'createdAt' => $story['createdAt'],
        'createdAtLabel' => $timeline['created'],
        'updatedAt' => $story['updatedAt'],
        'updatedAtLabel' => $timeline['updated'],
        'timelineLabel' => $timeline['label'],
        'timelineType' => $timeline['type'],
        'likes' => (int) $story['likes'],
        'comments' => (int) $story['comments'],
        'saves' => (int) $story['saves'],
        'isLiked' => isset($reactionMap[$storyId]),
        'isSaved' => isset($saveMap[$storyId]),
        'viewerHasCommented' => isset($commentMap[$storyId]) && $commentMap[$storyId] > 0,
        'viewerCommentCount' => (int) ($commentMap[$storyId] ?? 0),
        'tags' => $tags[$storyId] ?? [],
        'categories' => $categories[$storyId] ?? [],
      ];
    },
    $stories
  );
}

function fetchStoryRow(PDO $pdo, int $storyId): ?array
{
  $stmt = $pdo->prepare(
    <<<SQL
    SELECT
      cs.id,
      cs.travelerID,
      cs.caption,
      cs.mediaType,
      cs.mediaPath,
      cs.location,
      cs.createdAt,
      cs.updatedAt,
      cs.likes,
      cs.comments,
      cs.saves,
      t.travelerID AS resolvedTravelerID,
      t.username AS travelerUsername,
      t.fullName AS travelerFullName,
      t.contactNumber,
      t.profileImage AS travelerProfileImage,
      op.operatorID,
      op.username AS operatorUsername,
      op.fullName AS operatorFullName,
      op.profileImage AS operatorProfileImage,
      COALESCE(t.fullName, op.fullName, t.username, op.username) AS authorDisplayName,
      COALESCE(t.username, op.username) AS authorHandle,
      COALESCE(t.profileImage, op.profileImage) AS authorProfileImage,
      CASE
        WHEN op.operatorID IS NOT NULL THEN 'operator'
        ELSE 'traveler'
      END AS authorType
    FROM community_story cs
    LEFT JOIN Traveler t ON t.travelerID = cs.travelerID
    LEFT JOIN TourismOperator op ON op.operatorID = cs.travelerID
    WHERE cs.id = :id
    LIMIT 1
    SQL
  );
  $stmt->execute([':id' => $storyId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetchMediaPaths(PDO $pdo, int $storyId): array
{
  $stmt = $pdo->prepare(
    'SELECT mediaPath FROM community_story_media WHERE storyId = :storyId ORDER BY position ASC'
  );
  $stmt->execute([':storyId' => $storyId]);
  $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
  return array_values(
    array_filter(
      array_map(
        static fn($value): string => is_string($value) ? trim($value) : '',
        $paths ?: []
      )
    )
  );
}

function fetchUserReactions(PDO $pdo, array $storyIds, int $travelerId): array
{
  if (!$storyIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
  $params = $storyIds;
  $params[] = $travelerId;

  $stmt = $pdo->prepare(
    "SELECT storyId FROM community_story_reaction WHERE storyId IN ($placeholders) AND travelerId = ?"
  );
  $stmt->execute($params);

  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storyId) {
    $map[(int) $storyId] = true;
  }
  return $map;
}

function fetchUserSaves(PDO $pdo, array $storyIds, int $travelerId): array
{
  if (!$storyIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
  $params = $storyIds;
  $params[] = $travelerId;

  $stmt = $pdo->prepare(
    "SELECT storyId FROM community_story_save WHERE storyId IN ($placeholders) AND travelerId = ?"
  );
  $stmt->execute($params);

  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storyId) {
    $map[(int) $storyId] = true;
  }
  return $map;
}

function fetchViewerComments(PDO $pdo, array $storyIds, int $travelerId): array
{
  if (!$storyIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
  $params = $storyIds;
  $params[] = $travelerId;

  $stmt = $pdo->prepare(
    "SELECT storyId, COUNT(*) AS commentCount
     FROM community_story_comment
     WHERE storyId IN ($placeholders) AND travelerId = ?
     GROUP BY storyId"
  );
  $stmt->execute($params);

  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $storyId = (int) ($row['storyId'] ?? 0);
    if ($storyId <= 0) {
      continue;
    }
    $map[$storyId] = (int) ($row['commentCount'] ?? 0);
  }

  return $map;
}

function fetchMediaByStory(PDO $pdo, array $storyIds, array $stories): array
{
  if (!$storyIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
  $stmt = $pdo->prepare(
    "SELECT storyId, mediaType, mediaPath, position
     FROM community_story_media
     WHERE storyId IN ($placeholders)
     ORDER BY storyId ASC, position ASC"
  );
  $stmt->execute($storyIds);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $mediaMap = [];
  foreach ($rows as $row) {
    $storyId = (int) ($row['storyId'] ?? 0);
    if ($storyId <= 0) {
      continue;
    }

    $position = is_numeric($row['position'] ?? null) ? (int) $row['position'] : 0;
    $mediaMap[$storyId][] = [
      'id' => sprintf('%d-media-%d', $storyId, $position),
      'type' => ($row['mediaType'] ?? '') === 'video' ? 'video' : 'image',
      'url' => buildAssetUrl((string) ($row['mediaPath'] ?? '')),
      'position' => $position,
    ];
  }

  foreach ($mediaMap as &$items) {
    usort(
      $items,
      static fn(array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0)
    );
  }
  unset($items);

  return $mediaMap;
}

function buildLegacyMediaList(array $story): array
{
  $path = (string) ($story['mediaPath'] ?? '');
  if ($path === '') {
    return [];
  }

  $type = detectMediaType((string) ($story['mediaType'] ?? $path)) ?? 'image';
  $storyId = (int) ($story['id'] ?? 0);

  return [[
    'id' => $storyId > 0 ? sprintf('%d-media-legacy', $storyId) : uniqid('legacy-media-', true),
    'type' => $type === 'video' ? 'video' : 'image',
    'url' => buildAssetUrl($path),
    'position' => 0,
  ]];
}

function fetchTagsByStory(PDO $pdo, array $storyIds): array
{
  if (!$storyIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
  $stmt = $pdo->prepare(
    "SELECT storyId, tag
     FROM community_story_tag
     WHERE storyId IN ($placeholders)
     ORDER BY storyId ASC, tag ASC"
  );
  $stmt->execute($storyIds);

  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $storyId = (int) ($row['storyId'] ?? 0);
    if ($storyId <= 0) {
      continue;
    }
    $tag = trim((string) ($row['tag'] ?? ''));
    if ($tag === '') {
      continue;
    }
    $map[$storyId][] = $tag;
  }

  return $map;
}

function fetchCategoriesByStory(PDO $pdo, array $storyIds): array
{
  if (!$storyIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
  $stmt = $pdo->prepare(
    "SELECT storyId, category
     FROM community_story_category
     WHERE storyId IN ($placeholders)
     ORDER BY storyId ASC, category ASC"
  );
  $stmt->execute($storyIds);

  $map = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $storyId = (int) ($row['storyId'] ?? 0);
    if ($storyId <= 0) {
      continue;
    }
    $category = trim((string) ($row['category'] ?? ''));
    if ($category === '') {
      continue;
    }
    $map[$storyId][] = $category;
  }

  return $map;
}

function normaliseArrayInput($input): array
{
  if ($input === null) {
    return [];
  }

  if (is_string($input)) {
    $trimmed = trim($input);
    if ($trimmed === '') {
      return [];
    }

    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      return normaliseArrayInput($decoded);
    }

    $parts = preg_split('/[,;\n]+/', $trimmed) ?: [];
    return array_values(
      array_filter(
        array_map(
          static fn(string $value): string => mb_substr(trim($value), 0, 64),
          $parts
        )
      )
    );
  }

  if (!is_array($input)) {
    return [];
  }

  $values = [];
  foreach ($input as $item) {
    if (is_scalar($item)) {
      $value = mb_substr(trim((string) $item), 0, 64);
      if ($value !== '') {
        $values[] = $value;
      }
    }
  }

  return array_values(array_unique($values));
}

function normaliseUploadFiles($files): array
{
  if (!$files) {
    return [];
  }

  if (isset($files['name']) && is_array($files['name'])) {
    $normalized = [];
    $count = count($files['name']);
    for ($index = 0; $index < $count; $index++) {
      $name = $files['name'][$index] ?? '';
      $tmpName = $files['tmp_name'][$index] ?? '';
      if ($name === '' && $tmpName === '') {
        continue;
      }
      $normalized[] = [
        'name' => $name,
        'type' => $files['type'][$index] ?? '',
        'tmp_name' => $tmpName,
        'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'][$index] ?? 0,
      ];
    }
    return $normalized;
  }

  if (isset($files['tmp_name'])) {
    return [$files];
  }

  return [];
}

function parseJsonInput(): array
{
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') {
    return [];
  }

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function detectMediaType(string $value): ?string
{
  $lower = strtolower($value);
  if ($lower === '') {
    return null;
  }

  if (strpos($lower, 'video') !== false) {
    return 'video';
  }
  if (strpos($lower, 'image') !== false) {
    return 'image';
  }

  $extension = pathinfo($lower, PATHINFO_EXTENSION);
  if ($extension !== '') {
    $videoExt = ['mp4', 'mov', 'avi', 'webm', 'mkv'];
    $imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    if (in_array($extension, $videoExt, true)) {
      return 'video';
    }
    if (in_array($extension, $imageExt, true)) {
      return 'image';
    }
  }

  return null;
}

function storeCommunityMedia(array $upload, int $travelerId): ?string
{
  $tmpPath = $upload['tmp_name'] ?? '';
  if ($tmpPath === '' || !file_exists($tmpPath)) {
    return null;
  }

  $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
  if ($extension === '' && isset($upload['type'])) {
    $mimeExtensionMap = [
      'image/jpeg' => 'jpg',
      'image/jpg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
      'video/mp4' => 'mp4',
      'video/quicktime' => 'mov',
      'video/webm' => 'webm',
      'video/x-msvideo' => 'avi',
      'video/x-matroska' => 'mkv',
    ];
    $extension = $mimeExtensionMap[strtolower($upload['type'])] ?? '';
  }

  $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
  $relativeDirectory = 'community_media/' . date('Y/m');
  $baseDir = communityAssetsBaseDir();
  $directoryPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

  if (!is_dir($directoryPath) && !@mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
    return null;
  }

  try {
    $uniqueToken = bin2hex(random_bytes(8));
  } catch (Throwable $e) {
    $uniqueToken = str_replace('.', '', uniqid('media_', true));
  }
  $filename = sprintf('traveler_%d_%s.%s', $travelerId, $uniqueToken, $extension);
  $targetPath = $directoryPath . DIRECTORY_SEPARATOR . $filename;

  if (!@move_uploaded_file($tmpPath, $targetPath)) {
    if (!@rename($tmpPath, $targetPath)) {
      return null;
    }
  }

  return $relativeDirectory . '/' . $filename;
}

function fetchCommentRow(PDO $pdo, int $commentId): ?array
{
  $stmt = $pdo->prepare(
    'SELECT c.id, c.storyId, c.travelerId, c.content, c.rating, c.createdAt, c.updatedAt,
            t.username, t.fullName
     FROM community_story_comment c
     INNER JOIN Traveler t ON t.travelerID = c.travelerId
     WHERE c.id = :id
     LIMIT 1'
  );
  $stmt->execute([':id' => $commentId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetchComments(PDO $pdo, int $storyId, int $limit, int $offset): array
{
  $stmt = $pdo->prepare(
    'SELECT c.id, c.storyId, c.travelerId, c.content, c.rating, c.createdAt, c.updatedAt,
            t.username, t.fullName
     FROM community_story_comment c
     INNER JOIN Traveler t ON t.travelerID = c.travelerId
     WHERE c.storyId = :storyId
     ORDER BY c.createdAt DESC
     LIMIT :limit OFFSET :offset'
  );
  $stmt->bindValue(':storyId', $storyId, PDO::PARAM_INT);
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $countStmt = $pdo->prepare('SELECT COUNT(*) FROM community_story_comment WHERE storyId = :storyId');
  $countStmt->execute([':storyId' => $storyId]);
  $total = (int) $countStmt->fetchColumn();

  return [
    'rows' => $rows,
    'total' => $total,
  ];
}

function formatComment(array $row): array
{
  $authorName = $row['fullName'] ?: ($row['username'] ?? 'Traveler');

  return [
    'id' => (int) $row['id'],
    'storyId' => (int) $row['storyId'],
    'travelerId' => (int) $row['travelerId'],
    'authorName' => $authorName,
    'authorUsername' => $row['username'] ?? '',
    'authorInitials' => computeInitials($authorName),
    'content' => (string) $row['content'],
    'rating' => $row['rating'] !== null ? (int) $row['rating'] : null,
    'createdAt' => $row['createdAt'],
    'createdAtLabel' => buildRelativeTimeLabel($row['createdAt']),
    'updatedAt' => $row['updatedAt'] ?? $row['createdAt'],
  ];
}

function recalcStoryMetric(PDO $pdo, string $table, int $storyId): int
{
  $allowed = [
    'community_story_reaction',
    'community_story_save',
    'community_story_comment',
  ];

  if (!in_array($table, $allowed, true)) {
    return 0;
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE storyId = :storyId");
  $stmt->execute([':storyId' => $storyId]);
  return (int) $stmt->fetchColumn();
}

function updateStoryMetric(PDO $pdo, string $column, int $storyId, int $value): void
{
  $allowed = ['likes', 'saves', 'comments'];
  if (!in_array($column, $allowed, true)) {
    return;
  }

  $stmt = $pdo->prepare("UPDATE community_story SET $column = :value WHERE id = :id");
  $stmt->execute([
    ':value' => max(0, $value),
    ':id' => $storyId,
  ]);
}

function communityAssetsBaseDir(): string
{
  static $baseDir;
  if ($baseDir === null) {
    $baseDir = realpath(__DIR__ . '/../../public_assets');
    if ($baseDir === false) {
      $baseDir = __DIR__ . '/../../public_assets';
    }
    if (!is_dir($baseDir)) {
      @mkdir($baseDir, 0775, true);
    }
    $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
  }
  return $baseDir;
}

function cleanupStoredMedia(array $records): void
{
  foreach ($records as $record) {
    $path = '';
    if (is_array($record)) {
      $path = $record['path'] ?? $record['mediaPath'] ?? '';
    } elseif (is_string($record)) {
      $path = $record;
    }

    $path = trim($path);
    if ($path === '') {
      continue;
    }

    $fullPath = communityAssetsBaseDir() . DIRECTORY_SEPARATOR .
      str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));
    if (is_file($fullPath)) {
      @unlink($fullPath);
    }
  }
}

function buildAssetUrl(string $relativePath): string
{
  $trimmed = trim($relativePath);
  if ($trimmed === '') {
    return '';
  }

  if (preg_match('#^(?:https?:)?//#i', $trimmed) || str_starts_with($trimmed, 'data:')) {
    return $trimmed;
  }

  $normalised = ltrim(str_replace('\\', '/', $trimmed), '/');
  if (stripos($normalised, 'backend/public_assets/') === 0) {
    $normalised = substr($normalised, strlen('backend/public_assets/'));
  }
  if (stripos($normalised, 'public_assets/') === 0) {
    $normalised = substr($normalised, strlen('public_assets/'));
  }

  return $normalised;
}

function buildRelativeTimeLabel(string $dateTime): string
{
  if ($dateTime === '') {
    return '';
  }

  try {
    $timestamp = new DateTimeImmutable($dateTime);
    $now = new DateTimeImmutable('now');
  } catch (Throwable $e) {
    return $dateTime;
  }

  $diff = $now->getTimestamp() - $timestamp->getTimestamp();
  if ($diff < 60) {
    return 'Just now';
  }
  if ($diff < 3600) {
    $minutes = (int) floor($diff / 60);
    return $minutes === 1 ? '1 min ago' : $minutes . ' mins ago';
  }
  if ($diff < 86_400) {
    $hours = (int) floor($diff / 3600);
    return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
  }
  if ($diff < 604_800) {
    $days = (int) floor($diff / 86_400);
    return $days === 1 ? 'Yesterday' : $days . ' days ago';
  }
  if ($diff < 2_592_000) {
    $weeks = (int) floor($diff / 604_800);
    return $weeks <= 1 ? 'Last week' : $weeks . ' weeks ago';
  }

  return $timestamp->format('M j, Y');
}

function buildStoryTimelineMeta(array $story): array
{
  $createdRaw = $story['createdAt'] ?? '';
  $updatedRaw = $story['updatedAt'] ?? '';

  $createdDt = parseDateTimeValue($createdRaw);
  $updatedDt = parseDateTimeValue($updatedRaw);

  $createdLabel = $createdDt ? formatAbsoluteDateTime($createdDt) : ($createdRaw ?: '');
  $updatedLabel = $updatedDt ? formatAbsoluteDateTime($updatedDt) : ($updatedRaw ?: '');

  $hasUpdate = $updatedDt && $createdDt && $updatedDt > $createdDt;

  $type = $hasUpdate ? 'updated' : 'created';
  $labelSource = $hasUpdate ? $updatedLabel : $createdLabel;
  $label = $labelSource !== '' ? ucfirst($type) . ' ' . $labelSource : '';

  return [
    'type' => $type,
    'label' => $label,
    'created' => $createdLabel,
    'updated' => $updatedLabel,
  ];
}

function parseDateTimeValue(?string $value): ?DateTimeImmutable
{
  if (!$value) {
    return null;
  }

  try {
    return new DateTimeImmutable($value);
  } catch (Throwable $e) {
    return null;
  }
}

function formatAbsoluteDateTime(DateTimeImmutable $date): string
{
  return $date->format('M j, Y \\a\\t g:i A');
}

function resolveStoryAuthorProfileImage(array $story, string $authorType, int $travelerId, int $operatorId): array
{
  $candidates = [];
  $primary = $story['authorProfileImage'] ?? $story['profileImage'] ?? null;

  if ($authorType === 'operator' && $operatorId > 0) {
    $candidates[] = resolveProfileImageReference('operator', $operatorId, $primary);
  }

  if ($travelerId > 0) {
    $candidates[] = resolveProfileImageReference('traveler', $travelerId, $primary);
    $candidates[] = resolveProfileImageReference('traveler', $travelerId, $story['travelerProfileImage'] ?? null);
  }

  if ($authorType !== 'operator' && $operatorId > 0) {
    $candidates[] = resolveProfileImageReference('operator', $operatorId, $story['operatorProfileImage'] ?? null);
  }

  foreach ($candidates as $candidate) {
    if (!empty($candidate['public']) || !empty($candidate['relative'])) {
      return $candidate;
    }
  }

  return [
    'relative' => '',
    'public' => '',
  ];
}

function computeInitials(string $name): string
{
  $parts = preg_split('/\s+/', trim($name)) ?: [];
  if (!$parts) {
    return 'TR';
  }

  $initials = '';
  foreach ($parts as $part) {
    if ($part === '') {
      continue;
    }
    $initials .= mb_substr($part, 0, 1);
    if (mb_strlen($initials) >= 2) {
      break;
    }
  }

  return mb_strtoupper($initials);
}
