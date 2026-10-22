<?php
/**
 * GP-by-GP frames for the season race — the bar-chart-race view on
 * /season-chart.
 *
 * This used to be a `?data=1` branch inside animate_season.php, which served
 * both the page and its own JSON. When that page merged into /season-chart the
 * data half moved here, where API endpoints belong.
 *
 * One frame per GP checkpoint: every racer's cumulative score as it stood
 * after that GP. Systems that cannot be replayed GP by GP are flagged
 * `approximate` so the page can say so rather than implying a faithful replay.
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

header('Content-Type: application/json');
// Frames only change when a GP is added, and the page refetches on every
// season switch.
header('Cache-Control: public, max-age=120');

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();

// Fetch season rules
$rules = getSeasonRules($pdo, $seasonId);
$scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

// Every frame comes from seasonReplay() (gp_logic.php), the replay the season
// report's timeline uses too: exact for every system that can be replayed —
// Head-to-Head included, which this endpoint used to fake with a GPScore™
// average — and a plain points average, flagged `approximate`, for the rest.
// It also kept its own private copy of Top 12 Unique and ordered GPs by gpid
// as a string. Level racers sit in the order the season finished them.
$replay = seasonReplay($pdo, $seasonId);
$allGPs = $replay['gps'];

$racerStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
");
$racerStmt->execute([$seasonId . '%']);
$racerNames = array_column($racerStmt->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');

$frames = [];
foreach ($allGPs as $gpIdx => $gp) {
    $frame = $replay['frames'][$gp['gpid']] ?? [];
    $scores = [];
    foreach (seasonReplayOrder($frame, $replay['final']) as $rid) {
        $x = $frame[$rid];
        $scores[] = [
            'id'          => $rid,
            'name'        => $racerNames[$rid] ?? "Racer #$rid",
            'score'       => $x['score'],
            'char'        => getMostUsedCharacter($pdo, $rid, $seasonId) ?: 'Mii',
            'gps'         => $x['gps'],
            // Below the season's threshold: shown greyed with their running
            // score rather than hidden and then popping in at full value.
            'provisional' => !$x['qualifies'],
        ];
    }
    $frames[] = [
        'gpid'   => $gp['gpid'],
        'gpNum'  => $gpIdx + 1,
        'cup'    => $gp['cup_name'],
        'date'   => $gp['race_date'],
        'scores' => $scores,
    ];
}

// Season info
$seasonName = $rules['season_name'] ?? 'Season ' . strtoupper($seasonId);

echo json_encode([
    'season'       => $seasonId,
    'seasonName'   => $seasonName,
    'scoringSystem' => $scoringSystem,
    'systemName'   => getScoringSystemDef($scoringSystem)['name'] ?? $scoringSystem,
    'approximate'  => !$replay['exact'],
    'totalGPs'     => count($allGPs),
    'threshold'    => (int)($rules['min_races_threshold'] ?? 3),
    'rosterSize'   => count($racerNames),
    'frames'       => $frames
], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
