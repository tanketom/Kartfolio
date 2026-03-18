<?php
/**
 * Gemini AI Power Rankings Commentary Generator
 * Path: /cdnmk/public_html/api/gemini_power_rankings.php
 *
 * Admin-only API endpoint that generates short AI blurbs for each racer
 * in the power rankings, then caches them in recap_archive.
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';

// auth.php calls session_start() and loads config internally
require_admin();

header('Content-Type: application/json');

// ============================================================
// 1. Configuration
// ============================================================
$config = require __DIR__ . '/../../private/config/config.php';
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
// 4. Call Gemini API
// ============================================================
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

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

$jsonBody = json_encode($payload);
if ($jsonBody === false) {
    $jsonBody = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonBody === false) {
        echo json_encode(['error' => 'JSON encode failed: ' . json_last_error_msg()]);
        exit;
    }
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['error' => 'cURL error: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    $errorDetails = json_decode($response, true);
    $errorMsg = $errorDetails['error']['message'] ?? 'No details available';
    echo json_encode(['error' => "API returned {$httpCode}: {$errorMsg}"]);
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
