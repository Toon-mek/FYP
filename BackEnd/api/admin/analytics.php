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
        if ($result === false) {
            error_log("safeQuery failed: " . json_encode($pdo->errorInfo()));
            return $default;
        }
        $value = $result->fetchColumn();
        return $value !== false ? (int)$value : $default;
    } catch (Throwable $e) {
        error_log("safeQuery error: " . $e->getMessage());
        return $default;
    }
}

function safeQueryRow($pdo, $sql, $default = []) {
    try {
        $result = $pdo->query($sql);
        if ($result === false) {
            error_log("safeQueryRow failed: " . json_encode($pdo->errorInfo()));
            return $default;
        }
        $row = $result->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : $default;
    } catch (Throwable $e) {
        error_log("safeQueryRow error: " . $e->getMessage());
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

// Use Malaysia timezone for date calculations
$malaysiaTimezone = new DateTimeZone('Asia/Kuala_Lumpur');
$now = new DateTimeImmutable('now', $malaysiaTimezone);
$endDate = $now->format('Y-m-d');
$startDateTime = $now->modify("-{$days} days");
$startDate = $startDateTime->format('Y-m-d');

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
    
    // Confirmed Bookings
    $confirmedBookings = safeQuery($pdo, "SELECT COUNT(*) FROM traveler_booking_history 
        WHERE DATE(paidAt) BETWEEN '{$startDate}' AND '{$endDate}' 
        AND status = 'confirmed'");
    
    // Chatbot Usage - Count total messages/interactions
    $chatbotUsage = safeQuery($pdo, "SELECT COUNT(*) FROM ChatbotLog 
        WHERE DATE(timestamp) BETWEEN '{$startDate}' AND '{$endDate}'");
    
    // Chatbot Conversations - Count unique sessions
    $chatbotConversations = safeQuery($pdo, "SELECT COUNT(DISTINCT sessionID) FROM ChatbotConversation 
        WHERE DATE(timestamp) BETWEEN '{$startDate}' AND '{$endDate}'");
    
    // ==================== ANALYTICS DASHBOARD ====================
    
    // Daily Active Sessions - Count unique users who had active sessions on each day
    $dailyLogins = [];
    
    // Generate all dates in range
    $period = new DatePeriod(
        new DateTime($startDate),
        new DateInterval('P1D'),
        (new DateTime($endDate))->modify('+1 day')
    );
    
    foreach ($period as $date) {
        $currentDate = $date->format('Y-m-d');
        
        // Count unique active users on this date
        // A user has an active session on a date if they have ANY login record where:
        // - Login happened on or before this date
        // - Logout hasn't happened yet OR happened on or after this date
        $activeCount = safeQueryRow($pdo, "
            SELECT COUNT(DISTINCT user_id) as count
            FROM (
                SELECT CONCAT('T', travelerID) as user_id
                FROM TravelerLoginLog
                WHERE loginTimestamp <= '{$currentDate} 23:59:59'
                AND (logoutTimestamp IS NULL OR logoutTimestamp >= '{$currentDate} 00:00:00')
                UNION
                SELECT CONCAT('O', operatorID) as user_id
                FROM OperatorLoginLog
                WHERE loginTimestamp <= '{$currentDate} 23:59:59'
                AND (logoutTimestamp IS NULL OR logoutTimestamp >= '{$currentDate} 00:00:00')
            ) active_users
        ", ['count' => 0])['count'];
        
        $dailyLogins[] = [
            'date' => $currentDate,
            'count' => (int)$activeCount
        ];
    }
    
    // Community Activeness (Top users by posts, comments, stories, engagement)
    $communityActiveness = safeQueryAll($pdo, "
        SELECT 
            t.travelerID,
            COALESCE(t.fullName, t.username, 'Unknown') as userName,
            t.username,
            COUNT(DISTINCT CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.id END) as posts,
            COUNT(DISTINCT CASE WHEN DATE(csc.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN csc.id END) as comments,
            COUNT(DISTINCT CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.id END) as stories,
            (SELECT COUNT(*) 
             FROM community_story_reaction csr 
             INNER JOIN community_story cs_inner ON csr.storyId = cs_inner.id 
             WHERE cs_inner.travelerID = t.travelerID) as storyLikes,
            (SELECT COUNT(*) 
             FROM community_story_comment cscom 
             INNER JOIN community_story cs_inner ON cscom.storyId = cs_inner.id 
             WHERE cs_inner.travelerID = t.travelerID) as storyComments,
            (SELECT COUNT(*) 
             FROM community_story_save css 
             INNER JOIN community_story cs_inner ON css.storyId = cs_inner.id 
             WHERE cs_inner.travelerID = t.travelerID) as storySaves,
            (COUNT(DISTINCT CASE WHEN DATE(cs.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN cs.id END) + 
             COUNT(DISTINCT CASE WHEN DATE(csc.createdAt) BETWEEN '{$startDate}' AND '{$endDate}' THEN csc.id END)) as totalActivity
        FROM Traveler t
        LEFT JOIN community_story cs ON t.travelerID = cs.travelerID
        LEFT JOIN community_story_comment csc ON t.travelerID = csc.travelerId
        GROUP BY t.travelerID
        HAVING totalActivity > 0
        ORDER BY totalActivity DESC, storyLikes DESC
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
    $operatorRankings = array_map(function($row) use ($pdo) {
        // Get listing names for this operator
        $listings = [];
        try {
            $listingsQuery = $pdo->prepare("
                SELECT businessName 
                FROM BusinessListing 
                WHERE operatorID = ? AND status = 'Approved'
                ORDER BY businessName
            ");
            $listingsQuery->execute([(int)$row['operatorID']]);
            $listings = $listingsQuery->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log("Failed to fetch listings for operator {$row['operatorID']}: " . $e->getMessage());
        }
        
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
            'totalSaves' => (int)$row['totalSaves'],
            'listings' => $listings
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
        'totalListingReviews' => safeQuery($pdo, "SELECT COUNT(*) FROM ListingReview"),
        'totalCommunityReviews' => safeQuery($pdo, "SELECT COUNT(*) FROM community_story_comment"),
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
            'confirmedBookings' => $confirmedBookings,
            'chatbotUsage' => $chatbotUsage,
            'chatbotConversations' => $chatbotConversations
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

