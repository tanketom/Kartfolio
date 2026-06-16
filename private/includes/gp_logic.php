<?php
/**
 * GPScore™ Logic Engine - Season-Aware Version
 * Path: /cdnmk/private/includes/gp_logic.php
 */

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
            'qualifies_by_threshold' => false,
            'sort'                   => 'sortStandingsTop12Unique',
        ],
        'random_cup_draw' => [
            'name'                   => 'Random Cup Draw',
            'icon'                   => '🎲',
            'description'            => 'Assigned random cups',
            'long_description'       => 'Each player will be assigned a random set of cups at season start.',
            'calculate'              => 'calculateRandomCupDrawScore',
            'breakdown'              => null,
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
            'qualifies_by_threshold' => false,
            'sort'                   => null,
        ],
        'monster_hunt' => [
            'name'                   => 'MONSTER HUNT',
            'icon'                   => '👹',
            'description'            => 'XP per GP — the highest-Elo racer becomes the Monster; adventurers slay them for XP',
            'long_description'       => 'The Monster is the highest-Elo racer at race time (ties broken alphabetically; admins can override by flagging is_monster on result entry). CR multiplier (×1.0–×2.0) scales slay XP by the Elo gap. Ranking = average XP per GP.',
            'calculate'              => 'calculateMonsterHuntScore',
            'breakdown'              => 'breakdownMonsterHunt',
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
            'qualifies_by_threshold' => true,
            'sort'                   => null,
        ],
        'head_to_head' => [
            'name'                   => 'Head-to-Head',
            'icon'                   => '🤺',
            'description'            => 'Win rate across every head-to-head matchup',
            'long_description'       => 'Relative scoring built for small fields: in each GP you beat everyone you finish above and lose to everyone above you. Your score is your win rate across every head-to-head matchup all season — completely margin-blind and attendance-fair. A minimum-GPs threshold filters small-sample flukes; ties break on total wins.',
            'calculate'              => 'calculateHeadToHeadScore',
            'breakdown'              => 'breakdownHeadToHead',
            'qualifies_by_threshold' => true,
            'sort'                   => 'sortStandingsHeadToHead',
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
function calculateAverageAttendanceScore($pdo, $racer_id, $season_id, $rules) {
    // Default Fallbacks if rules aren't set yet
    $attWeight = $rules['attendance_weight'] ?? 1.0;
    $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
    $threshold = $rules['min_races_threshold'] ?? 3;
    $dropRate  = $rules['drop_rate'] ?? 10;

    // All race results for this racer in this season, from the shared
    // per-request cache (gp_points ASC). Tournament gpids never enter the
    // cache because the season prefix filter only matches 's%' gpids.
    $results = getRacerSeasonRows($pdo, $racer_id, $season_id);

    $totalRaces = count($results);

    // Ranking Threshold: Return 0 if they haven't raced enough
    if ($threshold > 0 && $totalRaces < $threshold) {
        return 0;
    }

    // Drop Logic (Worst scores removed)
    $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;

    // Extract points and slice off the lowest ones
    $pointsOnly = array_column($results, 'gp_points');
    $filteredPoints = array_slice($pointsOnly, $numToDrop);

    // Calculate Average
    $average = (count($filteredPoints) > 0) ? array_sum($filteredPoints) / count($filteredPoints) : 0;

    // Attendance Bonus with Weekly Cap
    $attendanceBonus = 0;
    $weeklyTracker = [];

    foreach ($results as $res) {
        $weekKey = date('Y-W', strtotime($res['race_date']));

        if (!isset($weeklyTracker[$weekKey])) {
            $weeklyTracker[$weekKey] = 0;
        }

        if ($weeklyTracker[$weekKey] < $weeklyCap) {
            $attendanceBonus += $attWeight;
            $weeklyTracker[$weekKey] += $attWeight;
        }
    }

    return round($average + $attendanceBonus, 2);
}

/**
 * Pre-Season System: Simple Average with 10% Drop
 * Used between official seasons for casual play
 */
function calculatePreSeasonScore($pdo, $racer_id, $season_id, $rules) {
    // All GP points for this racer, ASC, from the shared per-request cache.
    $results = array_column(getRacerSeasonRows($pdo, $racer_id, $season_id), 'gp_points');

    $totalRaces = count($results);
    if ($totalRaces === 0) return 0;

    // Drop 10% of worst scores (rounded down)
    $numToDrop = floor($totalRaces * 0.1);
    $filteredPoints = array_slice($results, $numToDrop);

    // Return average
    return round(array_sum($filteredPoints) / count($filteredPoints), 2);
}

/**
 * Cup-Based Scoring: Best Score on Each Required Cup
 * Sum of best scores across all cups (12 or 24)
 */
function calculateCupBasedScore($pdo, $racer_id, $season_id, $rules) {
    $requiredCups = array_slice(getMK8DCups(), 0, $rules['cups_required'] ?? 12);
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
    $requiredCups   = array_slice(getMK8DCups(), 0, $rules['cups_required'] ?? 12);
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
    $requiredCups      = array_slice(getMK8DCups(), 0, $rules['cups_required'] ?? 12);
    $perfectMultiplier = $rules['perfect_multiplier'] ?? 2.0;

    $totalScore = 0;
    foreach (getBestScorePerCup($pdo, $racer_id, $season_id, $requiredCups) as $score) {
        if ($score === null) continue;
        $totalScore += ($score == 60) ? ($score * $perfectMultiplier) : $score;
    }
    return round($totalScore, 2);
}

/**
 * Top 12 Unique: Best 12 GPs from 12 Separate Cups
 * Takes the best score from each cup, picks the top 12.
 * Tiebreaker: most perfect 60 scores in unique cups.
 */
function calculateTop12UniqueScore($pdo, $racer_id, $season_id, $rules) {
    $cupBests = array_values(array_filter(getBestScorePerCup($pdo, $racer_id, $season_id, getMK8DCups())));
    if (empty($cupBests)) return 0;
    rsort($cupBests);
    return round(array_sum(array_slice($cupBests, 0, 12)), 2);
}

/**
 * Top 12 Unique Tiebreaker: count of perfect 60s in unique cups
 */
function getTop12UniqueTiebreaker($pdo, $racer_id, $season_id) {
    $bestPerCup = getBestScorePerCup($pdo, $racer_id, $season_id, getMK8DCups());
    return count(array_filter($bestPerCup, fn($s) => $s === 60));
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
function mhComputeRaw($pdo, $racer_id, $season_id, $rules) {
    static $cache = [];
    $key = "{$racer_id}:{$season_id}";
    if (isset($cache[$key])) return $cache[$key];

    $slay_xp         = (int)($rules['mh_slay_xp']           ?? 100);
    $survive_xp      = (int)($rules['mh_survive_xp']         ?? 20);
    $party_bonus_xp  = (int)($rules['mh_party_bonus_xp']     ?? 50);
    $monster_win_xp  = (int)($rules['mh_monster_win_xp']     ?? 80);
    $monster_part_xp = (int)($rules['mh_monster_partial_xp'] ?? 30);
    $monster_loss_xp = (int)($rules['mh_monster_loss_xp']    ?? -40);

    // id → name map, one query for all racers per request (this function is
    // called once per racer on leaderboard pages).
    static $racerNames = null;
    if ($racerNames === null) {
        $racerNames = $pdo->query("SELECT id, name FROM racers")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $racerName = $racerNames[(int)$racer_id] ?? null;
    if (!$racerName) {
        return $cache[$key] = ['total_xp' => 0, 'gps' => 0];
    }

    $changelog = getMonsterHuntEloChangelog($pdo);

    // Season GP list is identical for every racer — fetch once per season.
    static $seasonGPCache = [];
    if (!isset($seasonGPCache[$season_id])) {
        $gpStmt = $pdo->prepare("
            SELECT DISTINCT gpid, race_date
            FROM results
            WHERE gpid LIKE ?
            ORDER BY race_date ASC, gpid ASC
        ");
        $gpStmt->execute([$season_id . '%']);
        $seasonGPCache[$season_id] = $gpStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $seasonGPs = $seasonGPCache[$season_id];

    $totalXP  = 0;
    $racerGPs = 0;
    $xpPerGP  = []; // gpid => xp earned

    foreach ($seasonGPs as $gp) {
        $gpid = $gp['gpid'];
        if (!isset($changelog[$gpid])) continue;
        $gpData = $changelog[$gpid];
        if (!isset($gpData[$racerName])) continue;
        $racerGPs++;

        if (count($gpData) < 2) {
            $totalXP += $survive_xp;
            $xpPerGP[$gpid] = $survive_xp;
            continue;
        }

        [$monsterName, $monsterElo] = pickMonster($gpid, $gpData, $pdo);

        $monsterRank    = $gpData[$monsterName]['rank'];
        $adventurerElos = [];
        foreach ($gpData as $name => $d) {
            if ($name !== $monsterName) $adventurerElos[] = $d['old_elo'];
        }
        $avgAdvElo = count($adventurerElos) > 0
            ? array_sum($adventurerElos) / count($adventurerElos)
            : $monsterElo;
        $eloGap = max(0, $monsterElo - $avgAdvElo);

        if      ($eloGap < 50)  $crMult = 1.0;
        elseif  ($eloGap < 150) $crMult = 1.25;
        elseif  ($eloGap < 300) $crMult = 1.5;
        else                    $crMult = 2.0;

        $advWon = $advLost = 0;
        foreach ($gpData as $name => $d) {
            if ($name === $monsterName) continue;
            if ($d['rank'] < $monsterRank) $advWon++; else $advLost++;
        }
        $fullSlay = ($advLost === 0 && $advWon > 0); // all adventurers beat Monster
        $isTpk    = ($advWon === 0);                 // Monster beat all (TPK)

        if ($racerName === $monsterName) {
            if ($isTpk)          $gpXP = $monster_win_xp;
            elseif ($fullSlay)   $gpXP = $monster_loss_xp;
            else                 $gpXP = $monster_part_xp;
        } else {
            $myRank = $gpData[$racerName]['rank'];
            if ($myRank < $monsterRank) {
                $gpXP = (int)round($slay_xp * $crMult);
                if ($fullSlay) $gpXP += $party_bonus_xp;
            } else {
                $gpXP = $survive_xp;
            }
        }

        $totalXP += $gpXP;
        $xpPerGP[$gpid] = $gpXP;
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

const POSITIONAL_POINTS_SCALE = [15, 12, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];

/** Positional points for a 1-based finish rank. 0 past the scale. */
function positionalPointsForRank(int $rank): int {
    return POSITIONAL_POINTS_SCALE[$rank - 1] ?? 0;
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
        $perGP[$row['gpid']] = positionalPointsForRank((int)$row['rank']);
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
        if ($pts === POSITIONAL_POINTS_SCALE[0]) $wins++; // top of the ladder = a GP win
    }
    $mode = $rules['pos_mode'] ?? 'best_n';
    return [
        'mode'         => $mode,
        'gps_played'   => $raw['gps'],
        'total_points' => array_sum($vals),
        'best_night'   => !empty($vals) ? $vals[0] : 0,
        'best_n'       => (int)($rules['best_n_count'] ?? 15),
        'counted'      => $mode === 'best_n' ? min((int)($rules['best_n_count'] ?? 15), $raw['gps']) : $raw['gps'],
        'wins'         => $wins,
        'score'        => calculatePositionalScore($pdo, $racer_id, $season_id, $rules),
    ];
}

// ============================================================================
// HEAD-TO-HEAD  ("Duels")  — RELATIVE
//
// In each GP you "beat" everyone you finish above and "lose" to everyone above
// you. Season score is your WIN RATE across every head-to-head matchup —
// margin-blind and attendance-fair (a ratio). The min_races_threshold gate
// filters small-sample flukes; ties break on absolute wins then name.
// ============================================================================

/**
 * Aggregate head-to-head record for a racer in a season.
 * Returns ['wins','losses','matchups','gps','rate'(0-100, 1dp)].
 */
function headToHeadRaw(PDO $pdo, int $racer_id, string $season_id): array {
    static $cache = [];
    $key = "$racer_id|$season_id";
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $pdo->prepare("
        SELECT (SELECT COUNT(*) FROM results WHERE gpid = res.gpid) AS participants,
               res.rank
        FROM results res
        WHERE res.racer_id = ? AND res.gpid LIKE ? AND res.gpid LIKE 's%'
    ");
    $stmt->execute([$racer_id, $season_id . '%']);

    $wins = 0; $losses = 0; $gps = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $n = (int)$row['participants'];
        if ($n < 2) continue; // a solo GP has no duels
        $rank    = (int)$row['rank'];
        $wins   += ($n - $rank);   // everyone you finished above
        $losses += ($rank - 1);    // everyone above you
        $gps++;
    }
    $matchups = $wins + $losses;
    $rate = $matchups > 0 ? round($wins / $matchups * 100, 1) : 0.0;
    return $cache[$key] = compact('wins', 'losses', 'matchups', 'gps') + ['rate' => $rate];
}

function calculateHeadToHeadScore($pdo, $racer_id, $season_id, $rules) {
    return headToHeadRaw($pdo, (int)$racer_id, $season_id)['rate'];
}

function breakdownHeadToHead($pdo, $racer_id, $season_id, $rules) {
    $raw = headToHeadRaw($pdo, (int)$racer_id, $season_id);
    return [
        'wins'       => $raw['wins'],
        'losses'     => $raw['losses'],
        'matchups'   => $raw['matchups'],
        'gps_played' => $raw['gps'],
        'win_rate'   => $raw['rate'],
    ];
}

/** Custom sort for head_to_head — win rate desc, then absolute wins, then name. */
function sortStandingsHeadToHead(array &$standings, PDO $pdo, string $season_id): void {
    foreach ($standings as &$s) {
        $s['tiebreaker'] = headToHeadRaw($pdo, (int)$s['id'], $season_id)['wins'];
    }
    unset($s);
    usort($standings, function ($a, $b) {
        if ($b['score'] != $a['score'])           return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
}

/**
 * Helper to get the next GPID (e.g., s01-14) based on existing data
 */
function getNextGPID($pdo) {
    $stmt = $pdo->query("SELECT gpid FROM results ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    if (!$last) return "s01-01";

    $parts = explode('-', $last);
    if (count($parts) < 2) return "s01-01";

    $num = (int)$parts[1] + 1;
    return $parts[0] . "-" . str_pad($num, 2, '0', STR_PAD_LEFT);
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
 * Cup list for MK8D — back-compat forwarder.
 * The canonical list lives in mk_data.php; keep this for older callers.
 */
function getMK8DCups(): array {
    if (!function_exists('getMKAllCups')) {
        require_once __DIR__ . '/mk_data.php';
    }
    return getMKAllCups();
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
    $requiredCups = array_slice(getMK8DCups(), $offset, $cupsRequired);
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
            'improvement_potential' => 60 - $best,
            'is_perfect'            => $best === 60,
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

// ── Breakdown helpers (registry-referenced) ──────────────────────────────

function breakdownAverageAttendance($pdo, $racer_id, $season_id, $rules) {
    $attWeight = $rules['attendance_weight'] ?? 1.0;
    $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
    $dropRate  = $rules['drop_rate'] ?? 10;
    $aaResults = getRacerSeasonRows($pdo, $racer_id, $season_id);
    $totalRaces = count($aaResults);
    $numDropped = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
    $filtered = array_slice(array_column($aaResults, 'gp_points'), $numDropped);
    $avg = count($filtered) > 0 ? round(array_sum($filtered) / count($filtered), 2) : 0;
    $att = 0; $weeklyTracker = [];
    foreach ($aaResults as $res) {
        $wk = date('Y-W', strtotime($res['race_date']));
        if (!isset($weeklyTracker[$wk])) $weeklyTracker[$wk] = 0;
        if ($weeklyTracker[$wk] < $weeklyCap) { $att += $attWeight; $weeklyTracker[$wk] += $attWeight; }
    }
    return [
        'total_races'   => $totalRaces,
        'races_counted' => $totalRaces - $numDropped,
        'races_dropped' => $numDropped,
        'avg'           => $avg,
        'att'           => round($att, 2),
    ];
}

function breakdownPreseason($pdo, $racer_id, $season_id, $rules) {
    $psTotalRaces = count(getRacerSeasonRows($pdo, $racer_id, $season_id));
    $psDropped = floor($psTotalRaces * 0.1);
    return [
        'total_races'   => $psTotalRaces,
        'races_counted' => $psTotalRaces - $psDropped,
        'races_dropped' => $psDropped,
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
    $bestPerCup = getBestScorePerCup($pdo, $racer_id, $season_id, getMK8DCups());
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

/** Canonical Mario Kart 12-position points scale. Index 0 = 1st place. */
const MIKKOLIIGA_POINTS_SCALE = [15, 12, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];

/** How many of a member's GPs count toward their season total. Drives every
 *  user-visible "best N counted" string too — change here, change everywhere. */
const MIKKOLIIGA_BEST_X = 10;

/** Internal score for an internal rank (1-based). 0 if past the scale. */
function mikkoliigaPointsForRank(int $internalRank): int {
    return MIKKOLIIGA_POINTS_SCALE[$internalRank - 1] ?? 0;
}

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
    $members = getMikkoliigaMemberIds($pdo, $season_id);
    if (!isset($members[$racer_id])) return [];
    if (empty($members)) return [];

    $memberIds = array_keys($members);
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

    // Pull every GP this racer played, with all co-participating Mikkoliiga
    // members and their gp_points.
    $sql = "
        SELECT res.gpid, res.racer_id, res.gp_points
        FROM results res
        WHERE res.racer_id IN ($placeholders)
          AND res.gpid LIKE ?
          AND res.gpid IN (
              SELECT gpid FROM results WHERE racer_id = ? AND gpid LIKE ?
          )
        ORDER BY res.gpid ASC, res.gp_points DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(
        $memberIds,
        [$season_id . '%', $racer_id, $season_id . '%']
    ));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byGP = [];
    foreach ($rows as $row) {
        $byGP[$row['gpid']][] = ['rid' => (int)$row['racer_id'], 'pts' => (int)$row['gp_points']];
    }

    $perGP = [];
    foreach ($byGP as $gpid => $participants) {
        // Mikkoliiga is a head-to-head among members — a GP only counts if at
        // least TWO Mikkoliiga members raced it. A lone member can't "win" an
        // empty field and bank a free 15.
        if (count($participants) < 2) continue;

        usort($participants, fn($a, $b) => $b['pts'] <=> $a['pts']);
        foreach ($participants as $i => $p) {
            if ($p['rid'] === $racer_id) {
                $perGP[$gpid] = mikkoliigaPointsForRank($i + 1);
                break;
            }
        }
    }
    return $perGP;
}

/**
 * Total Mikkoliiga season score for a racer: sum of best MIKKOLIIGA_BEST_X
 * internal GPs. Returns 0 if the racer isn't a Mikkoliiga member for that
 * season (live flag for active, snapshot for archived).
 */
function calculateMikkoliigaScore(PDO $pdo, int $racer_id, string $season_id): int {
    $members = getMikkoliigaMemberIds($pdo, $season_id);
    if (!isset($members[$racer_id])) return 0;

    $perGP = mikkoliigaScorePerGP($pdo, $racer_id, $season_id);
    if (empty($perGP)) return 0;

    $scores = array_values($perGP);
    rsort($scores);
    return array_sum(array_slice($scores, 0, MIKKOLIIGA_BEST_X));
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
function racerSeasonStats($pdo, $racer_id, $season_id): array {
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
    static $nameCache = [];
    if (!isset($nameCache[$racer_id])) {
        $ns = $pdo->prepare("SELECT name FROM racers WHERE id = ?");
        $ns->execute([$racer_id]);
        $nameCache[$racer_id] = $ns->fetchColumn() ?: '';
    }
    $rname = $nameCache[$racer_id];
    if ($rname !== '') {
        if (!function_exists('calculateAllELORatings')) require_once __DIR__ . '/elo_engine.php';
        $elo = calculateAllELORatings($pdo);
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
        'has_perfect'           => $best === 60,
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

    $attWeight = $rules['attendance_weight'] ?? 1.0;
    $weeklyCap = $rules['weekly_bonus_cap']  ?? 2;
    $dropRate  = $rules['drop_rate']         ?? 10;

    // The shared season cache holds every row with race_date; filtering in
    // PHP replaces the old one-query-per-racer loop. Cache order is
    // gp_points ASC, which is exactly what the drop logic below needs.
    $temp = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $allRows) {
        $rows = array_values(array_filter($allRows, fn($r) => $r['race_date'] <= $prevDate));
        if (empty($rows)) continue;

        $numToDrop = $dropRate > 0 ? (int)floor(count($rows) / $dropRate) : 0;
        $filtered  = array_slice(array_column($rows, 'gp_points'), $numToDrop);
        $average   = $filtered ? array_sum($filtered) / count($filtered) : 0;

        $bonus = 0; $weekly = [];
        foreach ($rows as $row) {
            $wk = date('Y-W', strtotime($row['race_date']));
            $weekly[$wk] = $weekly[$wk] ?? 0;
            if ($weekly[$wk] < $weeklyCap) { $bonus += $attWeight; $weekly[$wk] += $attWeight; }
        }
        $temp[] = ['id' => $rid, 'score' => $average + $bonus];
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

/**
 * Sort a standings array in-place according to the active scoring system.
 * Each entry must have 'score', 'name', and 'id'.
 * For top_12_unique, tiebreaker values are fetched and added as 'tiebreaker'.
 */
function sortStandingsByScoring(array &$standings, $system, $pdo = null, $season_id = null) {
    $def = getScoringSystemDef($system);

    if ($def['sort'] !== null && $pdo && $season_id) {
        $fn = $def['sort'];
        $fn($standings, $pdo, $season_id);
        return;
    }
    // Default: score desc, then name asc (deterministic).
    usort($standings, fn($a, $b) => $b['score'] != $a['score']
        ? $b['score'] <=> $a['score']
        : strcmp($a['name'], $b['name']));
}

/** Custom sort for top_12_unique — uses a unique-60s tiebreaker. */
function sortStandingsTop12Unique(array &$standings, PDO $pdo, string $season_id): void {
    foreach ($standings as &$s) {
        $s['tiebreaker'] = getTop12UniqueTiebreaker($pdo, $s['id'], $season_id);
    }
    unset($s);
    usort($standings, function ($a, $b) {
        if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
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