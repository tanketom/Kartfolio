<?php
/**
 * Coaching Report Generator — AJAX endpoint.
 *
 * Admin-only generation (Gemini costs); public read on racer.php.
 * Throttled to one fresh report per racer per 30 days unless ?force=1.
 *
 * Path: /cdnmk/public_html/api/generate_coaching_report.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';
require_once __DIR__ . '/../../private/includes/coaching_stats.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

header('Content-Type: application/json');

require_admin();
verify_csrf();

@set_time_limit(300);
ignore_user_abort(true);

$racerId = (int)($_POST['racer_id'] ?? 0);
$force   = !empty($_POST['force']);

if ($racerId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing racer_id']);
    exit;
}

// Throttle: refuse if a report was generated in the past 30 days (unless forced).
$throttleStmt = $pdo->prepare("
    SELECT id, generated_at FROM coaching_reports
    WHERE racer_id = ? AND generated_at > datetime('now', '-30 days')
    ORDER BY generated_at DESC LIMIT 1
");
$throttleStmt->execute([$racerId]);
$recent = $throttleStmt->fetch(PDO::FETCH_ASSOC);
if ($recent && !$force) {
    echo json_encode([
        'success'        => false,
        'error'          => 'Throttled — last report was ' . $recent['generated_at'] . '. Add force=1 to regenerate.',
        'last_report_id' => (int)$recent['id'],
    ]);
    exit;
}

// Gather signal.
try {
    $stats = gatherCoachingStats($pdo, $racerId);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Stats gather failed: ' . $e->getMessage()]);
    exit;
}
if (isset($stats['error'])) {
    echo json_encode(['success' => false, 'error' => $stats['error']]);
    exit;
}

// Gemini call via shared client.
$config = require __DIR__ . '/../../private/config/config.php';
$apiKey = $config['gemini_api_key'] ?? '';
if ($apiKey === '') {
    echo json_encode(['success' => false, 'error' => 'gemini_api_key missing from config.php']);
    exit;
}
$model = $config['model_name'] ?? 'gemini-2.5-flash';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$prompt     = buildCoachingPrompt($stats, $leagueName);

$payload = [
    'contents'         => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature'      => 0.7,
        // Generous ceiling so the visible response isn't squeezed by
        // thinking tokens on 2.5-series models (and so future prompt growth
        // doesn't silently truncate the output).
        'maxOutputTokens'  => 4000,
        // Disable internal "thinking" — the coaching task is rigid prose
        // generation from structured stats, no reasoning benefit. Without
        // this, gemini-2.5-flash burned most of maxOutputTokens on hidden
        // thinking and cut the visible report off mid-sentence.
        'thinkingConfig'   => ['thinkingBudget' => 0],
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
// Strip any stray markdown fence the model added despite our instructions.
$body = preg_replace('/^```[a-z]*\n?/i', '', $body);
$body = preg_replace('/\n?```\s*$/', '', $body);
$body = trim($body);

if ($body === '') {
    echo json_encode(['success' => false, 'error' => "Gemini returned empty body (model: $modelUsed)"]);
    exit;
}

// Persist (always append; history kept).
$ins = $pdo->prepare("INSERT INTO coaching_reports (racer_id, body, model_used, season_id) VALUES (?, ?, ?, ?)");
$ins->execute([$racerId, $body, $modelUsed, getCurrentSeasonNumber()]);
$reportId = (int)$pdo->lastInsertId();

echo json_encode([
    'success'      => true,
    'report_id'    => $reportId,
    'body'         => $body,
    'model_used'   => $modelUsed,
    'generated_at' => date('Y-m-d H:i:s'),
]);
