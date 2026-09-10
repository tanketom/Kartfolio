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

// "list-racers" mode: the chip row in the modal. Ordered by name (§10).
if (isset($_GET['list-racers'])) {
    $allRacers = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['racers' => $allRacers]);
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

echo json_encode($response);
