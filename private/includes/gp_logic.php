<?php
/**
 * GPScore™ Logic Engine - Season-Aware Version
 * Path: /cdnmk/private/includes/gp_logic.php
 */

require_once __DIR__ . '/mk_data.php';   // cups, characters, MK_MAX_GP_POINTS, ordinal()

// ============================================================================
// SCORING SYSTEM REGISTRY
//
// Single source of truth: each scoring system declares its calculator,
// breakdown helper, display metadata, sort comparator, and threshold-gating
// behaviour in one place. All five legacy switches (calculateGPScore,
// getScoringSystemInfo, getScoringBreakdown, racerQualifies,
// sortStandingsByScoring — plus api/simulate_scoring.php) delegate here.
//
// Adding a new system = add one entry below + the calculate/breakdown fns.
// ============================================================================

/**
 * Returns the scoring system registry.
 *
 * Each entry:
 *   - name         : display name (string, may use $rules via 'name_fn' instead)
 *   - icon         : emoji
 *   - description  : string OR callable($rules) => string for dynamic copy
 *   - calculate    : callable($pdo, $racer_id, $season_id, $rules) => number
 *   - breakdown    : callable($pdo, $racer_id, $season_id, $rules) => array (components)
 *   - qualifies_by_threshold : bool — true means min_races_threshold gates podium eligibility
 *   - sort         : null (default sort by score desc, name asc)
 *                    or callable(array &$standings, PDO $pdo, string $season_id)
 */
function getScoringSystemRegistry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;

    //
    // Each entry now has THREE description-shaped fields. Keep them in sync —
    // they're the single source of truth for everything the user sees:
    //   - description       : one-liner shown on the homepage, /stats, tooltips
    //   - long_description  : multi-sentence rule explainer shown on /scoring
    //                         and in the admin settings panel info-text
    //   - admin_blurb       : (optional) override for the admin "create season"
    //                         dropdown when the long_description is overkill.
    //                         Falls back to `description` if not set.
    //
    $registry = [
        'average_attendance' => [
            'name'                   => 'Average + Attendance',
            'icon'                   => '📊',
            'description'            => 'Average GP score with attendance bonuses',
            'long_description'       => 'Average GP score with attendance bonuses and drop mechanics. Configure the drop rate, attendance weight, weekly cap, and minimum-races threshold below.',
            'calculate'              => 'calculateAverageAttendanceScore',
            'breakdown'              => 'breakdownAverageAttendance',
            'tooltip'              => 'tooltipAverageAttendance',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
        ],
        'preseason' => [
            'name'                   => 'Pre-Season',
            'icon'                   => '🌟',
            'description'            => 'Simple average with 10% drop',
            'long_description'       => 'Simple average with the worst 10% of scores dropped. No configuration needed — designed for off-season play.',
            'calculate'              => 'calculatePreSeasonScore',
            'breakdown'              => 'breakdownPreseason',
            'tooltip'              => 'tooltipPreseason',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
        ],
        'cup_based' => [
            'name'                   => 'Cup-Based',
            'icon'                   => '🏆',
            'description'            => fn($rules) => ($rules['cups_required'] ?? 12) . ' cups required',
            'long_description'       => 'Sum of best scores across all required cups (12 or 24). Each racer must complete the configured cup count to be eligible.',
            'calculate'              => 'calculateCupBasedScore',
            'breakdown'              => 'breakdownCupSeries',
            'tooltip'              => 'tooltipCupBased',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'best_n_gps' => [
            'name'                   => fn($rules) => 'Best ' . ($rules['best_n_count'] ?? 15) . ' GPs',
            'icon'                   => '⭐',
            'description'            => 'Sum of top GP scores',
            'long_description'       => 'Sum of your best N GP scores; the rest are dropped. Configure N below.',
            'calculate'              => 'calculateBestNGPsScore',
            'breakdown'              => 'breakdownBestNGPs',
            'tooltip'              => 'tooltipBestNGps',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'drop_worst' => [
            'name'                   => 'Drop Worst',
            'icon'                   => '🗑️',
            'description'            => fn($rules) => 'Drop ' . ($rules['drop_worst_count'] ?? 2) . ' worst cups',
            'long_description'       => 'Play all cups; drop the X worst scores. Configure the drop count below.',
            'calculate'              => 'calculateDropWorstScore',
            'breakdown'              => 'breakdownCupSeries',
            'tooltip'              => 'tooltipCupBased',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
        ],
        'perfect_hunt' => [
            'name'                   => 'Perfect Hunt',
            'icon'                   => '💎',
            'description'            => fn($rules) => 'Perfect 60s × ' . ($rules['perfect_multiplier'] ?? 2.0),
            'long_description'       => 'Bonus multipliers awarded for every perfect 60 score. Configure the multiplier and required cup count below.',
            'calculate'              => 'calculatePerfectHuntScore',
            'breakdown'              => 'breakdownCupSeries',
            'tooltip'              => 'tooltipCupBased',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'top_12_unique' => [
            'name'                   => 'Top 12 Unique',
            'icon'                   => '🎯',
            'description'            => 'Best 12 GPs from 12 separate cups',
            'long_description'       => 'Cumulative score from the best 12 GPs, each from a different cup. Tiebreaker: most perfect 60 scores in unique cups.',
            'calculate'              => 'calculateTop12UniqueScore',
            'breakdown'              => 'breakdownTop12Unique',
            'tooltip'              => 'tooltipTop12Unique',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => getTop12UniqueTiebreaker($pdo, (int)$rid, $season_id),
                'level'   => 'Level on points',
                'ahead'   => 'perfect 60s',
                'both'    => 'Level on points and perfect 60s',
            ],
        ],
        'random_cup_draw' => [
            'name'                   => 'Random Cup Draw',
            'icon'                   => '🎲',
            'description'            => 'Assigned random cups',
            'long_description'       => 'Each player will be assigned a random set of cups at season start.',
            'calculate'              => 'calculateRandomCupDrawScore',
            'breakdown'              => null,
            'tooltip'              => null,
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'black_box' => [
            'name'                   => 'Black Box',
            'icon'                   => '⬛',
            'description'            => 'Classified scoring formula',
            'long_description'       => 'ADMIN EYES ONLY. Players see only "Black Box Score" — no formula, no breakdown, no explanation. The formula applies diminishing returns to high scorers, momentum bonuses for improvement streaks, "chaos points" seeded from race dates, and a comeback multiplier that scales inversely with historical average. Net effect: the leaderboard feels plausible but unpredictable, and lower-ranked players punch above their weight.',
            'calculate'              => 'calculateBlackBoxScore',
            'breakdown'              => 'breakdownBlackBox',
            'tooltip'              => 'tooltipBlackBox',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'monster_hunt' => [
            'name'                   => 'MONSTER HUNT',
            'icon'                   => '👹',
            'description'            => 'Hunt XP — the highest-Elo racer becomes the Monster; adventurers slay them for XP',
            'long_description'       => 'The Monster is the highest-Elo racer at race time (ties broken alphabetically; admins can override by flagging is_monster on result entry). CR multiplier (×1.0–×2.0) scales slay XP by the Elo gap. Ranking = the sum of your best-N XP hauls, so extra nights can only help; your title is a separate skill track based on average XP across every GP played.',
            'calculate'              => 'calculateMonsterHuntScore',
            'breakdown'              => 'breakdownMonsterHunt',
            'tooltip'              => 'tooltipMonsterHunt',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'bounty_hunter' => [
            'name'                   => 'Bounty Hunter',
            'icon'                   => '🎯',
            'description'            => 'Collect Elo-above-median bounties from racers you beat',
            'long_description'       => 'Every racer above the field median (by pre-GP Elo) carries a bounty equal to their Elo above the median. Beat them in a GP to collect (full bounty per beater — no splitting). Optional carrying cost subtracts your own bounty from your night\'s haul.',
            'calculate'              => 'calculateBountyHunterScore',
            'breakdown'              => 'breakdownBountyHunter',
            'tooltip'              => 'tooltipBountyHunter',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'pari_mutuel' => [
            'name'                   => 'Pari-Mutuel',
            'icon'                   => '🐎',
            'description'            => fn($rules) => 'Ante ' . ($rules['pm_ante'] ?? 100) . ' pts per GP into a redistributable pot',
            'long_description'       => 'Every participant pays an ante per GP into a shared pot. The pot redistributes by finish position via the chosen payout curve. Net per GP = winnings − ante (can go negative). Season score is the sum of all GP nets.',
            'calculate'              => 'calculateParimutuelScore',
            'breakdown'              => 'breakdownParimutuel',
            'tooltip'              => 'tooltipPariMutuel',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'positional_points' => [
            'name'                   => 'Positional Points',
            'icon'                   => '🏁',
            'description'            => fn($rules) => 'Finish-position points · '
                                          . (($rules['pos_mode'] ?? 'best_n') === 'best_n'
                                                ? 'best ' . ($rules['best_n_count'] ?? 15)
                                                : (($rules['pos_mode'] ?? 'best_n') === 'average' ? 'per-GP average' : 'season sum')),
            'long_description'       => 'Relative scoring: each GP awards points by finish position on a fixed Mario Kart ladder (1st=15, 2nd=12, 3rd=10, 4th=9, …), so a win always banks the same regardless of margin. Season aggregation is configurable — best-N nights, per-GP average, or straight sum — with a minimum-GPs eligibility gate.',
            'calculate'              => 'calculatePositionalScore',
            'breakdown'              => 'breakdownPositional',
            'tooltip'              => 'tooltipPositional',
            'qualifies_by_threshold' => true,
            'sort'                   => 'sortStandingsPositional',
            'tie_explain'            => 'tieExplainPositional',
        ],
        'head_to_head' => [
            'name'                   => 'Head-to-Head',
            'icon'                   => '🤺',
            'description'            => fn($rules) => 'Win rate across every matchup · CPUs count ×' . rtrim(rtrim(number_format(h2hWeightFromRules($rules), 2, '.', ''), '0'), '.'),
            'long_description'       => 'Relative scoring built for small fields: in each GP you beat everyone you finish above and lose to everyone above you, and your score is your win rate across every matchup all season — margin-blind and attendance-fair. Other humans count as a full opponent; the CPU karts filling the 12-kart grid count a fraction (the NPC weight, default 0.25), so finishing 4th of 12 as the last human still beats finishing 12th. A minimum-GPs threshold filters small-sample flukes; ties break on total wins.',
            'calculate'              => 'calculateHeadToHeadScore',
            'breakdown'              => 'breakdownHeadToHead',
            'tooltip'              => 'tooltipHeadToHead',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => headToHeadRaw($pdo, (int)$rid, $season_id)['wins'],
                'level'   => 'Level on win rate',
                'ahead'   => 'total wins',
                'both'    => 'Level on win rate and wins',
                'fmt'     => 'scoreNum',
            ],
        ],
        'blue_shell' => [
            'name'                   => 'Blue Shell',
            'icon'                   => '🐢',
            'description'            => fn($rules) => 'Catch-up scoring · +' . (int)round(((float)($rules['bs_rate'] ?? 0.10)) * 100) . '% per place behind the leader',
            'long_description'       => "Mario Kart's own philosophy as a season system. Each GP your points are multiplied by how far behind the leader you sit in the standings at that moment: the leader always scores ×1.0, and every place further back adds the catch-up rate (default +10%), up to a cap. A racer 5th in the table banks a 40-point night as 56. The field compresses toward the top without anyone being handed points for nothing — and it re-evaluates every single GP, so a comeback that works stops being rewarded the moment you're back on top. Racers with no prior GP get ×1.0.",
            'calculate'              => 'calculateBlueShellScore',
            'breakdown'              => 'breakdownBlueShell',
            'tooltip'                => 'tooltipBlueShell',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => blueShellSeason($pdo, $season_id, (array)$rules)['raw'][(int)$rid] ?? 0,
                'level'   => 'Level on catch-up points',
                'ahead'   => 'raw points',
                'both'    => 'Level on points, raw too',
            ],
        ],
        'territory' => [
            'name'                   => 'Territory',
            'icon'                   => '🏰',
            'description'            => 'Hold the cups — score is how many you own',
            'long_description'       => "Each of the 24 cups is held by whoever has posted the best result on it this season, and your score is the number of cups you hold. A 57 on Star Cup holds it until someone posts a 57 or better — an equal score takes it, so even a perfect 60 can be answered by a 60. Holdings must be defended: if a cup is raced the configured number of times without its holder racing it, the best challenger across those nights takes it. Different from Top 12 Unique, which sums your own bests — here only the league-best on each cup counts, and showing up matters.",
            'calculate'              => 'calculateTerritoryScore',
            'breakdown'              => 'breakdownTerritory',
            'tooltip'                => 'tooltipTerritory',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => array_sum(territorySeason($pdo, $season_id, (array)$rules)['by_racer'][(int)$rid] ?? []),
                'level'   => 'Same number of cups held',
                'ahead'   => 'points across them',
                'both'    => 'Same cups held and points',
            ],
        ],
        'median' => [
            'name'                   => 'Median',
            'icon'                   => '⚖️',
            'description'            => 'Your median GP score — no drops, no best-N',
            'long_description'       => "Your score is the median of your GP scores. No drops, no best-N, no attendance lever: the median simply doesn't grow with more races. A racer at 12, 45, 48, 50, 60 scores 48 — one disaster can't sink you and one fluke can't carry you. The simplest system in the league and the fairest across uneven attendance. A minimum-GPs threshold keeps two-race medians off the board; ties break on the mean.",
            'calculate'              => 'calculateMedianScore',
            'breakdown'              => 'breakdownMedian',
            'tooltip'                => 'tooltipMedian',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => ($p = array_map(fn($r) => (int)$r['gp_points'], getRacerSeasonRows($pdo, (int)$rid, $season_id))) ? array_sum($p) / count($p) : 0,
                'level'   => 'Level on median',
                'ahead'   => 'mean',
                'both'    => 'Level on median and mean',
                'fmt'     => fn($v) => scoreNum(round($v, 1)),
                'differs' => fn($x, $y) => round($x, 2) != round($y, 2),
            ],
        ],
        'hard_mode' => [
            'name'                   => 'Hard Mode',
            'icon'                   => '🔥',
            'description'            => 'Points weighted by how hard the cup is league-wide',
            'long_description'       => "Every GP is multiplied by how hard that cup has proven to be for the whole league, measured from every result ever posted on it: multiplier = league average ÷ that cup's average. If the league averages 45 and Rainbow Road averages 31, Rainbow pays ×1.45; a cup that averages 52 pays ×0.87. Multipliers are clamped (floor ×0.5, ceiling by the cap knob) and a cup with fewer than five results counts ×1.0 until there's data. Rewards racing the hard stuff over farming easy cups. Ties break on raw points.",
            'calculate'              => 'calculateHardModeScore',
            'breakdown'              => 'breakdownHardMode',
            'tooltip'                => 'tooltipHardMode',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => array_sum(array_map(fn($r) => (int)$r['gp_points'], getRacerSeasonRows($pdo, (int)$rid, $season_id))),
                'level'   => 'Level on weighted points',
                'ahead'   => 'raw points',
                'both'    => 'Level on weighted and raw points',
            ],
        ],
        'form' => [
            'name'                   => 'Form',
            'icon'                   => '📈',
            'description'            => fn($rules) => 'Average of your last ' . (int)($rules['form_window'] ?? 8) . ' GPs — old results fall off',
            'long_description'       => "Your score is the average of your most recent GPs (the window, default 8). Older results fall off the back, so the standings always show who is hot right now rather than who banked a big spring. Attendance-fair — a window is a window — and brutal to anyone coasting on early-season form. Until you've raced a full window it's the average of what you have; a minimum-GPs threshold keeps one-night wonders off the board. Ties break on the most recent GP.",
            'calculate'              => 'calculateFormScore',
            'breakdown'              => 'breakdownForm',
            'tooltip'                => 'tooltipForm',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => ($f = formRows($pdo, (int)$rid, $season_id, (array)$rules)['all']) ? (int)end($f)['gp_points'] : 0,
                'level'   => 'Level on form',
                'ahead'   => 'most recent GP',
                'both'    => 'Level on form and latest GP',
            ],
        ],

        // ── The weird ones ──
        'kart_bingo' => [
            'name'                   => 'Kart Bingo',
            'icon'                   => '🎱',
            'description'            => fn($rules) => 'A seeded 3×3 card of targets — ' . (int)($rules['bg_line_pts'] ?? 100) . ' per line, ' . (int)($rules['bg_card_pts'] ?? 500) . ' for the card',
            'long_description'       => "Every racer gets a 3×3 bingo card for the season, dealt from a seed so it is fixed from day one: score exactly 45, finish 4th, post a 60, finish directly behind a named rival, race Leaf Cup, three GPs in one night… Squares tick off automatically from results. Each completed line (rows, columns, diagonals) is worth the line value, a full card adds the card bonus, and your plain average is the tiebreak underneath. Every quiet Tuesday becomes a card-chase; the standings reward the racer who plays to their card, not the one with the highest average.",
            'calculate'              => 'calculateBingoScore',
            'breakdown'              => 'breakdownBingo',
            'tooltip'                => 'tooltipBingo',
            'qualifies_by_threshold' => false,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => bingoProgress($pdo, (int)$rid, $season_id, (array)$rules)['done'],
                'level'   => 'Level on bingo points',
                'ahead'   => 'squares ticked',
                'both'    => 'Level on bingo points and squares',
            ],
        ],
        'price_is_right' => [
            'name'                   => 'The Price Is Right',
            'icon'                   => '🏷️',
            'description'            => fn($rules) => 'Closest to the GP\'s ' . (($rules['pir_target'] ?? 'median') === 'mean' ? 'mean' : 'median') . ' without going over wins it',
            'long_description'       => "Every GP has a hidden target: the median (or mean) score of the humans in it. Closest to the target without going over wins the GP; anyone who went over ranks behind everyone who didn't, by how far they overshot. GP finishes are paid on the Mario Kart ladder (15, 12, 10, 9…) and your season is the sum of your best N. A 57 on a night where the target is 44 finishes behind a 43. It rewards reading the room, not the racing line — and makes the last race of every GP a game of chicken.",
            'calculate'              => 'calculatePriceScore',
            'breakdown'              => 'breakdownPrice',
            'tooltip'                => 'tooltipPrice',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => priceIsRightSeason($pdo, $season_id, (array)$rules)['racers'][(int)$rid]['hits'] ?? 0,
                'level'   => 'Level on ladder points',
                'ahead'   => 'GPs won on the nose',
                'both'    => 'Level on ladder points and wins',
            ],
        ],
        'equaliser' => [
            'name'                   => 'The Great Equaliser',
            'icon'                   => '⚖️',
            'description'            => fn($rules) => 'League average minus your distance from it — being average wins' . (($rules['eq_mode'] ?? 'season') === 'per_gp' ? ' (judged every GP)' : ''),
            'long_description'       => "Your score is the league average minus how far your own average sits from it, above or below. The most average racer in the league wins the season; the best and the worst tie for last. In per-GP mode each night is judged against that night's average and the results are averaged, so a wild 60 and a dismal 20 hurt exactly as much. Consistency and mediocrity are rewarded in equal measure, and the standings chart becomes a single spike in the middle.",
            'calculate'              => 'calculateEqualiserScore',
            'breakdown'              => 'breakdownEqualiser',
            'tooltip'                => 'tooltipEqualiser',
            'qualifies_by_threshold' => true,
            'sort'                   => null,
            'tiebreak'               => [
                'metric'  => fn($pdo, $rid, $season_id, $rules) => count(getRacerSeasonRows($pdo, (int)$rid, $season_id)),
                'level'   => 'Equally average',
                'ahead'   => 'GPs raced',
                'both'    => 'Equally average over the same number of GPs',
            ],
        ],
    ];
    return $registry;
}

/** Look up a single registry entry, falling back to average_attendance. */
function getScoringSystemDef(string $system): array {
    $reg = getScoringSystemRegistry();
    return $reg[$system] ?? $reg['average_attendance'];
}

// ============================================================================
// SEASON RESULTS CACHE
//
// Leaderboard-style pages used to run 1–2 "this racer's season results"
// queries PER RACER (scores, breakdowns, race counts, characters, cup
// bests...). This cache fetches the whole season ONCE per request and serves
// per-racer slices, so all of those helpers share a single query.
// ============================================================================

/**
 * All results for a season, keyed by racer_id. One query per season per
 * request (static cache). Rows are ordered gp_points ASC (matching the
 * per-racer queries this replaces); equal-points order is stabilised by id.
 */
function getSeasonResultsByRacer($pdo, $season_id) {
    static $cache = [];
    if (!isset($cache[$season_id])) {
        // SELECT * so every consumer (scoring, breakdowns, badges) can read any
        // column — badges needs is_lol / kart_setup / id beyond the scoring set.
        $stmt = $pdo->prepare("
            SELECT *
            FROM results
            WHERE gpid LIKE ?
            ORDER BY gp_points ASC, id ASC
        ");
        $stmt->execute([$season_id . '%']);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['racer_id']][] = $row;
        }
        $cache[$season_id] = $map;
    }
    return $cache[$season_id];
}

/** One racer's season rows (gp_points ASC) from the shared cache. */
function getRacerSeasonRows($pdo, $racer_id, $season_id) {
    return getSeasonResultsByRacer($pdo, $season_id)[(int)$racer_id] ?? [];
}

/**
 * Calculates the GPScore for a specific racer within a specific season.
 * Now supports multiple scoring systems based on season_meta.scoring_system
 */
function calculateGPScore($pdo, $racer_id, $season_id) {
    $rules         = getSeasonRules($pdo, $season_id);
    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
    $def           = getScoringSystemDef($scoringSystem);

    $fn = $def['calculate'];
    return $fn($pdo, $racer_id, $season_id, $rules);
}

/**
 * Legacy System: Average + Attendance
 * (Average of scores after drops) + (Attendance bonus capped per week)
 */
// ============================================================================
// AVERAGE + ATTENDANCE (GPScore™) and PRE-SEASON — the formulas, once.
// Pure functions of a row bag (gp_points, race_date, id): drop the worst
// floor(n / drop_rate) GPs, average the rest, add a weekly-capped attendance
// bonus. The live calculator, the breakdown, previous-standings and every
// season-replay page (stats chart, season report, animate) call these. They
// used to be seven copies of the same 25 lines.
// ============================================================================

/** Rows in drop order: gp_points ASC, then id ASC (the season cache's order). */
function aaSortRows(array $rows): array {
    usort($rows, fn($a, $b) => ((int)$a['gp_points'] <=> (int)$b['gp_points']) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)));
    return $rows;
}

/**
 * ['score' (rounded 2dp), 'avg', 'att' (both unrounded), 'total',
 *  'num_dropped', 'dropped' => rows, 'counted' => rows]
 */
function aaFromRows(array $rows, array $rules): array {
    $attWeight = $rules['attendance_weight'] ?? 1.0;
    $weeklyCap = $rules['weekly_bonus_cap']  ?? 2;
    $dropRate  = $rules['drop_rate']         ?? 10;

    $rows      = aaSortRows($rows);
    $n         = count($rows);
    $numToDrop = ($dropRate > 0) ? (int)floor($n / $dropRate) : 0;
    $dropped   = array_slice($rows, 0, $numToDrop);
    $counted   = array_slice($rows, $numToDrop);
    $pts       = array_column($counted, 'gp_points');
    $avg       = $pts ? array_sum($pts) / count($pts) : 0;

    // Attendance bonus with a weekly cap (order-independent).
    $att = 0; $weekly = [];
    foreach ($rows as $r) {
        $wk = date('Y-W', strtotime($r['race_date']));
        $weekly[$wk] = $weekly[$wk] ?? 0;
        if ($weekly[$wk] < $weeklyCap) { $att += $attWeight; $weekly[$wk] += $attWeight; }
    }
    return ['score' => round($avg + $att, 2), 'avg' => $avg, 'att' => $att, 'total' => $n,
            'num_dropped' => $numToDrop, 'dropped' => $dropped, 'counted' => $counted];
}

/** Pre-Season: drop the worst 10 % (rounded down), plain average. Same keys as aaFromRows minus 'att'. */
function preseasonFromRows(array $rows): array {
    $rows      = aaSortRows($rows);
    $n         = count($rows);
    $numToDrop = (int)floor($n * 0.1);
    $dropped   = array_slice($rows, 0, $numToDrop);
    $counted   = array_slice($rows, $numToDrop);
    $pts       = array_column($counted, 'gp_points');
    $avg       = $pts ? array_sum($pts) / count($pts) : 0;
    return ['score' => round($avg, 2), 'avg' => $avg, 'total' => $n,
            'num_dropped' => $numToDrop, 'dropped' => $dropped, 'counted' => $counted];
}

function calculateAverageAttendanceScore($pdo, $racer_id, $season_id, $rules) {
    $threshold = $rules['min_races_threshold'] ?? 3;
    // Tournament gpids never enter the season cache (prefix filter is 's%').
    $results = getRacerSeasonRows($pdo, $racer_id, $season_id);
    // Ranking threshold: 0 until they've raced enough.
    if ($threshold > 0 && count($results) < $threshold) return 0;
    return aaFromRows($results, (array)$rules)['score'];
}

function calculatePreSeasonScore($pdo, $racer_id, $season_id, $rules) {
    $rows = getRacerSeasonRows($pdo, $racer_id, $season_id);
    if (!$rows) return 0;
    return preseasonFromRows($rows)['score'];
}

/**
 * Cup-Based Scoring: Best Score on Each Required Cup
 * Sum of best scores across all cups (12 or 24)
 */
function calculateCupBasedScore($pdo, $racer_id, $season_id, $rules) {
    $requiredCups = array_slice(getMKAllCups(), 0, $rules['cups_required'] ?? 12);
    $bestPerCup   = getBestScorePerCup($pdo, $racer_id, $season_id, $requiredCups);
    return round(array_sum(array_filter($bestPerCup)), 2);
}

/**
 * Best N GPs: Sum of Best N GP Scores
 * All other GPs dropped automatically
 */
function calculateBestNGPsScore($pdo, $racer_id, $season_id, $rules) {
    $bestN = $rules['best_n_count'] ?? 15;

    // Cache rows are gp_points ASC — reverse for the top N.
    $points = array_column(getRacerSeasonRows($pdo, $racer_id, $season_id), 'gp_points');
    $topScores = array_slice(array_reverse($points), 0, $bestN);

    if (empty($topScores)) return 0;

    return round(array_sum($topScores), 2);
}

/**
 * Drop Worst Cups: Play All Cups, Drop N Worst
 * More forgiving than strict cup-based
 */
function calculateDropWorstScore($pdo, $racer_id, $season_id, $rules) {
    $requiredCups   = array_slice(getMKAllCups(), 0, $rules['cups_required'] ?? 12);
    $dropWorstCount = $rules['drop_worst_count'] ?? 2;

    $cupScores = array_values(array_filter(getBestScorePerCup($pdo, $racer_id, $season_id, $requiredCups)));
    if (empty($cupScores)) return 0;

    sort($cupScores);
    return round(array_sum(array_slice($cupScores, $dropWorstCount)), 2);
}

/**
 * Perfect Hunt: Bonus Multipliers for Perfect 60 Scores
 * Cup-based with multipliers for excellence
 */
function calculatePerfectHuntScore($pdo, $racer_id, $season_id, $rules) {
    $requiredCups      = array_slice(getMKAllCups(), 0, $rules['cups_required'] ?? 12);
    $perfectMultiplier = $rules['perfect_multiplier'] ?? 2.0;

    $totalScore = 0;
    foreach (getBestScorePerCup($pdo, $racer_id, $season_id, $requiredCups) as $score) {
        if ($score === null) continue;
        $totalScore += ($score == MK_MAX_GP_POINTS) ? ($score * $perfectMultiplier) : $score;
    }
    return round($totalScore, 2);
}

/**
 * Top 12 Unique: Best 12 GPs from 12 Separate Cups
 * Takes the best score from each cup, picks the top 12.
 * Tiebreaker: most perfect 60 scores in unique cups.
 */
function calculateTop12UniqueScore($pdo, $racer_id, $season_id, $rules) {
    $cupBests = array_values(array_filter(getBestScorePerCup($pdo, $racer_id, $season_id, getMKAllCups())));
    if (empty($cupBests)) return 0;
    rsort($cupBests);
    return round(array_sum(array_slice($cupBests, 0, 12)), 2);
}

/**
 * Top 12 Unique Tiebreaker: count of perfect 60s in unique cups
 */
function getTop12UniqueTiebreaker($pdo, $racer_id, $season_id) {
    $bestPerCup = getBestScorePerCup($pdo, $racer_id, $season_id, getMKAllCups());
    return count(array_filter($bestPerCup, fn($s) => $s === MK_MAX_GP_POINTS));
}

/**
 * Black Box: Opaque scoring system with hidden equalizer mechanics.
 *
 * The formula is intentionally obscure and favors lower-ranked players.
 * Components:
 *   1. Base Score: sqrt(gp_points) * 7.7  — diminishing returns at the top
 *   2. Comeback Multiplier: players with lower career averages get a boost (1.0 to 1.35)
 *   3. Momentum Bonus: improving over your own recent average earns extra points
 *   4. Chaos Points: deterministic pseudo-random offset seeded from racer_id + race_date
 *   5. Consistency Tax: very high variance in scores gets a small penalty
 *   6. Attendance Curve: logarithmic bonus for showing up (rewards regulars, but saturates)
 *
 * The result looks like a real score but the math is deliberately hard to reverse-engineer.
 */
function calculateBlackBoxScore($pdo, $racer_id, $season_id, $rules) {
    // Cache rows are gp_points ASC; Black Box needs race_date ASC (momentum
    // bonus walks results chronologically), so re-sort the slice.
    $results = getRacerSeasonRows($pdo, $racer_id, $season_id);
    usort($results, function ($a, $b) {
        if ($a['race_date'] !== $b['race_date']) return strcmp($a['race_date'], $b['race_date']);
        return $a['gp_points'] <=> $b['gp_points'];
    });

    $totalRaces = count($results);
    if ($totalRaces === 0) return 0;
    if ($totalRaces < 3) return 0; // Min threshold built-in

    // --- Career average (across ALL seasons) for comeback multiplier ---
    // One GROUP BY query for every racer, cached for the request.
    static $careerAvgs = null;
    if ($careerAvgs === null) {
        $careerAvgs = [];
        $caStmt = $pdo->query("SELECT racer_id, AVG(gp_points) AS career_avg FROM results WHERE gpid LIKE 's%' GROUP BY racer_id");
        foreach ($caStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $careerAvgs[(int)$row['racer_id']] = (float)$row['career_avg'];
        }
    }
    $careerAvg = $careerAvgs[(int)$racer_id] ?? 40.0;

    // Comeback multiplier: lower career avg = higher multiplier (range: 1.0 to 1.35)
    // A 60-avg player gets 1.0, a 20-avg player gets ~1.32
    $comebackMultiplier = 1.0 + max(0, (50 - $careerAvg) / 50) * 0.45;
    $comebackMultiplier = min($comebackMultiplier, 1.35);

    $runningTotal = 0;
    $points = array_column($results, 'gp_points');
    $mean = array_sum($points) / count($points);

    // --- Consistency Tax: standard deviation penalty ---
    $variance = 0;
    foreach ($points as $p) {
        $variance += ($p - $mean) ** 2;
    }
    $stdDev = sqrt($variance / count($points));
    // High variance (>12) starts to cost you; very consistent players are rewarded
    $consistencyFactor = 1.0 - max(0, ($stdDev - 10) * 0.008);
    $consistencyFactor = max(0.92, $consistencyFactor);

    // --- Process each GP ---
    $recentScores = [];
    foreach ($results as $i => $res) {
        $pts = (float)$res['gp_points'];
        $date = $res['race_date'];

        // 1. Base Score: square root diminishing returns
        //    A 60 becomes ~59.7, a 45 becomes ~51.6, a 30 becomes ~42.1, a 15 becomes ~29.8
        $baseScore = sqrt($pts) * 7.7;

        // 2. Momentum Bonus: if you beat your rolling 3-game average, get a bonus
        $momentumBonus = 0;
        if (count($recentScores) >= 3) {
            $recentAvg = array_sum(array_slice($recentScores, -3)) / 3;
            if ($pts > $recentAvg) {
                $improvement = $pts - $recentAvg;
                $momentumBonus = $improvement * 0.3; // 30% of improvement as bonus
            }
        }

        // 3. Chaos Points: deterministic but looks random
        //    Uses racer_id and date to seed a "random-looking" offset of -1.5 to +3.5
        $dateHash = crc32($racer_id . $date . $i);
        $chaosPoints = (($dateHash % 500) / 100) - 1.5; // Range: -1.5 to +3.49

        // 4. Sum this GP's contribution
        $gpContribution = ($baseScore + $momentumBonus + $chaosPoints) * $comebackMultiplier;
        $runningTotal += max(0, $gpContribution);

        $recentScores[] = $pts;
    }

    // Apply consistency factor
    $runningTotal *= $consistencyFactor;

    // 5. Attendance Curve: log bonus (shows up a lot = good, but diminishing)
    $attendanceBonus = log($totalRaces + 1, 2) * 3.3;

    $finalScore = $runningTotal / $totalRaces + $attendanceBonus;

    return round($finalScore, 2);
}

/**
 * Random Cup Draw: Each Player Assigned Random Cups
 * Scoring based on assigned cups only
 */
function calculateRandomCupDrawScore($pdo, $racer_id, $season_id, $rules) {
    // Get assigned cups from JSON
    $assignedCupsJSON = $rules['random_cups_assigned'] ?? '{}';
    $assignments = json_decode($assignedCupsJSON, true);

    $racerCups = $assignments[$racer_id] ?? [];

    if (empty($racerCups)) {
        // No assignment yet, return 0
        return 0;
    }

    $bestPerCup = getBestScorePerCup($pdo, $racer_id, $season_id, $racerCups);
    return round(array_sum(array_filter($bestPerCup)), 2);
}

/**
 * MONSTER HUNT: XP-based scoring where each GP has a Monster (the
 * highest-Elo participant) and Adventurers (everyone else). XP depends
 * on outcomes vs. the Monster.
 *
 * Ranking is by average XP per GP (min GPs required to rank).
 * Challenge Rating (CR) multiplies Adventurer slay XP based on Elo gap.
 *   CR1 (<50 gap)   × 1.0
 *   CR2 (50–150)    × 1.25
 *   CR3 (150–300)   × 1.5
 *   CR4 (300+)      × 2.0
 */

/**
 * Pick the Monster for a GP. Always the highest-Elo participant going
 * into the GP. Ties broken alphabetically by name (rare; needed for a
 * stable deterministic result).
 *
 * An explicit admin override exists: if a racer was flagged is_monster=1
 * when the GP was logged, that racer is the Monster regardless of Elo.
 * This lets admins force a Monster role for special events.
 *
 * $gpData: array keyed by racer name with {old_elo, rank, ...}
 * Returns [$monsterName, $monsterElo] or [null, PHP_INT_MIN] if empty.
 */
function pickMonster($gpid, array $gpData, $pdo = null) {
    // Explicit override: if a racer was flagged is_monster=1 when the GP was
    // logged, use them. The flag lookup is cached per gpid — mhComputeRaw
    // calls this for every (racer × GP) pair, which used to mean hundreds of
    // identical queries per leaderboard render.
    if ($pdo !== null) {
        // Prime ALL flagged GPs with one query on first call — this function
        // runs for every (racer × GP) pair on a MONSTER HUNT leaderboard.
        static $flagCache = null;
        if ($flagCache === null) {
            $flagCache = [];
            $rows = $pdo->query("
                SELECT res.gpid, r.name FROM results res
                JOIN racers r ON res.racer_id = r.id
                WHERE res.is_monster = 1
                ORDER BY res.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                // First flagged row per gpid — matches the old LIMIT 1.
                if (!isset($flagCache[$row['gpid']])) $flagCache[$row['gpid']] = $row['name'];
            }
        }
        $flagged = $flagCache[$gpid] ?? false;
        if ($flagged && isset($gpData[$flagged])) {
            return [$flagged, $gpData[$flagged]['old_elo']];
        }
    }

    // Default: highest-Elo participant. Alphabetical tiebreak.
    if (empty($gpData)) return [null, PHP_INT_MIN];
    $monsterName = null;
    $monsterElo  = PHP_INT_MIN;
    foreach ($gpData as $name => $d) {
        if ($d['old_elo'] > $monsterElo
            || ($d['old_elo'] === $monsterElo && ($monsterName === null || strcmp($name, $monsterName) < 0))) {
            $monsterElo  = $d['old_elo'];
            $monsterName = $name;
        }
    }
    return [$monsterName, $monsterElo];
}

/**
 * Cached Elo changelog keyed by gpid → racer name → {old_elo, gp_points, rank}
 */
function getMonsterHuntEloChangelog($pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (!function_exists('calculateAllELORatings')) {
        require_once __DIR__ . '/elo_engine.php';
    }

    $data = calculateAllELORatings($pdo);
    $cached = [];
    foreach ($data['gp_changelog'] as $gpLog) {
        $cached[$gpLog['gpid']] = [];
        foreach ($gpLog['racers'] as $racer) {
            $cached[$gpLog['gpid']][$racer['name']] = [
                'old_elo'  => $racer['old'],
                'gp_points' => $racer['points'],
                'rank'     => $racer['rank'],
            ];
        }
    }
    return $cached;
}

/**
 * Internal: compute raw MONSTER HUNT totals for a racer.
 * Statically cached per racer+season so score + display helpers
 * never run the GP loop twice in the same request.
 */
/** Challenge Rating from the Elo gap Monster → average adventurer: [tier 1-4, XP multiplier]. */
function mhCrTier(float $gap): array {
    if ($gap < 50)  return [1, 1.0];
    if ($gap < 150) return [2, 1.25];
    if ($gap < 300) return [3, 1.5];
    return [4, 2.0];
}

/**
 * THE MONSTER HUNT engine: every hunt of a season, fully resolved, once per
 * request. Each entry:
 *   gpid, date, cup, solo (only one human raced — straight survive XP),
 *   players (names), elos (name => Elo before the GP), ranks (name => finish),
 *   monster, monster_elo, monster_rank, gap, cr_tier, cr_mult,
 *   slayers (beat the Monster), survivors (didn't), full_slay, tpk,
 *   xp (name => XP earned this GP).
 * The Monster is pickMonster(): the is_monster admin flag when set, otherwise
 * the highest Elo with an alphabetical tiebreak.
 *
 * This is the ONLY place the walk lives. It used to be copied into
 * scoring.php, stats.php, mh_dashboard.php, badges.php and badges_overview.php,
 * and three of those copies picked the Monster by raw Elo, ignoring the
 * admin flag — on s03 (31 flagged GPs) that credited XP to the wrong racer on
 * 12 of them. Chronological (race_date, gpid).
 */
function mhSeasonHunts($pdo, string $season_id, array $rules): array {
    static $cache = [];
    $knobs = ['mh_slay_xp', 'mh_survive_xp', 'mh_party_bonus_xp', 'mh_monster_win_xp', 'mh_monster_partial_xp', 'mh_monster_loss_xp'];
    $key = $season_id . ':' . json_encode(array_intersect_key($rules, array_flip($knobs)));
    if (isset($cache[$key])) return $cache[$key];

    $slay_xp         = (int)($rules['mh_slay_xp']           ?? 100);
    $survive_xp      = (int)($rules['mh_survive_xp']         ?? 20);
    $party_bonus_xp  = (int)($rules['mh_party_bonus_xp']     ?? 50);
    $monster_win_xp  = (int)($rules['mh_monster_win_xp']     ?? 80);
    $monster_part_xp = (int)($rules['mh_monster_partial_xp'] ?? 30);
    $monster_loss_xp = (int)($rules['mh_monster_loss_xp']    ?? -40);

    $changelog = getMonsterHuntEloChangelog($pdo);

    // Season GP list is identical for every caller — fetch once per season.
    static $seasonGPCache = [];
    if (!isset($seasonGPCache[$season_id])) {
        $gpStmt = $pdo->prepare("
            SELECT gpid, MIN(race_date) AS race_date, MAX(cup_name) AS cup_name
            FROM results
            WHERE gpid LIKE ?
            GROUP BY gpid
            ORDER BY race_date ASC, gpid ASC
        ");
        $gpStmt->execute([$season_id . '%']);
        $seasonGPCache[$season_id] = $gpStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $hunts = [];
    foreach ($seasonGPCache[$season_id] as $gp) {
        $gpid = $gp['gpid'];
        if (!isset($changelog[$gpid])) continue;
        $gpData = $changelog[$gpid];

        $h = [
            'gpid' => $gpid, 'date' => $gp['race_date'], 'cup' => $gp['cup_name'],
            'solo' => false, 'players' => array_keys($gpData), 'elos' => [], 'ranks' => [],
            'monster' => null, 'monster_elo' => null, 'monster_rank' => null,
            'gap' => 0.0, 'cr_tier' => null, 'cr_mult' => null,
            'slayers' => [], 'survivors' => [], 'full_slay' => false, 'tpk' => false, 'xp' => [],
        ];
        foreach ($gpData as $name => $d) { $h['elos'][$name] = $d['old_elo']; $h['ranks'][$name] = $d['rank']; }

        if (count($gpData) < 2) {
            $h['solo'] = true;
            foreach ($gpData as $name => $d) $h['xp'][$name] = $survive_xp;
            $hunts[] = $h;
            continue;
        }

        [$monsterName, $monsterElo] = pickMonster($gpid, $gpData, $pdo);
        $monsterRank = $gpData[$monsterName]['rank'];

        $adventurerElos = [];
        foreach ($gpData as $name => $d) if ($name !== $monsterName) $adventurerElos[] = $d['old_elo'];
        $avgAdvElo = count($adventurerElos) > 0 ? array_sum($adventurerElos) / count($adventurerElos) : $monsterElo;
        $gap = max(0, $monsterElo - $avgAdvElo);
        [$crTier, $crMult] = mhCrTier((float)$gap);

        foreach ($gpData as $name => $d) {
            if ($name === $monsterName) continue;
            if ($d['rank'] < $monsterRank) $h['slayers'][] = $name; else $h['survivors'][] = $name;
        }
        $fullSlay = (count($h['survivors']) === 0 && count($h['slayers']) > 0); // all adventurers beat Monster
        $isTpk    = (count($h['slayers']) === 0);                                // Monster beat all (TPK)

        foreach ($gpData as $name => $d) {
            if ($name === $monsterName) {
                if ($isTpk)        $xp = $monster_win_xp;
                elseif ($fullSlay) $xp = $monster_loss_xp;
                else               $xp = $monster_part_xp;
            } elseif ($d['rank'] < $monsterRank) {
                $xp = (int)round($slay_xp * $crMult);
                if ($fullSlay) $xp += $party_bonus_xp;
            } else {
                $xp = $survive_xp;
            }
            $h['xp'][$name] = $xp;
        }

        $h['monster'] = $monsterName; $h['monster_elo'] = $monsterElo; $h['monster_rank'] = $monsterRank;
        $h['gap'] = $gap; $h['cr_tier'] = $crTier; $h['cr_mult'] = $crMult;
        $h['full_slay'] = $fullSlay; $h['tpk'] = $isTpk;
        $hunts[] = $h;
    }
    return $cache[$key] = $hunts;
}

function mhComputeRaw($pdo, $racer_id, $season_id, $rules) {
    static $cache = [];
    $key = "{$racer_id}:{$season_id}";
    if (isset($cache[$key])) return $cache[$key];

    $racerName = racerNamesMap($pdo)[(int)$racer_id] ?? null;
    if (!$racerName) {
        return $cache[$key] = ['total_xp' => 0, 'gps' => 0];
    }

    $totalXP  = 0;
    $racerGPs = 0;
    $xpPerGP  = []; // gpid => xp earned
    foreach (mhSeasonHunts($pdo, $season_id, (array)$rules) as $h) {
        if (!isset($h['xp'][$racerName])) continue;
        $racerGPs++;
        $totalXP += $h['xp'][$racerName];
        $xpPerGP[$h['gpid']] = $h['xp'][$racerName];
    }
    return $cache[$key] = ['total_xp' => $totalXP, 'gps' => $racerGPs, 'xp_per_gp' => $xpPerGP];
}

function calculateMonsterHuntScore($pdo, $racer_id, $season_id, $rules) {
    $best_x = max(1, (int)($rules['mh_best_x'] ?? 20));
    $raw    = mhComputeRaw($pdo, $racer_id, $season_id, $rules);
    if ($raw['gps'] === 0) return 0;

    $xpValues = array_values($raw['xp_per_gp']);
    rsort($xpValues);
    $topX = array_slice($xpValues, 0, $best_x);
    return round(array_sum($topX), 2);
}

/** Level 0–20 from accumulated XP using a sqrt curve (lv 20 = 8,000 XP). */
function getMonsterHuntLevel($total_xp) {
    return min(20, (int)floor(sqrt(max(0, $total_xp) / 20)));
}

/** Title based on avg XP/GP — the skill track. */
function getMonsterHuntTitle($avg_xp_per_gp) {
    if ($avg_xp_per_gp < 25)  return 'Commoner';
    if ($avg_xp_per_gp < 40)  return 'Chicken Chaser';
    if ($avg_xp_per_gp < 55)  return 'Rat Catcher';
    if ($avg_xp_per_gp < 70)  return 'Monster Hunter';
    if ($avg_xp_per_gp < 85)  return 'Slayer';
    if ($avg_xp_per_gp < 105) return 'Apex Predator';
    return 'Nemesis';
}

/** All MONSTER HUNT display data for a racer (uses cached raw computation). */
function getMonsterHuntDisplayData($pdo, $racer_id, $season_id, $rules) {
    $raw    = mhComputeRaw($pdo, $racer_id, $season_id, $rules);
    $best_x = max(1, (int)($rules['mh_best_x'] ?? 20));
    $avgXP  = $raw['gps'] > 0 ? round($raw['total_xp'] / $raw['gps'], 2) : 0;

    $xpValues = array_values($raw['xp_per_gp']);
    rsort($xpValues);
    $bestXSum  = round(array_sum(array_slice($xpValues, 0, $best_x)), 2);
    $bestXUsed = min($raw['gps'], $best_x); // how many hunts actually counted

    return [
        'system'      => 'monster_hunt',
        'total_xp'    => $raw['total_xp'],
        'gps'         => $raw['gps'],
        'avg_xp'      => $avgXP,
        'best_x'      => $best_x,
        'best_x_sum'  => $bestXSum,
        'best_x_used' => $bestXUsed,
        'level'       => getMonsterHuntLevel($raw['total_xp']),
        'title'       => getMonsterHuntTitle($avgXP),
    ];
}

// ============================================================================
// BOUNTY HUNTER
//
// Every above-median Elo racer carries a bounty equal to their Elo above the
// field median at GP time. Adventurers who finish ahead of a bounty target
// each collect that bounty (full, not split — keeps things dramatic).
// Optional carrying cost subtracts your own bounty from your night's haul if
// you don't end up beating anyone "important."
// ============================================================================

/**
 * Raw per-GP bounty haul for a racer in a season.
 * Returns ['per_gp' => [gpid => net_points], 'total' => sum, 'gps' => count].
 */
function bountyHunterRaw(PDO $pdo, int $racer_id, string $season_id, array $rules): array {
    static $cache = [];
    $key = "$racer_id|$season_id";
    if (isset($cache[$key])) return $cache[$key];

    $multiplier   = (float)($rules['bh_multiplier']    ?? 1.0);
    $carryingCost = (bool)  ($rules['bh_carrying_cost'] ?? 0);

    $changelog = getMonsterHuntEloChangelog($pdo);
    $racerNameStmt = $pdo->prepare("SELECT name FROM racers WHERE id = ?");
    $racerNameStmt->execute([$racer_id]);
    $racerName = $racerNameStmt->fetchColumn();
    if (!$racerName) return $cache[$key] = ['per_gp' => [], 'total' => 0, 'gps' => 0];

    // Walk all season GPs the racer participated in.
    $gpStmt = $pdo->prepare("SELECT DISTINCT gpid FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'");
    $gpStmt->execute([$racer_id, $season_id . '%']);
    $myGPs = $gpStmt->fetchAll(PDO::FETCH_COLUMN);

    $perGP   = [];
    $total   = 0;
    foreach ($myGPs as $gpid) {
        if (!isset($changelog[$gpid][$racerName])) continue;
        $gpData = $changelog[$gpid];
        if (count($gpData) < 2) {
            $perGP[$gpid] = 0;
            continue;
        }

        // Field median Elo (pre-GP) for this race.
        $elos = array_column($gpData, 'old_elo');
        sort($elos);
        $n      = count($elos);
        $median = ($n % 2 === 1)
            ? $elos[(int)floor($n / 2)]
            : ($elos[$n / 2 - 1] + $elos[$n / 2]) / 2;

        $myRank = $gpData[$racerName]['rank'];

        // Collect bounty for every racer above the median that we beat.
        $haul = 0;
        foreach ($gpData as $name => $d) {
            if ($name === $racerName) continue;
            $bounty = max(0, $d['old_elo'] - $median);
            if ($bounty > 0 && $d['rank'] > $myRank) {
                $haul += (int)round($bounty * $multiplier);
            }
        }

        // Carrying cost: if I'm above-median, my own bounty subtracts from
        // tonight's haul. Encourages strong players to actually hunt the
        // strong instead of farming the bottom.
        if ($carryingCost) {
            $myBounty = max(0, $gpData[$racerName]['old_elo'] - $median);
            $haul -= (int)round($myBounty * $multiplier);
        }

        $perGP[$gpid] = $haul;
        $total       += $haul;
    }

    return $cache[$key] = ['per_gp' => $perGP, 'total' => $total, 'gps' => count($perGP)];
}

function calculateBountyHunterScore($pdo, $racer_id, $season_id, $rules) {
    $raw = bountyHunterRaw($pdo, (int)$racer_id, $season_id, $rules);
    return (int)$raw['total'];
}

function breakdownBountyHunter($pdo, $racer_id, $season_id, $rules) {
    $raw = bountyHunterRaw($pdo, (int)$racer_id, $season_id, $rules);
    $hauls = array_values($raw['per_gp']);
    $best = !empty($hauls) ? max($hauls) : 0;
    $worst = !empty($hauls) ? min($hauls) : 0;
    return [
        'gps_played'    => $raw['gps'],
        'total_bounty'  => $raw['total'],
        'best_haul'     => $best,
        'worst_haul'    => $worst,
        'multiplier'    => (float)($rules['bh_multiplier']    ?? 1.0),
        'carrying_cost' => (bool)  ($rules['bh_carrying_cost'] ?? 0),
    ];
}

// ============================================================================
// PARI-MUTUEL
//
// Every participant pays a flat ante per GP into a shared pot. The pot
// redistributes by finish position according to a payout curve. Net per GP
// = winnings − ante. Season total can go negative.
// ============================================================================

/** Payout-curve presets keyed by name. Each is a list of fractions summing to 1.0. */
function pariMutuelPresets(): array {
    return [
        'steep'  => [0.50, 0.30, 0.15, 0.05],                   // top 4 get paid
        'medium' => [0.35, 0.22, 0.16, 0.12, 0.08, 0.05, 0.02], // top 7 get paid
        'flat'   => [0.20, 0.16, 0.14, 0.12, 0.10, 0.09, 0.08, 0.06, 0.05], // top 9
    ];
}

/**
 * Raw per-GP pari-mutuel result for a racer in a season.
 * Returns ['per_gp' => [gpid => net], 'total' => sum, 'gps' => count].
 */
function pariMutuelRaw(PDO $pdo, int $racer_id, string $season_id, array $rules): array {
    static $cache = [];
    $key = "$racer_id|$season_id";
    if (isset($cache[$key])) return $cache[$key];

    $ante     = (int)($rules['pm_ante']          ?? 100);
    $presets  = pariMutuelPresets();
    $preset   = $presets[$rules['pm_payout_preset'] ?? 'steep'] ?? $presets['steep'];

    // Pull every GP this racer participated in, then for each one count
    // participants + this racer's rank.
    $sql = "
        SELECT res.gpid,
               (SELECT COUNT(*) FROM results WHERE gpid = res.gpid) AS participants,
               res.rank
        FROM results res
        WHERE res.racer_id = ? AND res.gpid LIKE ? AND res.gpid LIKE 's%'
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$racer_id, $season_id . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $perGP = [];
    $total = 0;
    foreach ($rows as $row) {
        $n          = (int)$row['participants'];
        $myRank     = (int)$row['rank'];
        $pot        = $ante * $n;
        $share      = $preset[$myRank - 1] ?? 0; // ranks past the curve get 0
        $winnings   = (int)round($pot * $share);
        $net        = $winnings - $ante;
        $perGP[$row['gpid']] = $net;
        $total     += $net;
    }

    return $cache[$key] = ['per_gp' => $perGP, 'total' => $total, 'gps' => count($perGP)];
}

function calculateParimutuelScore($pdo, $racer_id, $season_id, $rules) {
    $raw = pariMutuelRaw($pdo, (int)$racer_id, $season_id, $rules);
    return (int)$raw['total'];
}

function breakdownParimutuel($pdo, $racer_id, $season_id, $rules) {
    $raw = pariMutuelRaw($pdo, (int)$racer_id, $season_id, $rules);
    $nets = array_values($raw['per_gp']);
    $best = !empty($nets) ? max($nets) : 0;
    $worst = !empty($nets) ? min($nets) : 0;
    return [
        'gps_played'  => $raw['gps'],
        'total_net'   => $raw['total'],
        'best_haul'   => $best,
        'worst_haul'  => $worst,
        'ante'        => (int)($rules['pm_ante'] ?? 100),
        'preset'      => $rules['pm_payout_preset'] ?? 'steep',
    ];
}

// ============================================================================
// POSITIONAL POINTS  ("Podium League")  — RELATIVE
//
// Each GP awards points by FINISH POSITION on a fixed Mario-Kart ladder
// (1st=15, 2nd=12, 3rd=10, 4th=9, …), NOT by raw GP points — so a win always
// banks the same regardless of margin. Season aggregation is a per-season knob
// (pos_mode): 'best_n' (sum of top N nights, reuses best_n_count), 'average'
// (points ÷ GPs played), or 'sum' (every night). Eligibility uses the shared
// min_races_threshold gate (qualifies_by_threshold = true).
// ============================================================================

/** The canonical Mario Kart 12-place points ladder — Positional Points and Mikkoliiga both rank on it. */
const MK_POINTS_SCALE = [15, 12, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];

/** Points for a finishing place on MK_POINTS_SCALE (0 beyond 12th). */
function mkPointsForRank(int $rank): int {
    return MK_POINTS_SCALE[$rank - 1] ?? 0;
}

/**
 * Per-GP positional points for a racer. Reads the per-request season-results
 * cache (the season prefix already excludes tournament 't…' GPIDs).
 * Returns ['per_gp' => [gpid => pts], 'sorted_desc' => [pts…], 'gps' => n].
 */
function positionalPointsRaw(PDO $pdo, int $racer_id, string $season_id): array {
    static $cache = [];
    $key = "$racer_id|$season_id";
    if (isset($cache[$key])) return $cache[$key];

    $perGP = [];
    foreach (getRacerSeasonRows($pdo, $racer_id, $season_id) as $row) {
        $perGP[$row['gpid']] = mkPointsForRank((int)$row['rank']);
    }
    $sorted = array_values($perGP);
    rsort($sorted); // best nights first, for best-N
    return $cache[$key] = ['per_gp' => $perGP, 'sorted_desc' => $sorted, 'gps' => count($perGP)];
}

function calculatePositionalScore($pdo, $racer_id, $season_id, $rules) {
    $raw  = positionalPointsRaw($pdo, (int)$racer_id, $season_id);
    $vals = $raw['sorted_desc'];
    if (empty($vals)) return 0;

    switch ($rules['pos_mode'] ?? 'best_n') {
        case 'sum':
            return array_sum($vals);
        case 'average':
            return round(array_sum($vals) / max(1, $raw['gps']), 1);
        case 'best_n':
        default:
            $n = (int)($rules['best_n_count'] ?? 15);
            if ($n < 1) $n = 15;
            return array_sum(array_slice($vals, 0, $n));
    }
}

function breakdownPositional($pdo, $racer_id, $season_id, $rules) {
    $raw  = positionalPointsRaw($pdo, (int)$racer_id, $season_id);
    $vals = $raw['sorted_desc'];
    $wins = 0;
    foreach ($raw['per_gp'] as $pts) {
        if ($pts === MK_POINTS_SCALE[0]) $wins++; // top of the ladder = a GP win
    }
    $mode     = $rules['pos_mode'] ?? 'best_n';
    $bestN    = (int)($rules['best_n_count'] ?? 15);
    $counted  = $mode === 'best_n' ? min($bestN, $raw['gps']) : $raw['gps'];
    // The lowest points value that still counts — i.e. what a new night has to
    // beat to displace one. Only meaningful once best-N is actually binding.
    $cutLine  = ($mode === 'best_n' && $counted > 0 && $raw['gps'] > $bestN) ? $vals[$counted - 1] : null;
    return [
        'mode'         => $mode,
        'gps_played'   => $raw['gps'],
        'total_points' => array_sum($vals),
        'best_night'   => !empty($vals) ? $vals[0] : 0,
        'best_n'       => $bestN,
        'counted'      => $counted,
        'cut_line'     => $cutLine,
        'wins'         => $wins,
        'score'        => calculatePositionalScore($pdo, $racer_id, $season_id, $rules),
    ];
}

/**
 * Per-GP Positional Points detail for the /scoring explainer: every GP this
 * racer ran, with the ladder points it earned, ordered best-first and flagged
 * counted / cut. Ordering is explicit (pts desc, then date, then gpid) so the
 * cut line never moves between requests.
 */
function positionalPointsDetail(PDO $pdo, int $racer_id, string $season_id, array $rules): array {
    $rows = [];
    foreach (getRacerSeasonRows($pdo, $racer_id, $season_id) as $r) {
        $rank = (int)$r['rank'];
        $rows[] = [
            'gpid' => $r['gpid'],
            'date' => $r['race_date'],
            'cup'  => $r['cup_name'] ?? '',
            'rank' => $rank,
            'pts'  => mkPointsForRank($rank),
        ];
    }
    usort($rows, function ($a, $b) {
        if ($a['pts'] !== $b['pts']) return $b['pts'] <=> $a['pts'];
        if ($a['date'] !== $b['date']) return strcmp($a['date'], $b['date']);
        return strcmp($a['gpid'], $b['gpid']);
    });

    $mode = $rules['pos_mode'] ?? 'best_n';
    $n    = (int)($rules['best_n_count'] ?? 15);
    if ($n < 1) $n = 15;
    $countedCount = ($mode === 'best_n') ? min($n, count($rows)) : count($rows);

    $posCounts = array_fill(1, count(MK_POINTS_SCALE), 0);
    foreach ($rows as $i => &$row) {
        $row['counted'] = $i < $countedCount;
        if (isset($posCounts[$row['rank']])) $posCounts[$row['rank']]++;
    }
    unset($row);

    return [
        'rows'          => $rows,
        'counted_count' => $countedCount,
        'mode'          => $mode,
        'best_n'        => $n,
        'pos_counts'    => $posCounts,
        'cut_line'      => ($countedCount > 0 && count($rows) > $countedCount) ? $rows[$countedCount - 1]['pts'] : null,
    ];
}

/**
 * Positional Points standings sort. Tie-break chain:
 *   1. score (the aggregated positional total) — highest first
 *   2. count-back — most 1st-place finishes, then most 2nds, then 3rds … (the
 *      F1 method: rewards the better peaks when totals are level)
 *   3. fewest GPs to make the top score — i.e. fewest GPs that actually count
 *      toward it (best-N → min(GPs, N); sum/average → all GPs). Banking the
 *      same total off fewer counted nights ranks higher.
 *   4. fewer GPs played overall — efficiency over total volume
 *   5. name A→Z — deterministic final fallback
 * Counts/GPs come from this season's results only (the season prefix already
 * excludes tournament 't…' GPIDs).
 */
function sortStandingsPositional(array &$standings, PDO $pdo, string $season_id): void {
    $rules = getSeasonRules($pdo, $season_id);
    $mode  = $rules['pos_mode'] ?? 'best_n';
    $n     = max(1, (int)($rules['best_n_count'] ?? 15));

    foreach ($standings as &$s) {
        $counts = array_fill(1, count(MK_POINTS_SCALE), 0);   // finishes by place 1..12
        $gps = 0;
        foreach (getRacerSeasonRows($pdo, $s['id'], $season_id) as $row) {
            $r = (int)$row['rank'];
            if ($r >= 1 && $r <= count(MK_POINTS_SCALE)) $counts[$r]++;
            $gps++;
        }
        $s['_posCounts']  = $counts;
        $s['_posGps']     = $gps;
        // GPs that actually contribute to the score (the "N cups" that make it).
        $s['_posCounted'] = ($mode === 'best_n') ? min($gps, $n) : $gps;
    }
    unset($s);

    usort($standings, function ($a, $b) {
        if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
        for ($p = 1; $p <= count(MK_POINTS_SCALE); $p++) {                       // count-back
            if ($a['_posCounts'][$p] !== $b['_posCounts'][$p]) {
                return $b['_posCounts'][$p] <=> $a['_posCounts'][$p];
            }
        }
        if ($a['_posCounted'] !== $b['_posCounted']) return $a['_posCounted'] <=> $b['_posCounted']; // fewest counted GPs
        if ($a['_posGps']     !== $b['_posGps'])     return $a['_posGps']     <=> $b['_posGps'];     // fewer total GPs
        return strcmp($a['name'], $b['name']);
    });
}

// ============================================================================
// HEAD-TO-HEAD  ("Duels")  — RELATIVE
//
// In each GP you "beat" everyone you finish above and "lose" to everyone above
// you. Season score is your WIN RATE across every matchup — margin-blind and
// attendance-fair (a ratio). Humans count 1; the CPU karts filling the rest of
// the 12-kart grid count h2h_npc_weight (0 = pure human duels, 1 = every kart
// is an opponent). The min_races_threshold gate filters small-sample flukes;
// ties break on absolute wins then name.
//
// HISTORY: this used to compute wins = (humans in GP) - (12-kart rank), i.e. it
// subtracted a grid position from a head count. Any human who finished behind
// an NPC went NEGATIVE (Ola, s05: 3 humans, 6th of 12 -> -3 wins, -60%). The
// grid below compares against the other humans' actual ranks instead.
// ============================================================================

/** Karts on a Mario Kart grid — humans plus CPU fillers. Shared with elo_engine.php (which guards the same define). */
if (!defined('ELO_FIELD_SIZE')) define('ELO_FIELD_SIZE', 12);

/** NPC weight for seasons that predate the knob. */
const H2H_NPC_WEIGHT_DEFAULT = 0.25;

/** Clamp a rules array's NPC weight to 0..1 (missing/blank -> default). */
function h2hWeightFromRules(array $rules): float {
    $w = $rules['h2h_npc_weight'] ?? null;
    if ($w === null || $w === '') $w = H2H_NPC_WEIGHT_DEFAULT;
    return max(0.0, min(1.0, (float)$w));
}

/**
 * Per-GP grid for a season: gpid => [racer_id => 12-kart rank]. Built once per
 * request from the shared season-results cache — no per-racer queries.
 */
function headToHeadGrid(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];
    $grid = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        foreach ($rows as $row) $grid[$row['gpid']][(int)$rid] = (int)$row['rank'];
    }
    return $cache[$season_id] = $grid;
}

/**
 * Aggregate head-to-head record for a racer in a season.
 * $npcWeight overrides the season's saved knob (the simulator passes its own).
 * Returns wins/losses/matchups (weighted, 2dp), gps, rate (0-100, 1dp), plus
 * the raw human/NPC splits so the breakdown can explain the number.
 */
function headToHeadRaw(PDO $pdo, int $racer_id, string $season_id, ?float $npcWeight = null): array {
    $w = $npcWeight ?? h2hWeightFromRules(getSeasonRules($pdo, $season_id));
    static $cache = [];
    $key = "$racer_id|$season_id|$w";
    if (isset($cache[$key])) return $cache[$key];

    $wins = 0.0; $losses = 0.0; $gps = 0;
    $hw = 0; $hl = 0; $nw = 0; $nl = 0;
    foreach (headToHeadGrid($pdo, $season_id) as $ranks) {
        if (!isset($ranks[$racer_id])) continue;
        $mine = $ranks[$racer_id];

        // Humans beaten / humans who beat me, from their real grid positions.
        $hb = 0; $ha = 0;
        foreach ($ranks as $rid => $rk) {
            if ($rid === $racer_id) continue;
            if ($rk > $mine) $hb++; elseif ($rk < $mine) $ha++;
        }
        // Everyone else on the grid is a CPU kart.
        $nb = max(0, (ELO_FIELD_SIZE - $mine) - $hb);
        $na = max(0, ($mine - 1) - $ha);

        $gpWins = $hb + $w * $nb;
        $gpLoss = $ha + $w * $na;
        if ($gpWins + $gpLoss <= 0) continue; // solo GP at weight 0: no duels

        $wins += $gpWins; $losses += $gpLoss;
        $hw += $hb; $hl += $ha; $nw += $nb; $nl += $na;
        $gps++;
    }
    $matchups = $wins + $losses;
    $rate = $matchups > 0 ? round($wins / $matchups * 100, 1) : 0.0;
    return $cache[$key] = [
        'wins' => round($wins, 2), 'losses' => round($losses, 2), 'matchups' => round($matchups, 2),
        'gps' => $gps, 'rate' => $rate,
        'human_wins' => $hw, 'human_losses' => $hl, 'npc_wins' => $nw, 'npc_losses' => $nl,
        'npc_weight' => $w,
    ];
}

function calculateHeadToHeadScore($pdo, $racer_id, $season_id, $rules) {
    // Weight comes from the rules handed in, so the simulator's override applies.
    return headToHeadRaw($pdo, (int)$racer_id, $season_id, h2hWeightFromRules((array)$rules))['rate'];
}

function breakdownHeadToHead($pdo, $racer_id, $season_id, $rules) {
    $raw = headToHeadRaw($pdo, (int)$racer_id, $season_id, h2hWeightFromRules((array)$rules));
    return [
        'wins'         => $raw['wins'],
        'losses'       => $raw['losses'],
        'matchups'     => $raw['matchups'],
        'gps_played'   => $raw['gps'],
        'win_rate'     => $raw['rate'],
        'human_wins'   => $raw['human_wins'],
        'human_losses' => $raw['human_losses'],
        'npc_wins'     => $raw['npc_wins'],
        'npc_losses'   => $raw['npc_losses'],
        'npc_weight'   => $raw['npc_weight'],
    ];
}


/**
 * Returns current season ID. Prefers the season whose start_date/end_date
 * window contains today so prep seasons don't hijack the live one; falls
 * back to the latest non-archived season, then the latest season overall.
 */
function getCurrentSeasonNumber() {
    static $cached = null;
    if ($cached !== null) return $cached;

    global $pdo;
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT season_id FROM season_meta
            WHERE status != 'archived'
              AND start_date IS NOT NULL AND end_date IS NOT NULL
              AND date('now') BETWEEN start_date AND end_date
            ORDER BY season_id DESC LIMIT 1
        ");
        $result = $stmt->fetchColumn();
        if ($result) {
            $cached = $result;
            return $cached;
        }
        $stmt = $pdo->query("SELECT season_id FROM season_meta WHERE status != 'archived' ORDER BY season_id DESC LIMIT 1");
        $result = $stmt->fetchColumn();
        if ($result) {
            $cached = $result;
            return $cached;
        }
        // Fallback: latest season overall
        $stmt = $pdo->query("SELECT season_id FROM season_meta ORDER BY season_id DESC LIMIT 1");
        $result = $stmt->fetchColumn();
        if ($result) {
            $cached = $result;
            return $cached;
        }
    }

    $cached = "s01";
    return $cached;
}

/**
 * Cached season rules fetcher. Returns the season_meta row for $season_id.
 * Memoised per-request so every scoring function shares one DB round-trip.
 */
function getSeasonRules($pdo, $season_id) {
    static $cache = [];
    if (!array_key_exists($season_id, $cache)) {
        $stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
        $stmt->execute([$season_id]);
        $cache[$season_id] = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return $cache[$season_id];
}


/**
 * Best gp_points per cup for a racer — computed from the shared season
 * cache, so leaderboard pages don't pay one query per racer.
 * Returns array keyed by cup name; value is int best score, or null if none.
 */
function getBestScorePerCup($pdo, $racer_id, $season_id, array $cups) {
    if (empty($cups)) return [];
    $wanted = array_flip($cups);
    $result = array_fill_keys($cups, null);
    foreach (getRacerSeasonRows($pdo, $racer_id, $season_id) as $row) {
        $cup = $row['cup_name'];
        if ($cup === null || !isset($wanted[$cup])) continue;
        $pts = (int)$row['gp_points'];
        if ($result[$cup] === null || $pts > $result[$cup]) $result[$cup] = $pts;
    }
    return $result;
}

/**
 * Get Cup Progress for Cup-Based Scoring Systems
 * Returns detailed progress for each cup.
 * $offset = 0 → base cups, $offset = 12 → DLC cups.
 */
function getCupProgress($pdo, $racer_id, $season_id, $cupsRequired = 12, $offset = 0) {
    $requiredCups = array_slice(getMKAllCups(), $offset, $cupsRequired);
    if (empty($requiredCups)) return [];

    // Per-cup stats + best gpid, computed from the shared season cache
    // (previously 2 queries per racer per call).
    $wanted = array_flip($requiredCups);
    $stats = [];
    $bestGpids = [];
    foreach (getRacerSeasonRows($pdo, $racer_id, $season_id) as $row) {
        $cup = $row['cup_name'];
        if ($cup === null || !isset($wanted[$cup])) continue;
        $pts = (int)$row['gp_points'];
        if (!isset($stats[$cup])) {
            $stats[$cup] = ['best_score' => $pts, 'attempts' => 0, 'last_played' => $row['race_date']];
            $bestGpids[$cup] = $row['gpid'];
        }
        $stats[$cup]['attempts']++;
        if ($row['race_date'] > $stats[$cup]['last_played']) {
            $stats[$cup]['last_played'] = $row['race_date'];
        }
        // Strict > keeps the FIRST max-scoring row in id order (rows are
        // gp_points ASC, id ASC) — matching the earliest-GP pick the old
        // GROUP BY query made on score ties (verified against all racers).
        if ($pts > $stats[$cup]['best_score']) {
            $stats[$cup]['best_score'] = $pts;
            $bestGpids[$cup] = $row['gpid'];
        }
    }

    $progress = [];
    foreach ($requiredCups as $cupName) {
        $s    = $stats[$cupName] ?? null;
        $best = $s ? (int)$s['best_score'] : 0;
        $progress[$cupName] = [
            'best_score'            => $best,
            'attempts'              => $s ? (int)$s['attempts'] : 0,
            'completed'             => $best > 0,
            'last_played'           => $s['last_played'] ?? null,
            'best_gpid'             => $bestGpids[$cupName] ?? null,
            'improvement_potential' => MK_MAX_GP_POINTS - $best,
            'is_perfect'            => $best === MK_MAX_GP_POINTS,
        ];
    }

    return $progress;
}

/** Thin wrapper — DLC cups are at offset 12. */
function getDLCCupProgress($pdo, $racer_id, $season_id) {
    return getCupProgress($pdo, $racer_id, $season_id, 12, 12);
}

/**
 * Get Scoring System Details for Display
 * Returns human-readable info about current season's scoring
 */
function getScoringSystemInfo($pdo, $season_id) {
    $rules = getSeasonRules($pdo, $season_id);

    if (!$rules) {
        $def = getScoringSystemDef('average_attendance');
        return [
            'system'           => 'average_attendance',
            'name'             => $def['name'],
            'description'      => 'Default scoring system',
            'long_description' => $def['long_description'] ?? '',
            'icon'             => $def['icon'],
        ];
    }

    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
    $def           = getScoringSystemDef($scoringSystem);

    // Resolve dynamic (callable) name / description against the rules.
    $name = is_callable($def['name']) ? ($def['name'])($rules) : $def['name'];
    $desc = is_callable($def['description']) ? ($def['description'])($rules) : $def['description'];

    return [
        'system'           => $scoringSystem,
        'name'             => $name,
        'description'      => $desc,
        'long_description' => $def['long_description'] ?? '',
        'icon'             => $def['icon'],
        'rules'            => $rules,
    ];
}

/**
 * Get Detailed Scoring Breakdown for a Racer
 * Returns component scores for display
 */
function getScoringBreakdown($pdo, $racer_id, $season_id) {
    $rules         = getSeasonRules($pdo, $season_id);
    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
    $def           = getScoringSystemDef($scoringSystem);

    $components = [];
    if ($def['breakdown'] !== null) {
        $fn         = $def['breakdown'];
        $components = $fn($pdo, $racer_id, $season_id, $rules);
    }

    return [
        'system'      => $scoringSystem,
        'total_score' => calculateGPScore($pdo, $racer_id, $season_id),
        'components'  => $components,
    ];
}

/**
 * One-line, human-readable "how this score happened" summary — the standings
 * hover text. Dispatches through the registry's 'tooltip' entry, so a new
 * scoring system explains itself by adding one key (never by editing pages).
 * Takes an ALREADY-COMPUTED breakdown so callers pay no extra queries.
 */
function scoringTooltipFromBreakdown(array $bd): string {
    $system = $bd['system'] ?? 'average_attendance';
    $def    = getScoringSystemDef($system);
    $c      = $bd['components'] ?? [];
    $score  = $bd['total_score'] ?? 0;

    $fn = $def['tooltip'] ?? null;
    if ($fn !== null && function_exists($fn)) {
        return $fn($c, $score);
    }
    // Systems with no bespoke summary (or no breakdown at all) still get the
    // system name and their score rather than another system's formula.
    return sprintf('%s %s · Score: %s', $def['icon'], $def['name'], scoreNum($score));
}

/** Trim trailing zeros so scores read 207 / 12.5 rather than 207.00 / 12.50. */
function scoreNum($v): string {
    $s = number_format((float)$v, 2, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

// ── Tooltip helpers (registry-referenced) ────────────────────────────────
// Each takes the system's breakdown components + final score.

function tooltipAverageAttendance(array $c, $score): string {
    return sprintf('Avg: %.2f (%d GPs counted, %d dropped) + Attendance: %.2f = %.2f',
        $c['avg'] ?? 0, $c['races_counted'] ?? 0, $c['races_dropped'] ?? 0, $c['att'] ?? 0, $score);
}

function tooltipPreseason(array $c, $score): string {
    return sprintf('Average: %.2f (%d GPs, %d dropped)',
        $score, $c['total_races'] ?? 0, $c['races_dropped'] ?? 0);
}

function tooltipCupBased(array $c, $score): string {
    return sprintf('Cups: %d/%d completed · Score: %.2f',
        $c['cups_completed'] ?? 0, $c['cups_required'] ?? 0, $score);
}

function tooltipBestNGps(array $c, $score): string {
    return sprintf('Best %d GPs: %.2f (%d total GPs, %d dropped)',
        $c['best_n_count'] ?? 0, $score, $c['total_gps_played'] ?? 0, $c['gps_dropped'] ?? 0);
}

function tooltipTop12Unique(array $c, $score): string {
    return sprintf('Top 12 Unique: %d cups played, best %d counted, %d perfects (tiebreaker) · Score: %d',
        $c['cups_played'] ?? 0, $c['cups_counted'] ?? 0, $c['unique_60s'] ?? 0, (int)$score);
}

function tooltipBlackBox(array $c, $score): string {
    return sprintf('⬛ Black Box Score: %.2f (%d GPs)', $score, $c['gps_played'] ?? 0);
}

function tooltipMonsterHunt(array $c, $score): string {
    return sprintf('👹 %s (lv. %d) · Best %d hunts: %d XP · %.1f avg XP/GP · %d total XP · %d GPs played',
        $c['title'] ?? '', $c['level'] ?? 0, $c['best_x_used'] ?? 0, $c['best_x_sum'] ?? 0,
        $c['avg_xp'] ?? 0, $c['total_xp'] ?? 0, $c['gps'] ?? 0);
}

function tooltipBountyHunter(array $c, $score): string {
    $s = sprintf('🎯 %s bounty collected over %d GPs · best night %s · ×%s multiplier',
        scoreNum($c['total_bounty'] ?? 0), $c['gps_played'] ?? 0,
        scoreNum($c['best_haul'] ?? 0), scoreNum($c['multiplier'] ?? 1));
    if (!empty($c['carrying_cost'])) $s .= ' · carrying cost on';
    return $s;
}

function tooltipPariMutuel(array $c, $score): string {
    return sprintf('🎰 Net %s over %d GPs · best night %s · worst %s · ante %s (%s payouts)',
        scoreNum($c['total_net'] ?? 0), $c['gps_played'] ?? 0,
        scoreNum($c['best_haul'] ?? 0), scoreNum($c['worst_haul'] ?? 0),
        scoreNum($c['ante'] ?? 0), $c['preset'] ?? 'standard');
}

/**
 * Positional Points — the standings hover has to answer "where did my number
 * come from", so it states the aggregation mode, how many nights counted,
 * the ladder, and what it takes to improve (the cut line).
 */
function tooltipPositional(array $c, $score): string {
    $gps    = (int)($c['gps_played'] ?? 0);
    $mode   = $c['mode'] ?? 'best_n';
    $wins   = (int)($c['wins'] ?? 0);
    $parts  = [];

    if ($mode === 'average') {
        $parts[] = sprintf('🏁 %s pts per GP — %s total across %d GP%s',
            scoreNum($score), scoreNum($c['total_points'] ?? 0), $gps, $gps === 1 ? '' : 's');
    } elseif ($mode === 'sum') {
        $parts[] = sprintf('🏁 %s pts — every one of %d GP%s counts',
            scoreNum($score), $gps, $gps === 1 ? '' : 's');
    } else {
        $counted = (int)($c['counted'] ?? 0);
        if ($gps > $counted) {
            $parts[] = sprintf('🏁 %s pts — best %d of %d GPs counted', scoreNum($score), $counted, $gps);
            $parts[] = sprintf('%d dropped', $gps - $counted);
        } else {
            // Under the best-N ceiling, so nothing is being dropped yet.
            $parts[] = sprintf('🏁 %s pts — all %d GP%s counted (best %d would count)',
                scoreNum($score), $gps, $gps === 1 ? '' : 's', (int)($c['best_n'] ?? 0));
        }
    }

    $parts[] = sprintf('%d win%s', $wins, $wins === 1 ? '' : 's');

    // What it takes to move the number. Once every counted night is already a
    // win there's no headroom left, so say that instead of "beat 15 pts".
    $cut = (int)($c['cut_line'] ?? 0);
    if ($cut > 0) {
        $parts[] = $cut >= MK_POINTS_SCALE[0]
            ? 'every counted night is a win — maxed out'
            : sprintf('beat %d pts to improve on a counted night', $cut);
    }

    return implode(' · ', $parts);
}

function tooltipHeadToHead(array $c, $score): string {
    // win_rate is already a 0-100 percentage (headToHeadRaw).
    $w = (float)($c['npc_weight'] ?? H2H_NPC_WEIGHT_DEFAULT);
    $s = sprintf('🤺 %.1f%% win rate · %d GP%s · vs humans %d–%d',
        $c['win_rate'] ?? 0, $c['gps_played'] ?? 0, ($c['gps_played'] ?? 0) === 1 ? '' : 's',
        $c['human_wins'] ?? 0, $c['human_losses'] ?? 0);
    if ($w > 0) {
        $s .= sprintf(' · vs CPUs %d–%d at ×%s', $c['npc_wins'] ?? 0, $c['npc_losses'] ?? 0, scoreNum($w));
    }
    return $s;
}

// ── Breakdown helpers (registry-referenced) ──────────────────────────────

function breakdownAverageAttendance($pdo, $racer_id, $season_id, $rules) {
    $aa = aaFromRows(getRacerSeasonRows($pdo, $racer_id, $season_id), (array)$rules);
    return [
        'total_races'   => $aa['total'],
        'races_counted' => $aa['total'] - $aa['num_dropped'],
        'races_dropped' => $aa['num_dropped'],
        'avg'           => $aa['counted'] ? round($aa['avg'], 2) : 0,
        'att'           => round($aa['att'], 2),
    ];
}

function breakdownPreseason($pdo, $racer_id, $season_id, $rules) {
    $ps = preseasonFromRows(getRacerSeasonRows($pdo, $racer_id, $season_id));
    return [
        'total_races'   => $ps['total'],
        'races_counted' => $ps['total'] - $ps['num_dropped'],
        'races_dropped' => $ps['num_dropped'],
    ];
}

/** Shared by cup_based, drop_worst, perfect_hunt — they all show cup progress. */
function breakdownCupSeries($pdo, $racer_id, $season_id, $rules) {
    $cupsRequired  = $rules['cups_required'] ?? 12;
    $progress      = getCupProgress($pdo, $racer_id, $season_id, $cupsRequired);
    $cupsCompleted = count(array_filter($progress, fn($c) => $c['completed']));
    return [
        'cups_required'   => $cupsRequired,
        'cups_completed'  => $cupsCompleted,
        'completion_rate' => round(($cupsCompleted / $cupsRequired) * 100, 1),
        'cup_details'     => $progress,
    ];
}

function breakdownBestNGPs($pdo, $racer_id, $season_id, $rules) {
    $bestN = $rules['best_n_count'] ?? 15;
    $totalGPs = count(getRacerSeasonRows($pdo, $racer_id, $season_id));
    return [
        'best_n_count'     => $bestN,
        'total_gps_played' => $totalGPs,
        'gps_dropped'      => max(0, $totalGPs - $bestN),
    ];
}

function breakdownTop12Unique($pdo, $racer_id, $season_id, $rules) {
    $bestPerCup = getBestScorePerCup($pdo, $racer_id, $season_id, getMKAllCups());
    $cupsPlayed = count(array_filter($bestPerCup));
    return [
        'cups_played'  => $cupsPlayed,
        'cups_counted' => min($cupsPlayed, 12),
        'unique_60s'   => getTop12UniqueTiebreaker($pdo, $racer_id, $season_id),
    ];
}

function breakdownBlackBox($pdo, $racer_id, $season_id, $rules) {
    return ['gps_played' => count(getRacerSeasonRows($pdo, $racer_id, $season_id))];
}

function breakdownMonsterHunt($pdo, $racer_id, $season_id, $rules) {
    return getMonsterHuntDisplayData($pdo, $racer_id, $season_id, $rules);
}

// ============================================================================
// CHARACTER HELPERS
// ============================================================================

/** Normalise colour variants: "Yoshi (Orange)" → "Yoshi", "Birdo (Blue)" → "Birdo". */
function normalizeCharacterName($name) {
    return preg_replace('/^(Yoshi|Birdo)\s*\(.+\)$/u', '$1', $name ?? '');
}

/** Character group lists for badge logic. */
function getCharacterGroups() {
    return [
        'babies'     => ['Baby Mario', 'Baby Luigi', 'Baby Peach', 'Baby Daisy', 'Baby Rosalina'],
        'heavies'    => ['Bowser', 'Dry Bowser', 'Morton', 'Wario', 'Donkey Kong', 'Funky Kong'],
        'spooky'     => ['Boo', 'Dry Bones', 'King Boo'],
        'og_stars'   => ['Mario', 'Luigi', 'Peach', 'Daisy'],
        'royals'     => ['Peach', 'Daisy', 'Rosalina'],
        'fungi'      => ['Toad', 'Toadette', 'Peachette'],
        'humans'     => ['Mii', 'Inkling Boy', 'Inkling Girl', 'Villager', 'Villager (M)', 'Villager (F)'],
        'furry'      => ['Tanooki Mario', 'Cat Peach'],
        'koopa_clan' => ['Bowser', 'Dry Bowser', 'Bowser Jr.', 'Koopa Troopa', 'Lakitu', 'Larry', 'Roy', 'Wendy', 'Ludwig', 'Iggy', 'Morton', 'Lemmy', 'Kamek', 'Dry Bones'],
        'reptiles'   => ['Yoshi', 'Birdo', 'Koopa Troopa', 'Dry Bones', 'Lakitu',
                         'Bowser', 'Dry Bowser', 'Bowser Jr.',
                         'Larry', 'Roy', 'Wendy', 'Ludwig', 'Iggy', 'Morton', 'Lemmy', 'Kamek'],
    ];
}

// ============================================================================
// SHARED QUERY HELPERS
// ============================================================================

/** Most-used character for a racer in a season (falls back to 'Mii'). */
function getMostUsedCharacter($pdo, $racer_id, $season_id) {
    // First try the racer's most-used character in THIS season, computed
    // from the shared season cache (no per-racer query). Ties break
    // alphabetically, matching SQLite's GROUP BY emit order.
    $tally = [];
    foreach (getRacerSeasonRows($pdo, $racer_id, $season_id) as $row) {
        $c = $row['character_used'] ?? '';
        $tally[$c] = ($tally[$c] ?? 0) + 1;
    }
    if (!empty($tally)) {
        // Tie-break: alphabetically LAST — matches what SQLite's GROUP BY /
        // ORDER BY COUNT(*) DESC emitted for this data (verified by the
        // task-13 regression run), so no racer's portrait changes.
        krsort($tally, SORT_STRING);
        arsort($tally);                       // stable in PHP 8 → count DESC, ties krsort order
        $char = array_key_first($tally);
        if ($char) return $char;
    }

    // Fallback: racer's most-used character across their ENTIRE career.
    // This keeps signature portraits (e.g. a Mikkoliigan who hasn't raced
    // this season yet) instead of dumping everyone to the generic Mii.
    // One GROUP BY query covers every racer; cached for the request.
    static $careerChars = null;
    if ($careerChars === null) {
        $careerChars = [];
        $rows = $pdo->query("
            SELECT racer_id, character_used, COUNT(*) AS plays
            FROM results
            GROUP BY racer_id, character_used
            ORDER BY racer_id, plays DESC, character_used DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $rid = (int)$row['racer_id'];
            // First row per racer = their top career group — which, like the
            // old per-racer LIMIT 1 query, may be NULL/'' (handled below).
            if (!array_key_exists($rid, $careerChars)) {
                $careerChars[$rid] = $row['character_used'];
            }
        }
    }
    $careerChar = $careerChars[(int)$racer_id] ?? null;
    if ($careerChar) return $careerChar;

    // Last resort: generic Mii portrait.
    return 'Mii';
}

// ============================================================================
// MIKKOLIIGA — parallel casual sub-league.
//
// Mikkoliiga is opt-in (racers.in_mikkoliiga = 1). Members race in the same
// GPs as the main league, but score internally: in each GP, only Mikkoliiga
// members are considered, re-ranked by their actual gp_points among each
// other, and awarded the canonical Mario Kart 12-position scale below. The
// season standing is the sum of a member's best MIKKOLIIGA_BEST_X scores.
// ============================================================================


/** How many of a member's GPs count toward their season total. Drives every
 *  user-visible "best N counted" string too — change here, change everywhere. */
const MIKKOLIIGA_BEST_X = 10;


/**
 * Mikkoliiga membership for a season.
 *
 * Archived seasons use the immutable snapshot in mikkoliiga_membership
 * (captured at season-close), so historical standings don't shift when a
 * member toggles their flag later. Live / upcoming seasons read the
 * current racers.in_mikkoliiga flag.
 *
 * Returns a set keyed by racer_id (values are true) for fast lookup.
 */
function getMikkoliigaMemberIds(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];

    // Is this season archived?
    $stmt = $pdo->prepare("SELECT status FROM season_meta WHERE season_id = ?");
    $stmt->execute([$season_id]);
    $status = $stmt->fetchColumn();

    $ids = [];
    if ($status === 'archived') {
        // Snapshot. If the season was archived before Mikkoliiga existed
        // (or before this snapshot system did), fall back to the live flag
        // so the sidebar isn't blank — but log nothing, because that's
        // acceptable behaviour for a never-snapshotted historical season.
        $snap = $pdo->prepare("SELECT racer_id FROM mikkoliiga_membership WHERE season_id = ?");
        $snap->execute([$season_id]);
        $snapIds = $snap->fetchAll(PDO::FETCH_COLUMN);

        if (empty($snapIds)) {
            $live = $pdo->query("SELECT id FROM racers WHERE in_mikkoliiga = 1");
            foreach ($live->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                $ids[(int)$rid] = true;
            }
        } else {
            foreach ($snapIds as $rid) {
                $ids[(int)$rid] = true;
            }
        }
    } else {
        // Live flag.
        $live = $pdo->query("SELECT id FROM racers WHERE in_mikkoliiga = 1");
        foreach ($live->fetchAll(PDO::FETCH_COLUMN) as $rid) {
            $ids[(int)$rid] = true;
        }
    }
    return $cache[$season_id] = $ids;
}

/**
 * Snapshot the current Mikkoliiga roster into the season-locked membership
 * table. Idempotent — re-running replaces any existing snapshot for the
 * season so re-closing a season after a flag change picks up the latest.
 */
function snapshotMikkoliigaMembership(PDO $pdo, string $season_id): int {
    $pdo->prepare("DELETE FROM mikkoliiga_membership WHERE season_id = ?")
        ->execute([$season_id]);

    $ins = $pdo->prepare("INSERT INTO mikkoliiga_membership (season_id, racer_id) VALUES (?, ?)");
    $ids = $pdo->query("SELECT id FROM racers WHERE in_mikkoliiga = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $rid) {
        $ins->execute([$season_id, (int)$rid]);
    }
    return count($ids);
}

/**
 * Per-GP internal Mikkoliiga score for a single racer in a single season.
 * Returns gpid => internal_points, sorted by gpid ascending.
 *
 * Membership is taken from the snapshot for archived seasons and from the
 * live flag for active ones — see getMikkoliigaMemberIds().
 */
function mikkoliigaScorePerGP(PDO $pdo, int $racer_id, string $season_id): array {
    return mikkoliigaSeasonPerGP($pdo, $season_id)[$racer_id] ?? [];
}

/**
 * Every member's per-GP Mikkoliiga points for a season, in one pass over the
 * season-results cache: racer_id => (gpid => points), gpids ascending.
 * Zero queries beyond the shared season fetch and the membership lookup.
 * This used to be one query per member per call — 14 on the homepage, ~39
 * on a racer profile (the standings and badges both walk the roster).
 *
 * Within a GP, members rank by gp_points desc, then racer_id asc, so a tie
 * resolves the same way every request (the old query had no tiebreak).
 */
function mikkoliigaSeasonPerGP(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];

    $members = getMikkoliigaMemberIds($pdo, $season_id);
    if (empty($members)) return $cache[$season_id] = [];

    $byGP = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        if (!isset($members[$rid])) continue;
        foreach ($rows as $row) $byGP[$row['gpid']][] = ['rid' => (int)$rid, 'pts' => (int)$row['gp_points']];
    }

    $out = [];
    foreach ($byGP as $gpid => $participants) {
        // Mikkoliiga is a head-to-head among members — a GP only counts if at
        // least TWO Mikkoliiga members raced it. A lone member can't "win" an
        // empty field and bank a free 15.
        if (count($participants) < 2) continue;
        usort($participants, fn($a, $b) => ($b['pts'] <=> $a['pts']) ?: ($a['rid'] <=> $b['rid']));
        foreach ($participants as $i => $p) $out[$p['rid']][$gpid] = mkPointsForRank($i + 1);
    }
    foreach ($out as &$m) ksort($m, SORT_STRING);
    unset($m);
    return $cache[$season_id] = $out;
}

/**
 * Mikkoliiga standings for a season. Returns an array of:
 *   ['id', 'name', 'nickname', 'score', 'gps_counted', 'total_gps']
 * sorted by score desc, then Elo desc as tiebreaker (if supplied), then name.
 *
 * $eloByName is an optional map of racer_name => rating, typically pulled
 * from calculateAllELORatings()['final']. Skipped if empty.
 */
function getMikkoliigaStandings(PDO $pdo, string $season_id, array $eloByName = []): array {
    $memberIds = array_keys(getMikkoliigaMemberIds($pdo, $season_id));
    if (empty($memberIds)) return [];

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name, nickname FROM racers WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->execute($memberIds);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $standings = [];
    foreach ($members as $m) {
        $perGP   = mikkoliigaScorePerGP($pdo, (int)$m['id'], $season_id);
        $scores  = array_values($perGP);
        rsort($scores);
        $kept    = array_slice($scores, 0, MIKKOLIIGA_BEST_X);
        $standings[] = [
            'id'          => (int)$m['id'],
            'name'        => $m['name'],
            'nickname'    => $m['nickname'] ?? '',
            'score'       => array_sum($kept),
            'gps_counted' => count($kept),
            'total_gps'   => count($scores),
        ];
    }

    usort($standings, function ($a, $b) use ($eloByName) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        if (!empty($eloByName)) {
            $ea = (int)($eloByName[$a['name']] ?? 0);
            $eb = (int)($eloByName[$b['name']] ?? 0);
            if ($ea !== $eb) return $eb <=> $ea;
        }
        return strcmp($a['name'], $b['name']);
    });
    return $standings;
}

// ============================================================================
// TEAMS — constructor-style team season layer.
//
// Each GP, a team scores its top TEAM_BEST_N member finishes that night (F1
// constructors logic — neutralises roster size and uneven attendance). The
// season total is the sum over GPs. Rosters are stored per season in
// team_members (admin-assigned), so membership is inherently snapshotted; the
// standings recompute live from the cached season results.
// ============================================================================

/** Default constructor depth, when a season hasn't overridden team_best_n. */
const TEAM_BEST_N = 2;

/** Effective best-N for a season: the season_meta override, or the default. */
function teamBestN(PDO $pdo, string $season_id): int {
    $rules = getSeasonRules($pdo, $season_id);
    $n = (int)($rules['team_best_n'] ?? TEAM_BEST_N);
    return max(1, $n);
}

/**
 * Teams + members for a season:
 *   [ team_id => ['id','name','color','members' => [racer_id => name]] ]
 */
function getTeamConfig(PDO $pdo, string $season_id): array {
    $tStmt = $pdo->prepare("SELECT id, name, color FROM teams WHERE season_id = ? ORDER BY id ASC");
    $tStmt->execute([$season_id]);
    $teams = [];
    foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $teams[(int)$t['id']] = [
            'id' => (int)$t['id'], 'name' => $t['name'],
            'color' => $t['color'] ?: '#e60012', 'members' => [],
        ];
    }
    if (empty($teams)) return [];

    $mStmt = $pdo->prepare("
        SELECT tm.team_id, tm.racer_id, r.name
        FROM team_members tm JOIN racers r ON r.id = tm.racer_id
        WHERE tm.season_id = ? ORDER BY r.name ASC
    ");
    $mStmt->execute([$season_id]);
    foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $tid = (int)$m['team_id'];
        if (isset($teams[$tid])) $teams[$tid]['members'][(int)$m['racer_id']] = $m['name'];
    }
    return $teams;
}

/**
 * Constructor standings for a season's teams, sorted by score desc (name
 * tiebreak). Each entry: id, name, color, score, gps_scored, members, member_count.
 */
function getTeamStandings(PDO $pdo, string $season_id): array {
    $teams = getTeamConfig($pdo, $season_id);
    if (empty($teams)) return [];

    $bestN    = teamBestN($pdo, $season_id);
    $bySeason = getSeasonResultsByRacer($pdo, $season_id);

    $standings = [];
    foreach ($teams as $team) {
        // Group every member's finishes by GP, then take the best N per GP.
        $byGp = [];
        foreach (array_keys($team['members']) as $rid) {
            foreach ($bySeason[$rid] ?? [] as $row) {
                $byGp[$row['gpid']][] = (int)$row['gp_points'];
            }
        }
        $total = 0; $gpsScored = 0;
        foreach ($byGp as $pts) {
            rsort($pts);
            $total += array_sum(array_slice($pts, 0, $bestN));
            $gpsScored++;
        }
        $standings[] = [
            'id'           => $team['id'],
            'name'         => $team['name'],
            'color'        => $team['color'],
            'score'        => $total,
            'gps_scored'   => $gpsScored,
            'members'      => $team['members'],
            'member_count' => count($team['members']),
        ];
    }

    usort($standings, fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));
    return $standings;
}

/**
 * Rich per-racer season stat bag, computed from the cached season rows (plus
 * the cached Elo changelog for the season delta). Shared by the
 * Consistency-vs-Ceiling panels and the Side Quests engine so both read one
 * consistent set of numbers.
 *
 * Returns: gps, points, avg, best (ceiling), stddev (consistency, lower=tighter),
 *   wins, podiums, podium_rate, lols, distinct_chars, max_char_plays,
 *   cups_raced, base_cups_raced, has_perfect, longest_win_streak,
 *   longest_podium_streak, comeback, elo_delta, top_char.
 */
function racerSeasonStats($pdo, $racer_id, $season_id, ?array $eloData = null): array {
    if (!defined('MK_BASE_CUPS')) require_once __DIR__ . '/mk_data.php';
    $rows = getRacerSeasonRows($pdo, $racer_id, $season_id);
    // Chronological for streak/comeback logic (cache is gp_points ASC).
    usort($rows, function ($a, $b) {
        if ($a['race_date'] !== $b['race_date']) return strcmp($a['race_date'], $b['race_date']);
        return (int)$a['id'] <=> (int)$b['id'];
    });

    $gps = count($rows);
    $pts = array_map(fn($r) => (int)$r['gp_points'], $rows);
    $points = array_sum($pts);
    $avg = $gps ? $points / $gps : 0;
    $best = $gps ? max($pts) : 0;

    // Std dev (population) — the consistency axis.
    $stddev = 0.0;
    if ($gps > 0) {
        $mean = $points / $gps;
        $stddev = sqrt(array_sum(array_map(fn($p) => ($p - $mean) ** 2, $pts)) / $gps);
    }

    $wins = $podiums = $lols = 0;
    $charTally = []; $cups = []; $baseCups = [];
    $ranks = [];
    foreach ($rows as $r) {
        $rk = (int)$r['rank'];
        $ranks[] = $rk;
        if ($rk === 1) $wins++;
        if ($rk <= 3) $podiums++;
        $lols += (int)($r['is_lol'] ?? 0);
        if (!empty($r['character_used'])) $charTally[$r['character_used']] = ($charTally[$r['character_used']] ?? 0) + 1;
        if (!empty($r['cup_name'])) {
            $cups[$r['cup_name']] = true;
            if (in_array($r['cup_name'], MK_BASE_CUPS, true)) $baseCups[$r['cup_name']] = true;
        }
    }
    arsort($charTally);

    // Streaks (chronological) + comeback.
    $lws = $cws = 0; $lps = $cps = 0; $comeback = false;
    foreach ($ranks as $i => $rk) {
        $cws = ($rk === 1) ? $cws + 1 : 0; $lws = max($lws, $cws);
        $cps = ($rk <= 3) ? $cps + 1 : 0; $lps = max($lps, $cps);
        if ($i > 0 && $rk === 1 && $ranks[$i - 1] >= 8) $comeback = true;
    }

    // Season Elo delta from the cached raw changelog.
    $eloDelta = 0;
    // One names map per request, not one query per racer — this runs for
    // every quest-holder inside the badge context on the homepage.
    static $nameCache = null;
    if ($nameCache === null) $nameCache = $pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rname = (string)($nameCache[$racer_id] ?? '');
    if ($rname !== '') {
        // Callers that already hold the Elo data (badgeSeasonContext) pass it
        // in; that skips calculateAllELORatings()'s per-call table-signature
        // query without weakening its mid-request invalidation for everyone
        // else. Quests/racer pages pass nothing and behave as before.
        if ($eloData === null) {
            if (!function_exists('calculateAllELORatings')) require_once __DIR__ . '/elo_engine.php';
            $eloData = calculateAllELORatings($pdo);
        }
        $elo = $eloData;
        $first = $last = null;
        foreach ($elo['gp_changelog'] ?? [] as $gpLog) {
            if (strpos($gpLog['gpid'], $season_id) !== 0) continue;
            foreach ($gpLog['racers'] as $rc) {
                if ($rc['name'] !== $rname) continue;
                if ($first === null) $first = $rc['old'];
                $last = $rc['new'];
            }
        }
        if ($first !== null && $last !== null) $eloDelta = (int)round($last - $first);
    }

    return [
        'gps'                   => $gps,
        'points'                => $points,
        'avg'                   => round($avg, 1),
        'best'                  => $best,
        'stddev'                => round($stddev, 1),
        'wins'                  => $wins,
        'podiums'               => $podiums,
        'podium_rate'           => $gps ? $podiums / $gps : 0,
        'lols'                  => $lols,
        'distinct_chars'        => count($charTally),
        'max_char_plays'        => $charTally ? (int)reset($charTally) : 0,
        'cups_raced'            => count($cups),
        'base_cups_raced'       => count($baseCups),
        'has_perfect'           => $best === MK_MAX_GP_POINTS,
        'longest_win_streak'    => $lws,
        'longest_podium_streak' => $lps,
        'comeback'              => $comeback,
        'elo_delta'             => $eloDelta,
        'top_char'              => $charTally ? array_key_first($charTally) : null,
    ];
}

/**
 * Consistency-vs-Ceiling archetype for a racer, placed against field medians.
 * $ceiling = best score, $stddev = consistency (lower is steadier).
 */
function consistencyCeilingArchetype(float $ceiling, float $stddev, float $medianCeiling, float $medianStddev): array {
    $highCeiling = $ceiling >= $medianCeiling;
    $consistent  = $stddev <= $medianStddev;
    if ($highCeiling && $consistent)  return ['label' => 'The Complete Package', 'blurb' => 'High ceiling, low variance — dangerous every single night.'];
    if ($highCeiling && !$consistent) return ['label' => 'Boom or Bust',         'blurb' => 'Massive highs, rocky lows. Never a dull race.'];
    if (!$highCeiling && $consistent) return ['label' => 'Steady Hand',          'blurb' => 'Reliable to a fault — you always know what you\'ll get.'];
    return ['label' => 'Wildcard', 'blurb' => 'Unpredictable and still finding the ceiling.'];
}

/** Number of GPs a racer played in a season (from the shared season cache). */
function getRaceCount($pdo, $racer_id, $season_id) {
    return count(getRacerSeasonRows($pdo, $racer_id, $season_id));
}

/** All racers who have at least one result in a season. */
function getActiveRacers($pdo, $season_id) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.* FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ?
        ORDER BY r.name ASC, r.id ASC
    ");
    $stmt->execute([$season_id . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Latest race date in a season, or null. */
function getLatestRaceDate($pdo, $season_id) {
    $stmt = $pdo->prepare("SELECT MAX(race_date) FROM results WHERE gpid LIKE ?");
    $stmt->execute([$season_id . '%']);
    return $stmt->fetchColumn() ?: null;
}

// ============================================================================
// STANDINGS HELPERS
// ============================================================================

/**
 * Calculate standings as they stood before the most-recent race date.
 * Returns [ racer_id => rank (int) ] or [] if no previous date exists.
 *
 * NOTE: Uses average_attendance formula only — intended for rank-change arrows
 * on display pages where speed matters more than scoring-system accuracy.
 */
function calculatePreviousStandings($pdo, $season_id, $latestDate, $rules = []) {
    if (!$latestDate) return [];

    $stmt = $pdo->prepare("SELECT MAX(race_date) FROM results WHERE gpid LIKE ? AND race_date < ?");
    $stmt->execute([$season_id . '%', $latestDate]);
    $prevDate = $stmt->fetchColumn();
    if (!$prevDate) return [];

    // The shared season cache holds every row with race_date; filter in PHP
    // to the rows that existed before the latest race night and score them
    // with the one formula (unrounded, for ranking).
    $temp = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $allRows) {
        $rows = array_values(array_filter($allRows, fn($r) => $r['race_date'] <= $prevDate));
        if (empty($rows)) continue;
        $aa = aaFromRows($rows, (array)$rules);
        $temp[] = ['id' => $rid, 'score' => $aa['avg'] + $aa['att']];
    }

    // Equal scores tie-break by racer id ASC — the order the old per-racer
    // query loop produced, so rank-change arrows don't flicker.
    usort($temp, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
    $map = [];
    foreach ($temp as $i => $r) $map[$r['id']] = $i + 1;
    return $map;
}

/**
 * Decides whether a racer qualifies for podium ranking under the active
 * scoring system. Only systems that average/sum attendance gate on
 * min_races_threshold; cup/best-N/hunt systems have their own completion
 * semantics and should qualify anyone who has raced at least once.
 */
function racerQualifies($raceCount, $rules) {
    $system = $rules['scoring_system'] ?? 'average_attendance';
    $def    = getScoringSystemDef($system);

    if ($def['qualifies_by_threshold']) {
        $threshold = (int)($rules['min_races_threshold'] ?? 0);
        return $raceCount >= $threshold;
    }
    return $raceCount > 0;
}

// ============================================================================
// GENERIC SINGLE-METRIC TIE-BREAK — one sort and one explainer for every
// system whose registry entry declares 'tiebreak':
//   'metric'  => fn($pdo, $racer_id, $season_id, $rules) => number   (higher wins)
//   'level'   => 'Level on points'          — clause when the metric separates them
//   'ahead'   => 'perfect 60s'              — "… X ahead on <ahead>: a vs b"
//   'both'    => 'Level on points and perfect 60s' — clause when the metric ties too
//   'fmt'     => optional callable to format the two values (default: integer)
//   'differs' => optional callable ($a, $b) => bool for the explainer's
//                "are they really different" test (default: !=)
// Seven systems used to carry a byte-identical sort function and a
// same-shaped explainer each (14 functions); now they are seven closures.
// ============================================================================

/** Attach 'tiebreaker' to every row and sort: score desc, tiebreaker desc, name asc. */
function sortStandingsByTiebreak(array &$standings, array $tb, PDO $pdo, string $season_id): void {
    $rules = getSeasonRules($pdo, $season_id);
    foreach ($standings as &$s) $s['tiebreaker'] = ($tb['metric'])($pdo, (int)$s['id'], $season_id, $rules);
    unset($s);
    usort($standings, function ($a, $b) {
        if ($b['score'] != $a['score'])           return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
}

/** "Level on X · A ahead on Y: a vs b", or "Level on X and Y · ordered alphabetically". */
function explainTieByTiebreak(array $tb, PDO $pdo, string $season_id, array $rules, array $a, array $b): string {
    $ma = ($tb['metric'])($pdo, (int)$a['id'], $season_id, $rules);
    $mb = ($tb['metric'])($pdo, (int)$b['id'], $season_id, $rules);
    $differs = isset($tb['differs']) ? ($tb['differs'])($ma, $mb) : ($ma != $mb);
    if ($differs) {
        $fmt = $tb['fmt'] ?? (fn($v) => (string)(int)$v);
        return sprintf('%s · %s ahead on %s: %s vs %s', $tb['level'], $a['name'], $tb['ahead'], $fmt($ma), $fmt($mb));
    }
    return $tb['both'] . ' · ordered alphabetically';
}

/**
 * Sort a standings array in-place according to the active scoring system.
 * Each entry must have 'score', 'name', and 'id'.
 * For top_12_unique, tiebreaker values are fetched and added as 'tiebreaker'.
 */
function sortStandingsByScoring(array &$standings, $system, $pdo = null, $season_id = null) {
    $def = getScoringSystemDef($system);

    if ($pdo && $season_id) {
        // Most systems break ties on ONE metric — declared as the registry's
        // 'tiebreak' entry and sorted here generically. Only a system whose
        // tie-break is genuinely multi-level (Positional count-back) keeps a
        // bespoke 'sort' function.
        if (!empty($def['tiebreak'])) { sortStandingsByTiebreak($standings, $def['tiebreak'], $pdo, $season_id); return; }
        if (($def['sort'] ?? null) !== null) { $fn = $def['sort']; $fn($standings, $pdo, $season_id); return; }
    }
    // Default: score desc, then name asc (deterministic).
    usort($standings, fn($a, $b) => $b['score'] != $a['score']
        ? $b['score'] <=> $a['score']
        : strcmp($a['name'], $b['name']));
}


// ============================================================================
// STREAK HELPERS
// ============================================================================

/**
 * Calculate current and maximum streaks from an ordered results array.
 *
 * @param array  $results   Rows with a 'rank' key, ordered oldest→newest.
 * @param string $type      'win' (rank == 1) or 'podium' (rank <= 3).
 * @return array ['current' => int, 'max' => int]
 */
function calculateStreaks(array $results, string $type = 'win') {
    $test = $type === 'win'
        ? fn($r) => (int)$r['rank'] === 1
        : fn($r) => (int)$r['rank'] <= 3;

    $current = $max = 0;
    foreach ($results as $r) {
        if ($test($r)) { $current++; $max = max($max, $current); }
        else            { $current = 0; }
    }
    return ['current' => $current, 'max' => $max];
}

// ============================================================================
// TIE-BREAK EXPLAINER — "why is A above B when they're level?"
//
// Registry entries may carry a 'tie_explain' fn ($pdo, $season_id, $rules,
// $above, $below) → string. Pages call explainStandingsTie() and never
// dispatch on the system themselves (§2a). Each explainer recomputes from the
// engine's own caches rather than trusting scratch keys a sorter may or may
// not have attached.
// ============================================================================

function explainStandingsTie(PDO $pdo, string $season_id, string $system, array $above, array $below): string {
    $def   = getScoringSystemDef($system);
    $rules = getSeasonRules($pdo, $season_id);
    if (!empty($def['tiebreak'])) return explainTieByTiebreak($def['tiebreak'], $pdo, $season_id, $rules, $above, $below);
    $fn    = $def['tie_explain'] ?? null;
    if ($fn !== null && function_exists($fn)) {
        $s = $fn($pdo, $season_id, $rules, $above, $below);
        if (is_string($s) && $s !== '') return $s;
    }
    return 'Level on score · ordered alphabetically';
}

function tieExplainPositional($pdo, $season_id, $rules, $a, $b): string {
    $count = function ($rid) use ($pdo, $season_id) {
        $c = array_fill(1, count(MK_POINTS_SCALE), 0);
        foreach (getRacerSeasonRows($pdo, (int)$rid, $season_id) as $r) { $k = (int)$r['rank']; if ($k >= 1 && $k <= count(MK_POINTS_SCALE)) $c[$k]++; }
        return $c;
    };
    $ca = $count($a['id']); $cb = $count($b['id']);
    for ($p = 1; $p <= count(MK_POINTS_SCALE); $p++) {
        if ($ca[$p] !== $cb[$p]) {
            return sprintf('Level on points · %s ahead on count-back: %d× %s place vs %d', $a['name'], $ca[$p], ordinal($p), $cb[$p]);
        }
    }
    $ga = count(getRacerSeasonRows($pdo, (int)$a['id'], $season_id));
    $gb = count(getRacerSeasonRows($pdo, (int)$b['id'], $season_id));
    if ($ga !== $gb) return sprintf('Level on points and count-back · %s reached it in fewer GPs (%d vs %d)', $a['name'], $ga, $gb);
    return 'Level on points and count-back · ordered alphabetically';
}


// ============================================================================
// BLUE SHELL — catch-up multiplier
//
// Whole-season pass, chronological: before each GP, rank everyone with prior
// results; each racer's points for that GP are multiplied by
// 1 + rate × (places behind the leader), capped. Computed once per request per
// (season, knobs) from the season cache — never per racer.
// ============================================================================

function blueShellSeason(PDO $pdo, string $season_id, array $rules): array {
    static $cache = [];
    $rate = max(0.0, (float)($rules['bs_rate'] ?? 0.10));
    $cap  = max(1.0, (float)($rules['bs_cap']  ?? 2.0));
    $key  = "$season_id|$rate|$cap";
    if (isset($cache[$key])) return $cache[$key];

    $gps = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        foreach ($rows as $r) {
            $gps[$r['gpid']]['date'] = $r['race_date'];
            $gps[$r['gpid']]['rows'][(int)$rid] = (int)$r['gp_points'];
        }
    }
    $order = array_keys($gps);
    usort($order, function ($x, $y) use ($gps) {
        $c = strcmp((string)$gps[$x]['date'], (string)$gps[$y]['date']);
        return $c !== 0 ? $c : strcmp($x, $y);
    });

    $score = []; $raw = []; $detail = [];
    foreach ($order as $gpid) {
        $ids = array_keys($score);
        usort($ids, function ($x, $y) use ($score, $raw) {
            if ($score[$x] != $score[$y]) return $score[$y] <=> $score[$x];
            if ($raw[$x]   != $raw[$y])   return $raw[$y]   <=> $raw[$x];
            return $x <=> $y;
        });
        $pos = array_flip($ids);
        foreach ($gps[$gpid]['rows'] as $rid => $pts) {
            $behind = $pos[$rid] ?? 0;                       // no history → no catch-up
            $mult   = min($cap, 1 + $rate * $behind);
            $w      = $pts * $mult;
            $score[$rid] = ($score[$rid] ?? 0) + $w;
            $raw[$rid]   = ($raw[$rid]   ?? 0) + $pts;
            $detail[$rid][] = ['gpid' => $gpid, 'pts' => $pts, 'behind' => $behind, 'mult' => $mult, 'weighted' => $w];
        }
    }
    return $cache[$key] = ['score' => $score, 'raw' => $raw, 'detail' => $detail, 'rate' => $rate, 'cap' => $cap];
}

function calculateBlueShellScore($pdo, $racer_id, $season_id, $rules) {
    return round(blueShellSeason($pdo, $season_id, (array)$rules)['score'][(int)$racer_id] ?? 0, 1);
}

function breakdownBlueShell($pdo, $racer_id, $season_id, $rules) {
    $s = blueShellSeason($pdo, $season_id, (array)$rules);
    $d = $s['detail'][(int)$racer_id] ?? [];
    $mults = array_column($d, 'mult');
    return [
        'gps_played' => count($d),
        'raw_points' => $s['raw'][(int)$racer_id] ?? 0,
        'score'      => round($s['score'][(int)$racer_id] ?? 0, 1),
        'avg_mult'   => $mults ? round(array_sum($mults) / count($mults), 2) : 1.0,
        'max_mult'   => $mults ? round(max($mults), 2) : 1.0,
        'rate'       => $s['rate'],
        'cap'        => $s['cap'],
    ];
}

function tooltipBlueShell(array $c, $score): string {
    return sprintf('🐢 %s pts from %d raw · %d GP%s · avg ×%s (peak ×%s) · +%d%% per place behind, cap ×%s',
        scoreNum($score), $c['raw_points'] ?? 0, $c['gps_played'] ?? 0, ($c['gps_played'] ?? 0) === 1 ? '' : 's',
        scoreNum($c['avg_mult'] ?? 1), scoreNum($c['max_mult'] ?? 1),
        (int)round(($c['rate'] ?? 0.1) * 100), scoreNum($c['cap'] ?? 2));
}


// ============================================================================
// TERRITORY — hold the cups
// ============================================================================

/**
 * Territory rules ("highest score holds, but you have to show up"):
 *   - A cup is held by the best score posted on it this season.
 *   - An EQUAL score takes it — the later post wins the tie, so a perfect 60
 *     can be answered by another 60.
 *   - Undefended decay: if the cup is raced tt_decay_gps times (default 4,
 *     0 = off) without the holder racing it, the best challenger across those
 *     nights takes it. Racing the cup yourself resets the count.
 * Returns ['hold' => cup => [racer_id, points, date, id, gpid, undefended],
 *          'by_racer' => racer_id => (cup => points),
 *          'events' => [[gpid, cup, from, to, type(claim|beat|tie|decay), points], …] chronological,
 *          'challengers' => cup => rows posted by non-holders while unchanged,
 *          'changed' => cup => ever changed hands, 'decay_gps' => N].
 * Cached per (season, decay knob) so the simulator can vary the knob.
 */
function territorySeason(PDO $pdo, string $season_id, ?array $rules = null): array {
    static $cache = [];
    $rules  = $rules ?? getSeasonRules($pdo, $season_id);
    $decayN = max(0, (int)($rules['tt_decay_gps'] ?? 4));
    $key = $season_id . ':' . $decayN;
    if (isset($cache[$key])) return $cache[$key];

    $all = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        foreach ($rows as $r) {
            if (($r['cup_name'] ?? '') === '') continue;
            $all[] = ['rid' => (int)$rid, 'cup' => $r['cup_name'], 'pts' => (int)$r['gp_points'], 'date' => (string)$r['race_date'], 'id' => (int)$r['id'], 'gpid' => (string)$r['gpid']];
        }
    }
    usort($all, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['gpid'], $b['gpid']) ?: ($a['id'] <=> $b['id']));
    // one group per (GP night, cup), in order — everyone who raced that cup that night
    $groups = []; $order = [];
    foreach ($all as $e) { $k = $e['gpid'] . '|' . $e['cup']; if (!isset($groups[$k])) { $groups[$k] = []; $order[] = $k; } $groups[$k][] = $e; }

    $mk = fn(array $e) => ['racer_id' => $e['rid'], 'points' => $e['pts'], 'date' => $e['date'], 'id' => $e['id'], 'gpid' => $e['gpid'], 'undefended' => 0];
    $hold = []; $events = []; $challengers = []; $changed = []; $undef = []; $bestSince = [];
    foreach ($order as $k) {
        $g = $groups[$k]; $cup = $g[0]['cup']; $gpid = $g[0]['gpid'];
        usort($g, fn($a, $b) => ($b['pts'] <=> $a['pts']) ?: ($a['id'] <=> $b['id']));   // best first
        $top = $g[0];
        if (!isset($hold[$cup])) {
            $hold[$cup] = $mk($top); $challengers[$cup] = 0; $changed[$cup] = false; $undef[$cup] = 0; $bestSince[$cup] = null;
            $events[] = ['gpid' => $gpid, 'cup' => $cup, 'from' => null, 'to' => $top['rid'], 'type' => 'claim', 'points' => $top['pts']];
            continue;
        }
        $h = $hold[$cup];
        $present = null; foreach ($g as $e) if ($e['rid'] === $h['racer_id']) { $present = $e; break; }
        foreach ($g as $e) if ($e['rid'] !== $h['racer_id']) $challengers[$cup]++;

        if ($top['rid'] !== $h['racer_id'] && $top['pts'] >= $h['points']) {          // beaten, or tied by a challenger
            $events[] = ['gpid' => $gpid, 'cup' => $cup, 'from' => $h['racer_id'], 'to' => $top['rid'], 'type' => $top['pts'] === $h['points'] ? 'tie' : 'beat', 'points' => $top['pts']];
            $hold[$cup] = $mk($top); $changed[$cup] = true; $undef[$cup] = 0; $bestSince[$cup] = null;
            continue;
        }
        if ($present !== null) {                                                       // holder defended (and maybe improved)
            if ($present['pts'] > $h['points']) { $h['points'] = $present['pts']; $h['date'] = $present['date']; $h['id'] = $present['id']; $h['gpid'] = $present['gpid']; }
            $h['undefended'] = 0; $undef[$cup] = 0; $bestSince[$cup] = null; $hold[$cup] = $h;
            continue;
        }
        // holder absent: the cup was raced without them
        $undef[$cup]++; $h['undefended'] = $undef[$cup];
        $best = null; foreach ($g as $e) if ($e['rid'] !== $h['racer_id']) { $best = $e; break; }
        if ($best !== null && ($bestSince[$cup] === null || $best['pts'] > $bestSince[$cup]['pts'])) $bestSince[$cup] = $best;
        if ($decayN > 0 && $undef[$cup] >= $decayN && $bestSince[$cup] !== null) {
            $events[] = ['gpid' => $gpid, 'cup' => $cup, 'from' => $h['racer_id'], 'to' => $bestSince[$cup]['rid'], 'type' => 'decay', 'points' => $bestSince[$cup]['pts']];
            $hold[$cup] = $mk($bestSince[$cup]); $changed[$cup] = true; $undef[$cup] = 0; $bestSince[$cup] = null;
        } else {
            $hold[$cup] = $h;
        }
    }
    $byRacer = [];
    foreach ($hold as $cup => $h) $byRacer[$h['racer_id']][$cup] = $h['points'];
    return $cache[$key] = ['hold' => $hold, 'by_racer' => $byRacer, 'events' => $events, 'challengers' => $challengers, 'changed' => $changed, 'decay_gps' => $decayN];
}

/**
 * Freeze a Territory season's final map (the payload the renderer draws) at
 * archive time. Immutable history (§8): re-running replaces the row, so an
 * admin re-archive corrects it. No-op for other scoring systems.
 */
function snapshotSeasonMap(PDO $pdo, string $season_id): bool {
    if ((getSeasonRules($pdo, $season_id)['scoring_system'] ?? '') !== 'territory') return false;
    $pdo->prepare("INSERT OR REPLACE INTO season_maps (season_id, payload, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
        ->execute([$season_id, json_encode(territoryMapPayload($pdo, $season_id), JSON_UNESCAPED_UNICODE)]);
    return true;
}

/** The map to show for a season: the frozen one for an archived season, live for a running one, null if not Territory. */
function seasonMapPayload(PDO $pdo, string $season_id): ?array {
    $rules = getSeasonRules($pdo, $season_id);
    if (($rules['scoring_system'] ?? '') !== 'territory') return null;
    if (($rules['status'] ?? '') === 'archived') {
        try { $st = $pdo->prepare("SELECT payload FROM season_maps WHERE season_id = ?"); $st->execute([$season_id]); $raw = $st->fetchColumn(); if ($raw) { $p = json_decode((string)$raw, true); if (is_array($p)) return $p + ['frozen' => true]; } } catch (PDOException $e) {}
    }
    return territoryMapPayload($pdo, $season_id) + ['frozen' => false];
}

/** Stable colour per racer for a season's map and chips: palette by racer id order. */
function territoryRacerColors(array $racerIds): array {
    $palette = ['#E60012', '#0066CC', '#2EBD59', '#FF8C00', '#8B5CF6', '#EC4899', '#14B8A6', '#F59E0B', '#6366F1', '#84CC16', '#F97316', '#A855F7', '#06B6D4', '#D946EF', '#10B981', '#3B82F6', '#EF4444', '#FB923C'];
    $ids = array_values(array_unique(array_map('intval', $racerIds))); sort($ids);
    $out = []; foreach ($ids as $i => $rid) $out[$rid] = $palette[$i % count($palette)];
    return $out;
}

/**
 * Everything the overworld map needs, in cup order (getMKAllCups == stop order):
 * per cup the holder, score to beat, undefended count and whether it changed
 * hands on the latest race night; a colour per racer; names.
 */
function territoryMapPayload(PDO $pdo, string $season_id): array {
    $t = territorySeason($pdo, $season_id);
    $names = racerNamesMap($pdo);
    $byRacer = getSeasonResultsByRacer($pdo, $season_id);
    $lastGp = null; $lastDate = '';
    foreach ($byRacer as $rows) foreach ($rows as $r) if (strcmp((string)$r['race_date'], $lastDate) >= 0) { if ((string)$r['race_date'] !== $lastDate || strcmp((string)$r['gpid'], (string)$lastGp) > 0) { $lastDate = (string)$r['race_date']; $lastGp = (string)$r['gpid']; } }
    $flipped = [];
    foreach ($t['events'] as $ev) if ($ev['gpid'] === $lastGp && $ev['from'] !== null && $ev['from'] !== $ev['to']) $flipped[$ev['cup']] = $ev['type'];
    $cups = [];
    foreach (getMKAllCups() as $c) {
        $h = $t['hold'][$c] ?? null;
        $cups[] = ['cup' => $c, 'holder' => $h ? $h['racer_id'] : null, 'name' => $h ? ($names[$h['racer_id']] ?? '') : null,
                   'pts' => $h ? $h['points'] : 0, 'undefended' => $h ? $h['undefended'] : 0, 'flip' => $flipped[$c] ?? null];
    }
    $colors = territoryRacerColors(array_keys($byRacer));
    $chars = []; foreach (array_keys($byRacer) as $rid) $chars[$rid] = getMostUsedCharacter($pdo, (int)$rid, $season_id) ?: 'Mii';
    return ['cups' => $cups, 'colors' => $colors, 'names' => array_intersect_key($names, $byRacer), 'chars' => $chars, 'decay' => $t['decay_gps'], 'held' => count($t['hold']), 'last_gp' => $lastGp];
}

function calculateTerritoryScore($pdo, $racer_id, $season_id, $rules) {
    return count(territorySeason($pdo, $season_id, (array)$rules)['by_racer'][(int)$racer_id] ?? []);
}

function breakdownTerritory($pdo, $racer_id, $season_id, $rules) {
    $t = territorySeason($pdo, $season_id, (array)$rules);
    $held = $t['by_racer'][(int)$racer_id] ?? [];
    arsort($held);
    return [
        'cups_held'   => count($held),
        'held'        => $held,                       // cup => points
        'held_points' => array_sum($held),
        'cups_total'  => function_exists('getMKAllCups') ? count(getMKAllCups()) : 24,
        'contested'   => count($t['hold']),           // cups anyone holds this season
    ];
}

function tooltipTerritory(array $c, $score): string {
    $held = $c['held'] ?? [];
    $names = array_slice(array_keys($held), 0, 3);
    $list = $names ? implode(', ', array_map(fn($k) => $k . ' (' . $held[$k] . ')', $names)) . (count($held) > 3 ? '…' : '') : 'none';
    return sprintf('🏰 holds %d of %d cups · %s · %d pts across held cups',
        (int)($c['cups_held'] ?? 0), (int)($c['cups_total'] ?? 24), $list, (int)($c['held_points'] ?? 0));
}


// ============================================================================
// MEDIAN
// ============================================================================

function medianOf(array $vals): float {
    if (!$vals) return 0.0;
    sort($vals); $n = count($vals);
    return $n % 2 ? (float)$vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
}

function calculateMedianScore($pdo, $racer_id, $season_id, $rules) {
    $pts = array_map(fn($r) => (int)$r['gp_points'], getRacerSeasonRows($pdo, (int)$racer_id, $season_id));
    return round(medianOf($pts), 1);
}

function breakdownMedian($pdo, $racer_id, $season_id, $rules) {
    $pts = array_map(fn($r) => (int)$r['gp_points'], getRacerSeasonRows($pdo, (int)$racer_id, $season_id));
    return [
        'gps_played' => count($pts),
        'median'     => round(medianOf($pts), 1),
        'mean'       => $pts ? round(array_sum($pts) / count($pts), 1) : 0,
        'low'        => $pts ? min($pts) : 0,
        'high'       => $pts ? max($pts) : 0,
    ];
}

function tooltipMedian(array $c, $score): string {
    return sprintf('⚖️ median %s · %d GP%s · mean %s · range %d–%d',
        scoreNum($score), $c['gps_played'] ?? 0, ($c['gps_played'] ?? 0) === 1 ? '' : 's',
        scoreNum($c['mean'] ?? 0), $c['low'] ?? 0, $c['high'] ?? 0);
}


// ============================================================================
// HARD MODE — cup-difficulty weighting
// ============================================================================

function hardModeCupFactors(PDO $pdo, float $cap): array {
    static $cache = [];
    $k = (string)$cap;
    if (isset($cache[$k])) return $cache[$k];
    // League-wide, all seasons. Two queries per request, memoised — not per racer.
    $rows   = $pdo->query("SELECT cup_name, AVG(gp_points) AS a, COUNT(*) AS n FROM results WHERE gpid LIKE 's%' AND cup_name IS NOT NULL AND cup_name != '' GROUP BY cup_name")->fetchAll(PDO::FETCH_ASSOC);
    $league = (float)$pdo->query("SELECT AVG(gp_points) FROM results WHERE gpid LIKE 's%'")->fetchColumn();
    if ($league <= 0) $league = 1.0;
    $f = [];
    foreach ($rows as $r) {
        $avg = (float)$r['a'];
        $f[$r['cup_name']] = ((int)$r['n'] < 5 || $avg <= 0) ? 1.0 : max(0.5, min($cap, $league / $avg));
    }
    return $cache[$k] = ['factors' => $f, 'league_avg' => $league];
}

function calculateHardModeScore($pdo, $racer_id, $season_id, $rules) {
    $cap = max(1.0, (float)($rules['hm_cap'] ?? 2.0));
    $f = hardModeCupFactors($pdo, $cap)['factors'];
    $sum = 0.0;
    foreach (getRacerSeasonRows($pdo, (int)$racer_id, $season_id) as $r) $sum += (int)$r['gp_points'] * ($f[$r['cup_name'] ?? ''] ?? 1.0);
    return round($sum, 1);
}

function breakdownHardMode($pdo, $racer_id, $season_id, $rules) {
    $cap = max(1.0, (float)($rules['hm_cap'] ?? 2.0));
    $f = hardModeCupFactors($pdo, $cap)['factors'];
    $raw = 0; $sum = 0.0; $n = 0; $hardest = null; $hardF = 0.0; $fs = [];
    foreach (getRacerSeasonRows($pdo, (int)$racer_id, $season_id) as $r) {
        $cup = $r['cup_name'] ?? ''; $m = $f[$cup] ?? 1.0;
        $raw += (int)$r['gp_points']; $sum += (int)$r['gp_points'] * $m; $n++; $fs[] = $m;
        if ($m > $hardF) { $hardF = $m; $hardest = $cup; }
    }
    return [
        'gps_played'  => $n, 'raw_points' => $raw, 'score' => round($sum, 1),
        'avg_factor'  => $fs ? round(array_sum($fs) / count($fs), 2) : 1.0,
        'hardest_cup' => $hardest, 'hardest_factor' => round($hardF, 2), 'cap' => $cap,
    ];
}

function tooltipHardMode(array $c, $score): string {
    $s = sprintf('🔥 %s pts from %d raw · %d GP%s · avg ×%s',
        scoreNum($score), $c['raw_points'] ?? 0, $c['gps_played'] ?? 0, ($c['gps_played'] ?? 0) === 1 ? '' : 's', scoreNum($c['avg_factor'] ?? 1));
    if (!empty($c['hardest_cup'])) $s .= sprintf(' · hardest: %s ×%s', $c['hardest_cup'], scoreNum($c['hardest_factor'] ?? 1));
    return $s;
}


// ============================================================================
// FORM — rolling window
// ============================================================================

function formRows($pdo, int $racer_id, string $season_id, array $rules): array {
    $rows = getRacerSeasonRows($pdo, $racer_id, $season_id);
    usort($rows, function ($a, $b) {
        $c = strcmp((string)$a['race_date'], (string)$b['race_date']);
        return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
    });
    $w = max(1, (int)($rules['form_window'] ?? 8));
    return ['window' => $w, 'all' => $rows, 'recent' => array_slice($rows, -$w)];
}

function calculateFormScore($pdo, $racer_id, $season_id, $rules) {
    $f = formRows($pdo, (int)$racer_id, $season_id, (array)$rules);
    $pts = array_map(fn($r) => (int)$r['gp_points'], $f['recent']);
    return $pts ? round(array_sum($pts) / count($pts), 1) : 0;
}

function breakdownForm($pdo, $racer_id, $season_id, $rules) {
    $f = formRows($pdo, (int)$racer_id, $season_id, (array)$rules);
    $pts = array_map(fn($r) => (int)$r['gp_points'], $f['recent']);
    $latest = $f['all'] ? (int)end($f['all'])['gp_points'] : 0;
    return [
        'window' => $f['window'], 'gps_used' => count($pts), 'gps_played' => count($f['all']),
        'form' => $pts ? round(array_sum($pts) / count($pts), 1) : 0, 'latest' => $latest,
    ];
}

function tooltipForm(array $c, $score): string {
    return sprintf('📈 form %s · last %d of %d GP%s · latest %d',
        scoreNum($score), $c['gps_used'] ?? 0, $c['gps_played'] ?? 0, ($c['gps_played'] ?? 0) === 1 ? '' : 's', $c['latest'] ?? 0);
}


// ============================================================================
// SEASON PLACEMENTS — one ranking per season, shared by badges (On the Up,
// From the Back) and anything else that needs "where did X finish". Gated by
// racerQualifies() and sorted through the registry, so it can never disagree
// with the homepage standings. Cached per request; reads the season cache.
// ============================================================================

/**
 * Every knob a new season starts with, per system — the ONE place these
 * numbers live. seasons.php, setup.php and the /scoring-systems examples
 * used to carry their own copies (and the registry closures still fall back
 * to the same values with ?? — keep them in agreement).
 */
function newSeasonDefaults(string $system): array {
    $aa = ($system === 'average_attendance');
    return [
        'cups_required'         => 12,
        'best_n_count'          => 15,
        'drop_worst_count'      => 2,
        'perfect_multiplier'    => 2.0,
        'attendance_weight'     => $aa ? 1.0 : 0.0,
        'weekly_bonus_cap'      => 2,
        'min_races_threshold'   => 3,
        'drop_rate'             => $aa ? 10 : 0,
        'mh_slay_xp'            => 100,
        'mh_survive_xp'         => 20,
        'mh_party_bonus_xp'     => 50,
        'mh_monster_win_xp'     => 80,
        'mh_monster_partial_xp' => 30,
        'mh_monster_loss_xp'    => -40,
        'mh_min_gps'            => 6,
        'mh_best_x'             => 20,
    ];
}

/** id => name, once per request. */
function racerNamesMap(PDO $pdo): array {
    static $map = null;
    if ($map === null) $map = $pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $map;
}

/** ['place' => [racer_id => 1-based placement among qualifiers], 'field' => qualifier count] */
function seasonPlacements(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];
    $rules = getSeasonRules($pdo, $season_id);
    $names = racerNamesMap($pdo);
    $rows  = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rrows) {
        if (!racerQualifies(count($rrows), $rules)) continue;
        $rows[] = ['id' => (int)$rid, 'name' => (string)($names[$rid] ?? ''), 'score' => calculateGPScore($pdo, (int)$rid, $season_id)];
    }
    sortStandingsByScoring($rows, $rules['scoring_system'] ?? 'average_attendance', $pdo, $season_id);
    $place = [];
    foreach ($rows as $i => $r) $place[$r['id']] = $i + 1;
    return $cache[$season_id] = ['place' => $place, 'field' => count($rows)];
}

/**
 * Freeze a season's final placements. Called when a season is archived (next
 * to snapshotMikkoliigaMembership); safe to re-run — it replaces the season's
 * rows, so re-opening and re-archiving recomputes. Returns rows written.
 */
function snapshotSeasonPlacements(PDO $pdo, string $season_id): int {
    $sp = seasonPlacements($pdo, $season_id);
    $pdo->prepare("DELETE FROM season_placements WHERE season_id = ?")->execute([$season_id]);
    $ins = $pdo->prepare("INSERT INTO season_placements (season_id, racer_id, place, field) VALUES (?, ?, ?, ?)");
    foreach ($sp['place'] as $rid => $p) $ins->execute([$season_id, (int)$rid, (int)$p, (int)$sp['field']]);
    return count($sp['place']);
}

/**
 * Placements for every ARCHIVED season, from the snapshot table — one query.
 * Any archived season with no snapshot yet (archived before the table
 * existed) is computed and written once, here, so the cost is paid a single
 * time rather than on every page load.
 * Returns racer_id => [[season_id, place, field], ...] in season order.
 */
function archivedSeasonPlacements(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $archived = $pdo->query("SELECT season_id FROM season_meta WHERE status = 'archived' ORDER BY season_id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $have = $pdo->query("SELECT DISTINCT season_id FROM season_placements")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_diff($archived, $have) as $missing) {
        try { snapshotSeasonPlacements($pdo, $missing); } catch (PDOException $e) { /* read-only DB etc. — fall through, recompute below */ }
    }

    $out = [];
    $rows = $pdo->query("
        SELECT p.season_id, p.racer_id, p.place, p.field
        FROM season_placements p JOIN season_meta m ON m.season_id = p.season_id
        WHERE m.status = 'archived'
        ORDER BY p.season_id ASC, p.place ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $out[(int)$r['racer_id']][] = [$r['season_id'], (int)$r['place'], (int)$r['field']];
    return $cache = $out;
}

// ============================================================================
// HEAD-TO-HEAD MATCHUPS — every ordered pair's record for a season, in one
// pass over the season cache. rivalries.php and rivalry_web.php used to run
// a COUNT plus a history query per ordered pair (2·N·(N−1): 1178 queries on
// a 25-racer season). Two racers "meet" when they have rows in the same GP
// on the same cup — the old SQL self-join; rows with no cup never join.
// ============================================================================

/**
 * [a][b] => ['wins' => a finished ahead, 'total', 'history' => [...]], history
 * newest first (race_date, gpid, id). Only pairs that actually met appear.
 * Cached per season per request.
 */
function seasonMatchups(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];

    $groups = [];   // "gpid|cup" => [[racer_id, row], ...]
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        foreach ($rows as $r) {
            if ($r['cup_name'] === null) continue;
            $groups[$r['gpid'] . '|' . $r['cup_name']][] = [(int)$rid, $r];
        }
    }
    $m = [];
    foreach ($groups as $g) {
        $n = count($g);
        if ($n < 2) continue;
        for ($i = 0; $i < $n; $i++) {
            [$a, $ra] = $g[$i];
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                [$b, $rb] = $g[$j];
                if ($a === $b) continue;
                if (!isset($m[$a][$b])) $m[$a][$b] = ['wins' => 0, 'total' => 0, 'history' => []];
                $m[$a][$b]['total']++;
                if ((int)$ra['rank'] < (int)$rb['rank']) $m[$a][$b]['wins']++;
                $m[$a][$b]['history'][] = [
                    'gpid' => $ra['gpid'], 'race_date' => $ra['race_date'], 'cup_name' => $ra['cup_name'],
                    'p1_rank' => $ra['rank'], 'p2_rank' => $rb['rank'],
                    'p1_points' => $ra['gp_points'], 'p2_points' => $rb['gp_points'],
                    '_id' => (int)$ra['id'],
                ];
            }
        }
    }
    foreach ($m as &$row) {
        foreach ($row as &$pair) {
            usort($pair['history'], fn($x, $y) => strcmp((string)$y['race_date'], (string)$x['race_date']) ?: strcmp((string)$y['gpid'], (string)$x['gpid']) ?: ($y['_id'] <=> $x['_id']));
            foreach ($pair['history'] as &$h) unset($h['_id']);
            unset($h);
        }
        unset($pair);
    }
    unset($row);
    return $cache[$season_id] = $m;
}

/** Distinct GPs a racer raced in a season, off the cache (was COUNT(DISTINCT gpid) per racer). */
function racerSeasonGpCount(PDO $pdo, int $racer_id, string $season_id): int {
    return count(array_unique(array_column(getRacerSeasonRows($pdo, $racer_id, $season_id), 'gpid')));
}

// ============================================================================
// PROGRESSIVE (REPLAY) SCORING — "what was X's score after GP k?"
// The season-replay pages (stats chart, season report timeline, animate)
// re-implement scoring on a growing bag of rows. Systems whose score depends
// only on the racer's own rows can be replayed exactly; this is the one
// implementation of those. Returns null for a system that cannot be replayed
// from one racer's rows alone (Elo-, standings- or league-dependent systems),
// so callers can label the chart as an approximation instead of mislabelling
// a GPScore™ curve as that system — which stats.php did for nine systems.
// Rows need gp_points, race_date, rank, id.
// ============================================================================
function progressiveScoreFromRows(string $system, array $rows, array $rules): ?float {
    switch ($system) {
        case 'positional_points': {
            $pts = array_map(fn($r) => mkPointsForRank((int)$r['rank']), $rows);
            if (!$pts) return 0.0;
            rsort($pts);
            switch ($rules['pos_mode'] ?? 'best_n') {
                case 'sum':     return (float)array_sum($pts);
                case 'average': return round(array_sum($pts) / max(1, count($pts)), 1);
                default:
                    $n = (int)($rules['best_n_count'] ?? 15); if ($n < 1) $n = 15;
                    return (float)array_sum(array_slice($pts, 0, $n));
            }
        }
        case 'average_attendance':
            return aaFromRows($rows, $rules)['score'];
        case 'preseason':
            return $rows ? preseasonFromRows($rows)['score'] : 0.0;
        case 'median':
            return round(medianOf(array_map(fn($r) => (int)$r['gp_points'], $rows)), 1);
        case 'form': {
            usort($rows, fn($a, $b) => strcmp((string)$a['race_date'], (string)$b['race_date']) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)));
            $w = max(1, (int)($rules['form_window'] ?? 8));
            $pts = array_map(fn($r) => (int)$r['gp_points'], array_slice($rows, -$w));
            return $pts ? round(array_sum($pts) / count($pts), 1) : 0.0;
        }
    }
    return null;
}

/** Systems the replay pages can reproduce exactly from a racer's own rows. */
function progressiveReplayableSystems(): array {
    return ['average_attendance', 'preseason', 'best_n_gps', 'cup_based', 'drop_worst', 'perfect_hunt',
            'top_12_unique', 'black_box', 'random_cup_draw', 'monster_hunt',
            'positional_points', 'median', 'form'];
}

// ============================================================================
// THE WEIRD ONES — Kart Bingo, The Price Is Right, The Great Equaliser.
// (The Cursed Crown was built and scrapped: a penalty that passes to whoever
// beats the holder makes 'don't beat the leader' the best play.) All read the season cache; per-GP systems group it by
// gpid once per season.
// ============================================================================

/** gpid => [racer_id => row], every human in each GP, chronological. Cached per request. */
function seasonGpGroups(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];
    $g = []; $when = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) foreach ($rows as $r) { $g[$r['gpid']][(int)$rid] = $r; $when[$r['gpid']] = [(string)$r['race_date'], (int)$r['id']]; }
    uksort($g, fn($a, $b) => strcmp($when[$a][0], $when[$b][0]) ?: strcmp($a, $b));
    return $cache[$season_id] = $g;
}

// ── Kart Bingo ──────────────────────────────────────────────────────────────

/** Racer ids a card may name in "finish ahead of / behind" squares — see bingoTargetPool(). */
function bingoPeoplePool(PDO $pdo, string $season_id, int $racer_id): array {
    static $cache = [];
    if (!isset($cache[$season_id])) {
        $st = $pdo->prepare("SELECT season_id FROM season_meta WHERE status = 'archived' AND season_id < ? ORDER BY season_id DESC LIMIT 1");
        $st->execute([$season_id]);
        $prev = $st->fetchColumn();
        $ids = $prev ? array_map('intval', array_keys(getSeasonResultsByRacer($pdo, (string)$prev))) : [];
        if (!$ids) $ids = array_map('intval', $pdo->query("SELECT id FROM racers WHERE COALESCE(is_retired, 0) = 0")->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
        $cache[$season_id] = $ids;
    }
    return array_values(array_filter($cache[$season_id], fn($id) => $id !== $racer_id));
}

/** The pool of possible squares for a racer: [key, label, check(rows, groups, rid) => bool]. */
function bingoTargetPool(PDO $pdo, int $racer_id, string $season_id): array {
    $names = racerNamesMap($pdo);
    // "Beat X" squares are only fair against people who actually turn up. The
    // pool must also be STABLE for the whole season (the card is seeded from it),
    // so it is the previous archived season's roster — fixed once that season
    // closed — and only for the first season ever the non-retired racers.
    $others = bingoPeoplePool($pdo, $season_id, $racer_id);
    $cups = getMKAllCups();
    $anyRow = fn(callable $f) => fn($rows) => (bool)array_filter($rows, $f);
    $pool = [];
    foreach ([42, 45, 48, 51, 54, 57] as $n) $pool["exact:$n"] = ["Score exactly $n", $anyRow(fn($r) => (int)$r['gp_points'] === $n)];
    $pool['perfect']  = ['Post a perfect 60',      $anyRow(fn($r) => (int)$r['gp_points'] === MK_MAX_GP_POINTS)];
    $pool['under:30'] = ['Score under 30',          $anyRow(fn($r) => (int)$r['gp_points'] < 30)];
    $pool['range:50'] = ['Score between 50 and 55', $anyRow(fn($r) => (int)$r['gp_points'] >= 50 && (int)$r['gp_points'] <= 55)];
    foreach ([1, 2, 3, 4] as $p) $pool["place:$p"] = ['Finish ' . ordinal($p), $anyRow(fn($r) => (int)$r['rank'] === $p)];
    $pool['last_human'] = ['Finish last of the humans (2+ racing)', function ($rows, $groups, $rid) { foreach ($rows as $r) { $g = $groups[$r['gpid']] ?? []; if (count($g) >= 2 && (int)$r['gp_points'] <= min(array_map(fn($x) => (int)$x['gp_points'], $g))) return true; } return false; }];
    foreach ($cups as $c) $pool["cup:$c"] = ["Race $c Cup", $anyRow(fn($r) => $r['cup_name'] === $c)];
    $pool['triple'] = ['Three GPs in one night', function ($rows) { $n = []; foreach ($rows as $r) $n[$r['race_date']] = ($n[$r['race_date']] ?? 0) + 1; return $n && max($n) >= 3; }];
    $pool['podium2'] = ['Two podiums in a row', function ($rows) { usort($rows, fn($a, $b) => strcmp((string)$a['race_date'], (string)$b['race_date']) ?: ((int)$a['id'] <=> (int)$b['id'])); $run = 0; foreach ($rows as $r) { $run = (int)$r['rank'] <= 3 ? $run + 1 : 0; if ($run >= 2) return true; } return false; }];
    foreach ($others as $o) {
        $pool["beat:$o"]   = ['Finish ahead of ' . $names[$o],  function ($rows, $groups, $rid) use ($o) { foreach ($rows as $r) { $g = $groups[$r['gpid']] ?? []; if (isset($g[$o]) && (int)$r['gp_points'] > (int)$g[$o]['gp_points']) return true; } return false; }];
        $pool["behind:$o"] = ['Finish directly behind ' . $names[$o], function ($rows, $groups, $rid) use ($o) { foreach ($rows as $r) { $g = $groups[$r['gpid']] ?? []; if (!isset($g[$o])) continue; $pts = array_map(fn($x) => (int)$x['gp_points'], $g); arsort($pts); $order = array_keys($pts); $i = array_search($rid, $order, true); if ($i !== false && $i > 0 && $order[$i - 1] === $o) return true; } return false; }];
    }
    return $pool;
}

/** The racer's 9 squares for the season: seeded, so the card never changes. Weighted so a card is 3–4 "easy", 3 "cup", 2–3 "people" squares. */
function bingoCard(PDO $pdo, int $racer_id, string $season_id): array {
    static $cache = [];
    $k = "$season_id:$racer_id";
    if (isset($cache[$k])) return $cache[$k];
    $pool = bingoTargetPool($pdo, $racer_id, $season_id);
    $keys = array_keys($pool);
    $easy = array_values(array_filter($keys, fn($x) => !str_starts_with($x, 'cup:') && !str_starts_with($x, 'beat:') && !str_starts_with($x, 'behind:')));
    $cups = array_values(array_filter($keys, fn($x) => str_starts_with($x, 'cup:')));
    $people = array_values(array_filter($keys, fn($x) => str_starts_with($x, 'beat:') || str_starts_with($x, 'behind:')));
    mt_srand(crc32("bingo:$season_id:$racer_id"));
    $pick = function (array $from, int $n) { shuffle($from); return array_slice($from, 0, $n); };
    $chosen = array_merge($pick($easy, 4), $pick($cups, 3), $pick($people, min(2, count($people))));
    while (count($chosen) < 9) { $extra = $pick(array_diff($easy, $chosen), 1); if (!$extra) break; $chosen = array_merge($chosen, $extra); }
    shuffle($chosen);
    mt_srand();   // don't leave the global RNG seeded for the rest of the request
    $card = [];
    foreach (array_slice($chosen, 0, 9) as $key) $card[] = ['key' => $key, 'label' => $pool[$key][0]];
    return $cache[$k] = $card;
}

/** Card with done flags, lines completed, full-card flag, plain average and the score. */
function bingoProgress(PDO $pdo, int $racer_id, string $season_id, array $rules): array {
    static $cache = [];
    $k = "$season_id:$racer_id:" . (int)($rules['bg_line_pts'] ?? 100) . ':' . (int)($rules['bg_card_pts'] ?? 500);
    if (isset($cache[$k])) return $cache[$k];
    $rows = getRacerSeasonRows($pdo, $racer_id, $season_id);
    $groups = seasonGpGroups($pdo, $season_id);
    $pool = bingoTargetPool($pdo, $racer_id, $season_id);
    $card = bingoCard($pdo, $racer_id, $season_id);
    $done = [];
    foreach ($card as $i => $sq) { $card[$i]['done'] = $rows ? (bool)($pool[$sq['key']][1])($rows, $groups, $racer_id) : false; $done[$i] = $card[$i]['done']; }
    $lines = 0; $lineSets = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
    foreach ($lineSets as $L) if (count($card) === 9 && $done[$L[0]] && $done[$L[1]] && $done[$L[2]]) $lines++;
    $full = count($card) === 9 && count(array_filter($done)) === 9;
    $pts = array_map(fn($r) => (int)$r['gp_points'], $rows);
    $avg = $pts ? array_sum($pts) / count($pts) : 0;
    $score = $lines * (int)($rules['bg_line_pts'] ?? 100) + ($full ? (int)($rules['bg_card_pts'] ?? 500) : 0) + round($avg, 2);
    return $cache[$k] = ['card' => $card, 'done' => count(array_filter($done)), 'lines' => $lines, 'full' => $full, 'avg' => round($avg, 2), 'score' => round($score, 2), 'gps' => count($rows)];
}
function calculateBingoScore($pdo, $racer_id, $season_id, $rules) { return bingoProgress($pdo, (int)$racer_id, $season_id, (array)$rules)['score']; }
function breakdownBingo($pdo, $racer_id, $season_id, $rules) { return bingoProgress($pdo, (int)$racer_id, $season_id, (array)$rules) + ['line_pts' => (int)($rules['bg_line_pts'] ?? 100), 'card_pts' => (int)($rules['bg_card_pts'] ?? 500)]; }
function tooltipBingo(array $c, $score): string {
    return sprintf('🎱 %s · %d/9 squares · %d line%s × %d%s · avg %s as tiebreak', scoreNum($score), (int)($c['done'] ?? 0), (int)($c['lines'] ?? 0), ($c['lines'] ?? 0) === 1 ? '' : 's', (int)($c['line_pts'] ?? 100), !empty($c['full']) ? ' · FULL CARD +' . (int)($c['card_pts'] ?? 500) : '', scoreNum($c['avg'] ?? 0));
}

// ── The Price Is Right ──────────────────────────────────────────────────────

/** Per GP: target and every human's bid ranked; per racer: ladder per GP, best-N sum, hits, busts. */
function priceIsRightSeason(PDO $pdo, string $season_id, array $rules): array {
    static $cache = [];
    $mode = ($rules['pir_target'] ?? 'median') === 'mean' ? 'mean' : 'median';
    $bestN = max(1, (int)($rules['pir_best_n'] ?? 15));
    $k = "$season_id:$mode:$bestN";
    if (isset($cache[$k])) return $cache[$k];
    $gps = []; $racers = [];
    foreach (seasonGpGroups($pdo, $season_id) as $gpid => $g) {
        $pts = array_map(fn($r) => (int)$r['gp_points'], $g);
        $target = $mode === 'mean' ? array_sum($pts) / count($pts) : medianOf(array_values($pts));
        $bids = [];
        foreach ($g as $rid => $r) { $p = (int)$r['gp_points']; $bids[] = ['rid' => (int)$rid, 'pts' => $p, 'over' => $p > $target, 'gap' => abs($target - $p)]; }
        usort($bids, fn($a, $b) => ((int)$a['over'] <=> (int)$b['over']) ?: ($a['gap'] <=> $b['gap']) ?: ($a['rid'] <=> $b['rid']));
        $rank = 0; $prev = null;
        foreach ($bids as $i => &$b) { $sig = [(int)$b['over'], $b['gap']]; if ($sig !== $prev) { $rank = $i + 1; $prev = $sig; } $b['rank'] = $rank; $b['ladder'] = mkPointsForRank($rank); }
        unset($b);
        $gps[$gpid] = ['target' => round($target, 1), 'bids' => $bids];
        foreach ($bids as $b) { $racers[$b['rid']]['gps'][$gpid] = $b; }
    }
    foreach ($racers as $rid => &$x) {
        $lad = array_map(fn($b) => $b['ladder'], $x['gps']); rsort($lad);
        $x['score'] = array_sum(array_slice($lad, 0, $bestN)); $x['counted'] = min($bestN, count($lad));
        $x['hits'] = count(array_filter($x['gps'], fn($b) => $b['rank'] === 1 && !$b['over']));
        $x['busts'] = count(array_filter($x['gps'], fn($b) => $b['over']));
        $x['played'] = count($x['gps']);
    }
    unset($x);
    return $cache[$k] = ['gps' => $gps, 'racers' => $racers, 'mode' => $mode, 'best_n' => $bestN];
}
function calculatePriceScore($pdo, $racer_id, $season_id, $rules) { return priceIsRightSeason($pdo, $season_id, (array)$rules)['racers'][(int)$racer_id]['score'] ?? 0; }
function breakdownPrice($pdo, $racer_id, $season_id, $rules) { $s = priceIsRightSeason($pdo, $season_id, (array)$rules); $r = $s['racers'][(int)$racer_id] ?? ['score' => 0, 'counted' => 0, 'hits' => 0, 'busts' => 0, 'played' => 0, 'gps' => []]; return $r + ['mode' => $s['mode'], 'best_n' => $s['best_n'], 'targets' => array_map(fn($g) => $g['target'], $s['gps'])]; }
function tooltipPrice(array $c, $score): string {
    return sprintf('🏷️ %s ladder pts from best %d of %d GP%s · %d on the nose · %d over the %s', scoreNum($score), (int)($c['counted'] ?? 0), (int)($c['played'] ?? 0), ($c['played'] ?? 0) === 1 ? '' : 's', (int)($c['hits'] ?? 0), (int)($c['busts'] ?? 0), $c['mode'] ?? 'median');
}

// ── The Great Equaliser ─────────────────────────────────────────────────────

function equaliserSeason(PDO $pdo, string $season_id, array $rules): array {
    static $cache = [];
    $mode = ($rules['eq_mode'] ?? 'season') === 'per_gp' ? 'per_gp' : 'season';
    $k = "$season_id:$mode";
    if (isset($cache[$k])) return $cache[$k];
    $out = ['mode' => $mode, 'league_avg' => 0, 'racers' => []];
    $all = []; foreach (getSeasonResultsByRacer($pdo, $season_id) as $rows) foreach ($rows as $r) $all[] = (int)$r['gp_points'];
    $out['league_avg'] = $all ? round(array_sum($all) / count($all), 2) : 0;
    if ($mode === 'season') {
        foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) { $p = array_map(fn($r) => (int)$r['gp_points'], $rows); $avg = $p ? array_sum($p) / count($p) : 0; $dist = abs($avg - $out['league_avg']); $out['racers'][(int)$rid] = ['avg' => round($avg, 2), 'dist' => round($dist, 2), 'score' => round($out['league_avg'] - $dist, 2), 'gps' => count($p)]; }
    } else {
        $night = []; foreach (seasonGpGroups($pdo, $season_id) as $gpid => $g) { $p = array_map(fn($r) => (int)$r['gp_points'], $g); $night[$gpid] = array_sum($p) / count($p); }
        foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) { $vals = []; $dists = []; foreach ($rows as $r) { $n = $night[$r['gpid']] ?? 0; $vals[] = $n - abs((int)$r['gp_points'] - $n); $dists[] = abs((int)$r['gp_points'] - $n); } $p = array_map(fn($r) => (int)$r['gp_points'], $rows); $out['racers'][(int)$rid] = ['avg' => $p ? round(array_sum($p) / count($p), 2) : 0, 'dist' => $dists ? round(array_sum($dists) / count($dists), 2) : 0, 'score' => $vals ? round(array_sum($vals) / count($vals), 2) : 0, 'gps' => count($p)]; }
    }
    return $cache[$k] = $out;
}
function calculateEqualiserScore($pdo, $racer_id, $season_id, $rules) { return equaliserSeason($pdo, $season_id, (array)$rules)['racers'][(int)$racer_id]['score'] ?? 0; }
function breakdownEqualiser($pdo, $racer_id, $season_id, $rules) { $s = equaliserSeason($pdo, $season_id, (array)$rules); return ($s['racers'][(int)$racer_id] ?? ['avg' => 0, 'dist' => 0, 'score' => 0, 'gps' => 0]) + ['league_avg' => $s['league_avg'], 'mode' => $s['mode']]; }
function tooltipEqualiser(array $c, $score): string {
    return sprintf('⚖️ %s = league avg %s − your distance %s (you average %s%s)', scoreNum($score), scoreNum($c['league_avg'] ?? 0), scoreNum($c['dist'] ?? 0), scoreNum($c['avg'] ?? 0), ($c['mode'] ?? 'season') === 'per_gp' ? ', judged per GP' : '');
}

