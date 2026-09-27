<?php
/**
 * Go/no-go test for reading results-screen photos (private/includes/result_scan.php).
 *
 *   php bin/scan_eval.php [--model=gemini-2.5-flash] [--limit=10] [--only=3.jpg]
 *                         [--dir=private/data/scan_fixtures] [--pace=13]
 *
 * The fixture folder holds photos of the results screen for GPs that are
 * already in the database, plus expected.json with what each photo shows (the
 * twelve totals top to bottom, null for a row the photo does not show, and the
 * coloured rows). The folder is gitignored — it is one league's TV.
 *
 * Built for the free tier, which allows 20 requests a day per model — shared
 * with the newscasts:
 *   - every read is saved under reads/<model>/ and reused, keyed on the prompt,
 *     so a run picks up where the last one stopped and a prompt change re-reads;
 *   - --limit caps how many NEW reads one run may spend (default 10, leaving
 *     the rest of the day for the newscasts);
 *   - the run stops at the first "daily quota" answer instead of retrying.
 *
 * Each photo gets ONE read, the way the form will read it, plus the checks the
 * form can make: the table's own arithmetic, and the line-up — the form knows
 * which racers are in the GP, so it knows how many players to expect and which
 * characters they have ever played.
 *
 *   PASS            read right, nothing flagged
 *   PASS, flagged   read right, but a check raised a (false) alarm — costs a look
 *   CAUGHT          read wrong, and a check says so
 *   SILENTLY WRONG  read wrong, nothing flagged — the number that must be zero
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/result_scan.php';

$opt    = getopt('', ['model::', 'dir::', 'only::', 'pace::', 'limit::']);
$model  = $opt['model'] ?? 'gemini-2.5-flash';
$dir    = rtrim($opt['dir'] ?? __DIR__ . '/../private/data/scan_fixtures', '/');
$pace   = (int)($opt['pace'] ?? 13);          // free tier: 5 requests a minute
$limit  = (int)($opt['limit'] ?? 10);
$apiKey = (string)(kartfolioConfig()['gemini_api_key'] ?? '');
if ($apiKey === '') { fwrite(STDERR, "No gemini_api_key in config.php\n"); exit(1); }

$expected = json_decode((string)@file_get_contents("$dir/expected.json"), true);
if (!$expected) { fwrite(STDERR, "No $dir/expected.json\n"); exit(1); }
if (!empty($opt['only'])) $expected = array_intersect_key($expected, [$opt['only'] => 1]);
uksort($expected, 'strnatcmp');

$cacheDir = "$dir/reads/$model";
@mkdir($cacheDir, 0750, true);
$promptKey = substr(sha1(resultScanPrompt() . json_encode(resultScanSchema())), 0, 8);

/** The GP's line-up and every character (family) each of them had played before that night. */
function evalLineup(PDO $pdo, string $gpid): array {
    $st = $pdo->prepare("SELECT racer_id, MIN(date(race_date)) AS d FROM results WHERE gpid = ? GROUP BY racer_id");
    $st->execute([$gpid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $h = $pdo->prepare("SELECT DISTINCT character_used FROM results WHERE racer_id = ? AND date(race_date) < ?");
        $h->execute([$r['racer_id'], $r['d']]);
        $out[(int)$r['racer_id']] = array_map('scanCharacterFamily', $h->fetchAll(PDO::FETCH_COLUMN));
    }
    return $out;
}

$key = fn($char, $pts) => scanCharacterFamily($char) . '|' . $pts;
$tally = ['PASS' => 0, 'PASS, flagged' => 0, 'CAUGHT' => 0, 'SILENTLY WRONG' => 0];
$totalsRight = $totalsSeen = $newReads = 0;
$stopped = '';
$lastCall = 0.0;

foreach ($expected as $file => $exp) {
    $cache = "$cacheDir/$file.$promptKey.json";
    if (is_file($cache)) {
        $read = json_decode(file_get_contents($cache), true);
    } else {
        if ($newReads >= $limit) { $stopped = "read limit of $limit reached"; break; }
        $wait = $pace - (microtime(true) - $lastCall);
        if ($lastCall > 0 && $wait > 0) usleep((int)($wait * 1e6));
        $lastCall = microtime(true);
        $read = resultScanRead([$model], $apiKey, file_get_contents("$dir/$file"));
        if ($read['error'] !== '') {
            if (stripos($read['error'], 'quota') !== false) { $stopped = 'daily quota used up'; break; }
            printf("%-7s %s  CALL FAILED: %s\n", $file, $exp['gpid'], strtok(trim($read['error']), "\n"));
            continue;
        }
        $newReads++;
        file_put_contents($cache, json_encode($read, JSON_PRETTY_PRINT));
    }

    $check = resultScanCheck($read);
    $lineup = evalLineup($pdo, $exp['gpid']);
    $problems = $check['problems'];
    if (count($check['humans']) !== count($lineup)) {
        $problems[] = 'Found ' . count($check['humans']) . ' players, but ' . count($lineup) . ' are racing.';
    }
    $known = array_unique(array_merge(...array_values($lineup) ?: [[]]));
    foreach ($check['humans'] as $h) {
        if (!in_array($h['family'], $known, true)) $problems[] = "Nobody racing has played {$h['screen']} before.";
    }
    // Missing rows are expected on the cut-off photos; they are not a sign
    // that the reading went wrong.
    $alarms = array_values(array_filter($problems, fn($p) => !str_starts_with($p, 'Only ')));

    $want = array_map(fn($h) => $key($h['character'], $h['points']), $exp['humans']); sort($want);
    $got  = array_map(fn($h) => $key($h['screen'], $h['points']), $check['humans']);    sort($got);
    $right = $got == $want;
    $verdict = $right ? ($alarms ? 'PASS, flagged' : 'PASS') : ($alarms ? 'CAUGHT' : 'SILENTLY WRONG');
    $tally[$verdict]++;

    foreach ($exp['totals'] as $j => $t) {
        if ($t === null) continue;
        $totalsSeen++;
        if (($read['rows'][$j]['points'] ?? null) === $t) $totalsRight++;
    }

    printf("%-7s %s  %-15s %s%s\n", $file, $exp['gpid'], $verdict,
        $check['complete'] ? 'sum ' . $check['sum'] : 'partial',
        $alarms ? '  ⚑ ' . implode(' | ', $alarms) : '');
    if (!$right) echo "          want [" . implode(', ', $want) . "]\n          got  [" . implode(', ', $got) . "]\n";
}

$done = array_sum($tally);
printf("\n%s · %d of %d photos judged · %d new reads this run%s\n", $model, $done, count($expected), $newReads,
    $stopped ? " · stopped: $stopped — run again to continue" : '');
foreach ($tally as $k => $v) printf("  %-15s %d\n", $k, $v);
printf("  totals read right: %d / %d\n", $totalsRight, $totalsSeen);
exit($tally['SILENTLY WRONG'] > 0 ? 2 : 0);
