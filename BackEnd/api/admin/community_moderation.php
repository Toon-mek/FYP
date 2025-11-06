<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/polyfills.php';
require_once __DIR__ . '/../helpers/notifications.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
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

switch ($method) {
    case 'GET':
        handleGet($pdo);
        break;
    case 'DELETE':
        handleDelete($pdo);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function handleGet(PDO $pdo): void
{
    $postId = isset($_GET['postId']) ? (int) $_GET['postId'] : null;
    if ($postId !== null && $postId > 0) {
        $post = loadSinglePost($pdo, $postId);
        if ($post === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Post not found']);
            return;
        }

        echo json_encode([
            'post' => $post,
        ]);
        return;
    }

    $limit = clamp((int) ($_GET['limit'] ?? 50), 1, 200);
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $mediaFilter = normaliseKey($_GET['mediaType'] ?? ($_GET['visibility'] ?? 'all'));
    $categoryFilter = trim((string) ($_GET['category'] ?? ''));
    $authorFilter = normaliseKey($_GET['authorType'] ?? 'all');
    $search = trim((string) ($_GET['search'] ?? ''));

    if ($authorFilter === 'operator') {
        $authorFilter = 'traveler';
    }

    $filters = [
        'limit' => $limit,
        'offset' => $offset,
        'mediaType' => $mediaFilter,
        'category' => $categoryFilter,
        'authorType' => $authorFilter,
        'search' => $search,
    ];

    $listResult = loadPostList($pdo, $filters);
    $response = [
        'posts' => $listResult['posts'],
        'total' => $listResult['total'],
        'summary' => buildModerationSummary($pdo),
        'categories' => buildCategoryOptions($pdo),
        'filters' => [
            'mediaType' => $mediaFilter,
            'category' => $categoryFilter === '' ? 'all' : $categoryFilter,
            'authorType' => $authorFilter,
            'search' => $search,
        ],
    ];

    echo json_encode($response);
}

function handleDelete(PDO $pdo): void
{
    $payload = parseJsonInput();
    $postId = (int) ($payload['postId'] ?? $payload['id'] ?? ($_GET['postId'] ?? 0));
    $adminId = isset($payload['adminId']) ? (int) $payload['adminId'] : null;
    $reason = trim((string) ($payload['reason'] ?? ''));

    if ($postId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'postId is required']);
        return;
    }

    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please include a short note explaining why the story was removed.']);
        return;
    }

    $storyRow = fetchStoryRow($pdo, $postId);
    if ($storyRow === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Post not found or already removed']);
        return;
    }

    $mediaPaths = fetchMediaPaths($pdo, $postId);
    if (!empty($storyRow['mediaPath'])) {
        $mediaPaths[] = $storyRow['mediaPath'];
    }

    $pdo->beginTransaction();
    try {
        deleteStoryAssociations($pdo, $postId);

        $stmt = $pdo->prepare('DELETE FROM community_story WHERE id = :id');
        $stmt->execute([':id' => $postId]);

        logStoryRemoval($pdo, $postId, $adminId, $reason);
        notifyStoryRemoval($pdo, $storyRow, $reason);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Failed to delete community post: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to remove post. Please try again.']);
        return;
    }

    cleanupStoredMedia($mediaPaths);

    echo json_encode([
        'ok' => true,
        'message' => 'Post removed successfully.',
    ]);
}

function notifyStoryRemoval(PDO $pdo, array $storyRow, string $reason): void
{
    $recipientId = (int) ($storyRow['travelerId'] ?? $storyRow['travelerID'] ?? 0);

    if ($recipientId <= 0) {
        return;
    }

    $title = 'Community post removed';
    $caption = trim((string) ($storyRow['caption'] ?? ''));
    $postLabel = $caption !== '' ? sprintf('"%s"', mb_substr($caption, 0, 80)) : 'your story';

    $message = sprintf(
        'Your community post %s was removed by an administrator. Reason: %s',
        $postLabel,
        $reason
    );

    recordNotification($pdo, 'Traveler', $recipientId, $title, $message);
}

function loadSinglePost(PDO $pdo, int $postId): ?array
{
    $row = fetchStoryRow($pdo, $postId);
    if ($row === null) {
        return null;
    }

    $mapped = mapStoriesForAdmin($pdo, [$row]);
    return $mapped[0] ?? null;
}

function loadPostList(PDO $pdo, array $filters): array
{
    $limit = $filters['limit'];
    $offset = $filters['offset'];
    $mediaType = $filters['mediaType'];
    $category = $filters['category'];
    $authorType = $filters['authorType'];
    if ($authorType === 'operator') {
        $authorType = 'traveler';
    }
    $search = $filters['search'];

    $conditions = [];
    $params = [];
    $joins = [];

    if ($category !== '' && strtolower($category) !== 'all') {
        $joins[] = 'INNER JOIN community_story_category csc_filter
            ON csc_filter.storyId = cs.id AND csc_filter.category = :categoryFilter';
        $params[':categoryFilter'] = $category;
    }

    if ($mediaType === 'image') {
        $conditions[] = "(cs.mediaType IS NULL OR cs.mediaType = '' OR cs.mediaType = 'image')";
    } elseif ($mediaType === 'video') {
        $conditions[] = "cs.mediaType = 'video'";
    }

    if ($authorType === 'traveler') {
        $conditions[] = 'cs.travelerID IS NOT NULL';
    }

    if ($search !== '') {
        $conditions[] = "(LOWER(cs.caption) LIKE :searchLower OR LOWER(cs.location) LIKE :searchLower
            OR LOWER(t.fullName) LIKE :searchLower OR LOWER(t.username) LIKE :searchLower)";
        $params[':searchLower'] = '%' . mb_strtolower($search, 'UTF-8') . '%';
    }

    $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $joinClause = $joins ? "\n" . implode("\n", $joins) : '';

    $selectSql = <<<SQL
SELECT
    cs.id,
    cs.travelerID,
    cs.caption,
    cs.location,
    cs.mediaType,
    cs.mediaPath,
    cs.likes,
    cs.saves,
    cs.comments,
    cs.createdAt,
    cs.updatedAt,
    t.travelerID AS travelerId,
    t.username AS travelerUsername,
    t.fullName AS travelerName,
    t.email AS travelerEmail,
    t.contactNumber AS travelerPhone,
    t.profileImage AS travelerProfileImage
FROM community_story cs
LEFT JOIN traveler t ON t.travelerID = cs.travelerID
$joinClause
$whereClause
ORDER BY cs.createdAt DESC
LIMIT :limit OFFSET :offset
SQL;

    $stmt = $pdo->prepare($selectSql);
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            continue;
        }
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $mapped = mapStoriesForAdmin($pdo, $rows);

    $countSql = <<<SQL
SELECT COUNT(DISTINCT cs.id)
FROM community_story cs
LEFT JOIN traveler t ON t.travelerID = cs.travelerID
$joinClause
$whereClause
SQL;

    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            continue;
        }
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    return [
        'posts' => $mapped,
        'total' => $total,
    ];
}

function mapStoriesForAdmin(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return [];
    }

    $storyIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $mediaMap = fetchMediaByStory($pdo, $storyIds, $rows);
    $categoryMap = fetchCategoriesByStory($pdo, $storyIds);
    $tagMap = fetchTagsByStory($pdo, $storyIds);

    $mapped = [];
    foreach ($rows as $row) {
        $storyId = (int) $row['id'];
        $mediaItems = $mediaMap[$storyId] ?? buildLegacyMediaList($row);
        $primaryMedia = $mediaItems[0] ?? null;

        if (!$primaryMedia) {
            $primaryMedia = [
                'type' => normaliseMediaType((string) ($row['mediaType'] ?? 'image')),
                'url' => buildAssetUrl((string) ($row['mediaPath'] ?? '')),
                'position' => 0,
            ];
            $mediaItems = [$primaryMedia];
        }

        $author = buildAuthorPayload($row);
        $timeline = buildStoryTimelineMeta($row);
        $likes = (int) ($row['likes'] ?? 0);
        $comments = (int) ($row['comments'] ?? 0);
        $saves = (int) ($row['saves'] ?? 0);

        $mapped[] = [
            'id' => $storyId,
            'caption' => $row['caption'] ?? '',
            'location' => $row['location'] ?? '',
            'mediaType' => $primaryMedia['type'],
            'mediaCount' => count($mediaItems),
            'media' => $mediaItems,
            'categories' => $categoryMap[$storyId] ?? [],
            'tags' => $tagMap[$storyId] ?? [],
            'createdAt' => normaliseDateTime($row['createdAt'] ?? null),
            'updatedAt' => normaliseDateTime($row['updatedAt'] ?? null),
            'timeline' => $timeline,
            'metrics' => [
                'likes' => $likes,
                'comments' => $comments,
                'saves' => $saves,
                'duration' => $row['duration'] ?? null,
                'engagementScore' => $likes + $comments + $saves,
            ],
            'author' => $author,
        ];
    }

    return $mapped;
}

function buildModerationSummary(PDO $pdo): array
{
    $summary = [
        'totalPosts' => 0,
        'recentRemovals30d' => 0,
    ];

    try {
        $totals = $pdo->query('SELECT COUNT(*) AS totalPosts FROM community_story');
        if ($totals !== false) {
            $row = $totals->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['totalPosts'] = (int) ($row['totalPosts'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('Failed to compute community totals: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM community_story_moderation_log
             WHERE action = 'remove' AND createdAt >= (CURRENT_TIMESTAMP - INTERVAL 30 DAY)"
        );
        if ($stmt !== false) {
            $summary['recentRemovals30d'] = (int) $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('Failed to compute removal summary: ' . $e->getMessage());
    }

    return $summary;
}

function buildCategoryOptions(PDO $pdo): array
{
    $options = [
        [
            'value' => 'all',
            'label' => 'All categories',
            'count' => null,
        ],
    ];

    try {
        $stmt = $pdo->query(
            "SELECT category, COUNT(*) AS total
             FROM community_story_category
             GROUP BY category
             ORDER BY total DESC, category ASC
             LIMIT 20"
        );
        if ($stmt !== false) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $category = (string) ($row['category'] ?? '');
                if ($category === '') {
                    continue;
                }
                $options[] = [
                    'value' => $category,
                    'label' => $category,
                    'count' => (int) ($row['total'] ?? 0),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Failed to load category options: ' . $e->getMessage());
    }

    return $options;
}

function fetchStoryRow(PDO $pdo, int $storyId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            cs.id,
            cs.travelerID,
            cs.caption,
            cs.location,
            cs.mediaType,
            cs.mediaPath,
            cs.likes,
            cs.saves,
            cs.comments,
            cs.createdAt,
            cs.updatedAt,
            t.travelerID AS travelerId,
            t.username AS travelerUsername,
            t.fullName AS travelerName,
            t.email AS travelerEmail,
            t.contactNumber AS travelerPhone,
            t.profileImage AS travelerProfileImage
         FROM community_story cs
         LEFT JOIN traveler t ON t.travelerID = cs.travelerID
         WHERE cs.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $storyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function deleteStoryAssociations(PDO $pdo, int $storyId): void
{
    $tables = [
        'community_story_comment',
        'community_story_media',
        'community_story_tag',
        'community_story_category',
        'community_story_reaction',
        'community_story_save',
    ];

    foreach ($tables as $table) {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE storyId = :id");
        $stmt->execute([':id' => $storyId]);
    }
}

function logStoryRemoval(PDO $pdo, int $storyId, ?int $adminId, string $reason): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO community_story_moderation_log (storyId, adminId, action, reason, details)
         VALUES (:storyId, :adminId, 'remove', :reason, NULL)"
    );
    $stmt->execute([
        ':storyId' => $storyId,
        ':adminId' => $adminId ?: null,
        ':reason' => $reason !== '' ? $reason : null,
    ]);
}

function fetchMediaByStory(PDO $pdo, array $storyIds, array $rows): array
{
    if (!$storyIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($storyIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, storyId, mediaType, mediaPath, position
         FROM community_story_media
         WHERE storyId IN ($placeholders)
         ORDER BY storyId ASC, position ASC"
    );
    $stmt->execute($storyIds);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $storyId = (int) $row['storyId'];
        $map[$storyId][] = [
            'id' => (int) $row['id'],
            'type' => normaliseMediaType((string) ($row['mediaType'] ?? 'image')),
            'url' => buildAssetUrl((string) ($row['mediaPath'] ?? '')),
            'position' => (int) ($row['position'] ?? 0),
        ];
    }

    foreach ($map as &$items) {
        usort(
            $items,
            static fn(array $a, array $b): int => ($a['position'] ?? 0) <=> ($b['position'] ?? 0)
        );
    }
    unset($items);

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
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $storyId = (int) $row['storyId'];
        $category = trim((string) ($row['category'] ?? ''));
        if ($category === '') {
            continue;
        }
        $map[$storyId][] = $category;
    }

    return $map;
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
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $storyId = (int) $row['storyId'];
        $tag = trim((string) ($row['tag'] ?? ''));
        if ($tag === '') {
            continue;
        }
        $map[$storyId][] = $tag;
    }

    return $map;
}

function buildLegacyMediaList(array $row): array
{
    $path = (string) ($row['mediaPath'] ?? '');
    if ($path === '') {
        return [];
    }

    return [[
        'id' => (int) ($row['id'] ?? 0),
        'type' => normaliseMediaType((string) ($row['mediaType'] ?? 'image')),
        'url' => buildAssetUrl($path),
        'position' => 0,
    ]];
}

function buildAuthorPayload(array $row): array
{
    $name = $row['travelerName'] ?? $row['travelerUsername'] ?? 'Traveler';
    $username = $row['travelerUsername'] ?? '';
    $email = $row['travelerEmail'] ?? '';
    $contact = $row['travelerPhone'] ?? '';
    $profileImage = $row['travelerProfileImage'] ?? '';

    return [
        'type' => 'traveler',
        'id' => (int) ($row['travelerId'] ?? 0),
        'name' => $name,
        'username' => $username,
        'email' => $email,
        'contact' => $contact,
        'avatar' => buildAssetUrl($profileImage),
        'initials' => computeInitials($name),
    ];
}

function normaliseKey(string $value): string
{
    $normalised = strtolower(trim($value));
    return $normalised === '' ? 'all' : $normalised;
}

function clamp(int $value, int $min, int $max): int
{
    return max($min, min($value, $max));
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

function normaliseDateTime(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
        return $dt->format(DateTimeInterface::ATOM);
    } catch (Throwable) {
        return $value;
    }
}

function parseDateTimeValue(?string $value): ?DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable) {
        return null;
    }
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

function formatAbsoluteDateTime(DateTimeImmutable $date): string
{
    return $date->format('M j, Y \\a\\t g:i A');
}

function normaliseMediaType(string $value): string
{
    $lower = strtolower(trim($value));
    return $lower === 'video' ? 'video' : 'image';
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
