<?php
/**
 * Season Report Generator & Archiver
 * Path: /cdnmk/public_html/api/generate_season_report.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_admin();

@set_time_limit(300);
ignore_user_abort(true);

$config = require __DIR__ . '/../../private/config/config.php';
$apiKey = $config['gemini_api_key'] ?? '';
// gemini-1.5-flash has been retired from v1beta; default to the current
// flash so a missing config key doesn't always 404 on the first attempt.
$model  = $config['model_name'] ?? 'gemini-2.5-flash';

$seasonId = $_GET['season'] ?? null;
if (!$seasonId) {
    header("Location: ../admin/seasons.php");
    exit;
}

// 1. Fetch Season Rules
$rules = getSeasonRules($pdo, $seasonId);

// 2. Identify the Champion (Ranking logic)
$racerStmt = $pdo->prepare("SELECT DISTINCT r.id, r.name FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
$racerStmt->execute([$seasonId . "%"]);
$racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

$standings = [];
foreach ($racers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);
    if ($score > 0) {
        $standings[] = ['id' => $r['id'], 'name' => $r['name'], 'score' => $score];
    }
}
$currentScoringSystem = $rules['scoring_system'] ?? 'average_attendance';
if ($currentScoringSystem === 'top_12_unique') {
    foreach ($standings as &$s) {
        $s['tiebreaker'] = getTop12UniqueTiebreaker($pdo, $s['id'], $seasonId);
    }
    unset($s);
    usort($standings, function($a, $b) {
        if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
} else {
    usort($standings, fn($a, $b) => $b['score'] <=> $a['score']);
}

$winner = $standings[0] ?? null;
$winnerName = $winner['name'] ?? 'The Ghost';
$winnerChar = 'Mii';

if ($winner) {
    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$winner['id'], $seasonId . "%"]);
    $winnerChar = $charStmt->fetchColumn() ?: 'Mii';
}

// 3. Prepare AI Prompt
$standingsText = "";
foreach ($standings as $i => $s) {
    $rank = $i + 1;
    $standingsText .= "#$rank: {$s['name']} ({$s['score']} pts)\n";
}

$scoringInfo = getScoringSystemInfo($pdo, $seasonId);
$prompt = "Act as a Mushroom Kingdom sports historian. Write an archival report for Season $seasonId.
The champion is $winnerName.

This season was scored under {$scoringInfo['name']} {$scoringInfo['icon']} — {$scoringInfo['long_description']}
Read the final standings through THIS system (the 'pts' below are its scores); don't assume Average + Attendance / GPScore™ unless that's the named system.

Final Standings:
$standingsText
Tone: Professional, nostalgic, slightly eccentric.";

// 4. Gemini API Call (shared client: retry + model fallback)
$payload = ["contents" => [["parts" => [["text" => $prompt]]]]];
[$response, $httpCode, $lastError, $modelUsed] =
    callGeminiWithRetry(geminiDefaultModelChain($model), $apiKey, $payload);

if ($response === null) {
    // Don't block the archive on AI failure — log a placeholder and move on.
    $aiReport = "Historical report unavailable (Gemini error: " . $lastError . ").";
} else {
    $result   = json_decode($response, true);
    $aiReport = $result['candidates'][0]['content']['parts'][0]['text']
        ?? "Historical report pending archive retrieval.";
}

// 5. Update Metadata with Final Snapshot
$update = $pdo->prepare("
    UPDATE season_meta 
    SET ecology_report = ?, 
        status = 'archived', 
        closed_at = CURRENT_TIMESTAMP,
        champion_name = ?,
        champion_char = ?
    WHERE season_id = ?
");
$update->execute([$aiReport, $winnerName, $winnerChar, $seasonId]);

$_SESSION['success'] = "Season archived! Long live Champion $winnerName!";
header("Location: ../season_archives.php");
exit;