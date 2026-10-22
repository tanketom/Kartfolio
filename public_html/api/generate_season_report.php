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

// 2. The final table — leaderboardRows(), the same builder the homepage, the
//    signs and the broadcasts read: registry sort with the system's own
//    tie-break, and a rank only for racers who meet the season's threshold.
//    This used to be its own usort on calculateGPScore(): no qualifier gate,
//    no tie-break outside one system.
require_once __DIR__ . '/../../private/includes/leaderboard.php';
$rows   = leaderboardRows($pdo, $seasonId, 0, false);
$ranked = array_values(array_filter($rows, fn($r) => $r['rank'] !== null));

$winner     = $ranked[0] ?? null;
$winnerName = $winner['name'] ?? 'The Ghost';
$winnerChar = ($winner['char'] ?? '') ?: 'Mii';

// 3. The briefing. Each line carries the scoring system's own account of the
//    number (scoringTooltipFromBreakdown, §2a). It used to say "(88.0 pts)"
//    for every system and tell the writer the pts were the score, so the S05
//    report called an 88.0% Head-to-Head win rate "88 pts" and could not say
//    what share of matchups the champion won. The system's long description
//    gives its general defaults (an NPC weight of 0.25); the lines carry this
//    season's real ones (×0.2).
$scoringInfo = getScoringSystemInfo($pdo, $seasonId);
$seasonName  = trim((string)($rules['season_name'] ?? ''));
$qualDef     = getScoringSystemDef($scoringInfo['system'] ?? 'average_attendance');

$standingsText = '';
foreach ($ranked as $r) {
    $standingsText .= "#{$r['rank']} {$r['name']} — score {$r['score']} — {$r['raceCount']} GPs — {$r['tooltip']}";
    if (!empty($r['tie'])) $standingsText .= " — TIE-BREAK: {$r['tie']}";
    $standingsText .= "\n";
}
$unranked = array_values(array_filter($rows, fn($r) => $r['rank'] === null));
if ($unranked) {
    $standingsText .= "\nNot ranked (raced, but below the season's minimum): "
        . implode(', ', array_map(fn($r) => "{$r['name']} ({$r['raceCount']} GP" . ($r['raceCount'] === 1 ? '' : 's') . ")", $unranked))
        . "\n";
}
$qualLine = !empty($qualDef['qualifies_by_threshold'])
    ? "Qualification: a racer needed at least " . (int)($rules['min_races_threshold'] ?? 0) . " GPs this season to hold a position."
    : "Qualification: under this system everyone who raced holds a position.";

// The standing brief is the system prompt; the season's facts are the user
// turn (joined into one text for Gemini by newswriter.php).
$systemPrompt = "Act as a Mushroom Kingdom sports historian writing the archival report of a finished season "
              . "of a workplace Mario Kart 8 Deluxe league.\n"
              . "Tone: Professional, nostalgic, slightly eccentric.\n"
              . "Each standings line gives a racer's score, then the scoring system's own account of what that "
              . "number is. Describe every score in those terms and units: a win rate is a percentage, not points — "
              . "write 'pts' only where the account itself speaks of points. "
              . "The system description gives the system's general defaults; where a figure in the standings "
              . "differs (a weight, a threshold), the standings are this season's truth. "
              . "Quote every figure exactly as given; if you compare two figures, subtract them exactly. "
              . "Output the report only — no preamble, no notes about the briefing.";

$userPrompt = "Write the archival report for Season " . strtoupper($seasonId) . ($seasonName !== '' ? " — \"{$seasonName}\"" : '') . ".\n"
            . "The champion is $winnerName.\n\n"
            . "This season was scored under {$scoringInfo['name']} {$scoringInfo['icon']} — {$scoringInfo['long_description']}\n\n"
            . "$qualLine\n\n"
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