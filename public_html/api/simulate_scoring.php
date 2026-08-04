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

// Start from the season's REAL saved configuration, then swap in the system
// being tried and any knob the caller explicitly set.
//
// This used to be a hardcoded block that ignored season_meta entirely, so the
// simulator scored with invented settings: attendance_weight 1.0, drop_rate 10,
// min_races_threshold 3 regardless of what the season actually used, and no
// pos_mode / bh_* / pm_* keys at all — meaning Positional Points was always
// simulated in best_n mode even for an average- or sum-mode season. Simulating
// a season under its own system therefore could not reproduce its standings.
$rules = getSeasonRules($pdo, $season);
if (empty($rules)) {
    // No season_meta row (shouldn't happen — the season has results) — fall
    // back to the engine's own defaults rather than inventing values here.
    $rules = ['min_races_threshold' => 3];
}
$rules['scoring_system'] = $system;

// Knob overrides. Each applies only to the system whose UI exposes it (see
// updateSimulator() in admin/seasons.php) — best_n_count is shared by
// best_n_gps AND positional_points, so scoping by system is what stops one
// system's box from silently rewriting the other's setting. Values are
// clamped/whitelisted here exactly as the season save handler does, so a
// hand-edited URL can't push the engine somewhere the config UI can't.
if ($system === 'best_n_gps'   && isset($_GET['best_n']))       $rules['best_n_count']       = max(1, $bestN);
if ($system === 'drop_worst'   && isset($_GET['drop_worst']))   $rules['drop_worst_count']   = max(0, $dropWorst);
if ($system === 'perfect_hunt' && isset($_GET['perfect_mult'])) $rules['perfect_multiplier'] = max(1.0, $perfectMult);

if ($system === 'positional_points') {
    if (isset($_GET['pos_mode'])) {
        $mode = $_GET['pos_mode'];
        $rules['pos_mode'] = in_array($mode, ['best_n', 'average', 'sum'], true) ? $mode : 'best_n';
    }
    if (isset($_GET['pos_best_n'])) $rules['best_n_count'] = max(1, (int)$_GET['pos_best_n']);
}

if ($system === 'bounty_hunter') {
    if (isset($_GET['bh_multiplier']))    $rules['bh_multiplier']    = max(0.1, (float)$_GET['bh_multiplier']);
    if (isset($_GET['bh_carrying_cost'])) $rules['bh_carrying_cost'] = !empty($_GET['bh_carrying_cost']) ? 1 : 0;
}

if ($system === 'pari_mutuel') {
    if (isset($_GET['pm_ante'])) $rules['pm_ante'] = max(1, (int)$_GET['pm_ante']);
    if (isset($_GET['pm_payout_preset'])) {
        $preset = $_GET['pm_payout_preset'];
        $rules['pm_payout_preset'] = in_array($preset, ['steep', 'medium', 'flat'], true) ? $preset : 'steep';
    }
}

// MONSTER HUNT knobs: only when explicitly supplied, otherwise the season's
// own values (or the scoring function's defaults) stand.
foreach ([
    'mh_slay_xp', 'mh_survive_xp', 'mh_party_bonus_xp', 'mh_monster_win_xp',
    'mh_monster_partial_xp', 'mh_monster_loss_xp', 'mh_min_gps',
] as $mhKey) {
    if (isset($_GET[$mhKey])) $rules[$mhKey] = (int)$_GET[$mhKey];
}

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
        // 'id' is what the registry's sort functions key off to look up a
        // racer's rows (count-back, unique-60s, head-to-head wins).
        'id'    => (int)$racer['id'],
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

// Order exactly like the real standings do: through the registry, so each
// system applies its own tie-breaks (Positional count-back, Top 12 unique-60s,
// Head-to-Head wins) and everything else falls back to score desc + name asc.
//
// This was a bare score-only usort. PHP 8 sorts are stable, so tied racers kept
// the input order — and the racers above are fetched ORDER BY name, which meant
// ties silently resolved alphabetically. The simulator ranked Andreas over
// Hanna on s04 (both 207) purely because "A" < "H", while the live standings
// put Hanna first on count-back (17 second places to his 6).
sortStandingsByScoring($standings, $system, $pdo, $season);

// Add rank, and drop the scratch keys the registry sorters hang off each row
// (_posCounts / _posGps / _posCounted / tiebreaker) so they don't ride along
// in the JSON — _posCounts alone is a 12-entry array per racer.
foreach ($standings as $i => &$s) {
    $s['rank'] = $i + 1;
    foreach (array_keys($s) as $k) {
        if ($k === 'tiebreaker' || (is_string($k) && str_starts_with($k, '_'))) unset($s[$k]);
    }
}
unset($s);

echo json_encode([
    'system' => $system,
    'season' => $season,
    'standings' => $standings
]);
