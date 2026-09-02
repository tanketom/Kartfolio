<?php
/**
 * Wrapped personas — Aura, Club, and Racing Personality catalogs + pickers.
 *
 * Each picker evaluates its catalog in priority order against a $stats array
 * (built in wrapped.php) and returns the first match, falling back to a
 * guaranteed default at the end of the list. Keeping this out of wrapped.php
 * so the catalogs are easy to tune and unit-test in isolation.
 *
 * $stats keys used:
 *   gps, wins, podiums, avg, points, win_rate, podium_rate, std_dev,
 *   best_pts, has_perfect, peak_elo, elo_delta, lols, lol_rate,
 *   distinct_chars, cups_raced, cup_concentration, longest_podium_streak,
 *   comeback, attendance_rank, second_half_jump, top_group, group_pct,
 *   points_percentile
 *
 * Path: /cdnmk/private/includes/wrapped_personas.php
 */

/** Pick the first catalog entry whose 'match' closure returns true (single-racer fallback). */
function wrappedPick(array $catalog, array $stats): array {
    foreach ($catalog as $entry) {
        if (($entry['match'])($stats)) return $entry;
    }
    return end($catalog); // catalogs always end with an unconditional default
}

/**
 * RARITY-FIRST assignment across the whole roster. wrappedPick() is
 * first-match-wins in catalogue order, so everyone who qualified for an
 * early entry landed there (2026: ten "Fresh Tracks", six "Fading Star",
 * twelve clubs unused). Here every racer's matches are collected first and
 * each racer takes the matching entry that the FEWEST racers qualify for —
 * specific achievements beat generic buckets automatically, unconditional
 * defaults only ever catch someone who matched nothing else. Ties keep the
 * catalogue's priority order. Deterministic: no state, no randomness.
 *
 * @param array $bags  racer_id => stat bag (the same shape wrappedPick takes)
 * @return array racer_id => catalogue entry
 */
function wrappedAssignAll(array $catalog, array $bags): array {
    $matches = [];   // racer_id => [catalog index, ...]
    $count   = array_fill(0, count($catalog), 0);
    foreach ($bags as $rid => $bag) {
        foreach ($catalog as $i => $entry) {
            if (($entry['match'])($bag)) { $matches[$rid][] = $i; $count[$i]++; }
        }
    }
    $out = [];
    foreach ($bags as $rid => $bag) {
        $best = null;
        foreach ($matches[$rid] ?? [] as $i) {
            if ($best === null || $count[$i] < $count[$best]) $best = $i;   // strict <, so ties keep order
        }
        $out[$rid] = $catalog[$best ?? array_key_last($catalog)];
    }
    return $out;
}

/**
 * Stat bag for every racer with a GP this year — the fields the catalogues
 * match on, computed from ONE query of the year's rows plus the Elo
 * changelog. Mirrors the per-racer computation in wrapped.php exactly (the
 * regression check compares them). Cached per request.
 */
function wrappedStatBags(PDO $pdo, int $year, array $roster): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];

    $st = $pdo->prepare("
        SELECT racer_id, gp_points, rank, cup_name, character_used, is_lol
        FROM results
        WHERE gpid LIKE 's%' AND race_date >= ? AND race_date < ?
        ORDER BY race_date ASC, id ASC");
    $st->execute(["$year-01-01", ($year + 1) . "-01-01"]);
    $byRacer = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byRacer[(int)$r['racer_id']][] = $r;

    // Elo first/last/peak per racer name for the year.
    if (!function_exists('calculateAllELORatings')) require_once __DIR__ . '/elo_engine.php';
    $eloData = calculateAllELORatings($pdo);
    $elo = [];   // name => [first, last, peak]
    foreach ($eloData['gp_changelog'] ?? [] as $gpLog) {
        if (substr($gpLog['date'], 0, 4) !== (string)$year) continue;
        foreach ($gpLog['racers'] as $rc) {
            $n = $rc['name'];
            if (!isset($elo[$n])) $elo[$n] = [$rc['old'], $rc['new'], $rc['new']];
            else { $elo[$n][1] = $rc['new']; $elo[$n][2] = max($elo[$n][2], $rc['new']); }
        }
    }

    $groups = getCharacterGroups();
    $rosterList = array_values($roster);
    $bags = [];
    foreach ($byRacer as $rid => $rows) {
        $name   = $roster[$rid]['name'] ?? '';
        $gps    = count($rows);
        $points = array_sum(array_map(fn($r) => (int)$r['gp_points'], $rows));
        $avg    = $gps ? round($points / $gps, 1) : 0;
        $wins = 0; $podiums = 0; $lols = 0; $bestPts = -1;
        $charTally = []; $cupTally = []; $ranksChrono = [];
        foreach ($rows as $r) {
            if ((int)$r['rank'] === 1) $wins++;
            if ((int)$r['rank'] <= 3) $podiums++;
            $lols += (int)$r['is_lol'];
            if ((int)$r['gp_points'] > $bestPts) $bestPts = (int)$r['gp_points'];
            if ($r['character_used']) $charTally[$r['character_used']] = ($charTally[$r['character_used']] ?? 0) + 1;
            if ($r['cup_name'])       $cupTally[$r['cup_name']]        = ($cupTally[$r['cup_name']] ?? 0) + 1;
            $ranksChrono[] = (int)$r['rank'];
        }
        $ptVals = array_map(fn($r) => (int)$r['gp_points'], $rows);
        $mean   = $gps ? array_sum($ptVals) / $gps : 0;
        $stdDev = $gps ? sqrt(array_sum(array_map(fn($p) => ($p - $mean) ** 2, $ptVals)) / $gps) : 0;
        $longestPodium = 0; $run = 0;
        foreach ($ranksChrono as $rk) { if ($rk <= 3) { $run++; $longestPodium = max($longestPodium, $run); } else $run = 0; }
        $comeback = false;
        for ($i = 1; $i < count($ranksChrono); $i++) if ($ranksChrono[$i] === 1 && $ranksChrono[$i - 1] >= 10) { $comeback = true; break; }
        $half = (int)floor($gps / 2); $secondHalfJump = 0;
        if ($half >= 1) { $first = array_slice($ptVals, 0, $half); $second = array_slice($ptVals, $half); $secondHalfJump = (array_sum($second) / count($second)) - (array_sum($first) / count($first)); }
        $groupCounts = array_fill_keys(array_keys($groups), 0);
        foreach ($charTally as $char => $cnt) {
            $norm = normalizeCharacterName($char);
            foreach ($groups as $gk => $members) if (in_array($norm, $members, true) || in_array($char, $members, true)) $groupCounts[$gk] += $cnt;
        }
        arsort($groupCounts);
        $topGroup = (max($groupCounts) > 0) ? array_key_first($groupCounts) : null;
        $ahead = 0; foreach ($rosterList as $o) if ((float)$o['points'] < (float)$points) $ahead++;
        $pointsPercentile = count($rosterList) > 1 ? round($ahead / (count($rosterList) - 1) * 100) : 100;
        $attendanceRank = 1; foreach ($rosterList as $o) if ((int)$o['gps'] > $gps) $attendanceRank++;
        [$eFirst, $eLast, $ePeak] = $elo[$name] ?? [null, null, null];
        $bags[$rid] = [
            'gps' => $gps, 'wins' => $wins, 'podiums' => $podiums, 'avg' => $avg, 'points' => $points,
            'win_rate' => $gps ? $wins / $gps : 0, 'podium_rate' => $gps ? $podiums / $gps : 0,
            'std_dev' => $stdDev, 'best_pts' => $bestPts, 'has_perfect' => ($bestPts === MK_MAX_GP_POINTS),
            'peak_elo' => $ePeak ?? 0, 'elo_delta' => ($eFirst !== null && $eLast !== null) ? (int)round($eLast - $eFirst) : 0,
            'lols' => $lols, 'lol_rate' => $gps ? $lols / $gps : 0,
            'distinct_chars' => count($charTally), 'cups_raced' => count($cupTally),
            'cup_concentration' => $gps ? (max($cupTally ?: [0]) / $gps) : 0,
            'longest_podium_streak' => $longestPodium, 'comeback' => $comeback, 'attendance_rank' => $attendanceRank,
            'second_half_jump' => $secondHalfJump, 'top_group' => $topGroup, 'points_percentile' => $pointsPercentile,
        ];
    }
    return $cache[$year] = $bags;
}

/**
 * Aura / club / personality for EVERY racer this year, rarity-first, cached
 * in sim_cache on the results signature (the assignment is a pure function
 * of the year's rows). Returns racer_id => ['aura' => entry, 'club' => entry,
 * 'personality' => entry].
 */
function wrappedAssignments(PDO $pdo, int $year, array $roster): array {
    static $mem = [];
    if (isset($mem[$year])) return $mem[$year];
    if (!function_exists('simCacheGet')) require_once __DIR__ . '/sim_cache.php';
    $sig = $pdo->query("SELECT COUNT(*) || ':' || COALESCE(MAX(id),0) FROM results")->fetchColumn();
    $key = 'wrapped:' . $year . ':' . $sig . ':' . crc32((string)@file_get_contents(__FILE__));
    $catalogs = ['aura' => wrappedAuras(), 'club' => wrappedClubs(), 'personality' => wrappedPersonalities()];
    $byKey = [];
    foreach ($catalogs as $kind => $cat) foreach ($cat as $e) $byKey[$kind][$e['key']] = $e;

    $hit = simCacheGet($pdo, $key);
    if ($hit === null) {
        $bags = wrappedStatBags($pdo, $year, $roster);
        $hit = [];
        foreach ($catalogs as $kind => $cat) foreach (wrappedAssignAll($cat, $bags) as $rid => $e) $hit[$rid][$kind] = $e['key'];
        simCachePut($pdo, $key, $hit);
    }
    $out = [];
    foreach ($hit as $rid => $kinds) foreach ($kinds as $kind => $k) if (isset($byKey[$kind][$k])) $out[(int)$rid][$kind] = $byKey[$kind][$k];
    return $mem[$year] = $out;
}

/** Minimum GPs before a racer is judged on "performance" auras/clubs.
 *  Below this they get character-identity or a low-GP catch-all instead, so a
 *  one-night racer isn't branded a "Fading Star" or "Specialist" off one GP. */
const WRAPPED_MIN_GPS = 6;

/**
 * ~22 Auras, in priority order. Performance auras are gated behind
 * WRAPPED_MIN_GPS and front-loaded by distinctiveness so the field spreads
 * out (the biggest winner takes Pure Gold, the biggest climber Ascendant,
 * etc.) instead of everyone collapsing onto Ice Cold. Character-identity
 * auras are ungated (your main says something from race one), and low-GP
 * racers fall to Rookie Fire / Fresh Tracks.
 */
function wrappedAuras(): array {
    $vol = fn($s) => $s['gps'] >= WRAPPED_MIN_GPS; // "enough volume to judge"
    return [
        // ── Performance: selective traits first so the strong field spreads ──
        ['key' => 'frontrunner',  'label' => 'Pure Gold',     'grad' => ['#f7b733', '#fc4a1a'], 'meaning' => 'The front of the pack is the only view you recognise. This season had a glow to it.',          'match' => fn($s) => $vol($s) && $s['win_rate'] >= 0.35],
        ['key' => 'ascendant',    'label' => 'Ascendant',     'grad' => ['#56ab2f', '#a8e063'], 'meaning' => 'Everything about this season pointed upward — you are not where you started, and that is the point.', 'match' => fn($s) => $vol($s) && $s['elo_delta'] >= 120],
        ['key' => 'explosive',    'label' => 'Explosive',     'grad' => ['#f7971e', '#ff0080'], 'meaning' => 'When it went off, the whole room felt it. Containment was never the plan.',                  'match' => fn($s) => $vol($s) && $s['best_pts'] >= 55 && $s['std_dev'] >= 13],
        ['key' => 'clinical',     'label' => 'Clinical',      'grad' => ['#11998e', '#38ef7d'], 'meaning' => 'Precision over flash — the kind of year that wins by simply never slipping.',                'match' => fn($s) => $vol($s) && $s['avg'] >= 45 && $s['std_dev'] < 8],
        ['key' => 'cursed',       'label' => 'Cursed',        'grad' => ['#3f5efb', '#1d976c'], 'meaning' => 'The shells found you — the blue ones especially. Some seasons are just haunted.',            'match' => fn($s) => $vol($s) && $s['lol_rate'] >= 0.25],
        ['key' => 'chaotic',      'label' => 'Chaotic',       'grad' => ['#fc466b', '#ffe53b'], 'meaning' => 'Order is for other people. Your season ran on pure entropy and somehow it worked.',           'match' => fn($s) => $vol($s) && $s['lols'] >= 6],
        ['key' => 'underdog',     'label' => 'Underdog Grit', 'grad' => ['#4568dc', '#b06ab3'], 'meaning' => 'Counted out more than once — and you kept showing up to prove the maths wrong.',               'match' => fn($s) => $vol($s) && $s['points_percentile'] <= 40 && $s['wins'] >= 1],
        ['key' => 'slow_burn',    'label' => 'Slow Burn',     'grad' => ['#cb2d3e', '#ef473a'], 'meaning' => 'You took your time. By the end, nobody wanted to see your name on the sheet.',                 'match' => fn($s) => $vol($s) && $s['second_half_jump'] >= 8],
        ['key' => 'fading_star',  'label' => 'Fading Star',   'grad' => ['#42275a', '#734b6d'], 'meaning' => 'Burned bright early and asked hard questions late. The light is still there.',               'match' => fn($s) => $vol($s) && $s['elo_delta'] <= -120],
        // ── Consistency: catch-alls for the steady, after the standouts ──
        ['key' => 'relentless',   'label' => 'Relentless',    'grad' => ['#ff512f', '#dd2476'], 'meaning' => 'No peaks, no valleys — just a tide that only ever came in.',                                  'match' => fn($s) => $vol($s) && $s['longest_podium_streak'] >= 8],
        ['key' => 'ice_cold',     'label' => 'Ice Cold',      'grad' => ['#7ee8fa', '#1f6feb'], 'meaning' => 'Cool to the touch and lethal under pressure — nothing rattled a season this composed.',     'match' => fn($s) => $vol($s) && $s['has_perfect'] && $s['std_dev'] < 9],
        ['key' => 'wildcard',     'label' => 'Wildcard',      'grad' => ['#8a2be2', '#00c9ff'], 'meaning' => 'Impossible to predict, impossible to ignore — your season kept everyone guessing.',           'match' => fn($s) => $vol($s) && $s['std_dev'] >= 14],
        ['key' => 'ironclad',     'label' => 'Ironclad',      'grad' => ['#485563', '#29323c'], 'meaning' => 'Through every race night, every cup, every shell — you simply never left the grid.',          'match' => fn($s) => $s['attendance_rank'] === 1 && $s['gps'] >= 15],
        ['key' => 'workhorse',    'label' => 'Workhorse',     'grad' => ['#603813', '#b29f94'], 'meaning' => 'No spotlight, no fireworks — just a season of quietly putting in the laps.',                   'match' => fn($s) => $s['gps'] >= 15 && $s['podium_rate'] < 0.40],
        // ── Character identity (valid from race one) ──
        ['key' => 'featherweight','label' => 'Featherlight',  'grad' => ['#89f7fe', '#66a6ff'], 'meaning' => 'Quick, weightless, hard to pin down — your season slipped through the field like air.',        'match' => fn($s) => $s['top_group'] === 'babies'],
        ['key' => 'heavyweight',  'label' => 'Heavy Metal',   'grad' => ['#870000', '#190a05'], 'meaning' => 'Built like a wall and twice as hard to move. You won on sheer presence.',                      'match' => fn($s) => $s['top_group'] === 'heavies'],
        ['key' => 'spectral',     'label' => 'Spectral',      'grad' => ['#41295a', '#2f0743'], 'meaning' => 'There is a chill to this season — you raced with the dead, and they raced well.',             'match' => fn($s) => $s['top_group'] === 'spooky'],
        ['key' => 'regal',        'label' => 'Regal',         'grad' => ['#ee9ca7', '#ffdde1'], 'meaning' => 'You carried yourself like the crown was already yours. Some seasons just have poise.',       'match' => fn($s) => $s['top_group'] === 'royals'],
        ['key' => 'feral',        'label' => 'Feral',         'grad' => ['#134e5e', '#71b280'], 'meaning' => 'Untamed and a little dangerous — you ran on instinct and teeth all year.',                    'match' => fn($s) => in_array($s['top_group'], ['furry', 'reptiles'], true)],
        // ── Low-GP catch-alls ──
        ['key' => 'rookie_fire',  'label' => 'Rookie Fire',   'grad' => ['#ff4e50', '#f9d423'], 'meaning' => 'You did not race often, but every time you did the field noticed. A spark with range.',      'match' => fn($s) => $s['gps'] < WRAPPED_MIN_GPS && $s['avg'] >= 38],
        // Newcomers are judged against each other, in three activity bands.
        ['key' => 'one_lap',      'label' => 'One Lap',       'grad' => ['#bdc3c7', '#2c3e50'], 'meaning' => 'One start on the board — the grid has your name now, and that is how every season begins.',    'match' => fn($s) => $s['gps'] === 1],
        ['key' => 'fresh_tracks', 'label' => 'Fresh Tracks',  'grad' => ['#36d1dc', '#5b86e5'], 'meaning' => 'Only a handful of starts so far — the season is still wide open for you.',                    'match' => fn($s) => $s['gps'] >= 2 && $s['gps'] <= 3],
        ['key' => 'warming_up',   'label' => 'Warming Up',    'grad' => ['#f6d365', '#fda085'], 'meaning' => 'A few nights in and finding the racing line — one more push and you race with the regulars.', 'match' => fn($s) => $s['gps'] >= 4 && $s['gps'] < WRAPPED_MIN_GPS],
        ['key' => 'steady',       'label' => 'Steady Hand',   'grad' => ['#5b6f8c', '#2c3e50'], 'meaning' => 'No drama, no collapse — a season that simply, reliably, got the job done.',                   'match' => fn($s) => true], // default
    ];
}

/**
 * ~23 Clubs in priority order. The specific achievement clubs come first, then
 * character identity, then the broad ones — and "The Perfectionists" (any 60)
 * is pushed DOWN so it stops swallowing every high-volume racer. Specialists
 * is gated behind WRAPPED_MIN_GPS (one GP = one cup ≠ a specialist), and
 * genuinely low-GP racers land in The Newcomers.
 */
function wrappedClubs(): array {
    $vol = fn($s) => $s['gps'] >= WRAPPED_MIN_GPS;
    return [
        // ── Rare / standout achievements first ──
        ['key' => 'comeback',       'name' => 'The Comeback Club',   'blurb' => 'Down is never out. You answer last place with a win.',           'match' => fn($s) => $vol($s) && $s['comeback']],
        ['key' => 'climbers',       'name' => 'The Climbers',        'blurb' => 'You spent the year going up. The only direction you know.',       'match' => fn($s) => $vol($s) && $s['elo_delta'] >= 120],
        ['key' => 'chaos',          'name' => 'The Chaos Cartel',    'blurb' => 'Where you go, blue shells follow. Beautiful disaster.',           'match' => fn($s) => $s['lols'] >= 6],
        ['key' => 'podium',         'name' => 'The Podium Regulars', 'blurb' => 'Top three is your natural habitat.',                              'match' => fn($s) => $vol($s) && $s['podium_rate'] >= 0.65],
        ['key' => 'variety',        'name' => 'The Variety Pack',    'blurb' => 'A different racer every night. Commitment? Never heard of it.',   'match' => fn($s) => $s['distinct_chars'] >= 10],
        // ── Character identity — the everyday spread ──
        ['key' => 'spook',          'name' => 'The Spook Squad',     'blurb' => 'Boo, Dry Bones, King Boo — you race with the dead.',              'match' => fn($s) => $s['top_group'] === 'spooky'],
        ['key' => 'royals',         'name' => 'The Royal Court',     'blurb' => 'Peach, Daisy, Rosalina. You ride with the crown.',                'match' => fn($s) => $s['top_group'] === 'royals'],
        ['key' => 'reptiles',       'name' => 'The Reptile House',   'blurb' => 'Cold-blooded and quick. Scales over fur.',                        'match' => fn($s) => $s['top_group'] === 'reptiles'],
        ['key' => 'fungi',          'name' => 'The Fungi',           'blurb' => 'Toad, Toadette, Peachette. A real fun guy.',                      'match' => fn($s) => $s['top_group'] === 'fungi'],
        ['key' => 'heavies',        'name' => 'The Heavies',         'blurb' => 'Bowser, DK, Wario. You win on sheer mass.',                       'match' => fn($s) => $s['top_group'] === 'heavies'],
        ['key' => 'feathers',       'name' => 'The Featherweights',  'blurb' => 'Light, nimble, untouchable on the corners.',                      'match' => fn($s) => $s['top_group'] === 'babies'],
        ['key' => 'koopa',          'name' => 'The Koopa Klan',      'blurb' => 'Bowser and the brood. The dark side has cookies.',                'match' => fn($s) => $s['top_group'] === 'koopa_clan'],
        ['key' => 'og_stars',       'name' => 'The OG Stars',        'blurb' => 'Mario, Luigi, Peach, Daisy. Old reliable.',                       'match' => fn($s) => $s['top_group'] === 'og_stars'],
        ['key' => 'persons',        'name' => "The Just-A-Persons",  'blurb' => 'Mii, Inkling, Villager. Keeping it refreshingly human.',          'match' => fn($s) => $s['top_group'] === 'humans'],
        ['key' => 'furries',        'name' => 'The Furries',         'blurb' => 'Tanooki Mario, Cat Peach. Suspiciously fluffy.',                  'match' => fn($s) => $s['top_group'] === 'furry'],
        // ── Effort / playstyle, then broad fallbacks ──
        ['key' => 'grinders',       'name' => 'The Grinders',        'blurb' => 'You showed up more than almost anyone. The league runs on you.',  'match' => fn($s) => $s['attendance_rank'] <= 2 && $s['gps'] >= 15],
        ['key' => 'wildcards',      'name' => 'The Wildcards',       'blurb' => 'Nobody can predict your night. Including you.',                   'match' => fn($s) => $vol($s) && $s['std_dev'] >= 14],
        ['key' => 'specialists',    'name' => 'The Specialists',     'blurb' => 'One cup is basically your second home.',                          'match' => fn($s) => $vol($s) && $s['cup_concentration'] >= 0.40],
        ['key' => 'completionists', 'name' => 'The Completionists',  'blurb' => 'Every cup, every circuit. You leave no track unraced.',           'match' => fn($s) => $s['cups_raced'] >= 22],
        ['key' => 'perfectionists', 'name' => 'The Perfectionists', 'blurb' => 'You found the flawless 60 — and clearly liked the taste.',         'match' => fn($s) => $s['has_perfect']],
        ['key' => 'iron',           'name' => 'The Iron Riders',     'blurb' => 'Season after season, GP after GP. Built to last.',                'match' => fn($s) => $s['gps'] >= 20],
        ['key' => 'debutants',      'name' => 'The Debutants',       'blurb' => 'One GP, one story to tell. Everyone\'s first night looks like this.',      'match' => fn($s) => $s['gps'] === 1],
        ['key' => 'newcomers',      'name' => 'The Newcomers',       'blurb' => 'New to the grid this season — the field is still learning your name.', 'match' => fn($s) => $s['gps'] >= 2 && $s['gps'] < WRAPPED_MIN_GPS],
        ['key' => 'backmarkers',    'name' => "The Backmarkers' Union", 'blurb' => 'Results be damned — you turn up and you race. Respect.',       'match' => fn($s) => true], // default
    ];
}

/** ~9 Racing Personalities (the "Listening Personality" analog). */
function wrappedPersonalities(): array {
    return [
        // Newcomers get their own two so the default doesn't swallow them.
        ['key' => 'debut',       'label' => 'The Debut',       'blurb' => 'One night on the grid — the league has seen you now, and it will be asking when you are back.', 'match' => fn($s) => $s['gps'] === 1],
        ['key' => 'prospect',    'label' => 'The Prospect',    'blurb' => 'A few starts, a lot of upside. The regulars have started paying attention.',                 'match' => fn($s) => $s['gps'] >= 2 && $s['gps'] < WRAPPED_MIN_GPS],
        ['key' => 'frontrunner', 'label' => 'The Frontrunner', 'blurb' => 'When you race, you race to win — and you usually do.',          'match' => fn($s) => $s['win_rate'] >= 0.35],
        ['key' => 'specialist',  'label' => 'The Specialist',  'blurb' => 'Give you the right cup and the league is in trouble.',          'match' => fn($s) => $s['cup_concentration'] >= 0.40 && $s['wins'] >= 1],
        ['key' => 'comebackkid', 'label' => 'The Comeback Kid','blurb' => 'You are at your most dangerous when written off.',             'match' => fn($s) => $s['comeback']],
        ['key' => 'climber',     'label' => 'The Climber',     'blurb' => 'Every month a little better. The graph only points up.',       'match' => fn($s) => $s['elo_delta'] >= 100],
        ['key' => 'wildcard',    'label' => 'The Wildcard',    'blurb' => 'Brilliant one night, baffling the next. Never boring.',        'match' => fn($s) => $s['std_dev'] >= 14],
        ['key' => 'grinder',     'label' => 'The Grinder',     'blurb' => 'The most reliable seat on the grid. You never miss.',          'match' => fn($s) => $s['attendance_rank'] <= 2],
        ['key' => 'tortoise',    'label' => 'The Tortoise',    'blurb' => 'Slow and steady — and somehow still stealing wins.',           'match' => fn($s) => $s['avg'] < 38 && $s['wins'] >= 1],
        ['key' => 'ironheart',   'label' => 'The Ironheart',   'blurb' => 'Mid-pack and proud, the heartbeat of every race night.',       'match' => fn($s) => $s['gps'] >= 12],
        ['key' => 'steady',      'label' => 'The Steady Hand', 'blurb' => 'No drama, no fuss — just clean, consistent karting.',          'match' => fn($s) => true], // default
    ];
}
