<?php
require_once __DIR__ . '/../../private/includes/session.php';
/**
 * Season Report Generator & Archiver
 *
 * The archival report is written through newswriter.php — Claude first,
 * Gemini as the backup — the same writer the News desk uses.
 * Path: /cdnmk/public_html/api/generate_season_report.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/newswriter.php';

if (session_status() === PHP_SESSION_NONE) { kartfolioSessionStart(); }
require_admin();

// POST + CSRF only: this endpoint archives the season and overwrites the
// champion snapshot — a forgeable GET could archive the live season. Callers
// arrive via csrf_bridge_post() (redirects can only carry GET) or POST forms.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/seasons.php");
    exit;
}
verify_csrf();

@set_time_limit(300);
ignore_user_abort(true);

$config = kartfolioConfig();

$seasonId = $_POST['season'] ?? null;
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
$seasonName  = trim((string)($rules['season_name'] ?? ''));

// The standing brief is the system prompt; the season's facts are the user
// turn (joined into one text for Gemini by newswriter.php).
$systemPrompt = "Act as a Mushroom Kingdom sports historian writing the archival report of a finished season "
              . "of a workplace Mario Kart 8 Deluxe league.\n"
              . "Tone: Professional, nostalgic, slightly eccentric.\n"
              . "Read the final standings through the scoring system the report names (the 'pts' are its scores); "
              . "do not assume Average + Attendance / GPScore™ unless that is the named system. "
              . "Quote every figure exactly as given. Output the report only — no preamble, no notes about the briefing.";

$userPrompt = "Write the archival report for Season " . strtoupper($seasonId) . ($seasonName !== '' ? " — \"{$seasonName}\"" : '') . ".\n"
            . "The champion is $winnerName.\n\n"
            . "This season was scored under {$scoringInfo['name']} {$scoringInfo['icon']} — {$scoringInfo['long_description']}\n\n"
            . "Final Standings:\n$standingsText";

// 4. Write it — Claude first, Gemini as the backup. Never block the archive on
//    a writer failure: record a placeholder and move on.
if (!newsWriterOrder($config)) {
    $aiReport = "Historical report unavailable (no AI writer is configured — php bin/set_api_key.php).";
} else {
    $written = newsroomWrite($config, $systemPrompt, $userPrompt);
    $aiReport = $written['text'] !== null
        ? $written['text']
        : "Historical report unavailable (writer error: " . $written['error'] . ").";
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