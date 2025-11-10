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
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

function safeQuery($pdo, $sql, $default = 0) {
    try {
        $result = $pdo->query($sql);
        if ($result === false) return $default;
        $value = $result->fetchColumn();
        return $value !== false ? (int)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function safeQueryAll($pdo, $sql, $default = []) {
    try {
        $result = $pdo->query($sql);
        if ($result === false) return $default;
        return $result->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $default;
    }
}

// Get date range (default 30 days)
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$days = max(1, min(365, $days));

$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime("-{$days} days"));

try {
    // ==================== USAGE REPORTS ====================
    
    // Active Users
    $activeUsers = [
        'travelers' => safeQuery($pdo, "SELECT COUNT(DISTINCT travelerID) FROM TravelerLoginLog 
            WHERE DATE(loginTimestamp) BETWEEN '{$startDate}' AND '{$endDate}'"),
        'operators' => safeQuery($pdo, "SELECT COUNT(DISTINCT operatorID) FROM OperatorLoginLog 
            WHERE DATE(loginTimestamp) BETWEEN '{$startDate}' AND '{$endDate}'"),
    ];
    $activeUsers['total'] = $activeUsers['travelers'] + $activeUsers['operators'];
    
    // New Listings
    $newListings = safeQuery($pdo, "SELECT COUNT(*) FROM BusinessListing 
        WHERE DATE(submittedDate) BETWEEN '{$startDate}' AND '{$endDate}'");
    
    // Simulated Bookings (Messages as proxy for inquiries)
    $simulatedBookings = safeQuery($pdo, "SELECT COUNT(*) FROM Message 
        WHERE DATE(sentAt) BETWEEN '{$startDate}' AND '{$endDate}' 
        AND listingID IS NOT NULL");
    
    // Chatbot Usage
    $chatbotUsage = 0;
    
    // ==================== ANALYTICS DASHBOARD ====================
    
    // Daily Logins Trend
    $dailyLogins = safeQueryAll($pdo, "
        SELECT DATE(loginTimestamp) as date, COUNT(*) as count
        FROM (
            SELECT loginTimestamp FROM TravelerLoginLog WHERE DATE(loginTimestamp) BETWEEN '{$startDate}' AND '{$endDate}'
            UNION ALL
            SELECT loginTimestamp FROM OperatorLoginLog WHERE DATE(loginTimestamp) BETWEEN '{$startDate}' AND '{$endDate}'
        ) combined
        GROUP BY DATE(loginTimestamp)
        ORDER BY date ASC
    ");
    $dailyLogins = array_map(function($row) {
        return [
            'date' => $row['date'],
            'count' => (int)$row['count']
        ];
    }, $dailyLogins);
    
    // Community Activeness (Top users by posts, comments, stories, engagement)
    $communityActiveness = safeQueryAll($pdo, "
        SELECT 
            t.travelerID,
            COALESCE(t.fullName, t.username, 'Unknown') as userName,
            t.username,
            COUNT(DISTINCT CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.id END) as posts,
            COUNT(DISTINCT CASE WHEN DATE(csc.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN csc.id END) as comments,
            COUNT(DISTINCT CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.id END) as stories,
            COALESCE(SUM(CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.likes ELSE 0 END), 0) as storyLikes,
            COALESCE(SUM(CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.comments ELSE 0 END), 0) as storyComments,
            COALESCE(SUM(CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.saves ELSE 0 END), 0) as storySaves,
            (COUNT(DISTINCT CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.id END) + 
             COUNT(DISTINCT CASE WHEN DATE(csc.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN csc.id END)) as totalActivity
        FROM Traveler t
        LEFT JOIN community_story cs ON t.travelerID = cs.travelerID
        LEFT JOIN community_story_comment csc ON t.travelerID = csc.travelerId
        GROUP BY t.travelerID
        HAVING totalActivity > 0
        ORDER BY totalActivity DESC, storyLikes DESC
        LIMIT 10
    ");
    $communityActiveness = array_map(function($row) {
        $engagement = (int)$row['storyLikes'] + (int)$row['storyComments'] + (int)$row['storySaves'];
        return [
            'userID' => (int)$row['travelerID'],
            'userName' => $row['userName'],
            'username' => $row['username'] ?? '',
            'posts' => (int)$row['posts'],
            'comments' => (int)$row['comments'],
            'stories' => (int)$row['stories'],
            'likes' => (int)$row['storyLikes'],
            'storyComments' => (int)$row['storyComments'],
            'saves' => (int)$row['storySaves'],
            'engagement' => $engagement,
            'totalActivity' => (int)$row['totalActivity']
        ];
    }, $communityActiveness);

    // Community Post Categories
    $communityCategoryRaw = safeQueryAll($pdo, "
        SELECT
            CASE
                WHEN csc.category IS NULL OR csc.category = '' THEN 'Uncategorized'
                ELSE csc.category
            END AS category,
            COUNT(*) as count
        FROM community_story cs
        LEFT JOIN community_story_category csc ON csc.storyId = cs.id
        WHERE DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}'
        GROUP BY category
        ORDER BY count DESC
        LIMIT 12
    ");
    $communityCategories = array_map(function($row) {
        return [
            'category' => $row['category'],
            'count' => (int)$row['count'],
        ];
    }, $communityCategoryRaw);
    
    // Top User Activities
    $userActivities = [
        'posts' => safeQuery($pdo, "SELECT COUNT(*) FROM community_story 
            WHERE DATE(createdAt) BETWEEN '{$startDate}' AND '{$endDate}'"),
        'comments' => safeQuery($pdo, "SELECT COUNT(*) FROM community_story_comment 
            WHERE DATE(createdAt) BETWEEN '{$startDate}' AND '{$endDate}'"),
        'reviews' => safeQuery($pdo, "SELECT COUNT(*) FROM ListingReview 
            WHERE DATE(createdAt) BETWEEN '{$startDate}' AND '{$endDate}'"),
        'saves' => safeQuery($pdo, "SELECT COUNT(*) FROM ListingSave 
            WHERE DATE(savedAt) BETWEEN '{$startDate}' AND '{$endDate}'"),
        'messages' => safeQuery($pdo, "SELECT COUNT(*) FROM Message 
            WHERE DATE(sentAt) BETWEEN '{$startDate}' AND '{$endDate}'"),
    ];
    
    // Operator Rankings
    $operatorRankings = safeQueryAll($pdo, "
        SELECT 
            o.operatorID,
            o.fullName,
            o.businessType,
            COUNT(DISTINCT bl.listingID) as totalListings,
            COUNT(DISTINCT CASE WHEN bl.status = 'Approved' THEN bl.listingID END) as approvedListings,
            COUNT(DISTINCT lr.reviewID) as totalReviews,
            COALESCE(AVG(lr.rating), 0) as avgRating,
            COUNT(DISTINCT CASE WHEN lr.rating >= 4 THEN lr.reviewID END) as goodReviews,
            COUNT(DISTINCT CASE WHEN lr.rating < 3 THEN lr.reviewID END) as badReviews,
            COUNT(DISTINCT ls.saveID) as totalSaves
        FROM TourismOperator o
        LEFT JOIN BusinessListing bl ON o.operatorID = bl.operatorID
        LEFT JOIN ListingReview lr ON bl.listingID = lr.listingID
        LEFT JOIN ListingSave ls ON bl.listingID = ls.listingID
        GROUP BY o.operatorID
        ORDER BY approvedListings DESC, avgRating DESC, totalSaves DESC
        LIMIT 20
    ");
    $operatorRankings = array_map(function($row) {
        return [
            'operatorID' => (int)$row['operatorID'],
            'name' => $row['fullName'],
            'businessType' => $row['businessType'] ?? 'N/A',
            'totalListings' => (int)$row['totalListings'],
            'approvedListings' => (int)$row['approvedListings'],
            'totalReviews' => (int)$row['totalReviews'],
            'avgRating' => round((float)$row['avgRating'], 1),
            'goodReviews' => (int)$row['goodReviews'],
            'badReviews' => (int)$row['badReviews'],
            'totalSaves' => (int)$row['totalSaves']
        ];
    }, $operatorRankings);
    
    // Category Distribution
    $categoryDistribution = safeQueryAll($pdo, "
        SELECT 
            COALESCE(category, 'Others') as category,
            COUNT(*) as count
        FROM BusinessListing
        WHERE status = 'Approved'
        GROUP BY category
        ORDER BY count DESC
    ");
    $categoryDistribution = array_map(function($row) {
        return [
            'category' => $row['category'],
            'count' => (int)$row['count']
        ];
    }, $categoryDistribution);
    
    // Total Statistics
    $totalStats = [
        'totalUsers' => safeQuery($pdo, "SELECT COUNT(*) FROM Traveler") +
                       safeQuery($pdo, "SELECT COUNT(*) FROM TourismOperator"),
        'totalListings' => safeQuery($pdo, "SELECT COUNT(*) FROM BusinessListing WHERE status = 'Approved'"),
        'totalReviews' => safeQuery($pdo, "SELECT COUNT(*) FROM ListingReview"),
        'totalMessages' => safeQuery($pdo, "SELECT COUNT(*) FROM Message"),
    ];
    
    echo json_encode([
        'ok' => true,
        'dateRange' => [
            'start' => $startDate,
            'end' => $endDate,
            'days' => $days
        ],
        'usageReports' => [
            'activeUsers' => $activeUsers,
            'newListings' => $newListings,
            'simulatedBookings' => $simulatedBookings,
            'chatbotUsage' => $chatbotUsage
        ],
        'analytics' => [
            'dailyLogins' => $dailyLogins,
            'communityActiveness' => $communityActiveness,
            'communityCategories' => $communityCategories,
            'userActivities' => $userActivities,
            'operatorRankings' => $operatorRankings,
            'categoryDistribution' => $categoryDistribution
        ],
        'totalStats' => $totalStats
    ]);
    
} catch (Throwable $e) {
    error_log('Analytics error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate analytics report'
    ]);
}

