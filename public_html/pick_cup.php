<?php
/**
 * Random Cup Picker — the JSON behind the "What cup?" wheel in the nav.
 *
 * Weighted by season race count (a cup nobody has raced this season floats to
 * the top), boosted hard for cups the selected racers have never done, and in
 * MONSTER HUNT seasons it also assigns the Monster and the adventurers.
 *
 * Two things it will not hand you:
 *   - a cup already raced TODAY, because on a five-GP night the season-wide
 *     weighting cheerfully returned the cup from ten minutes ago;
 *   - anything in ?exclude=, which is the modal's "Not that one" veto list.
 * Both relax rather than fail if they would leave nothing to draw from —
 * today's cups come back first, since that rule is automatic, and an explicit
 * veto is only overridden when there is literally nothing else left.
 *
 * Everything reads from the ONE season-results query in gp_logic's cache
 * (§9). This used to run a COUNT per cup — 24 of them — plus a cup list and a
 * name lookup per selected racer, so a single dice roll cost ~32 queries.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';

header('Content-Type: application/json');

$cups          = getMKAllCups();
$currentSeason = getCurrentSeasonNumber();

// "list-racers" mode: everything the modal needs before its first spin —
// the chip row (ordered by name, §10), the cup list so the wheel can riffle
// through names while it thinks, and who raced the most recent GP, which the
// modal preselects so a game night does not retype its own line-up.
if (isset($_GET['list-racers'])) {
    $allRacers = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $lastGpid = null; $lastKey = ''; $byGp = [];
    foreach (getSeasonResultsByRacer($pdo, $currentSeason) as $rid => $rows) {
        foreach ($rows as $r) {
            $gpid = (string)($r['gpid'] ?? '');
            if ($gpid === '') continue;
            $byGp[$gpid][(int)$rid] = true;
            // Latest race date wins, then the highest gpid on that date (§10).
            $key = (string)($r['race_date'] ?? '') . '|' . $gpid;
            if ($key > $lastKey) { $lastKey = $key; $lastGpid = $gpid; }
        }
    }

    echo json_encode([
        'racers'  => $allRacers,
        'allCups' => $cups,
        'lastGp'  => $lastGpid === null ? null : [
            'gpid'   => $lastGpid,
            'racers' => array_slice(array_keys($byGp[$lastGpid]), 0, MK_MAX_HUMAN_PLAYERS),
        ],
    ]);
    exit;
}

$rules          = getSeasonRules($pdo, $currentSeason);
$scoringSystem  = $rules['scoring_system'] ?? 'average_attendance';
$isMonsterHunt  = ($scoringSystem === 'monster_hunt');

$racerIds = [];
if (!empty($_GET['racers'])) {
    $racerIds = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['racers'])), fn($id) => $id > 0));
}

// ── One pass over the season cache ────────────────────────────────────────
$names = racerNamesMap($pdo);
$today = date('Y-m-d');

$gpsPerCup    = [];   // cup => [gpid => true]   (the DISTINCT gpid count)
$racerCups    = [];   // rid => [cup => best points that racer scored there]
$cupBest      = [];   // cup => ['racer_id' =>, 'points' =>, 'row_id' =>]
$racedToday   = [];   // cup => true

foreach (getSeasonResultsByRacer($pdo, $currentSeason) as $rid => $rows) {
    $rid = (int)$rid;
    foreach ($rows as $r) {
        $cup = (string)($r['cup_name'] ?? '');
        if ($cup === '') continue;
        $pts   = (int)($r['gp_points'] ?? 0);
        $rowId = (int)($r['id'] ?? 0);

        $gpsPerCup[$cup][(string)($r['gpid'] ?? '')] = true;
        if (!isset($racerCups[$rid][$cup]) || $pts > $racerCups[$rid][$cup]) $racerCups[$rid][$cup] = $pts;
        if ((string)($r['race_date'] ?? '') === $today) $racedToday[$cup] = true;

        // Season best per cup; ties go to the earlier row so the name shown
        // does not depend on the query plan (§10).
        if (!isset($cupBest[$cup])
            || $pts > $cupBest[$cup]['points']
            || ($pts === $cupBest[$cup]['points'] && $rowId < $cupBest[$cup]['row_id'])) {
            $cupBest[$cup] = ['racer_id' => $rid, 'points' => $pts, 'row_id' => $rowId];
        }
    }
}

$raceCounts = [];
foreach ($cups as $cup) $raceCounts[$cup] = count($gpsPerCup[$cup] ?? []);

// How many of the selected racers have never raced each cup this season.
$missingCounts = [];
if ($racerIds) {
    foreach ($cups as $cup) {
        $missing = 0;
        foreach ($racerIds as $rid) if (!isset($racerCups[$rid][$cup])) $missing++;
        $missingCounts[$cup] = $missing;
    }
}

// ── What we refuse to draw ────────────────────────────────────────────────
$vetoed = [];
if (!empty($_GET['exclude'])) {
    foreach (explode(',', (string)$_GET['exclude']) as $c) {
        $c = trim($c);
        if ($c !== '' && in_array($c, $cups, true)) $vetoed[$c] = true;
    }
}

$relaxed  = null;
$eligible = array_values(array_filter($cups, fn($c) => !isset($vetoed[$c]) && !isset($racedToday[$c])));
if (!$eligible) {   // a very long night: let today's cups back in first
    $eligible = array_values(array_filter($cups, fn($c) => !isset($vetoed[$c])));
    $relaxed  = 'today';
}
if (!$eligible) {   // everything vetoed too — the veto list has to give
    $eligible = $cups;
    $relaxed  = 'all';
}

// ── Weights ───────────────────────────────────────────────────────────────
$maxCount    = $raceCounts ? max($raceCounts) : 0;
$racerCount  = count($racerIds);
$weights     = [];
$totalWeight = 0;

foreach ($eligible as $cup) {
    // Base weight: less raced this season = more likely.
    $baseWeight = ($maxCount - $raceCounts[$cup]) + 1;

    if ($racerCount > 0) {
        $missing = $missingCounts[$cup];
        if ($missing === $racerCount)  $weight = $baseWeight * 20;              // nobody has done it
        elseif ($missing > 0)          $weight = $baseWeight * (5 + $missing * 5);
        else                           $weight = 1;                             // everyone has
    } else {
        $weight = $baseWeight;
    }

    $weights[$cup] = $weight;
    $totalWeight  += $weight;
}

$rand         = mt_rand(1, max(1, $totalWeight));
$runningTotal = 0;
$selectedCup  = $eligible[0];
foreach ($eligible as $cup) {
    $runningTotal += $weights[$cup];
    if ($rand <= $runningTotal) { $selectedCup = $cup; break; }
}

// ── MONSTER HUNT roles ────────────────────────────────────────────────────
$mhData = null;
if ($isMonsterHunt) {
    require_once __DIR__ . '/../private/includes/elo_engine.php';
    $allRatings = calculateAllELORatings($pdo)['ratings'];   // ['Name' => float]

    // Roles cover the selected racers when there are any; the whole roster
    // otherwise dumps every name into the modal and pushes the buttons off
    // the viewport.
    $participants = [];
    foreach (($racerIds ?: array_keys($names)) as $rid) {
        $rid = (int)$rid;
        if (!isset($names[$rid])) continue;
        $participants[] = ['id' => $rid, 'name' => $names[$rid], 'elo' => (int)round($allRatings[$names[$rid]] ?? 1000)];
    }
    // Elo first, then name, so equal ratings do not order themselves (§10).
    usort($participants, fn($a, $b) => ($b['elo'] <=> $a['elo']) ?: strcmp($a['name'], $b['name']));

    if ($participants) {
        // Highest Elo is the Monster — same rule as pickMonster() in gp_logic.
        $monster     = $participants[0];
        $adventurers = array_slice($participants, 1);

        $advElo    = array_column($adventurers, 'elo');
        $avgAdvElo = $advElo ? array_sum($advElo) / count($advElo) : $monster['elo'];
        $eloGap    = max(0, $monster['elo'] - $avgAdvElo);
        if      ($eloGap < 50)  { $monster['cr_tier'] = 1; $monster['cr_epithet'] = 'the Rival'; }
        elseif  ($eloGap < 150) { $monster['cr_tier'] = 2; $monster['cr_epithet'] = 'the Beast'; }
        elseif  ($eloGap < 300) { $monster['cr_tier'] = 3; $monster['cr_epithet'] = 'the Fearsome One'; }
        else                    { $monster['cr_tier'] = 4; $monster['cr_epithet'] = 'the Dragon'; }

        $mhData = ['monster' => $monster, 'adventurers' => $adventurers];
    }
}

/**
 * Kartificial's line about the draw. He already hosts the World Cup
 * (wc_pickem.php, the bracket, /scoring-systems), so the cup wheel gets the
 * same mascot rather than a second one.
 *
 * Deliberately NOT a Gemini call: every fact here is already computed above,
 * so the patter is instant, free, works offline and cannot stall a game night
 * behind a model timeout. He leads with the most interesting true thing and
 * adds one supporting fact.
 *
 * @return array{line: string, mood: string}
 */
function kartificialLine(string $cup, array $f): array {
    $pick = fn(array $lines) => $lines[mt_rand(0, count($lines) - 1)];

    // Vetoes first — he is reacting to the player, not the draw.
    if ($f['relaxed'] === 'all') {
        return ['line' => "You have vetoed the entire garage. $cup it is. I do not make the rules.", 'mood' => 'grumpy'];
    }
    if ($f['vetoed'] >= 3) {
        return ['line' => $pick([
            "$cup. That is veto number {$f['vetoed']}. I am a random number generator, not a waiter.",
            "Fine. $cup. Shall I keep going until you like one?",
            "$cup, after {$f['vetoed']} rejections. My confidence is not what it was.",
        ]), 'mood' => 'grumpy'];
    }

    $lead = null; $support = null; $mood = 'neutral';

    if ($f['holder'] !== null) {
        $lead = "$cup belongs to {$f['holder']['holder']} — {$f['holder']['points']} points of it.";
        $support = 'Go and take it.';
        $mood = 'excited';
    } elseif ($f['racerCount'] > 0 && $f['missing'] === $f['racerCount']) {
        $lead = "$cup. Not one of you has raced it this season.";
        $mood = 'excited';
    } elseif ($f['racerCount'] > 0 && $f['missing'] === 1 && $f['firstTimer'] !== null) {
        $lead = "$cup — and {$f['firstTimer']} has never seen it.";
        $mood = 'excited';
    } elseif ($f['seasonRaceCount'] === 0) {
        $lead = "$cup. Untouched all season.";
        $mood = 'excited';
    } else {
        $lead = $pick([
            "$cup. Raced {$f['seasonRaceCount']} times this season.",
            "The wheel says $cup.",
            "$cup. I have consulted the numbers.",
        ]);
    }

    if ($support === null && $f['best'] !== null) {
        $support = "{$f['best']['name']}'s {$f['best']['score']} is the number to beat.";
    }
    if ($support === null && $f['vetoed'] > 0) {
        $support = $f['vetoed'] === 1 ? 'One cup rejected so far.' : "{$f['vetoed']} cups rejected so far.";
    }
    if ($support === null && $f['excludedToday'] > 0) {
        $support = $f['excludedToday'] === 1
            ? 'Skipping the one you already raced tonight.'
            : "Skipping the {$f['excludedToday']} you already raced tonight.";
    }

    return ['line' => trim($lead . ($support ? ' ' . $support : '')), 'mood' => $mood];
}

/**
 * Kartificial's pick: who he expects to be the best racer in the line-up on
 * the cup just drawn. A fixed formula over the league's own results, not a
 * model call, for the same reason as his line above — instant, free, and
 * every fact he quotes is true.
 *
 * Each racer's expected score = their average over their last 20 GPs (form)
 * plus how far their average on THIS cup sits above or below their career
 * average, shrunk by how often they have raced it (n / (n + 3)), so one lucky
 * night on a cup does not make a specialist. Highest expected score is the
 * pick. No odds are shown and nothing is recorded: What cup? is often drawn
 * and then not raced, so a hit rate would mean nothing.
 *
 * Two queries: the line-up's career results, and the best finishing place in
 * every GP ever raced on this cup (so "the best racer here" is judged against
 * everyone who raced it, not just tonight's line-up).
 *
 * @return array{name: string, line: string}|null  null with fewer than two
 *         racers selected, or when nobody selected has raced before
 */
function kartificialPick(PDO $pdo, string $cup, array $racerIds, array $names): ?array {
    if (count($racerIds) < 2) return null;

    $in = implode(',', array_fill(0, count($racerIds), '?'));
    $st = $pdo->prepare("SELECT racer_id, gpid, cup_name, gp_points, rank FROM results
                         WHERE gpid LIKE 's%' AND racer_id IN ($in)
                         ORDER BY race_date DESC, id DESC");
    $st->execute(array_values($racerIds));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT gpid, MIN(rank) FROM results WHERE gpid LIKE 's%' AND cup_name = ? GROUP BY gpid");
    $st->execute([$cup]);
    $topRank = $st->fetchAll(PDO::FETCH_KEY_PAIR);

    $f = [];   // racer_id => facts
    foreach ($rows as $r) {
        $rid = (int)$r['racer_id'];
        $f[$rid] ??= ['all' => [], 'recent' => [], 'here' => [], 'best' => 0];
        $pts = (int)$r['gp_points'];
        $f[$rid]['all'][] = $pts;
        if (count($f[$rid]['recent']) < 20) $f[$rid]['recent'][] = $pts;   // newest first
        if ($r['cup_name'] === $cup) {
            $f[$rid]['here'][] = $pts;
            if ((int)$r['rank'] === (int)($topRank[$r['gpid']] ?? -1)) $f[$rid]['best']++;
        }
    }

    $avg = fn(array $a) => $a ? array_sum($a) / count($a) : 0.0;
    $cands = [];
    foreach ($racerIds as $rid) {
        if (empty($f[$rid]['all']) || !isset($names[$rid])) continue;   // never raced: no basis to pick
        $x = $f[$rid];
        $n = count($x['here']);
        $delta = $n ? $avg($x['here']) - $avg($x['all']) : 0.0;
        $cands[] = [
            'id' => $rid, 'name' => $names[$rid], 'n' => $n, 'best' => $x['best'],
            'delta' => $delta, 'recent' => $avg($x['recent']),
            'expect' => $avg($x['recent']) + $delta * $n / ($n + 3),
        ];
    }
    if (!$cands) return null;
    // Expected score, then form, then name — never the query plan (§10).
    usort($cands, fn($a, $b) => ($b['expect'] <=> $a['expect']) ?: ($b['recent'] <=> $a['recent']) ?: strcmp($a['name'], $b['name']));

    $p = $cands[0];
    $q = $cands[1] ?? null;
    $c = "$cup Cup";
    $one = fn(float $d) => number_format(abs($d), 1);

    // Who has been the best racer on this cup most often (ties: name, §10).
    $leader = null;
    foreach ($cands as $o) {
        if ($o['best'] > 0 && ($leader === null || $o['best'] > $leader['best']
            || ($o['best'] === $leader['best'] && strcmp($o['name'], $leader['name']) < 0))) $leader = $o;
    }
    $pLeads = $leader === null || $leader['id'] === $p['id'] || $leader['best'] <= $p['best'];
    $topForm = max(array_column($cands, 'recent'));
    $r = (int)round($p['recent']);

    if ($q && $p['expect'] - $q['expect'] < 1.5) {
        $line = ($p['n'] + $q['n'] > 0)
            ? "{$p['name']} or {$q['name']}. " . ($p['n'] + $q['n']) . " $c" . ($p['n'] + $q['n'] === 1 ? '' : 's') . " between them all-time and nothing in it. I'm saying {$p['name']}, quietly."
            : "{$p['name']} or {$q['name']}, and neither has raced $c. I'm saying {$p['name']}, quietly.";
    } elseif ($pLeads && $p['n'] >= 4 && $p['best'] * 2 >= $p['n']) {
        $line = "My pick: {$p['name']}, the best racer on $c {$p['best']} times in {$p['n']} all-time.";
    } elseif ($p['n'] >= 3 && $p['delta'] >= 3) {
        $line = "My pick: {$p['name']}, who scores {$one($p['delta'])} above the usual average on $c.";
    } elseif ($p['n'] === 0) {
        $line = "My pick: {$p['name']}. Never raced $c, but nobody here is in better form.";
    } else {
        // Plain form. The variant follows the cup, so a draw always reads the same.
        $variants = ["My pick: {$p['name']}, on form: $r a GP lately. Prove me wrong.",
                     "{$p['name']}. $r a GP over the last twenty. I have spoken."];
        if ($p['recent'] >= $topForm) $variants[] = "I'm going with {$p['name']}. Nobody here has been scoring more lately: $r a GP.";
        $line = $variants[crc32($cup) % count($variants)];
    }

    // One aside about somebody else, when there is a true thing worth saying
    // — never about the runner-up the line has just named.
    $named = $q && $p['expect'] - $q['expect'] < 1.5;
    $aside = null;
    if (!$pLeads && $leader['best'] >= 3 && !($named && $leader['id'] === $q['id'])) {
        $aside = "{$leader['name']} has been the best racer on $c more often over the years, though: {$leader['best']} times to {$p['best']}.";
    }
    if ($aside === null) {
        foreach (array_slice($cands, 1) as $o) {
            if ($named && $o['id'] === $q['id']) continue;
            if ($o['n'] >= 3 && $o['best'] >= 3 && $o['best'] * 2 >= $o['n']) {
                $aside = "Don't rule out {$o['name']}: the best racer on $c {$o['best']} times over the years."; break;
            }
        }
    }
    if ($aside === null) {
        $most = null;
        foreach ($cands as $o) if ($o['id'] !== $p['id'] && ($most === null || $o['n'] > $most['n'])) $most = $o;
        if ($most && $named && $most['id'] === $q['id']) $most = null;
        if ($most && $most['n'] >= 5 && $most['delta'] <= -3) {
            $aside = "{$most['name']} has raced $c more than anyone here, {$most['n']} times, and $c still owes {$most['name']} one.";
        }
    }

    return ['name' => $p['name'], 'line' => $aside ? "$line $aside" : $line];
}

// ── Response ──────────────────────────────────────────────────────────────
$response = [
    'cup'             => $selectedCup,
    'seasonRaceCount' => $raceCounts[$selectedCup],
    'allCups'         => $cups,
    'is_monster_hunt' => $isMonsterHunt,
    'excludedToday'   => array_values(array_keys($racedToday)),
    'vetoedCount'     => count($vetoed),
    'relaxed'         => $relaxed,
];

// The number to beat: the season's best score in this cup, whoever set it.
if (isset($cupBest[$selectedCup])) {
    $best = $cupBest[$selectedCup];
    $response['bestThisSeason'] = [
        'name'  => $names[$best['racer_id']] ?? 'Unknown',
        'score' => $best['points'],
    ];
}

// Territory seasons: the draw is a raid, so say whose cup it is.
if ($scoringSystem === 'territory') {
    $hold = territorySeason($pdo, $currentSeason, $rules)['hold'] ?? [];
    if (isset($hold[$selectedCup])) {
        $response['territory'] = [
            'holder' => $names[(int)$hold[$selectedCup]['racer_id']] ?? 'Unknown',
            'points' => (int)$hold[$selectedCup]['points'],
        ];
    }
}

if ($racerCount > 0) {
    $racerDetails = [];
    foreach ($racerIds as $rid) {
        $best = $racerCups[$rid][$selectedCup] ?? null;
        $racerDetails[] = [
            'id'        => $rid,
            'name'      => $names[$rid] ?? 'Unknown',
            'hasDone'   => $best !== null,
            'bestScore' => $best,
        ];
    }
    $response['racerDetails'] = $racerDetails;
    $response['missingCount'] = $missingCounts[$selectedCup];
}

if ($mhData) {
    $response['monster']     = $mhData['monster'];
    $response['adventurers'] = $mhData['adventurers'];
}

// The host has the last word — after every fact above is settled.
$firstTimer = null;
if ($racerCount > 0 && ($response['missingCount'] ?? 0) === 1) {
    foreach ($response['racerDetails'] as $rd) if (!$rd['hasDone']) { $firstTimer = $rd['name']; break; }
}
$response['host'] = kartificialLine($selectedCup, [
    'seasonRaceCount' => $raceCounts[$selectedCup],
    'racerCount'      => $racerCount,
    'missing'         => $missingCounts[$selectedCup] ?? 0,
    'firstTimer'      => $firstTimer,
    'best'            => $response['bestThisSeason'] ?? null,
    'holder'          => $response['territory'] ?? null,
    'vetoed'          => count($vetoed),
    'excludedToday'   => count($racedToday),
    'relaxed'         => $relaxed,
]);
$pick = kartificialPick($pdo, $selectedCup, $racerIds, $names);
if ($pick) $response['pick'] = $pick;

echo json_encode($response);
