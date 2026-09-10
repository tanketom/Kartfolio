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

// Get all GPs in order
$gpStmt = $pdo->prepare("
    SELECT DISTINCT gpid, MIN(race_date) as race_date,
           (SELECT cup_name FROM results r2 WHERE r2.gpid = r1.gpid LIMIT 1) as cup_name
    FROM results r1
    WHERE gpid LIKE ? AND gpid LIKE 's%'
    GROUP BY gpid
    ORDER BY gpid ASC
");
$gpStmt->execute([$seasonId . '%']);
$allGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all racers who participated in this season
$racerStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
    ORDER BY r.name
");
$racerStmt->execute([$seasonId . '%']);
$racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

// Get character portrait per racer (most used in season)
$racerChars = [];
foreach ($racers as $r) {
    $charStmt = $pdo->prepare("
        SELECT character_used, COUNT(*) as c
        FROM results
        WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
        GROUP BY character_used ORDER BY c DESC LIMIT 1
    ");
    $charStmt->execute([$r['id'], $seasonId . '%']);
    $racerChars[$r['id']] = $charStmt->fetchColumn() ?: 'Mii';
}

// Pre-fetch ALL results for performance (avoid N*M queries)
$allResultsStmt = $pdo->prepare("
    SELECT gpid, racer_id, gp_points, race_date, cup_name, rank, id
    FROM results
    WHERE gpid LIKE ? AND gpid LIKE 's%'
    ORDER BY gpid ASC
");
$allResultsStmt->execute([$seasonId . '%']);
$allResults = $allResultsStmt->fetchAll(PDO::FETCH_ASSOC);

// Index results by racer => [gpid => {points, date, cup}]
$racerResults = [];
foreach ($allResults as $row) {
    $racerResults[$row['racer_id']][$row['gpid']] = $row;
}

// Build frames: for each GP checkpoint, calculate GPScore for every racer
// using only results up to and including that GP
$frames = [];
$gpsSoFar = [];

foreach ($allGPs as $gpIdx => $gp) {
    $gpsSoFar[] = $gp['gpid'];
    $gpIdSet = $gpsSoFar; // GPs up to this point

    $scores = [];
    foreach ($racers as $r) {
        $rid = $r['id'];

        // Collect this racer's results up to this GP
        $racerPointsSoFar = [];
        $racerDatesSoFar = [];
        $racerCupsSoFar = [];
        $racerRowsSoFar = [];

        foreach ($gpIdSet as $gpid) {
            if (isset($racerResults[$rid][$gpid])) {
                $res = $racerResults[$rid][$gpid];
                $racerPointsSoFar[] = (int)$res['gp_points'];
                $racerDatesSoFar[] = $res['race_date'];
                $racerRowsSoFar[] = $res;
                $racerCupsSoFar[$res['cup_name']] = max(
                    $racerCupsSoFar[$res['cup_name']] ?? 0,
                    (int)$res['gp_points']
                );
            }
        }

        $totalRaces = count($racerPointsSoFar);
        if ($totalRaces === 0) continue;

        // Calculate score based on scoring system using data up to this GP
        $score = 0;
        $provisional = false;

        switch ($scoringSystem) {
            case 'positional_points':
            case 'median':
            case 'form':
            case 'preseason':
                // Exact replays from the racer's own rows (gp_logic).
                $score = progressiveScoreFromRows($scoringSystem, $racerRowsSoFar, $rules);
                break;

            case 'top_12_unique':
                $cupBests = array_values($racerCupsSoFar);
                rsort($cupBests);
                $top12 = array_slice($cupBests, 0, 12);
                $score = array_sum($top12);
                break;

            case 'average_attendance':
            default:
                // Any other system lands here as an APPROXIMATION — the
                // payload carries 'approximate' so the page can say so.
                // Below the qualifying threshold the racer is PROVISIONAL:
                // shown greyed with their running average instead of being
                // hidden (score 0) and then popping in at full value.
                $threshold   = (int)($rules['min_races_threshold'] ?? 3);
                $provisional = ($threshold > 0 && $totalRaces < $threshold);
                $score = aaFromRows($racerRowsSoFar, $rules)['score'];
                break;
        }

        $scores[] = [
            'id'    => $rid,
            'name'  => $r['name'],
            'score' => $score,
            'char'  => $racerChars[$rid] ?? 'Mii',
            'gps'         => $totalRaces,
            'provisional' => $provisional,
        ];
    }

    // Sort by score descending
    // Qualified racers first (score desc), then provisional ones; ties by
    // name so two level racers stop swapping between frames.
    usort($scores, fn($a, $b) => ((int)$a['provisional'] <=> (int)$b['provisional']) ?: ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));

    $frames[] = [
        'gpid'     => $gp['gpid'],
        'gpNum'    => $gpIdx + 1,
        'cup'      => $gp['cup_name'],
        'date'     => $gp['race_date'],
        'scores'   => $scores
    ];
}

// Season info
$seasonName = $rules['season_name'] ?? 'Season ' . strtoupper($seasonId);

echo json_encode([
    'season'       => $seasonId,
    'seasonName'   => $seasonName,
    'scoringSystem' => $scoringSystem,
    'systemName'   => getScoringSystemDef($scoringSystem)['name'] ?? $scoringSystem,
    'approximate'  => !in_array($scoringSystem, ['average_attendance', 'preseason', 'top_12_unique', 'positional_points', 'median', 'form'], true),
    'totalGPs'     => count($allGPs),
    'threshold'    => (int)($rules['min_races_threshold'] ?? 3),
    'rosterSize'   => count($racers),
    'frames'       => $frames
], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
