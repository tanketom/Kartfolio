<?php
/**
 * ELO Rating Engine - Shared Calculation Module
 * Path: /cdnmk/private/includes/elo_engine.php
 *
 * Extracted from elo_trends.php for reuse across predictions, power rankings, and records.
 */

// ELO Parameters
if (!defined('ELO_INITIAL_RATING'))  define('ELO_INITIAL_RATING', 1500);
if (!defined('ELO_K_FACTOR_NEW'))    define('ELO_K_FACTOR_NEW', 40);
if (!defined('ELO_K_FACTOR_MID'))    define('ELO_K_FACTOR_MID', 30);
if (!defined('ELO_K_FACTOR_VET'))    define('ELO_K_FACTOR_VET', 20);
if (!defined('ELO_MIN_RATING'))      define('ELO_MIN_RATING', 100);
if (!defined('ELO_FIELD_SIZE'))      define('ELO_FIELD_SIZE', 12);    // Total racers per GP (humans + CPUs)
if (!defined('ELO_CPU_RATING'))      define('ELO_CPU_RATING', 1200);  // Fixed ELO for CPU opponents

/**
 * Calculate expected score for a racer against all opponents
 */
function eloExpectedScore($racerRating, $opponentRatings) {
    $expected = 0;
    foreach ($opponentRatings as $oppRating) {
        $expected += 1 / (1 + pow(10, ($oppRating - $racerRating) / 400));
    }
    return $expected;
}

/**
 * Get K-factor based on games played
 */
function eloKFactor($gamesPlayed) {
    if ($gamesPlayed < 10) return ELO_K_FACTOR_NEW;
    if ($gamesPlayed < 30) return ELO_K_FACTOR_MID;
    return ELO_K_FACTOR_VET;
}

/**
 * Calculate all ELO ratings from scratch using all race results.
 *
 * @param PDO $pdo Database connection
 * @return array [
 *   'ratings'      => ['Name' => float, ...],           // Current ratings
 *   'games_played' => ['Name' => int, ...],              // Total GPs per racer
 *   'history'      => ['Name' => [{date, rating, change}, ...], ...],
 *   'all_changes'  => [{racer, gpid, date, change, rank, old_rating, new_rating}, ...],
 *   'gp_changelog' => [{gpid, date, cup, racers: [{name, rank, points, old, new, change, expected, actual, k}]}, ...],
 *   'timeline'     => [date1, date2, ...]
 * ]
 */
function calculateAllELORatings($pdo) {
    // Per-request memoization. This is a pure function of the results table,
    // but it's heavy (walks every row), and several pages call it more than
    // once per request via different helpers. Cache keyed on a cheap table
    // signature so the result auto-invalidates if a row is inserted/edited
    // mid-request (e.g. add_result.php logs a GP, then renders standings).
    static $cache = [];
    $sig = $pdo->query("SELECT COUNT(*) || ':' || COALESCE(MAX(id),0) FROM results")->fetchColumn();
    if (isset($cache[$sig])) return $cache[$sig];

    // 1. Fetch ALL Race Results (chronologically - ELO is always all-time)
    $stmt = $pdo->query("
        SELECT res.gpid, res.race_date, res.racer_id, r.name, res.rank, res.gp_points, res.cup_name
        FROM results res
        JOIN racers r ON res.racer_id = r.id
        ORDER BY res.race_date ASC, res.gpid ASC, res.rank ASC
    ");
    $all_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Group results by Grand Prix
    $gps = [];
    foreach ($all_results as $result) {
        $gpid = $result['gpid'];
        if (!isset($gps[$gpid])) {
            $gps[$gpid] = [
                'date' => $result['race_date'],
                'cup' => $result['cup_name'] ?? '',
                'results' => []
            ];
        }
        $gps[$gpid]['results'][] = $result;
    }

    // 3. Process Each GP and Calculate ELO Progression
    $ratings = [];
    $games_played = [];
    $rating_history = [];
    $all_changes = [];
    $gp_changelog = [];
    $timeline = [];

    foreach ($gps as $gpid => $gp) {
        $gpDate = $gp['date'];
        $results = $gp['results'];
        $numHumans = count($results);
        $numCPUs = ELO_FIELD_SIZE - $numHumans; // Typically 8-10 CPU opponents

        $timeline[] = $gpDate;

        // Initialize new racers
        foreach ($results as $result) {
            $racer = $result['name'];
            if (!isset($ratings[$racer])) {
                $ratings[$racer] = ELO_INITIAL_RATING;
                $games_played[$racer] = 0;
            }
        }

        // Calculate rating changes for this GP
        $changes = [];
        foreach ($results as $result) {
            $racer = $result['name'];
            $currentRating = $ratings[$racer];
            $k = eloKFactor($games_played[$racer]);

            // Actual score = opponents beaten out of all 11 opponents (humans + CPUs)
            // rank is out of 12 total, so opponents beaten = 12 - rank
            $actualScore = ELO_FIELD_SIZE - $result['rank'];

            // Get opponent ratings: other humans (real ELO) + CPUs (fixed rating)
            $opponentRatings = [];
            foreach ($results as $opp) {
                if ($opp['name'] !== $racer) {
                    $opponentRatings[] = $ratings[$opp['name']];
                }
            }
            // Add CPU opponents at fixed rating
            for ($cpu = 0; $cpu < $numCPUs; $cpu++) {
                $opponentRatings[] = ELO_CPU_RATING;
            }

            // Calculate expected score (now against all 11 opponents)
            $expectedScore = eloExpectedScore($currentRating, $opponentRatings);

            // Calculate rating change
            $ratingChange = $k * ($actualScore - $expectedScore);

            $changes[$racer] = [
                'old' => $currentRating,
                'change' => $ratingChange,
                'new' => $currentRating + $ratingChange,
                'expected' => $expectedScore,
                'actual' => $actualScore,
                'rank' => $result['rank'],
                'points' => $result['gp_points']
            ];

            // Track all changes for upset detection
            $all_changes[] = [
                'racer' => $racer,
                'gpid' => $gpid,
                'date' => $gpDate,
                'change' => $ratingChange,
                'rank' => $result['rank'],
                'old_rating' => $currentRating,
                'new_rating' => $currentRating + $ratingChange
            ];
        }

        // Build changelog entry for this GP
        $gpLog = [
            'gpid' => $gpid,
            'date' => $gpDate,
            'cup'  => $gp['cup'],
            'racers' => []
        ];

        // Sort changes by rank for display
        $sortedChanges = $changes;
        uasort($sortedChanges, fn($a, $b) => $a['rank'] <=> $b['rank']);

        foreach ($sortedChanges as $racer => $change) {
            $gpLog['racers'][] = [
                'name'     => $racer,
                'rank'     => $change['rank'],
                'points'   => $change['points'],
                'old'      => round($change['old'], 1),
                'new'      => round(max(ELO_MIN_RATING, $change['new']), 1),
                'change'   => round($change['change'], 1),
                'expected' => round($change['expected'], 1),
                'actual'   => $change['actual'],
                'k'        => eloKFactor($games_played[$racer])
            ];
        }
        $gp_changelog[] = $gpLog;

        // Apply changes and record history
        foreach ($changes as $racer => $change) {
            $newRating = max(ELO_MIN_RATING, $change['new']);
            $ratings[$racer] = $newRating;
            $games_played[$racer]++;

            if (!isset($rating_history[$racer])) {
                $rating_history[$racer] = [];
            }
            $rating_history[$racer][] = [
                'date' => $gpDate,
                'rating' => $newRating,
                'change' => $change['change']
            ];
        }

        // For racers who didn't participate in this GP, maintain their rating
        foreach ($ratings as $racer => $rating) {
            if (!isset($changes[$racer])) {
                if (!isset($rating_history[$racer])) {
                    $rating_history[$racer] = [];
                }
                $rating_history[$racer][] = [
                    'date' => $gpDate,
                    'rating' => $rating,
                    'change' => 0
                ];
            }
        }
    }

    $timeline = array_values(array_unique($timeline));
    sort($timeline);

    return $cache[$sig] = [
        'ratings'      => $ratings,
        'games_played' => $games_played,
        'history'      => $rating_history,
        'all_changes'  => $all_changes,
        'gp_changelog' => $gp_changelog,
        'timeline'     => $timeline
    ];
}
