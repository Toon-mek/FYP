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

try {
    // Get user message
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON payload');
    }

    $userMessage = trim((string)($input['message'] ?? ''));
    if ($userMessage === '') {
        throw new Exception('Message is required');
    }

    $persona = normalisePersona($input['persona'] ?? null);
    $moduleActions = determineModuleActions($userMessage, $persona['role']);

    $quickReply = tryQuickReply($userMessage, $persona);
    if ($quickReply !== null) {
        $quickReply['actions'] = $quickReply['actions'] ?? $moduleActions;
        echo json_encode(['ok' => true, 'reply' => $quickReply['reply'], 'actions' => $quickReply['actions']]);
        exit;
    }

    $personaTip = tryOperatorTipReply($userMessage, $persona);
    if ($personaTip !== null) {
        $personaTip['actions'] = $personaTip['actions'] ?? $moduleActions;
        echo json_encode(['ok' => true, 'reply' => $personaTip['reply'], 'actions' => $personaTip['actions']]);
        exit;
    }

    // Fall back to Gemini API
    $apiKey = 'AIzaSyBX-rjihi94msB_QIHbvmYI6pKdJ0GYr2Q';
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

    // Build conversation context
    $historyBlock = buildHistoryBlock($input['history'] ?? []);
    $moduleGuide = buildModuleGuide($persona['role']);
    $personaContext = buildPersonaContext($persona);
    $prompt = <<<PROMPT
You are a dedicated AI assistant for Malaysia Sustainable Travel. Your only responsibility is to guide travelers to the correct in-app modules, provide general tips, and reassure them that detailed data can be viewed inside those modules.

Important rules:
1. Never reveal internal metrics, database counts, or administrator-only information.
2. If users ask for restricted data (e.g., totals, user numbers, moderation notes), politely decline and point them to the relevant module or contact support.
3. Encourage responsible, sustainable travel practices for Malaysia.
4. Whenever possible, reference the specific module that can help and briefly describe what the user can do there.
5. Keep responses under 4 concise sentences.

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

    echo json_encode(['ok' => true, 'reply' => $reply, 'actions' => $moduleActions]);

} catch (Exception $e) {
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
