<?php
/**
 * Commissioner's Desk digest generator — AJAX endpoint.
 *
 * Admin-only (Gemini cost + private content). Generates a short tactical
 * briefing for the current (or ?season=) season and caches it in
 * commish_digests (one row per season, overwritten on regenerate).
 *
 * Path: /cdnmk/public_html/api/generate_commish_digest.php
 * Route: POST /api/commish-digest
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';
require_once __DIR__ . '/../../private/includes/commish_digest.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

header('Content-Type: application/json');

require_admin();
verify_csrf();

@set_time_limit(300);
ignore_user_abort(true);

$seasonId = trim((string)($_POST['season_id'] ?? '')) ?: getCurrentSeasonNumber();

// Gather league signal.
try {
    $signal = gatherCommishSignal($pdo, $seasonId);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Signal gather failed: ' . $e->getMessage()]);
    exit;
}

$config = require __DIR__ . '/../../private/config/config.php';
$apiKey = $config['gemini_api_key'] ?? '';
if ($apiKey === '') {
    echo json_encode(['success' => false, 'error' => 'gemini_api_key missing from config.php']);
    exit;
}
$model      = $config['model_name'] ?? 'gemini-2.5-flash';
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$prompt     = buildCommishPrompt($signal, $leagueName);

$payload = [
    'contents'         => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature'     => 0.6,
        'maxOutputTokens' => 4000,
        // Structured prose from facts — no internal reasoning needed, and
        // thinking tokens would otherwise eat the visible budget (see CLAUDE.md §4).
        'thinkingConfig'  => ['thinkingBudget' => 0],
    ],
    'safetySettings'   => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
    ],
];

[$response, $httpCode, $lastError, $modelUsed] =
    callGeminiWithRetry(geminiDefaultModelChain($model), $apiKey, $payload);

if ($response === null) {
    echo json_encode(['success' => false, 'error' => $lastError]);
    exit;
}

$json = json_decode($response, true);
$body = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
$body = preg_replace('/^```[a-z]*\n?/i', '', $body);
$body = preg_replace('/\n?```\s*$/', '', $body);
$body = trim($body);

if ($body === '') {
    echo json_encode(['success' => false, 'error' => "Gemini returned empty body (model: {$modelUsed})"]);
    exit;
}

// Upsert — one digest per season.
$stmt = $pdo->prepare("
    INSERT INTO commish_digests (season_id, body, model_used, generated_at)
    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(season_id) DO UPDATE SET
        body = excluded.body,
        model_used = excluded.model_used,
        generated_at = excluded.generated_at
");
$stmt->execute([$seasonId, $body, $modelUsed]);

echo json_encode([
    'success'      => true,
    'season_id'    => $seasonId,
    'body'         => $body,
    'model_used'   => $modelUsed,
    'generated_at' => date('Y-m-d H:i:s'),
]);
