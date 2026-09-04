<?php
/**
 * Mac's Mushroom Musings generator — AJAX endpoint.
 *
 * Admin-only generation (Gemini costs); public read via track_musings table
 * on cup_detail.php.
 *
 * Path: /cdnmk/public_html/api/generate_track_musings.php
 * Route: POST /api/generate-track-musings   (see .htaccess)
 *
 * Mac is a folksy Toad caddie persona — short (~80–100 words), warm prose
 * covering one hazard, one line/shortcut tip, and one character archetype
 * that thrives on the named track. Cached forever; regenerable on demand.
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';
require_once __DIR__ . '/../../private/includes/mk_data.php';
require_once __DIR__ . '/../../private/includes/settings.php';

header('Content-Type: application/json');

require_admin();
verify_csrf();

@set_time_limit(180);
ignore_user_abort(true);

$trackName = trim((string)($_POST['track_name'] ?? ''));
if ($trackName === '') {
    echo json_encode(['success' => false, 'error' => 'Missing track_name']);
    exit;
}

// Validate against the canonical track list — refuse arbitrary strings.
if (getMKTrackCup($trackName) === null) {
    echo json_encode(['success' => false, 'error' => "Unknown track: {$trackName}"]);
    exit;
}

$cupName = getMKTrackCup($trackName);
$era     = getMKTrackEra($trackName);

$config = kartfolioConfig();
$apiKey = $config['gemini_api_key'] ?? '';
if ($apiKey === '') {
    echo json_encode(['success' => false, 'error' => 'gemini_api_key missing from config.php']);
    exit;
}
$model = $config['model_name'] ?? 'gemini-2.5-flash';

// Build Mac's prompt.
$retroNote = $era
    ? "This is a retro track originally from the {$era} era — Mac would remember racing it in the old days."
    : "This is a native Mario Kart 8 Deluxe track — Mac saw it land for the first time on Switch.";

// Pippin (Mac's young Toad nephew running a food cart) is meant to be a rare
// cameo, not a fixture. Gate his prompt-level mention on a 1-in-4 dice roll so
// he genuinely sprinkles in across the 96 tracks instead of dominating every
// musing the way he did when listed as the default aside example. The other
// aside options below are deliberately concrete enough that the model doesn't
// fall back to a generic line — they're alternatives, not vague suggestions.
$mentionsPippin = mt_rand(1, 4) === 1;
$pippinClause   = $mentionsPippin
    ? "You may, if it fits naturally and only once, mention your young nephew Pippin (a Toad helping out around the track today). Do not force him in.\n"
    : "";

$prompt = <<<PROMPT
You are Mac, an old Toad caddie who has worked every Mario Kart cup since the
SNES era. You speak in short, plainspoken, warm paragraphs — no bullet points,
no headings, no markdown fences. You're writing a short strategy musing for
one specific track that a player is about to race.

TRACK: {$trackName}
PARENT CUP: {$cupName}
{$retroNote}

Write a single paragraph of 80–110 words. Include, woven naturally into the
prose:
  1. One hazard or section of the track to respect.
  2. One line, shortcut, or item-timing tip.
  3. One character archetype (heavy / cruiser / light) or specific character
     that tends to thrive here.

If — and only if — it fits naturally, you may drop in one short Mario-universe
aside: a remark from Lakitu signalling the start, the way the wind smells
above this track, a memory of an older version of this circuit, or a quiet
comment about a character who always seems to show up here. Pick at most one,
and skip the aside entirely if nothing fits cleanly.
{$pippinClause}
Refer to the track by name at least once. End on a friendly encouragement,
not a sales line.

Do not write "Mac says:" or any preamble. Just the musing itself.
PROMPT;

$payload = [
    'contents'         => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature'     => 0.8,
        'maxOutputTokens' => 1200,
        // Same lesson as the coaching reports: 2.5-flash eats the output
        // budget with hidden thinking unless explicitly capped.
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

// Upsert: one row per track, regenerate overwrites.
$stmt = $pdo->prepare("
    INSERT INTO track_musings (track_name, body, model_used, generated_at)
    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(track_name) DO UPDATE SET
        body = excluded.body,
        model_used = excluded.model_used,
        generated_at = excluded.generated_at
");
$stmt->execute([$trackName, $body, $modelUsed]);

echo json_encode([
    'success'      => true,
    'track_name'   => $trackName,
    'body'         => $body,
    'model_used'   => $modelUsed,
    'generated_at' => date('Y-m-d H:i:s'),
]);
