<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Origin');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Enhanced chatbot logging with conversation history and metrics
function logChatbotUsage($pdo, $travelerId = null, $intent = '', $responseTime = 0, $success = true, $errorType = null) {
    if ($pdo === null) {
        error_log('ChatbotLog: PDO is null, cannot log usage');
        return;
    }
    
    try {
        // Create enhanced ChatbotLog table
        $pdo->exec("CREATE TABLE IF NOT EXISTS ChatbotLog (
            id INT AUTO_INCREMENT PRIMARY KEY,
            travelerID INT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            intent VARCHAR(100) NULL,
            responseTime DECIMAL(6,3) NULL,
            success BOOLEAN DEFAULT TRUE,
            errorType VARCHAR(100) NULL,
            INDEX idx_timestamp (timestamp),
            INDEX idx_intent (intent)
        )");
        
        // Create conversation history table
        $pdo->exec("CREATE TABLE IF NOT EXISTS ChatbotConversation (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sessionID VARCHAR(100) NOT NULL,
            travelerID INT NULL,
            role ENUM('user', 'assistant') NOT NULL,
            message TEXT NOT NULL,
            actions JSON NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (sessionID),
            INDEX idx_traveler (travelerID)
        )");
        
        // Insert usage log with metrics
        $stmt = $pdo->prepare("INSERT INTO ChatbotLog (travelerID, timestamp, intent, responseTime, success, errorType) VALUES (?, NOW(), ?, ?, ?, ?)");
        $result = $stmt->execute([$travelerId, $intent, $responseTime, $success, $errorType]);
        
        if (!$result) {
            error_log('ChatbotLog insert failed: ' . json_encode($stmt->errorInfo()));
        } else {
            error_log('ChatbotLog inserted successfully - TravelerID: ' . ($travelerId ?? 'null') . ', Intent: ' . $intent);
        }
    } catch (Exception $e) {
        error_log('Chatbot logging failed: ' . $e->getMessage());
    }
}

function saveConversationMessage($pdo, $sessionId, $travelerId, $role, $message, $actions = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO ChatbotConversation (sessionID, travelerID, role, message, actions, timestamp) VALUES (?, ?, ?, ?, ?, NOW())");
        $actionsJson = $actions ? json_encode($actions) : null;
        $stmt->execute([$sessionId, $travelerId, $role, $message, $actionsJson]);
    } catch (Exception $e) {
        error_log('Conversation save failed: ' . $e->getMessage());
    }
}

try {
    // Connect to database for logging
    $pdo = null;
    try {
        $pdo = require __DIR__ . '/../../config/db.php';
        if ($pdo === null) {
            error_log('CHATBOT: Database connection returned null');
        } else {
            error_log('CHATBOT: Database connected successfully');
        }
    } catch (Exception $e) {
        error_log('DB connection failed for chatbot logging: ' . $e->getMessage());
    }
    
    // Get user message
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON payload');
    }

    $userMessage = trim((string)($input['message'] ?? ''));
    if ($userMessage === '') {
        throw new Exception('Message is required');
    }
    
    // Start performance tracking
    $startTime = microtime(true);
    $travelerId = isset($input['travelerId']) ? (int)$input['travelerId'] : null;
    $sessionId = trim((string)($input['sessionId'] ?? ''));
    if ($sessionId === '') {
        $sessionId = 'session_' . uniqid();
    }
    
    // Save user message to conversation history
    if ($pdo !== null) {
        saveConversationMessage($pdo, $sessionId, $travelerId, 'user', $userMessage);
    }

    $persona = normalisePersona($input['persona'] ?? null);
    
    // Detect query intent for analytics
    $intent = detectQueryIntent($userMessage, $persona['role']);
    
    $moduleActions = determineModuleActions($userMessage, $persona['role']);

    // Check for itinerary planning request FIRST (before FAQ)
    $itineraryIntent = detectItineraryIntent($userMessage);
    if ($itineraryIntent !== null && $persona['role'] === 'traveler') {
        $reply = "I can help plan your trip! To create a personalized itinerary, I'll need a few details. Would you like to start the trip planner?";
        $actions = [
            [
                'type' => 'module',
                'module' => 'trips',
                'label' => 'Open Trip Planner',
                'description' => 'Create a detailed AI-powered itinerary',
                'view' => 'traveler',
            ],
        ];
        
        $responseTime = microtime(true) - $startTime;
        if ($pdo !== null) {
            logChatbotUsage($pdo, $travelerId, 'itinerary_planning', $responseTime, true);
            saveConversationMessage($pdo, $sessionId, $travelerId, 'assistant', $reply, $actions);
        }
        
        echo json_encode(['ok' => true, 'reply' => $reply, 'actions' => $actions, 'sessionId' => $sessionId]);
        exit;
    }

    // Enhanced FAQ system - check for common questions
    $faqReply = tryFAQReply($userMessage, $persona, $pdo, $travelerId);
    if ($faqReply !== null) {
        $faqReply['actions'] = $faqReply['actions'] ?? $moduleActions;
        $responseTime = microtime(true) - $startTime;
        
        $loggingStatus = 'not attempted';
        if ($pdo !== null) {
            $loggingStatus = 'pdo available';
            logChatbotUsage($pdo, $travelerId, $intent, $responseTime, true);
            saveConversationMessage($pdo, $sessionId, $travelerId, 'assistant', $faqReply['reply'], $faqReply['actions']);
            $loggingStatus = 'logged';
        } else {
            $loggingStatus = 'pdo is null';
        }
        
        error_log('FAQ Response - Logging: ' . $loggingStatus . ', TravelerID: ' . ($travelerId ?? 'null') . ', Intent: ' . $intent);
        
        echo json_encode(['ok' => true, 'reply' => $faqReply['reply'], 'actions' => $faqReply['actions'], 'sessionId' => $sessionId, 'debug' => ['logging' => $loggingStatus, 'pdo' => ($pdo !== null)]]);
        exit;
    }

    $quickReply = tryQuickReply($userMessage, $persona);
    if ($quickReply !== null) {
        $quickReply['actions'] = $quickReply['actions'] ?? $moduleActions;
        $responseTime = microtime(true) - $startTime;
        
        $loggingStatus = 'not attempted';
        if ($pdo !== null) {
            logChatbotUsage($pdo, $travelerId, $intent, $responseTime, true);
            saveConversationMessage($pdo, $sessionId, $travelerId, 'assistant', $quickReply['reply'], $quickReply['actions']);
            $loggingStatus = 'logged';
        } else {
            $loggingStatus = 'pdo is null';
        }
        
        echo json_encode(['ok' => true, 'reply' => $quickReply['reply'], 'actions' => $quickReply['actions'], 'sessionId' => $sessionId, 'debug' => ['logging' => $loggingStatus, 'pdo' => ($pdo !== null)]]);
        exit;
    }

    $personaTip = tryOperatorTipReply($userMessage, $persona);
    if ($personaTip !== null) {
        $personaTip['actions'] = $personaTip['actions'] ?? $moduleActions;
        $responseTime = microtime(true) - $startTime;
        
        $loggingStatus = 'not attempted';
        if ($pdo !== null) {
            logChatbotUsage($pdo, $travelerId, $intent, $responseTime, true);
            saveConversationMessage($pdo, $sessionId, $travelerId, 'assistant', $personaTip['reply'], $personaTip['actions']);
            $loggingStatus = 'logged';
        } else {
            $loggingStatus = 'pdo is null';
        }
        
        echo json_encode(['ok' => true, 'reply' => $personaTip['reply'], 'actions' => $personaTip['actions'], 'sessionId' => $sessionId, 'debug' => ['logging' => $loggingStatus, 'pdo' => ($pdo !== null)]]);
        exit;
    }
    
    // Check for marketplace search
    $marketplaceSearch = tryMarketplaceSearch($userMessage, $pdo, $persona);
    if ($marketplaceSearch !== null) {
        $responseTime = microtime(true) - $startTime;
        
        $loggingStatus = 'not attempted';
        if ($pdo !== null) {
            logChatbotUsage($pdo, $travelerId, 'marketplace_search', $responseTime, true);
            saveConversationMessage($pdo, $sessionId, $travelerId, 'assistant', $marketplaceSearch['reply'], $marketplaceSearch['actions']);
            $loggingStatus = 'logged';
        } else {
            $loggingStatus = 'pdo is null';
        }
        
        echo json_encode(['ok' => true, 'reply' => $marketplaceSearch['reply'], 'actions' => $marketplaceSearch['actions'], 'sessionId' => $sessionId, 'debug' => ['logging' => $loggingStatus, 'pdo' => ($pdo !== null)]]);
        exit;
    }

    // Fall back to Gemini API
    $apiKey = 'AIzaSyBX-rjihi94msB_QIHbvmYI6pKdJ0GYr2Q';
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

    // Build conversation context with enhanced prompting
    $historyBlock = buildHistoryBlock($input['history'] ?? []);
    $moduleGuide = buildModuleGuide($persona['role']);
    $personaContext = buildPersonaContext($persona);
    $fewShotExamples = buildFewShotExamples($persona['role']);
    
    $prompt = <<<PROMPT
You are a dedicated AI assistant for Malaysia Sustainable Travel. Your only responsibility is to guide travelers to the correct in-app modules, provide general tips, and reassure them that detailed data can be viewed inside those modules.

Important rules:
1. Never reveal internal metrics, database counts, or administrator-only information.
2. If users ask for restricted data (e.g., totals, user numbers, moderation notes), politely decline and point them to the relevant module or contact support.
3. Encourage responsible, sustainable travel practices for Malaysia.
4. Whenever possible, reference the specific module that can help and briefly describe what the user can do there.
5. Keep responses under 4 concise sentences unless the question requires detailed explanation.
6. Maintain a friendly, helpful tone - use "you can", "feel free to", "I'd be happy to help".
7. If you cannot help with a request, apologize and suggest alternatives.

{$fewShotExamples}

Suggest the relevant in-app module from the list below and mention that a shortcut button is available for the user when appropriate.

Persona context:
{$personaContext}

Available modules you can reference:
{$moduleGuide}

Conversation history:
{$historyBlock}USER: {$userMessage}
PROMPT;

    $body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array('text' => $prompt)
                )
            )
        ),
        'generationConfig' => array(
            'temperature' => 0.7,
            'maxOutputTokens' => 1024
        )
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new Exception('Failed to connect to AI service: ' . $curlError);
    }

    if ($status !== 200) {
        $data = json_decode($response, true);
        throw new Exception($data['error']['message'] ?? 'API returned error response');
    }

    $data = json_decode($response, true);
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    
    if (!$reply) {
        throw new Exception('No response from AI service');
    }
    
    // Track successful response
    $responseTime = microtime(true) - $startTime;
    if ($pdo !== null) {
        logChatbotUsage($pdo, $travelerId, $intent, $responseTime, true);
        saveConversationMessage($pdo, $sessionId, $travelerId, 'assistant', $reply, $moduleActions);
    }

    echo json_encode(['ok' => true, 'reply' => $reply, 'actions' => $moduleActions, 'sessionId' => $sessionId]);

} catch (Exception $e) {
    // Track failed response
    if (isset($pdo) && $pdo !== null && isset($travelerId) && isset($startTime)) {
        $responseTime = microtime(true) - $startTime;
        $intent = $intent ?? 'unknown';
        logChatbotUsage($pdo, $travelerId, $intent, $responseTime, false, get_class($e));
    }
    
    error_log('Chatbot error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Formats chat history into a text block for context
 */
function buildHistoryBlock(array $history): string {
    if (empty($history)) {
        return '';
    }

    $lines = [];
    foreach ($history as $entry) {
        $role = strtoupper((string)($entry['role'] ?? 'USER'));
        $text = (string)($entry['text'] ?? $entry['content'] ?? '');
        if ($text !== '') {
            $lines[] = "{$role}: {$text}";
        }
    }

    return $lines ? implode("\n", $lines) . "\n" : '';
}

function containsAny(string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function determineModuleActions(string $message, string $personaRole = 'guest'): array {
    $text = strtolower($message);
    $actions = [];
    foreach (getModuleDefinitions($personaRole) as $definition) {
        if (!empty($definition['keywords']) && containsAny($text, $definition['keywords'])) {
            $actions[] = buildModuleAction($definition);
        }
    }

    if (!$actions && strlen($text) <= 2) {
        return $actions;
    }

    if (!$actions) {
        $fallback = getModuleDefinitionByKey('dashboard', $personaRole);
        if ($fallback) {
            $actions[] = buildModuleAction($fallback);
        }
    }

    return $actions;
}

function getModuleDefinitions(string $personaRole = 'guest'): array {
    $view = resolveViewForRole($personaRole);

    $definitions = [
        [
            'key' => 'dashboard',
            'label' => 'Open dashboard overview',
            'description' => 'See eco metrics, trip planning widgets, and quick actions in one place.',
            'keywords' => ['dashboard', 'overview', 'home', 'summary', 'stats'],
            'view' => $view,
        ],
        [
            'key' => 'weather',
            'label' => 'Go to Weather outlook',
            'description' => 'Check localized forecasts, air quality, and travel advice.',
            'keywords' => ['weather', 'forecast', 'temperature', 'rain', 'climate', 'humidity'],
            'view' => $view,
        ],
        [
            'key' => 'community',
            'label' => 'View Community feed',
            'description' => 'Explore traveler stories, itineraries, and local insights.',
            'keywords' => ['community', 'feed', 'stories', 'social', 'post', 'share', 'food', 'hungry', 'eat', 'restaurant', 'recommendation', 'suggestion', 'tips','local','fun','explore'],
            'view' => $view,
        ],
        [
            'key' => 'saved-posts',
            'label' => 'Open Saved posts',
            'description' => 'Revisit the stories and guides you bookmarked for later.',
            'keywords' => ['saved', 'bookmark', 'favorites', 'liked post'],
            'view' => $view,
        ],
        [
            'key' => 'messages',
            'label' => 'Open Messages',
            'description' => 'Chat with operators or fellow travelers in one inbox.',
            'keywords' => ['message', 'chat', 'inbox', 'contact', 'operator', 'traveler', 'guide'],
            'view' => $view,
        ],
        [
            'key' => 'notifications',
            'label' => 'Open Notifications',
            'description' => 'Review platform updates, approvals, and alerts.',
            'keywords' => ['notification', 'alert', 'update', 'reminder'],
            'view' => $view,
        ],
    ];

    if ($personaRole === 'operator') {
        $definitions = array_merge(
            $definitions,
            [
                [
                    'key' => 'upload-info',
                    'label' => 'Upload business info',
                    'description' => 'Complete your company profile and submit registration details.',
                    'keywords' => ['register business', 'upload info', 'business info', 'registration form', 'start registration'],
                    'view' => $view,
                ],
                [
                    'key' => 'media-manager',
                    'label' => 'Open Media Manager',
                    'description' => 'Upload photos, menus, brochures, and other marketing assets.',
                    'keywords' => ['photo', 'media', 'image', 'menu', 'brochure', 'upload pictures'],
                    'view' => $view,
                ],
                [
                    'key' => 'manage-listings',
                    'label' => 'Manage listings',
                    'description' => 'Edit listing details, toggle visibility, and review submission status.',
                    'keywords' => ['listing', 'manage listing', 'new listing', 'approve listing', 'edit listing', 'inventory'],
                    'view' => $view,
                ],
                [
                    'key' => 'guidelines',
                    'label' => 'Read operator guidelines',
                    'description' => 'Follow onboarding checklists, QA tips, and compliance reminders.',
                    'keywords' => ['guideline', 'how to start', 'policy', 'rules', 'new operator', 'documentation', 'workflow'],
                    'view' => $view,
                ],
            ]
        );
    }

    if ($personaRole !== 'guest') {
        $definitions[] = [
            'key' => 'profile',
            'label' => 'Edit profile & personal details',
            'description' => 'Update your personal information, contact methods, and preferences securely.',
            'keywords' => [
                'profile',
                'personal',
                'details',
                'account',
                'name',
                'email',
                'contact',
                'information',
                'edit info',
            ],
            'view' => $view,
            'params' => ['editProfile' => '1'],
        ];
    }

    return $definitions;
}

function getModuleDefinitionByKey(string $key, string $personaRole = 'guest'): ?array {
    foreach (getModuleDefinitions($personaRole) as $definition) {
        if ($definition['key'] === $key) {
            return $definition;
        }
    }
    return null;
}

function buildModuleGuide(string $personaRole = 'guest'): string {
    $lines = [];
    foreach (getModuleDefinitions($personaRole) as $definition) {
        $lines[] = sprintf('- %s: %s', $definition['label'], $definition['description']);
    }
    return implode("\n", $lines);
}

function normalisePersona(mixed $raw): array {
    $role = 'guest';
    $displayName = '';

    if (is_string($raw)) {
        $role = trim(strtolower($raw)) ?: 'guest';
    } elseif (is_array($raw)) {
        $role = strtolower((string)($raw['role'] ?? $raw['type'] ?? 'guest'));
        $displayName = trim((string)($raw['displayName'] ?? $raw['name'] ?? ''));
    }

    if (!in_array($role, ['traveler', 'operator', 'admin'], strict: true)) {
        $role = 'guest';
    }

    $labels = [
        'traveler' => 'Traveler persona',
        'operator' => 'Business operator persona',
        'admin' => 'Administrator persona',
        'guest' => 'Guest visitor persona',
    ];

    $guidance = [
        'traveler' => 'Focus on eco-friendly itineraries, saved stories, weather planning, and community engagement.',
        'operator' => 'Highlight listing management tips, responding to traveler messages, and promoting responsible experiences.',
        'admin' => 'Assist with oversight tasks, moderation guidance, and pointing to notification feeds.',
        'guest' => 'Encourage sign-up, showcase key modules, and provide high-level inspiration.',
    ];

    return [
        'role' => $role,
        'label' => $labels[$role] ?? 'Guest visitor persona',
        'displayName' => $displayName,
        'guidance' => $guidance[$role] ?? '',
    ];
}

function buildPersonaContext(array $persona): string {
    $parts = [];
    if (!empty($persona['label'])) {
        $parts[] = $persona['label'];
    }
    if (!empty($persona['displayName'])) {
        $parts[] = 'Preferred name: ' . $persona['displayName'];
    }
    if (!empty($persona['guidance'])) {
        $parts[] = 'Support focus: ' . $persona['guidance'];
    }
    if (!$parts) {
        return 'Guest visitor persona. Encourage onboarding.';
    }
    return implode("\n", $parts);
}

function resolveViewForRole(string $role): string {
    return match ($role) {
        'traveler' => 'traveler',
        'operator' => 'operator',
        'admin' => 'admin',
        default => 'home',
    };
}

function tryQuickReply(string $message, array $persona): ?array {
    $text = strtolower($message);
    $contactKeywords = ['contact', 'customer service', 'support', 'helpdesk', 'phone', 'call', 'helpline'];
    if (containsAny($text, $contactKeywords)) {
        $reply = "For direct help, call our customer care line at 03-4526 8731 / 03-9631 4758 or email MST@company.com.my.";
        return [
            'reply' => $reply,
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Compose in Gmail',
                    'url' => 'https://mail.google.com/mail/u/0/?view=cm&fs=1&to=MST@company.com.my',
                ],
                [
                    'type' => 'link',
                    'label' => 'Open default mail app',
                    'url' => 'mailto:MST@company.com.my',
                ],
            ],
        ];
    }
    return null;
}

function tryOperatorTipReply(string $message, array $persona): ?array {
    if (($persona['role'] ?? '') !== 'operator') {
        return null;
    }

    $section = resolveOperatorTipSection($message);
    if (!$section) {
        return null;
    }

    $tipData = pickOperatorTip($section['id']);
    if (!$tipData) {
        return null;
    }

    $title = $tipData['title'] ?? ucfirst($section['id']);
    $tip = $tipData['tip'];
    $reply = sprintf('Operator tip — %s: %s', $title, $tip);

    $actions = [];
    if (!empty($section['module'])) {
        $definition = getModuleDefinitionByKey($section['module'], 'operator');
        if ($definition) {
            $actions[] = buildModuleAction($definition);
        }
    }

    return [
        'reply' => $reply,
        'actions' => $actions,
    ];
}

function resolveOperatorTipSection(string $message): ?array {
    $text = strtolower($message);

    $sections = [
        [
            'id' => 'start',
            'module' => 'upload-info',
            'keywords' => ['start', 'registration', 'register', 'business info', 'new operator', 'onboard'],
        ],
        [
            'id' => 'media',
            'module' => 'media-manager',
            'keywords' => ['photo', 'media', 'image', 'picture', 'gallery', 'menu', 'brochure', 'upload photos'],
        ],
        [
            'id' => 'listings',
            'module' => 'manage-listings',
            'keywords' => ['listing', 'publish', 'visible', 'approval', 'pending', 'hide listing', 'inventory'],
        ],
        [
            'id' => 'guidelines',
            'module' => 'guidelines',
            'keywords' => ['guideline', 'checklist', 'rules', 'policy', 'documentation', 'workflow', 'how to'],
        ],
        [
            'id' => 'notifications',
            'module' => 'notifications',
            'keywords' => ['notification', 'alert', 'update', 'admin message'],
        ],
    ];

    foreach ($sections as $section) {
        if (containsAny($text, $section['keywords'])) {
            return $section;
        }
    }

    return null;
}

function pickOperatorTip(string $sectionId): ?array {
    $data = getOperatorTipData();
    $sections = $data['sections'] ?? [];
    foreach ($sections as $section) {
        if (($section['id'] ?? '') === $sectionId) {
            $tips = $section['tips'] ?? [];
            if (!$tips) {
                return null;
            }
            $tip = $tips[array_rand($tips)];
            return [
                'title' => $section['title'] ?? ucfirst($sectionId),
                'tip' => $tip,
            ];
        }
    }
    return null;
}

function getOperatorTipData(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = dirname(__DIR__, 3) . '/FrontEnd/src/data/operatorPrompts.json';
    if (is_readable($path)) {
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $cache = $data;
            return $cache;
        }
    }

    $cache = [
        'sections' => [
            [
                'id' => 'start',
                'title' => 'Start registration',
                'tips' => [
                    'Confirm business ownership or authority before uploading documents.',
                    'Keep your SSM/ROC certificate and tourism license handy before starting the form.',
                    'Save progress frequently; partially completed profiles stay in Draft state.',
                ],
            ],
            [
                'id' => 'media',
                'title' => 'Media manager',
                'tips' => [
                    'Upload at least 3 photos per listing: hero shot, activity highlight, and accommodation/meal detail.',
                    'Use landscape orientation, 1920x1080 or higher, JPG/PNG under 4MB.',
                    'Add captions that highlight sustainability efforts such as eco-certifications or community impact.',
                ],
            ],
            [
                'id' => 'listings',
                'title' => 'Listing management',
                'tips' => [
                    'Double-check pricing, inclusions, and seasonal availability before toggling listings to Visible.',
                    'Pending Review indicates admin QA is in progress; you will get a notification for any required changes.',
                    'Use the visibility toggle to temporarily hide listings during maintenance without deleting them.',
                ],
            ],
            [
                'id' => 'guidelines',
                'title' => 'Operator guidelines',
                'tips' => [
                    'Follow the checklist before submitting updates to reduce back-and-forth with admins.',
                    'Share local community or conservation partnerships inside your listing description to earn the Responsible Travel badge.',
                    'Update contact details quarterly so travelers can always reach you.',
                ],
            ],
            [
                'id' => 'notifications',
                'title' => 'Notifications',
                'tips' => [
                    'Review admin notifications daily for approval results or compliance reminders.',
                    'Acknowledging a notification will mark it as read for all operator teammates.',
                ],
            ],
        ],
    ];

    return $cache;
}

function buildModuleAction(array $definition): array {
    return [
        'type' => 'module',
        'module' => $definition['key'],
        'label' => $definition['label'],
        'description' => $definition['description'] ?? '',
        'view' => $definition['view'] ?? null,
        'params' => $definition['params'] ?? [],
    ];
}

// ===== NEW HELPER FUNCTIONS =====

function detectQueryIntent(string $message, string $role): string {
    $text = strtolower($message);
    
    // Intent patterns
    if (preg_match('/\b(plan|itinerary|trip|travel)\b/i', $text)) {
        return 'itinerary_planning';
    }
    if (preg_match('/\b(hotel|accommodation|stay|lodging|resort)\b/i', $text)) {
        return 'marketplace_search';
    }
    if (preg_match('/\b(weather|forecast|climate)\b/i', $text)) {
        return 'weather_info';
    }
    if (preg_match('/\b(book|booking|reserve|payment)\b/i', $text)) {
        return 'booking_help';
    }
    if (preg_match('/\b(edit|change|update|modify)\b/i', $text)) {
        return 'edit_help';
    }
    if (preg_match('/\b(contact|support|help|call)\b/i', $text)) {
        return 'customer_support';
    }
    
    return 'general_inquiry';
}

function detectItineraryIntent(string $message): ?array {
    $text = strtolower($message);
    $patterns = [
        'plan.*trip',
        'create.*itinerary',
        'create.*trip',
        'make.*trip',
        'build.*itinerary',
        'new.*trip',
        'travel.*plan',
        '\\d+[- ]day.*trip',
        'trip.*to.*\\w+',
        'visit.*\\w+',
        'itinerary.*for',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match('/' . $pattern . '/i', $text)) {
            return ['detected' => true, 'pattern' => $pattern];
        }
    }
    
    return null;
}

function tryFAQReply(string $message, array $persona, $pdo = null, $travelerId = null): ?array {
    $text = strtolower($message);
    
    // ============================================
    // BUSINESS OPERATOR FAQs (Check FIRST - before traveler FAQs)
    // ============================================
    
    if ($persona['role'] === 'operator') {
        
        // Create/Upload listing FAQ
        if (str_contains($text, 'listing') || str_contains($text, 'business')) {
            if (containsAny($text, ['create', 'add', 'new', 'upload', 'submit', 'list', 'register', 'how do i', 'how to'])) {
                return [
                    'reply' => 'To create a new listing: 1) Go to "Upload Business Info" and fill in your business details (name, description, location, contact). 2) Navigate to "Upload Photos / Media" to add high-quality images. 3) Submit for admin verification. Check the "Operator Guidelines" for detailed requirements!',
                    'actions' => [[
                        'type' => 'module',
                        'module' => 'upload-info',
                        'label' => 'Upload Business Info',
                        'description' => 'Start creating your listing',
                        'view' => 'operator',
                    ]],
                ];
            }
        }
        
        // Manage listings FAQ
        if (containsAny($text, ['manage listing', 'edit listing', 'my listing', 'view listing', 'listing status', 'update listing'])) {
            return [
                'reply' => 'You can manage all your listings in the "Manage Listings" section. View status (pending, active, rejected), edit details, hide/unhide listings, and track verification progress.',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'manage-listings',
                    'label' => 'Manage My Listings',
                    'description' => 'View and edit your business listings',
                    'view' => 'operator',
                ]],
            ];
        }
        
        // Upload photos/media FAQ
        if (containsAny($text, ['upload photo', 'add image', 'upload media', 'add picture', 'gallery', 'upload menu', 'add brochure'])) {
            return [
                'reply' => 'Upload high-quality photos, menus, brochures, and promotional materials in the "Upload Photos / Media" section. Good visuals attract more travelers and increase bookings!',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'media-manager',
                    'label' => 'Upload Media',
                    'description' => 'Add photos and promotional materials',
                    'view' => 'operator',
                ]],
            ];
        }
        
        // Verification/approval FAQ
        if (containsAny($text, ['verification', 'approval', 'pending', 'under review', 'waiting approval', 'not approved', 'rejected'])) {
            return [
                'reply' => 'All new listings require admin verification to ensure quality and sustainability standards. Check your listing status in "Manage Listings". Pending listings are reviewed within 24-48 hours.',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'manage-listings',
                    'label' => 'Check Listing Status',
                    'description' => 'View verification progress',
                    'view' => 'operator',
                ]],
            ];
        }
        
        // Guidelines/documentation FAQ
        if (containsAny($text, ['guideline', 'how to', 'documentation', 'rules', 'policy', 'requirement', 'checklist'])) {
            return [
                'reply' => 'Check the "Operator Guidelines" for complete documentation on creating listings, photo requirements, sustainability criteria, and best practices. Follow these guidelines for faster approval!',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'guidelines',
                    'label' => 'View Guidelines',
                    'description' => 'Read operator documentation',
                    'view' => 'operator',
                ]],
            ];
        }
        
        // Dashboard/analytics FAQ
        if (containsAny($text, ['dashboard', 'analytics', 'statistics', 'views', 'performance', 'insights'])) {
            return [
                'reply' => 'Your Dashboard Overview shows key metrics: total listings, active/pending status, visitor engagement, and performance insights. Use this data to optimize your listings!',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'overview',
                    'label' => 'View Dashboard',
                    'description' => 'Check your business analytics',
                    'view' => 'operator',
                ]],
            ];
        }
        
        // Eco-certification/sustainability FAQ
        if (containsAny($text, ['eco', 'sustainable', 'certification', 'green', 'eco-friendly', 'environment'])) {
            return [
                'reply' => 'Highlight your sustainability practices! Include eco-certifications, green initiatives, and responsible tourism practices in your listing. Eco-certified businesses get featured prominence and attract conscious travelers.',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'upload-info',
                    'label' => 'Add Eco Information',
                    'description' => 'Update sustainability details',
                    'view' => 'operator',
                ]],
            ];
        }
        
        // Messages/communication FAQ
        if (containsAny($text, ['message', 'contact', 'inquiry', 'traveler message', 'communication'])) {
            return [
                'reply' => 'Check your Messages section to communicate with travelers, respond to inquiries, and manage booking requests. Quick responses improve your business rating!',
                'actions' => [[
                    'type' => 'module',
                    'module' => 'messages',
                    'label' => 'View Messages',
                    'description' => 'Check traveler inquiries',
                    'view' => 'operator',
                ]],
            ];
        }
    }
    
    // ============================================
    // TRAVELER FAQs
    // ============================================
    
    // Weather FAQ
    if (containsAny($text, ['weather', 'forecast', 'temperature', 'rain', 'climate'])) {
        return [
            'reply' => 'Check the Weather module for localized forecasts, air quality, and travel advice tailored to your destination. You can view detailed weather patterns to plan your activities better!',
            'actions' => [[
                'type' => 'module',
                'module' => 'weather',
                'label' => 'Go to Weather outlook',
                'description' => 'Check forecasts and travel advice',
                'view' => resolveViewForRole($persona['role']),
            ]],
        ];
    }
    
    // Itinerary editing FAQ
    if (containsAny($text, ['edit itinerary', 'change trip', 'modify plan', 'update itinerary', 'edit trip', 'saved trip', 'edit my trip', 'change itinerary', 'modify trip'])) {
        return [
            'reply' => 'To edit your saved itineraries, go to your Traveler Dashboard and select the "Itineraries" tab. Click on any saved trip to view details, then use the edit options to modify activities, dates, locations, or bookings.',
            'actions' => [[
                'type' => 'module',
                'module' => 'dashboard',
                'label' => 'Go to My Itineraries',
                'description' => 'View and edit your saved trips',
                'view' => 'traveler',
                'params' => ['tab' => 'itineraries'],
            ]],
        ];
    }
    
    // Booking history FAQ - check database for actual bookings (MUST come before Payment FAQ)
    if (str_contains($text, 'booking') && (str_contains($text, 'history') || str_contains($text, 'see') || str_contains($text, 'view') || str_contains($text, 'check') || str_contains($text, 'show') || str_contains($text, 'my') || str_contains($text, 'past') || str_contains($text, 'previous'))) {
        if ($persona['role'] === 'traveler') {
            // Check if user has booking history
            $hasBookings = false;
            $bookingCount = 0;
            
            if ($pdo !== null && $travelerId !== null && $travelerId > 0) {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM traveler_booking_history WHERE travelerID = ?");
                    $stmt->execute([$travelerId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $bookingCount = (int)($result['count'] ?? 0);
                    $hasBookings = $bookingCount > 0;
                } catch (Exception $e) {
                    error_log('Booking count check failed: ' . $e->getMessage());
                    error_log('TravelerID: ' . $travelerId);
                }
            } else {
                error_log('Cannot check bookings - PDO: ' . ($pdo ? 'yes' : 'no') . ', TravelerID: ' . ($travelerId ?? 'null'));
            }
            
            if ($hasBookings) {
                $reply = "You have {$bookingCount} booking" . ($bookingCount > 1 ? 's' : '') . " in your history. Click below to view your booking details, payment receipts, and trip information.";
                $buttonLabel = 'View My Bookings';
            } else {
                $reply = "You don't have any confirmed bookings yet. Browse our marketplace to discover eco-friendly accommodations and experiences, then make your first booking!";
                $buttonLabel = 'Go to Booking History';
            }
            
            return [
                'reply' => $reply,
                'actions' => [[
                    'type' => 'module',
                    'module' => 'payment-history',
                    'label' => $buttonLabel,
                    'description' => 'View your booking history and receipts',
                    'view' => 'traveler',
                ]],
            ];
        }
    }
    
    // Marketplace FAQ - direct marketplace request
    if (containsAny($text, ['marketplace', 'market place', 'browse listing', 'see listing'])) {
        return [
            'reply' => 'Browse our Marketplace to discover eco-friendly accommodations, sustainable experiences, and green travel options across Malaysia. You can filter by location, price, and eco-certifications!',
            'actions' => [[
                'type' => 'module',
                'module' => 'marketplace',
                'label' => 'Open Marketplace',
                'description' => 'Browse eco-friendly stays and activities',
                'view' => resolveViewForRole($persona['role']),
            ]],
        ];
    }
    
    // Payment FAQ
    if (containsAny($text, ['payment', 'pay', 'book', 'reserve', 'purchase'])) {
        return [
            'reply' => 'For bookings and payments, you can browse sustainable accommodations and experiences in our marketplace. Each listing shows pricing and booking options directly.',
            'actions' => [[
                'type' => 'module',
                'module' => 'marketplace',
                'label' => 'Explore Marketplace',
                'description' => 'Browse eco-friendly stays and activities',
                'view' => resolveViewForRole($persona['role']),
            ]],
        ];
    }
    
    // Community/recommendations FAQ
    if (containsAny($text, ['recommend', 'suggestion', 'where to', 'best place', 'food', 'restaurant', 'eat'])) {
        return [
            'reply' => 'Explore the Community feed for authentic traveler recommendations, local food spots, and hidden gems! You can also save posts you like for later reference.',
            'actions' => [[
                'type' => 'module',
                'module' => 'community',
                'label' => 'View Community feed',
                'description' => 'See traveler stories and local insights',
                'view' => resolveViewForRole($persona['role']),
            ]],
        ];
    }
    
    // Save posts/itineraries FAQ
    if (containsAny($text, ['save post', 'save itinerary', 'bookmark', 'saved items', 'how to save', 'favorite'])) {
        return [
            'reply' => 'You can save posts and itineraries for later! In the Community feed, click the bookmark icon on any post. Your saved items are accessible from the Saved Posts section in your dashboard.',
            'actions' => [[
                'type' => 'module',
                'module' => 'saved-posts',
                'label' => 'View Saved Posts',
                'description' => 'Access your bookmarked content',
                'view' => 'traveler',
            ]],
        ];
    }
    
    // Emergency contacts FAQ
    if (containsAny($text, ['emergency', 'help', 'police', 'hospital', 'ambulance', 'emergency number', 'emergency contact'])) {
        return [
            'reply' => 'Emergency contacts in Malaysia: Police/Ambulance/Fire: 999 | Tourism Police: 03-2149 6590 | Private Ambulance: 1-300-36-9999. For medical emergencies, find the nearest hospital or clinic.',
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Call 999 (Emergency)',
                    'url' => 'tel:999',
                ],
                [
                    'type' => 'link',
                    'label' => 'Tourism Police Hotline',
                    'url' => 'tel:+60321496590',
                ],
            ],
        ];
    }
    
    // Visa/travel requirements FAQ
    if (containsAny($text, ['visa', 'passport', 'travel requirement', 'entry requirement', 'immigration', 'visa-free', 'evisa'])) {
        return [
            'reply' => 'Visa requirements for Malaysia vary by nationality. Many countries enjoy visa-free entry for 30-90 days. Check the official Immigration Department of Malaysia website for specific requirements based on your passport.',
            'actions' => [
                [
                    'type' => 'link',
                    'label' => 'Check Visa Requirements',
                    'url' => 'https://www.imi.gov.my/index.php/en/',
                ],
                [
                    'type' => 'link',
                    'label' => 'Apply for eVisa',
                    'url' => 'https://visa.imi.gov.my/evisa/evisa.jsp',
                ],
            ],
        ];
    }
    
    // Eco-friendly travel practices FAQ
    if (containsAny($text, ['eco-friendly', 'sustainable', 'green travel', 'responsible travel', 'eco tips', 'environmental'])) {
        return [
            'reply' => 'Practice sustainable travel in Malaysia: Use public transport, choose eco-certified accommodations, reduce plastic waste, support local businesses, respect wildlife, and participate in conservation activities. Every small action counts!',
            'actions' => [[
                'type' => 'module',
                'module' => 'community',
                'label' => 'Explore Eco Experiences',
                'description' => 'Find sustainable activities and stays',
                'view' => 'traveler',
            ]],
        ];
    }
    
    // Budget planning FAQ
    if (containsAny($text, ['budget', 'cost', 'how much', 'expensive', 'cheap', 'price', 'money', 'afford'])) {
        return [
            'reply' => 'Malaysia offers great value! Budget travelers: RM100-150/day, Mid-range: RM200-400/day, Luxury: RM500+/day. Use our AI trip planner to create itineraries matching your budget, including accommodation, meals, and activities!',
            'actions' => [[
                'type' => 'module',
                'module' => 'dashboard',
                'label' => 'Plan Budget Trip',
                'description' => 'Create AI-powered itinerary with budget',
                'view' => 'traveler',
            ]],
        ];
    }
    
    return null;
}

function tryMarketplaceSearch(string $message, $pdo, array $persona): ?array {
    $text = strtolower($message);
    
    // Check for accommodation search keywords
    $accommodationKeywords = ['hotel', 'resort', 'accommodation', 'stay', 'lodging', 'room', 'hostel', 'guesthouse'];
    $locationKeywords = ['in', 'at', 'near', 'around'];
    
    $hasAccommodation = containsAny($text, $accommodationKeywords);
    $hasLocation = false;
    $location = '';
    
    // Extract location if mentioned
    foreach ($locationKeywords as $locKeyword) {
        if (strpos($text, $locKeyword) !== false) {
            $hasLocation = true;
            // Try to extract location name
            if (preg_match('/' . $locKeyword . '\\s+([\\w\\s]+?)(?:\\s|$|\\?|\\.)/i', $message, $matches)) {
                $location = trim($matches[1]);
                break;
            }
        }
    }
    
    if (!$hasAccommodation) {
        return null;
    }
    
    // Check for eco-friendly keywords
    $ecoFriendly = containsAny($text, ['eco', 'sustainable', 'green', 'responsible', 'environmental', 'eco-friendly']);
    
    // Build response
    if ($location !== '') {
        $ecoPrefix = $ecoFriendly ? 'eco-friendly ' : '';
        $reply = "Great! I can help you find {$ecoPrefix}accommodations in {$location}. Check out our marketplace for sustainable stays and experiences.";
    } else {
        $ecoPrefix = $ecoFriendly ? 'eco-friendly ' : '';
        $reply = "I can help you find {$ecoPrefix}accommodations! Browse our marketplace for sustainable stays across Malaysia.";
    }
    
    return [
        'reply' => $reply,
        'actions' => [[
            'type' => 'module',
            'module' => 'community',
            'label' => 'Browse Marketplace',
            'description' => 'Explore eco-friendly accommodations and experiences',
            'view' => resolveViewForRole($persona['role']),
        ]],
    ];
}

function buildFewShotExamples(string $role): string {
    return <<<EXAMPLES

Example interactions to guide your tone:

USER: How do I check the weather?
ASSISTANT: You can check weather forecasts in the Weather module! It shows localized forecasts, air quality, and travel advisories to help you plan your trip better.

USER: I want to plan a trip to Penang
ASSISTANT: I'd be happy to help you plan a trip to Penang! Would you like to use our AI-powered trip planner? It can create a personalized itinerary based on your interests and budget.

USER: Where can I find good food recommendations?
ASSISTANT: The Community feed is perfect for discovering authentic food spots! Travelers share their favorite restaurants, street food finds, and local dining experiences. You can also save posts for later.

USER: How many users are on the platform?
ASSISTANT: I don't have access to user statistics, but I can point you to relevant modules! If you're an admin, you can check analytics in the admin dashboard.

EXAMPLES;
}
