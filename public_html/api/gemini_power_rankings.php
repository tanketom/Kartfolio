<?php
require_once __DIR__ . '/../../private/includes/session.php';
/**
 * Gemini AI Power Rankings Commentary Generator
 * Path: /cdnmk/public_html/api/gemini_power_rankings.php
 *
 * Admin-only API endpoint that generates short AI blurbs for each racer
 * in the power rankings, then caches them in recap_archive.
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';

// auth.php calls kartfolioSessionStart() and loads config internally
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST only']); exit; }
verify_csrf();   // token arrives in the X-CSRF-Token header (see power_rankings.php)

header('Content-Type: application/json');

// Allow room for the fallback chain (up to 4 models × 3 retries with
// exponential backoff). Strictly above the per-call cap in gemini_client.
@set_time_limit(300);
ignore_user_abort(true);

// ============================================================
// 1. Configuration
// ============================================================
$config = kartfolioConfig();
$apiKey = $config['gemini_api_key'] ?? '';
$model  = $config['model_name'] ?? 'gemini-2.5-flash';

if (empty($apiKey)) {
    echo json_encode(['error' => 'Gemini API key not configured']);
    exit;
}

// ============================================================
// 2. Parse Input
// ============================================================
$input    = json_decode(file_get_contents('php://input'), true);
$rankings = $input['rankings'] ?? [];

if (empty($rankings)) {
    echo json_encode(['error' => 'No ranking data provided']);
    exit;
}

// ============================================================
// 3. Build Prompt
// ============================================================
$dataText = "";
foreach ($rankings as $r) {
    $rankPos    = $r['rank_pos'] ?? '?';
    $name       = $r['name'] ?? 'Unknown';
    $powerScore = $r['power_score'] ?? 0;
    $elo        = $r['elo'] ?? 1500;
    $formAvg    = $r['form_avg'] ?? 0;
    $consNorm   = $r['cons_norm'] ?? 50;
    $movement   = $r['movement'] ?? 0;
    $winStreak  = $r['win_streak'] ?? 0;
    $podStreak  = $r['podium_streak'] ?? 0;
    $char       = $r['char'] ?? 'Mii';

    $movementText = $movement > 0 ? "+{$movement}" : (string)$movement;

    $dataText .= "#{$rankPos} {$name} — Power: {$powerScore}, ELO: {$elo}, Form avg: {$formAvg}, Consistency: {$consNorm}, Movement: {$movementText}, Win streak: {$winStreak}, Podium streak: {$podStreak}, Main: {$char}\n";
}

$prompt = "You are a fun, slightly eccentric Mario Kart league analyst. For each racer below, write exactly ONE short sentence (max 15 words) as a power ranking blurb. Be witty, competitive, reference their stats. Use Mario Kart metaphors when possible.

Return ONLY a JSON object like {\"racer_id\": \"blurb\", ...} where racer_id is the numeric ID. No markdown, no backticks, just raw JSON.

Rankings:
{$dataText}

Racer IDs for mapping:
";

// Add ID mapping so Gemini knows which numeric IDs to use
foreach ($rankings as $r) {
    $prompt .= "{$r['name']} = ID {$r['id']}\n";
}

// ============================================================
// 4. Call Gemini API (shared client: retry + model fallback)
// ============================================================
$payload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt]
            ]
        ]
    ],
    "safetySettings" => [
        ["category" => "HARM_CATEGORY_HARASSMENT",        "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_HATE_SPEECH",       "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT",  "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT",  "threshold" => "BLOCK_ONLY_HIGH"]
    ]
];

$modelChain = geminiDefaultModelChain($model);
[$response, $httpCode, $lastError, $modelUsed] = callGeminiWithRetry($modelChain, $apiKey, $payload);

if ($response === null) {
    echo json_encode(['error' => $lastError]);
    exit;
}

// ============================================================
// 5. Parse Response
// ============================================================
$result = json_decode($response, true);
$text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($text)) {
    echo json_encode(['error' => 'Empty response from API']);
    exit;
}

// Strip markdown backticks if present
$text = preg_replace('/^```json\s*/', '', trim($text));
$text = preg_replace('/\s*```$/', '', $text);

$commentaries = json_decode($text, true);

if (!$commentaries || !is_array($commentaries)) {
    echo json_encode(['error' => 'Failed to parse AI response as JSON', 'raw' => $text]);
    exit;
}

// ============================================================
// 6. Cache in recap_archive
// ============================================================
try {
    $currentSeason = getCurrentSeasonNumber();

    $cacheStmt = $pdo->prepare("
        INSERT INTO recap_archive (season_id, recap_text, headline, program_key, created_at)
        VALUES (?, ?, ?, 'power_rankings', CURRENT_TIMESTAMP)
    ");
    $cacheStmt->execute([
        $currentSeason,
        json_encode($commentaries),
        'Power Rankings Analysis'
    ]);
} catch (Exception $e) {
    // Caching failure is non-fatal; still return the commentaries
}

// ============================================================
// 7. Return Result
// ============================================================
echo json_encode(['commentaries' => $commentaries]);
