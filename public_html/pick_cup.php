<?php
/**
 * Random Cup Picker - Weighted by Season Race Count
 * Supports optional racer filtering: picks cups that selected racers haven't done yet
 * In MONSTER HUNT seasons, also returns Monster/Party role assignments.
 * Path: /cdnmk/public_html/pick_cup.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';

header('Content-Type: application/json');

$cups = getMKAllCups();

// Get current season
$currentSeason = getCurrentSeasonNumber();

// Check if this is a MONSTER HUNT season
$mhStmt = $pdo->prepare("SELECT scoring_system FROM season_meta WHERE season_id = ?");
$mhStmt->execute([$currentSeason]);
$seasonMeta = $mhStmt->fetch(PDO::FETCH_ASSOC);
$isMonsterHunt = ($seasonMeta && $seasonMeta['scoring_system'] === 'monster_hunt');

// Check for racer IDs (comma-separated)
$racerIds = [];
if (!empty($_GET['racers'])) {
    $racerIds = array_map('intval', explode(',', $_GET['racers']));
    $racerIds = array_filter($racerIds, fn($id) => $id > 0);
}

// If "list-racers" mode, return the racer list for the UI
if (isset($_GET['list-racers'])) {
    $stmt = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC");
    $allRacers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['racers' => $allRacers]);
    exit;
}

// Get race counts for each cup in the current season
$raceCounts = [];
foreach ($cups as $cup) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT gpid) as count
        FROM results
        WHERE cup_name = ? AND gpid LIKE ?
    ");
    $stmt->execute([$cup, $currentSeason . "%"]);
    $raceCounts[$cup] = (int)$stmt->fetchColumn();
}

// If racers are specified, calculate per-racer cup completion
$racerCupData = [];
$missingCounts = []; // cup => how many of the selected racers haven't done it

if (!empty($racerIds)) {
    foreach ($racerIds as $rid) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT cup_name
            FROM results
            WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
        ");
        $stmt->execute([$rid, $currentSeason . '%']);
        $racerCupData[$rid] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // For each cup, count how many selected racers are missing it
    foreach ($cups as $cup) {
        $missing = 0;
        foreach ($racerIds as $rid) {
            if (!in_array($cup, $racerCupData[$rid] ?? [])) {
                $missing++;
            }
        }
        $missingCounts[$cup] = $missing;
    }
}

// Calculate weights
$maxCount = max($raceCounts) ?: 0;
$weights = [];
$totalWeight = 0;
$racerCount = count($racerIds);

foreach ($cups as $cup) {
    // Base weight: less raced in season = higher weight
    $baseWeight = ($maxCount - $raceCounts[$cup]) + 1;

    if ($racerCount > 0) {
        $missing = $missingCounts[$cup];
        if ($missing === $racerCount) {
            // Nobody has done it — strongest boost
            $weight = $baseWeight * 20;
        } elseif ($missing > 0) {
            // Some haven't done it — moderate boost
            $weight = $baseWeight * (5 + ($missing * 5));
        } else {
            // Everyone has done it — minimal weight (still possible but unlikely)
            $weight = 1;
        }
    } else {
        $weight = $baseWeight;
    }

    $weights[$cup] = $weight;
    $totalWeight += $weight;
}

// Pick a random cup based on weights
$rand = mt_rand(1, max(1, $totalWeight));
$runningTotal = 0;
$selectedCup = $cups[0];

foreach ($cups as $cup) {
    $runningTotal += $weights[$cup];
    if ($rand <= $runningTotal) {
        $selectedCup = $cup;
        break;
    }
}

// Build MONSTER HUNT role data if applicable
$mhData = null;
if ($isMonsterHunt) {
    require_once __DIR__ . '/../private/includes/elo_engine.php';
    $eloResult = calculateAllELORatings($pdo);
    $allRatings = $eloResult['ratings']; // ['Name' => float, ...]

    // If the user selected racers, the role assignment is for THAT subset
    // (the modal otherwise dumps the entire league as adventurers and
    // pushes the action buttons off the viewport). Fall back to the full
    // roster only when no racers were picked.
    if (!empty($racerIds)) {
        $placeholders = implode(',', array_fill(0, count($racerIds), '?'));
        $participantStmt = $pdo->prepare("SELECT id, name FROM racers WHERE id IN ($placeholders) ORDER BY name ASC");
        $participantStmt->execute($racerIds);
    } else {
        $participantStmt = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC");
    }
    $participants = $participantStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build list with current Elo, sorted descending
    $eloStandings = [];
    foreach ($participants as $p) {
        $eloStandings[] = [
            'id'   => $p['id'],
            'name' => $p['name'],
            'elo'  => (int)round($allRatings[$p['name']] ?? 1000),
        ];
    }
    usort($eloStandings, fn($a, $b) => $b['elo'] <=> $a['elo']);

    if (!empty($eloStandings)) {
        // The Monster is the highest-Elo participant. Matches the post-GP
        // pickMonster() helper in gp_logic.php.
        $monster     = $eloStandings[0];
        $monsterName = $monster['name'];

        $adventurers = array_values(array_filter($eloStandings, fn($r) => $r['name'] !== $monsterName));

        // CR tier — gap between monster Elo and avg adventurer Elo
        $advEloVals = array_column($adventurers, 'elo');
        $avgAdvElo  = count($advEloVals) > 0
            ? array_sum($advEloVals) / count($advEloVals)
            : $monster['elo'];
        $eloGap = max(0, $monster['elo'] - $avgAdvElo);
        if      ($eloGap < 50)  { $crTier = 1; $crEpithet = 'the Rival'; }
        elseif  ($eloGap < 150) { $crTier = 2; $crEpithet = 'the Beast'; }
        elseif  ($eloGap < 300) { $crTier = 3; $crEpithet = 'the Fearsome One'; }
        else                    { $crTier = 4; $crEpithet = 'the Dragon'; }
        $monster['cr_tier']    = $crTier;
        $monster['cr_epithet'] = $crEpithet;

        $mhData = [
            'is_monster_hunt' => true,
            'monster'         => $monster,
            'adventurers'     => $adventurers,
        ];
    }
}

// Build response
$response = [
    'cup' => $selectedCup,
    'seasonRaceCount' => $raceCounts[$selectedCup],
    'allCups' => $cups,
    'is_monster_hunt' => $isMonsterHunt,
];

// If racers were specified, add per-racer info for the selected cup
if ($racerCount > 0) {
    $racerDetails = [];
    foreach ($racerIds as $rid) {
        // Get racer name
        $nameStmt = $pdo->prepare("SELECT name FROM racers WHERE id = ?");
        $nameStmt->execute([$rid]);
        $name = $nameStmt->fetchColumn() ?: 'Unknown';

        $hasDone = in_array($selectedCup, $racerCupData[$rid] ?? []);

        $bestScore = null;
        if ($hasDone) {
            $scoreStmt = $pdo->prepare("
                SELECT MAX(gp_points) FROM results
                WHERE racer_id = ? AND cup_name = ? AND gpid LIKE ? AND gpid LIKE 's%'
            ");
            $scoreStmt->execute([$rid, $selectedCup, $currentSeason . '%']);
            $bestScore = (int)$scoreStmt->fetchColumn();
        }

        $racerDetails[] = [
            'id' => $rid,
            'name' => $name,
            'hasDone' => $hasDone,
            'bestScore' => $bestScore
        ];
    }

    $response['racerDetails'] = $racerDetails;
    $response['missingCount'] = $missingCounts[$selectedCup];
}

// Attach MONSTER HUNT role data
if ($mhData) {
    $response['monster']     = $mhData['monster'];
    $response['adventurers'] = $mhData['adventurers'];
}

echo json_encode($response);
