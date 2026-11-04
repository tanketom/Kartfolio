<?php
/**
 * Kartfolio checks — the executable half of CLAUDE.md's test checklist.
 * Run through bin/check.sh (which also lints and builds the fixture).
 *
 *   php bin/check.php /path/to/fixture.db
 *
 * Fails (exit 1) on: any PHP warning/notice/deprecation while rendering a
 * page, a page that fatals, a badge icon used twice, a registry entry missing
 * its calculate/breakdown/tooltip, a replay helper that disagrees with its
 * live calculator, a map stop count that isn't the cup count, or db.php
 * costing more than its steady-state statements per request.
 */
declare(strict_types=1);
$db = $argv[1] ?? '';
if ($db === '' || !is_file($db)) { fwrite(STDERR, "usage: php bin/check.php <fixture.db>\n"); exit(2); }
putenv("KARTFOLIO_DB=$db");
error_reporting(E_ALL);
$root = realpath(__DIR__ . '/..');
$fail = 0; $pass = 0;
$ok = function (string $what, bool $cond, string $detail = '') use (&$fail, &$pass) { if ($cond) { $pass++; echo "  ✓ $what\n"; } else { $fail++; echo "  ✗ $what" . ($detail !== '' ? " — $detail" : '') . "\n"; } };

require "$root/private/includes/db.php";
require "$root/private/includes/gp_logic.php";
require "$root/private/includes/badges.php";

// ── Roll Call ──
echo "roll call\n";
require_once __DIR__ . '/../private/includes/team_season.php';
$rcK = ['show' => 1, 'beat' => 3, 'swap' => true];
$rcRow = fn($rid, $n, $pts, $date) => ['racer_id' => $rid, 'gpid' => sprintf('s09gp%02d', $n), 'gp_points' => $pts, 'race_date' => $date];
$rc = rollCallFromRows([
    $rcRow(1, 1, 30, '2026-11-05'), $rcRow(1, 2, 50, '2026-11-06'), $rcRow(1, 3, 55, '2026-11-08'),   // veteran on 40: one week, one beat, however many GPs
    $rcRow(1, 4, 40, '2026-11-09'),                                                                    // exactly the number is not a beat, and Monday is a new week
    $rcRow(2, 5, 20, '2026-11-09'), $rcRow(2, 6, 21, '2026-11-10'), $rcRow(2, 7, 10, '2026-11-16'),    // newcomer: debut beats, then own running average
], [1 => 40.0], $rcK);
$ok('a week pays once, however many GPs', $rc['racers'][1]['by_week']['2026-11-02']['pts'] === 3 && $rc['racers'][1]['by_week']['2026-11-02']['gps'] === 3);
$ok('level with your number is not a beat', $rc['racers'][1]['by_week']['2026-11-09']['pts'] === 1);
$ok('a season score is its weeks', $rc['racers'][1]['points'] === 4 && $rc['racers'][1]['weeks'] === 2 && $rc['racers'][1]['beats'] === 1);
$ok("a newcomer's debut is a beat, then their own average", $rc['racers'][2]['by_week']['2026-11-09']['beat'] && !$rc['racers'][2]['by_week']['2026-11-16']['beat'] && abs($rc['racers'][2]['number'] - 17.0) < 0.001);
$ok('the last frame equals the score', $rc['frames']['s09gp07'][2]['score'] === 4.0 && $rc['frames']['s09gp04'][1]['score'] === 4.0);
$ok('the swap is the Monday nearest halfway', rollCallSwapDate(['start_date' => '2026-11-05', 'end_date' => '2026-12-21']) === '2026-11-30' && rollCallSwapDate(['start_date' => '2026-11-05', 'end_date' => '2026-12-27']) === '2026-11-30');
$ok('the swap can be switched off', rollCallSwapDate(['start_date' => '2026-11-05', 'end_date' => '2026-12-21', 'rc_swap' => 0]) === null);
$rcStints = [1 => [['from' => '2026-11-02', 'team' => 7, 'reason' => 'draw'], ['from' => '2026-11-09', 'team' => 8, 'reason' => 'swap']], 2 => [['from' => '2026-11-09', 'team' => 8, 'reason' => 'join']]];
$rcTotals = rollCallTeamTotals($rc, $rcStints, [7, 8]);
$ok('points stay with the team that earned them', $rcTotals[7]['score'] === 3 && $rcTotals[8]['score'] === 1 + 3 + 1);
$ok('a week before the first stint goes to the first team', rollCallTeamInWeek($rcStints[2], '2026-11-02') === 8);
$rcPick = teamSeasonPickSwap([7 => 20, 8 => 9, 9 => 12], [7 => [1 => 9, 2 => 7, 3 => 4], 8 => [4 => 9, 5 => 0], 9 => [6 => 12]], [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D', 5 => 'E', 6 => 'F']);
$ok("the swap: most-present team's second, least-present team's last", $rcPick === [2, 7, 5, 8]);
$ok('no swap between level teams', teamSeasonPickSwap([7 => 5, 8 => 5], [7 => [1 => 5], 8 => [2 => 5]], []) === null);
$rcTable = getTeamStandings($pdo, 's04');
$rcSum = 0; foreach (rollCallSeason($pdo, 's04', getSeasonRules($pdo, 's04'))['racers'] as $x) $rcSum += $x['points'];
$ok('every point in the fixture season reached a team', count($rcTable) === 3 && array_sum(array_column($rcTable, 'score')) === $rcSum, array_sum(array_column($rcTable, 'score')) . " of $rcSum");
$ok('nobody who raced is left without a team', !array_diff(array_keys(getSeasonResultsByRacer($pdo, 's04')), array_merge(...array_map(fn($t) => array_keys($t['members']), $rcTable))));

$ok('a draw can come from one season, the last three, or all', teamSeasonRefSeasons($pdo, 's04', 's01') === ['s01'] && teamSeasonRefSeasons($pdo, 's04', 'all') === ['s03', 's02', 's01'] && teamSeasonRefSeasons($pdo, 's04', 'last3') === ['s03', 's02', 's01'] && teamSeasonRefSeasons($pdo, 's03', 'last3') === ['s02', 's01'] && teamSeasonRefSeasons($pdo, 's04', 'nonsense') === []);
$ok('pots from several seasons respect the size asked for', count(array_merge(...teamSeasonPots($pdo, 's04', 'all', 3, 2))) === 6);
$rcChamps = teamSeasonChampions($pdo, 's04');
$rcPlace = seasonPlacements($pdo, 's04')['place'];
$ok('every racer on the top team who raced is a champion', $rcChamps && count($rcChamps) === count(array_intersect_key($rcTable[0]['members'], getSeasonResultsByRacer($pdo, 's04'))));
$ok('the champions finish joint first, and the next racer after them', !array_diff_key($rcChamps, array_filter($rcPlace, fn($p) => $p === 1)) && count(array_keys($rcPlace, 1, true)) === count($rcChamps) && (count($rcPlace) === count($rcChamps) || min(array_diff($rcPlace, [1])) === count($rcChamps) + 1));
$ok('the season title holders are the racers, not the team', (seasonTitleHolders($pdo)['s04'] ?? []) === array_values($rcChamps));

// ── registry shape ──
echo "registry\n";
foreach (getScoringSystemRegistry() as $key => $def) {
    // breakdown/tooltip may be null (the dispatchers have safe fallbacks) but the keys must exist and non-null values must be callable
    $ok("$key has name/icon/calculate/breakdown/tooltip", !empty($def['name']) && !empty($def['icon']) && isset($def['calculate']) && is_callable($def['calculate']) && array_key_exists('breakdown', $def) && array_key_exists('tooltip', $def) && ($def['breakdown'] === null || is_callable($def['breakdown'])) && ($def['tooltip'] === null || is_callable($def['tooltip'])));
    if (!empty($def['tiebreak'])) $ok("$key tiebreak has metric/level/ahead/both", is_callable($def['tiebreak']['metric'] ?? null) && isset($def['tiebreak']['level'], $def['tiebreak']['ahead'], $def['tiebreak']['both']));
}

// ── badges ──
echo "badges\n";
$cat = badgeCatalog();
$icons = array_column($cat, 'icon');
$ok('every badge icon is unique (' . count($cat) . ' badges)', count($icons) === count(array_unique($icons)), implode(' ', array_keys(array_filter(array_count_values($icons), fn($n) => $n > 1))));
$ok('every catalogue category is in the category order', !array_diff(array_unique(array_column($cat, 'category')), badgeCategoryOrder()));

// /wrapped: light text on an aura's tint must stay readable, whatever colours
// an aura uses. Raw auras painted behind the text gave white on yellow at 1.5:1.
require_once "$root/private/includes/wrapped_personas.php";
$wrText = ['#ffffff', '#FFD700', '#bba6ff', '#e8e3ff', '#c4bbe6', '#b9aee0', '#cbb9ff', '#ffe9a8', '#4fd67a', '#ff9c9c'];
$wrWorst = 99.0; $wrAt = '';
foreach (wrappedAuras() as $a) foreach ($a['grad'] as $c) foreach ($wrText as $t) {
    $cr = (wrappedLuminance($t) + 0.05) / (wrappedLuminance(wrappedTint($c)) + 0.05);
    if ($cr < $wrWorst) { $wrWorst = $cr; $wrAt = "{$a['label']} $c with $t"; }
}
$ok('every /wrapped aura tint keeps slide text at 4.5:1 (worst ' . round($wrWorst, 2) . ')', $wrWorst >= 4.5, $wrAt);
$emitted = [];
foreach (['s01', 's02'] as $s) foreach (array_keys(getSeasonResultsByRacer($pdo, $s)) as $rid) foreach (getRacerBadges($pdo, (int)$rid, $s) as $b) $emitted[$b['title']] = true;
$ok('badge emitters produce catalogue titles only', !array_diff(array_keys($emitted), array_column($cat, 'title')), implode(', ', array_diff(array_keys($emitted), array_column($cat, 'title'))));

// ── replay helpers agree with live calculators ──
echo "scoring\n";
$bad = [];
foreach (['s01', 's02'] as $s) { $rules = getSeasonRules($pdo, $s); foreach (array_keys(getSeasonResultsByRacer($pdo, $s)) as $rid) { $rows = getRacerSeasonRows($pdo, (int)$rid, $s);
    foreach ([['average_attendance', 'calculateAverageAttendanceScore'], ['preseason', 'calculatePreSeasonScore'], ['positional_points', 'calculatePositionalScore'], ['median', 'calculateMedianScore'], ['form', 'calculateFormScore'],
              ['best_n_gps', 'calculateBestNGPsScore'], ['cup_based', 'calculateCupBasedScore'], ['drop_worst', 'calculateDropWorstScore'], ['perfect_hunt', 'calculatePerfectHuntScore'], ['top_12_unique', 'calculateTop12UniqueScore'],
              ['black_box', 'calculateBlackBoxScore'], ['random_cup_draw', 'calculateRandomCupDrawScore']] as [$sys, $fn]) {
        $live = $fn($pdo, (int)$rid, $s, $rules); if ($sys === 'average_attendance' && $live === 0) continue;   // threshold gate lives in the calculator
        $rep = progressiveRacerScore($pdo, $sys, $s, (array)$rules, (int)$rid, $rows);
        if (abs((float)$rep - (float)$live) > 1e-9) $bad[] = "$s/$rid/$sys $rep≠$live";
    } } }
$ok('progressiveRacerScore == live calculators (12 per-racer replays)', !$bad, implode(' ', array_slice($bad, 0, 5)));
// every system's calculator returns a number for every racer (the weird ones included)
$bad = [];
foreach (getScoringSystemRegistry() as $key => $def) foreach (array_keys(getSeasonResultsByRacer($pdo, 's02')) as $rid) { $v = ($def['calculate'])($pdo, (int)$rid, 's02', getSeasonRules($pdo, 's02')); if (!is_numeric($v)) $bad[] = "$key/$rid"; }
$ok('every registry calculate() returns a number on s02 (' . count(getScoringSystemRegistry()) . ' systems)', !$bad, implode(' ', array_slice($bad, 0, 5)));
foreach (['s01', 's02'] as $s) { $sp = seasonPlacements($pdo, $s); $ok("seasonPlacements($s) ranks the qualifiers 1..n", array_values($sp['place']) === range(1, $sp['field'])); }
$t = territorySeason($pdo, 's02');
$ok('territorySeason: every held cup has a holder who is a racer', !array_diff(array_column($t['hold'], 'racer_id'), array_map('intval', $pdo->query("SELECT id FROM racers")->fetchAll(PDO::FETCH_COLUMN))));
$ok('territory events only claim/beat/tie/decay', !array_diff(array_unique(array_column($t['events'], 'type')), ['claim', 'beat', 'tie', 'decay']));
$payload = territoryMapPayload($pdo, 's02');
$ok('territoryMapPayload has one entry per cup (' . count(getMKAllCups()) . ')', count($payload['cups']) === count(getMKAllCups()));

// ── db.php per-request cost ──
echo "database\n";
$dbSrc = file_get_contents("$root/private/includes/db.php");
$counting = 'class CPDO extends PDO { public $n=0; #[\ReturnTypeWillChange] function prepare($q,$o=[]){$this->n++;return parent::prepare($q,$o);} #[\ReturnTypeWillChange] function query($q,...$a){$this->n++;return parent::query($q,...$a);} #[\ReturnTypeWillChange] function exec($q){$this->n++;return parent::exec($q);} }';
$src = str_replace(['new PDO(', '__DIR__', '__FILE__'], ['new CPDO(', var_export("$root/private/includes", true), var_export("$root/private/includes/db.php", true)], $dbSrc);
$probe = "<?php $counting ?>$src<?php echo \$pdo->n;";
$tmp = tempnam(sys_get_temp_dir(), 'dbprobe'); file_put_contents($tmp, $probe);
$n = (int)trim((string)shell_exec('KARTFOLIO_DB=' . escapeshellarg($db) . ' php ' . escapeshellarg($tmp) . ' 2>/dev/null')); unlink($tmp);
$ok("db.php steady state is ≤ 6 statements per request (got $n)", $n > 0 && $n <= 6);

// ── render every public page, warnings are failures ──
echo "pages\n";
$pages = [];
foreach (glob("$root/public_html/*.php") as $f) $pages[basename($f, '.php')] = [];
unset($pages['login'], $pages['logout']);
$variants = ['teams' => ['', 'season=s04'], 'index' => ['', 'season=s01', 'season=s02', 'season=s03', 'season=s04'], 'racer' => ['id=1', 'id=2'], 'scoring' => ['season=s01', 'season=s02', 'season=s03', 'season=s04'], 'stats' => ['', 'season=s01', 'season=s02', 'season=all'], 'timeline_gp' => ['gp=s02gp01'], 'cup_detail' => ['cup=mushroom'], 'view_season_report' => ['season=s01', 'season=s02'], 'animate_season' => ['season=s02'], 'wrapped' => ['racer=1'], 'badges_overview' => ['season=s02'], 'season_chart' => ['season=s02'], 'mh_dashboard' => ['season=s02'], 'rank_graphic' => ['season=s02'], 'yearbook' => ['', 'season=s01', 'season=s02'], 'cup_mastery' => ['season=s02'], 'territory' => ['', 'season=s01', 'season=s02', 'season=zz'], 'predictions' => [''], 'season' => null];
$racerIds = $pdo->query("SELECT id FROM racers ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$renderer = tempnam(sys_get_temp_dir(), 'render'); file_put_contents($renderer, '<?php
// Renders one page from the CLI. A page may exit() (a redirect, a "not found"),
// so the verdict is delivered from a shutdown function. No session: pages that
// need an admin redirect and exit, which counts as rendering cleanly.
error_reporting(E_ALL); ini_set("display_errors", "1");
[$_, $file, $qs] = $argv; parse_str($qs, $_GET);
$_SERVER["REQUEST_URI"] = "/" . basename($file, ".php") . ($qs !== "" ? "?$qs" : ""); $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["SERVER_NAME"] = "localhost"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
ob_start();
register_shutdown_function(function () {
    $o = (string)ob_get_clean();
    $e = error_get_last();
    if ($e && in_array($e["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { echo "PROBLEM fatal: " . $e["message"] . " @" . basename($e["file"]) . ":" . $e["line"]; return; }
    if (preg_match_all("/(?:Fatal error|Parse error|Warning|Notice|Deprecated): [^\\n]{0,180}/", $o, $m)) { echo "PROBLEM " . implode(" | ", array_slice(array_unique($m[0]), 0, 3)); return; }
    echo "ok";
});
require $file;');
foreach ($pages as $name => $_) {
    $qsList = $variants[$name] ?? [''];
    foreach ($qsList as $qs) {
        $qs = str_replace('id=1', 'id=' . ($racerIds[0] ?? 1), str_replace('id=2', 'id=' . ($racerIds[1] ?? 1), $qs));
        $out = trim((string)shell_exec('cd ' . escapeshellarg("$root/public_html") . ' && KARTFOLIO_DB=' . escapeshellarg($db) . ' php ' . escapeshellarg($renderer) . ' ' . escapeshellarg("$root/public_html/$name.php") . ' ' . escapeshellarg($qs) . ' 2>&1 | tail -1'));
        $ok("$name.php" . ($qs !== '' ? "?$qs" : ''), $out === 'ok', $out);
    }
}
unlink($renderer);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
