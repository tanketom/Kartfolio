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
$emitted = [];
foreach (['s01', 's02'] as $s) foreach (array_keys(getSeasonResultsByRacer($pdo, $s)) as $rid) foreach (getRacerBadges($pdo, (int)$rid, $s) as $b) $emitted[$b['title']] = true;
$ok('badge emitters produce catalogue titles only', !array_diff(array_keys($emitted), array_column($cat, 'title')), implode(', ', array_diff(array_keys($emitted), array_column($cat, 'title'))));

// ── replay helpers agree with live calculators ──
echo "scoring\n";
$bad = [];
foreach (['s01', 's02'] as $s) { $rules = getSeasonRules($pdo, $s); foreach (array_keys(getSeasonResultsByRacer($pdo, $s)) as $rid) { $rows = getRacerSeasonRows($pdo, (int)$rid, $s);
    foreach ([['average_attendance', 'calculateAverageAttendanceScore'], ['preseason', 'calculatePreSeasonScore'], ['positional_points', 'calculatePositionalScore'], ['median', 'calculateMedianScore'], ['form', 'calculateFormScore']] as [$sys, $fn]) {
        $live = $fn($pdo, (int)$rid, $s, $rules); if ($sys === 'average_attendance' && $live === 0) continue;   // threshold gate lives in the calculator
        $rep = progressiveScoreFromRows($sys, $rows, (array)$rules);
        if (abs((float)$rep - (float)$live) > 1e-9) $bad[] = "$s/$rid/$sys $rep≠$live";
    } } }
$ok('progressiveScoreFromRows == live calculators (AA, preseason, positional, median, form)', !$bad, implode(' ', array_slice($bad, 0, 5)));
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
$variants = ['index' => ['', 'season=s01', 'season=s02', 'season=s03'], 'racer' => ['id=1', 'id=2'], 'scoring' => ['season=s01', 'season=s02', 'season=s03'], 'stats' => ['', 'season=s01'], 'timeline_gp' => ['gp=s02gp01'], 'cup_detail' => ['cup=mushroom'], 'view_season_report' => ['season=s01', 'season=s02'], 'animate_season' => ['season=s02'], 'wrapped' => ['racer=1'], 'badges_overview' => ['season=s02'], 'season_chart' => ['season=s02'], 'mh_dashboard' => ['season=s02'], 'rank_graphic' => ['season=s02'], 'cup_mastery' => ['season=s02'], 'predictions' => [''], 'season' => null];
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
