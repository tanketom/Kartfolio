<?php
/**
 * Scoring Simulator API
 * Returns simulated standings for a given season + scoring system.
 * Path: /cdnmk/public_html/api/simulate_scoring.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Admin check (without including auth.php which has side effects)
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

$season = $_GET['season'] ?? '';
$system = $_GET['system'] ?? 'average_attendance';
$bestN = (int)($_GET['best_n'] ?? 15);
$dropWorst = (int)($_GET['drop_worst'] ?? 2);
$perfectMult = (float)($_GET['perfect_mult'] ?? 2.0);

if (empty($season)) {
    echo json_encode(['error' => 'No season specified']);
    exit;
}

// Get racers in this season
$racerStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
    ORDER BY r.name ASC
");
$racerStmt->execute([$season . '%']);
$racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($racers)) {
    echo json_encode(['error' => 'No data for this season', 'standings' => []]);
    exit;
}

// Build simulated rules array (mimics season_meta row structure)
$rules = [
    'scoring_system'       => $system,
    'attendance_weight'    => 1.0,
    'weekly_bonus_cap'     => 2,
    'min_races_threshold'  => 3,
    'drop_rate'            => 10,
    'cups_required'        => 12,
    'allow_retries'        => 1,
    'best_n_count'         => $bestN,
    'drop_worst_count'     => $dropWorst,
    'perfect_multiplier'   => $perfectMult,
    // MONSTER HUNT defaults
    'mh_slay_xp'           => (int)($_GET['mh_slay_xp']           ?? 100),
    'mh_survive_xp'        => (int)($_GET['mh_survive_xp']         ?? 20),
    'mh_party_bonus_xp'    => (int)($_GET['mh_party_bonus_xp']     ?? 50),
    'mh_monster_win_xp'    => (int)($_GET['mh_monster_win_xp']     ?? 80),
    'mh_monster_partial_xp'=> (int)($_GET['mh_monster_partial_xp'] ?? 30),
    'mh_monster_loss_xp'   => (int)($_GET['mh_monster_loss_xp']    ?? -40),
    'mh_min_gps'           => (int)($_GET['mh_min_gps']            ?? 6),
];

// Route to the correct scoring function via the shared registry.
function simulateScore($pdo, $racerId, $seasonId, $system, $rules) {
    $def = getScoringSystemDef($system);
    $fn  = $def['calculate'];
    return $fn($pdo, $racerId, $seasonId, $rules);
}

// Calculate scores
$standings = [];
foreach ($racers as $racer) {
    $score = simulateScore($pdo, $racer['id'], $season, $system, $rules);

    // Get GP count
    $gpStmt = $pdo->prepare("SELECT COUNT(DISTINCT gpid) FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $gpStmt->execute([$racer['id'], $season . '%']);
    $gpCount = (int)$gpStmt->fetchColumn();

    // Get avg and best
    $statsStmt = $pdo->prepare("SELECT AVG(gp_points) as avg_pts, MAX(gp_points) as best, MIN(gp_points) as worst FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $statsStmt->execute([$racer['id'], $season . '%']);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $entry = [
        'name'  => $racer['name'],
        'score' => round((float)$score, 2),
        'gps'   => $gpCount,
        'avg'   => round((float)$stats['avg_pts'], 1),
        'best'  => (int)$stats['best'],
        'worst' => (int)$stats['worst'],
    ];
    if ($system === 'monster_hunt') {
        $mhData = getMonsterHuntDisplayData($pdo, $racer['id'], $season, $rules);
        $entry['mh_title']    = $mhData['title'];
        $entry['mh_level']    = $mhData['level'];
        $entry['mh_total_xp'] = $mhData['total_xp'];
    }
    $standings[] = $entry;
}

// Sort by score descending
usort($standings, fn($a, $b) => $b['score'] <=> $a['score']);

// Add rank
foreach ($standings as $i => &$s) {
    $s['rank'] = $i + 1;
}
unset($s);

echo json_encode([
    'system' => $system,
    'season' => $season,
    'standings' => $standings
]);
