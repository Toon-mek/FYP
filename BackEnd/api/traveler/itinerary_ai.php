<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/api_keys.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$destination = trim((string)($payload['destination'] ?? ''));
$origin = trim((string)($payload['origin'] ?? ''));
$startDate = trim((string)($payload['startDate'] ?? ''));
$endDate = trim((string)($payload['endDate'] ?? ''));
$duration = (int)($payload['durationDays'] ?? 0);
$interests = $payload['interests'] ?? [];
$budget = isset($payload['budget']) ? (float)$payload['budget'] : 0.0;
$budgetRange = is_array($payload['budgetRange'] ?? null) ? $payload['budgetRange'] : [];
$budgetMin = isset($payload['budgetMin']) ? (float)$payload['budgetMin'] : null;
$budgetMax = isset($payload['budgetMax']) ? (float)$payload['budgetMax'] : null;
if ($budgetMin === null && isset($budgetRange[0])) {
    $budgetMin = (float)$budgetRange[0];
}
if ($budgetMax === null && isset($budgetRange[1])) {
    $budgetMax = (float)$budgetRange[1];
}
if ($budget <= 0 && $budgetMin !== null && $budgetMax !== null) {
    $budget = ($budgetMin + $budgetMax) / 2;
}
$groupSize = (int)($payload['groupSize'] ?? 0);
$travelStyles = $payload['travelStyles'] ?? [];
$accommodation = trim((string)($payload['accommodation'] ?? ''));
$travelStats = $payload['travelStats'] ?? null;
$selectedExperiences = is_array($payload['selectedExperiences'] ?? null) ? $payload['selectedExperiences'] : [];
$selectedStays = is_array($payload['selectedStays'] ?? null) ? $payload['selectedStays'] : [];

if ($destination === '' || $startDate === '' || $endDate === '') {
    http_response_code(400);
    echo json_encode(['error' => 'destination, startDate and endDate are required']);
    exit;
}

if ($duration <= 0) {
    $duration = max(1, (int)ceil((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);
}

$geminiKey = resolveApiKey('gemini', 'api_key', 'GEMINI_API_KEY');
if ($geminiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Gemini API key missing']);
    exit;
}

$prompt = buildPrompt([
    'destination' => $destination,
    'origin' => $origin,
    'startDate' => $startDate,
    'endDate' => $endDate,
    'duration' => $duration,
    'interests' => $interests,
    'travelStyles' => $travelStyles,
    'groupSize' => $groupSize,
    'budget' => $budget,
    'budgetMin' => $budgetMin,
    'budgetMax' => $budgetMax,
    'accommodation' => $accommodation,
    'travelStats' => $travelStats,
    'selectedExperiences' => $selectedExperiences,
    'selectedStays' => $selectedStays,
]);

$model = $_ENV['GEMINI_ITINERARY_MODEL'] ?? 'gemini-2.0-flash';
$endpoint = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $model);

$itinerarySchema = [
    'type' => 'object',
    'properties' => [
        'summary' => [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'tagline' => ['type' => 'string'],
                'dailyHighlights' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ],
        'days' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'day' => ['type' => 'integer'],
                    'date' => ['type' => 'string'],
                    'theme' => ['type' => 'string'],
                    'meals' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'place' => ['type' => 'string'],
                                'notes' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'segments' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'time' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'address' => ['type' => 'string'],
                                'latitude' => ['type' => 'number'],
                                'longitude' => ['type' => 'number'],
                                'category' => ['type' => 'string'],
                                'estimatedCost' => ['type' => 'number'],
                                'tips' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'required' => ['summary', 'days'],
];

$body = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => $prompt],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature' => 0.55,
        'maxOutputTokens' => 768,
        'responseMimeType' => 'text/plain',
    ],
    'tools' => [
        [
            'functionDeclarations' => [
                [
                    'name' => 'deliver_itinerary',
                    'description' => 'Return the generated itinerary in the agreed JSON schema.',
                    'parameters' => $itinerarySchema,
                ],
            ],
        ],
    ],
    'toolConfig' => [
        'functionCallingConfig' => [
            'mode' => 'ANY',
            'allowedFunctionNames' => ['deliver_itinerary'],
        ],
    ],
];

$response = callGemini($endpoint, $geminiKey, $body);

if ($response['status'] !== 200) {
    http_response_code($response['status']);
    echo json_encode(['error' => 'Gemini request failed', 'details' => $response['body']]);
    exit;
}

$structured = extractStructuredPlan($response['body']);

echo json_encode([
    'ok' => true,
    'raw' => $response['body'],
    'plan' => $structured,
]);

function buildPrompt(array $context): string
{
    $interests = $context['interests'] ?: ['culture', 'nature'];
    $styles = $context['travelStyles'] ?: ['balanced'];
    $accommodation = $context['accommodation'] ?: 'comfort';
    $budgetLine = formatBudgetLine($context);
    $lines = [
        "You are an eco-conscious Malaysian trip planner.",
        "Generate a {$context['duration']}-day itinerary for {$context['destination']} departing from {$context['origin']} between {$context['startDate']} and {$context['endDate']}.",
        "Group size: " . ($context['groupSize'] ?: 2) . ".",
        "Preferred accommodation style: {$accommodation}. Tailor recommendations to match this comfort level.",
        $budgetLine,
        "Interests: " . implode(', ', $interests) . '.',
        "Preferred travel styles: " . implode(', ', $styles) . '.',
        "Every day must include four time-ordered segments (morning activity, lunch/meal, afternoon highlight, evening experience) plus one lodging/stay segment.",
        "Encode stay ideas as segments with category set to \"lodging\" and mention the nightly rate that fits the traveler's budget.",
        "Set segment.category to values such as 'morning', 'meal', 'afternoon', 'evening', 'nightlife', or 'lodging' so the UI can label them.",
        "Each segment must include an estimatedCost in MYR and keep the combined daily spend within the traveler's stated budget range (use low-cost or free options when needed).",
        "Limit segment descriptions to a single concise sentence (16 words or fewer).",
        "Whenever practical, include approximate latitude/longitude inside segments to help map plotting.",
    ];
    if (!empty($context['travelStats'])) {
        $travelLine = formatTravelStatsLine($context['travelStats'], $context['origin'], $context['destination']);
        if ($travelLine !== '') {
            $lines[] = $travelLine;
        }
    }
    if (!empty($context['selectedExperiences'])) {
        $lines[] = 'Prioritise these traveler-approved experience picks (keep day order flexible while ensuring they appear somewhere):';
        foreach (formatExperienceSelectionsForPrompt($context['selectedExperiences']) as $experienceLine) {
            $lines[] = $experienceLine;
        }
    }
    if (!empty($context['selectedStays'])) {
        $lines[] = 'Prioritise these Booking.com stays for lodging segments when the budget allows:';
        foreach (formatStaySelectionsForPrompt($context['selectedStays']) as $stayLine) {
            $lines[] = $stayLine;
        }
    }
    $lines[] = "Call the deliver_itinerary function exactly once with the finished itinerary JSON. Do not return free-form text.";
    return implode("\n", $lines);
}

function formatBudgetLine(array $context): string
{
    $min = isset($context['budgetMin']) ? (float)$context['budgetMin'] : null;
    $max = isset($context['budgetMax']) ? (float)$context['budgetMax'] : null;
    if ($min && $max && $max > $min) {
        return sprintf(
            'Budget range (MYR): RM%s - RM%s.',
            number_format($min, 0, '.', ','),
            number_format($max, 0, '.', ',')
        );
    }
    $budget = isset($context['budget']) ? (float)$context['budget'] : 1500;
    if ($budget <= 0) {
        $budget = 1500;
    }
    return sprintf('Budget (MYR): RM%s.', number_format($budget, 0, '.', ','));
}

function formatTravelStatsLine(array $stats, string $origin, string $destination): string
{
    $distance = $stats['distanceText'] ?? '';
    $duration = $stats['durationText'] ?? '';
    if ($distance === '' && $duration === '') {
        return '';
    }
    $parts = [];
    if ($distance !== '') {
        $parts[] = $distance;
    }
    if ($duration !== '') {
        $parts[] = $duration;
    }
    $originLabel = $origin !== '' ? $origin : 'origin';
    $destinationLabel = $destination !== '' ? $destination : 'destination';
    return sprintf('Door-to-door estimate %s → %s: %s.', $originLabel, $destinationLabel, implode(' / ', $parts));
}

function formatExperienceSelectionsForPrompt(array $groups): array
{
    $lines = [];
    foreach ($groups as $group) {
        $picks = [];
        foreach ($group['picks'] ?? [] as $pick) {
            $entry = $pick['title'] ?? 'Experience';
            $subtitle = $pick['subtitle'] ?? ($pick['metadata']['address'] ?? '');
            if ($subtitle !== '') {
                $entry .= ' @ ' . $subtitle;
            }
            if (!empty($pick['priceText'])) {
                $entry .= ' (' . $pick['priceText'] . ')';
            }
            $picks[] = $entry;
        }
        if ($picks) {
            $label = $group['label'] ?? ucfirst((string)($group['theme'] ?? 'Experience'));
            $lines[] = sprintf('- %s: %s', $label, implode('; ', $picks));
        }
    }
    if (!$lines) {
        $lines[] = '- Use AI-curated experiences if no manual picks were supplied.';
    }
    return $lines;
}

function formatStaySelectionsForPrompt(array $stays): array
{
    $lines = [];
    foreach ($stays as $stay) {
        $entry = $stay['title'] ?? 'Stay';
        $subtitle = $stay['subtitle'] ?? ($stay['metadata']['address'] ?? '');
        if ($subtitle !== '') {
            $entry .= ' @ ' . $subtitle;
        }
        if (!empty($stay['priceText'])) {
            $entry .= ' (' . $stay['priceText'] . ')';
        }
        $lines[] = '- ' . $entry;
    }
    if (!$lines) {
        $lines[] = '- If no stay was selected, choose one that matches the comfort band.';
    }
    return $lines;
}

function callGemini(string $endpoint, string $apiKey, array $body): array
{
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint . '?key=' . urlencode($apiKey),
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($raw === false) {
        return ['status' => 502, 'body' => ['error' => $error ?: 'Unknown cURL error']];
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 502, 'body' => ['error' => 'Invalid JSON from Gemini', 'raw' => $raw]];
    }
    return ['status' => $status ?: 200, 'body' => $decoded];
}

function extractStructuredPlan(array $response): ?array
{
    $candidates = $response['candidates'] ?? [];
    if (!$candidates) {
        return null;
    }

    foreach ($candidates as $candidate) {
        $finishReason = $candidate['finishReason'] ?? '';
        $finishMessage = $candidate['finishMessage'] ?? '';
        if ($finishReason === 'MALFORMED_FUNCTION_CALL' && $finishMessage) {
            $attempt = repairMalformedFunctionCall($finishMessage);
            if ($attempt !== null) {
                return $attempt;
            }
        }

        $parts = $candidate['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['functionCall']['args']) && is_array($part['functionCall']['args'])) {
                return $part['functionCall']['args'];
            }
            if (isset($part['text'])) {
                $attempt = decodeJsonLenient($part['text']);
                if ($attempt !== null) {
                    return $attempt;
                }
            }
        }
    }

    return null;
}

function repairMalformedFunctionCall(string $message): ?array
{
    $summaryPos = strpos($message, 'summary=');
    $daysPos = strpos($message, ', days=');
    if ($summaryPos === false || $daysPos === false || $daysPos <= $summaryPos) {
        return null;
    }

    $summaryText = substr($message, $summaryPos + 8, $daysPos - ($summaryPos + 8));
    $daysText = substr($message, $daysPos + 7);

    $json = '{"summary":' . trim($summaryText) . ',"days":' . trim($daysText);
    return decodeJsonLenient($json);
}

function decodeJsonLenient(string $json): ?array
{
    $buffer = trim($json);
    $buffer = preg_replace('/^```json/i', '', $buffer);
    $buffer = preg_replace('/```$/', '', $buffer);
    $buffer = preg_replace("/[\r\n]+/", ' ', $buffer);

    for ($i = 0; $i < 4000 && $buffer !== ''; $i++) {
        $balanced = balanceJsonDelimiters($buffer);
        $decoded = json_decode($balanced, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        $buffer = substr($buffer, 0, -1);
    }
    return null;
}

function balanceJsonDelimiters(string $json): string
{
    $opens = substr_count($json, '[');
    $closes = substr_count($json, ']');
    if ($closes < $opens) {
        $json .= str_repeat(']', $opens - $closes);
    }
    $opens = substr_count($json, '{');
    $closes = substr_count($json, '}');
    if ($closes < $opens) {
        $json .= str_repeat('}', $opens - $closes);
    }
    return $json;
}
