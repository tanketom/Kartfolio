<?php
/**
 * GPScore™ Logic Engine - Season-Aware Version
 * Path: /cdnmk/private/includes/gp_logic.php
 */

/**
 * Calculates the GPScore for a specific racer within a specific season.
 * Now supports multiple scoring systems based on season_meta.scoring_system
 */
function calculateGPScore($pdo, $racer_id, $season_id) {
    // 1. Fetch Season Rules from Metadata
    $stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
    $stmt->execute([$season_id]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get scoring system (default to average_attendance for legacy seasons)
    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    // Route to appropriate calculation based on system
    switch ($scoringSystem) {
        case 'preseason':
            return calculatePreSeasonScore($pdo, $racer_id, $season_id, $rules);

        case 'cup_based':
            return calculateCupBasedScore($pdo, $racer_id, $season_id, $rules);

        case 'best_n_gps':
            return calculateBestNGPsScore($pdo, $racer_id, $season_id, $rules);

        case 'drop_worst':
            return calculateDropWorstScore($pdo, $racer_id, $season_id, $rules);

        case 'perfect_hunt':
            return calculatePerfectHuntScore($pdo, $racer_id, $season_id, $rules);

        case 'top_12_unique':
            return calculateTop12UniqueScore($pdo, $racer_id, $season_id, $rules);

        case 'black_box':
            return calculateBlackBoxScore($pdo, $racer_id, $season_id, $rules);

        case 'random_cup_draw':
            return calculateRandomCupDrawScore($pdo, $racer_id, $season_id, $rules);

        case 'average_attendance':
        default:
            return calculateAverageAttendanceScore($pdo, $racer_id, $season_id, $rules);
    }
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

    // Fetch all race results for this racer in this season
    // IMPORTANT: Exclude tournament races (gpid starts with 't') - only include season races (gpid starts with 's')
    $stmt = $pdo->prepare("SELECT gp_points, race_date FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' ORDER BY gp_points ASC");
    $stmt->execute([$racer_id, $season_id . "%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    // Fetch all GP results
    $stmt = $pdo->prepare("SELECT gp_points FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' ORDER BY gp_points ASC");
    $stmt->execute([$racer_id, $season_id . "%"]);
    $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

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
    $cupsRequired = $rules['cups_required'] ?? 12;

    // Get list of cups to check
    $allCups = getMK8DCups();
    $requiredCups = array_slice($allCups, 0, $cupsRequired);

    $totalScore = 0;
    $cupsCompleted = 0;

    foreach ($requiredCups as $cupName) {
        // Get best score for this cup
        $stmt = $pdo->prepare("
            SELECT MAX(gp_points) as best_score
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([$racer_id, $season_id . '%', $cupName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['best_score']) {
            $totalScore += $result['best_score'];
            $cupsCompleted++;
        }
    }

    return round($totalScore, 2);
}

/**
 * Best N GPs: Sum of Best N GP Scores
 * All other GPs dropped automatically
 */
function calculateBestNGPsScore($pdo, $racer_id, $season_id, $rules) {
    $bestN = $rules['best_n_count'] ?? 15;

    // Fetch all GP scores, ordered by points DESC
    $stmt = $pdo->prepare("
        SELECT gp_points
        FROM results
        WHERE racer_id = ?
            AND gpid LIKE ?
            AND gpid LIKE 's%'
        ORDER BY gp_points DESC
        LIMIT ?
    ");
    $stmt->execute([$racer_id, $season_id . '%', $bestN]);
    $topScores = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($topScores)) return 0;

    return round(array_sum($topScores), 2);
}

/**
 * Drop Worst Cups: Play All Cups, Drop N Worst
 * More forgiving than strict cup-based
 */
function calculateDropWorstScore($pdo, $racer_id, $season_id, $rules) {
    $cupsRequired = $rules['cups_required'] ?? 12;
    $dropWorstCount = $rules['drop_worst_count'] ?? 2;

    // Get list of cups
    $allCups = getMK8DCups();
    $requiredCups = array_slice($allCups, 0, $cupsRequired);

    $cupScores = [];

    foreach ($requiredCups as $cupName) {
        // Get best score for this cup
        $stmt = $pdo->prepare("
            SELECT MAX(gp_points) as best_score
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([$racer_id, $season_id . '%', $cupName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['best_score']) {
            $cupScores[] = (int)$result['best_score'];
        }
    }

    if (empty($cupScores)) return 0;

    // Sort ascending, drop worst N
    sort($cupScores);
    $filteredScores = array_slice($cupScores, $dropWorstCount);

    return round(array_sum($filteredScores), 2);
}

/**
 * Perfect Hunt: Bonus Multipliers for Perfect 60 Scores
 * Cup-based with multipliers for excellence
 */
function calculatePerfectHuntScore($pdo, $racer_id, $season_id, $rules) {
    $cupsRequired = $rules['cups_required'] ?? 12;
    $perfectMultiplier = $rules['perfect_multiplier'] ?? 2.0;

    $allCups = getMK8DCups();
    $requiredCups = array_slice($allCups, 0, $cupsRequired);

    $totalScore = 0;

    foreach ($requiredCups as $cupName) {
        $stmt = $pdo->prepare("
            SELECT MAX(gp_points) as best_score
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([$racer_id, $season_id . '%', $cupName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['best_score']) {
            $score = (int)$result['best_score'];

            // Apply multiplier for perfect scores
            if ($score == 60) {
                $totalScore += ($score * $perfectMultiplier);
            } else {
                $totalScore += $score;
            }
        }
    }

    return round($totalScore, 2);
}

/**
 * Top 12 Unique: Best 12 GPs from 12 Separate Cups
 * Takes the best score from each cup, picks the top 12.
 * Tiebreaker: most perfect 60 scores in unique cups.
 */
function calculateTop12UniqueScore($pdo, $racer_id, $season_id, $rules) {
    $allCups = getMK8DCups();

    // Get best score per cup
    $cupBests = [];
    foreach ($allCups as $cupName) {
        $stmt = $pdo->prepare("
            SELECT MAX(gp_points) as best_score
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([$racer_id, $season_id . '%', $cupName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['best_score']) {
            $cupBests[] = (int)$result['best_score'];
        }
    }

    if (empty($cupBests)) return 0;

    // Sort descending and take top 12
    rsort($cupBests);
    $top12 = array_slice($cupBests, 0, 12);

    return round(array_sum($top12), 2);
}

/**
 * Top 12 Unique Tiebreaker: count of perfect 60s in unique cups
 */
function getTop12UniqueTiebreaker($pdo, $racer_id, $season_id) {
    $allCups = getMK8DCups();

    $perfect60Count = 0;
    foreach ($allCups as $cupName) {
        $stmt = $pdo->prepare("
            SELECT MAX(gp_points) as best_score
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([$racer_id, $season_id . '%', $cupName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && (int)($result['best_score'] ?? 0) === 60) {
            $perfect60Count++;
        }
    }

    return $perfect60Count;
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
    // Fetch all GP results for this racer in this season
    $stmt = $pdo->prepare("
        SELECT gp_points, race_date
        FROM results
        WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
        ORDER BY race_date ASC, gp_points ASC
    ");
    $stmt->execute([$racer_id, $season_id . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRaces = count($results);
    if ($totalRaces === 0) return 0;
    if ($totalRaces < 3) return 0; // Min threshold built-in

    // --- Career average (across ALL seasons) for comeback multiplier ---
    $careerStmt = $pdo->prepare("SELECT AVG(gp_points) as career_avg FROM results WHERE racer_id = ? AND gpid LIKE 's%'");
    $careerStmt->execute([$racer_id]);
    $careerAvg = (float)($careerStmt->fetchColumn() ?: 40);

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

    $totalScore = 0;

    foreach ($racerCups as $cupName) {
        $stmt = $pdo->prepare("
            SELECT MAX(gp_points) as best_score
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([$racer_id, $season_id . '%', $cupName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['best_score']) {
            $totalScore += $result['best_score'];
        }
    }

    return round($totalScore, 2);
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
 * Returns current season ID by finding the latest non-archived season.
 * Falls back to the latest season overall if none match.
 */
function getCurrentSeasonNumber() {
    static $cached = null;
    if ($cached !== null) return $cached;

    global $pdo;
    if ($pdo) {
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
 * Hardcoded Cup list for MK8D
 * First 12 are base game, next 12 are DLC
 */
function getMK8DCups() {
    return [
        // Base Game Cups (12)
        "Mushroom", "Flower", "Star", "Special",
        "Shell", "Banana", "Leaf", "Lightning",
        "Egg", "Triforce", "Crossing", "Bell",

        // DLC Cups (12)
        "Golden Dash", "Lucky Cat", "Turnip", "Propeller",
        "Rock", "Moon", "Fruit", "Boomerang",
        "Feather", "Cherry", "Acorn", "Spiny"
    ];
}

/**
 * Get Cup Progress for Cup-Based Scoring Systems
 * Returns detailed progress for each cup
 */
function getCupProgress($pdo, $racer_id, $season_id, $cupsRequired = 12) {
    $allCups = getMK8DCups();
    $requiredCups = array_slice($allCups, 0, $cupsRequired);

    $progress = [];

    foreach ($requiredCups as $cupName) {
        $stmt = $pdo->prepare("
            SELECT
                MAX(gp_points) as best_score,
                COUNT(*) as attempts,
                MAX(race_date) as last_played,
                (SELECT gpid FROM results
                 WHERE racer_id = ?
                   AND gpid LIKE ?
                   AND gpid LIKE 's%'
                   AND cup_name = ?
                   AND gp_points = (SELECT MAX(gp_points) FROM results WHERE racer_id = ? AND gpid LIKE ? AND cup_name = ?)
                 LIMIT 1) as best_gpid
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([
            $racer_id, $season_id . '%', $cupName,
            $racer_id, $season_id . '%', $cupName,
            $racer_id, $season_id . '%', $cupName
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $progress[$cupName] = [
            'best_score' => $result['best_score'] ?? 0,
            'attempts' => $result['attempts'] ?? 0,
            'completed' => $result['best_score'] ? true : false,
            'last_played' => $result['last_played'] ?? null,
            'best_gpid' => $result['best_gpid'] ?? null,
            'improvement_potential' => 60 - ($result['best_score'] ?? 0),
            'is_perfect' => ($result['best_score'] ?? 0) == 60
        ];
    }

    return $progress;
}

/**
 * Get Cup Progress for DLC Cups (cups 13-24)
 * Returns detailed progress for each DLC cup
 */
function getDLCCupProgress($pdo, $racer_id, $season_id) {
    $allCups = getMK8DCups();
    $dlcCups = array_slice($allCups, 12, 12); // DLC cups are indices 12-23

    $progress = [];

    foreach ($dlcCups as $cupName) {
        $stmt = $pdo->prepare("
            SELECT
                MAX(gp_points) as best_score,
                COUNT(*) as attempts,
                MAX(race_date) as last_played,
                (SELECT gpid FROM results
                 WHERE racer_id = ?
                   AND gpid LIKE ?
                   AND gpid LIKE 's%'
                   AND cup_name = ?
                   AND gp_points = (SELECT MAX(gp_points) FROM results WHERE racer_id = ? AND gpid LIKE ? AND cup_name = ?)
                 LIMIT 1) as best_gpid
            FROM results
            WHERE racer_id = ?
                AND gpid LIKE ?
                AND gpid LIKE 's%'
                AND cup_name = ?
        ");
        $stmt->execute([
            $racer_id, $season_id . '%', $cupName,
            $racer_id, $season_id . '%', $cupName,
            $racer_id, $season_id . '%', $cupName
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $progress[$cupName] = [
            'best_score' => $result['best_score'] ?? 0,
            'attempts' => $result['attempts'] ?? 0,
            'completed' => $result['best_score'] ? true : false,
            'last_played' => $result['last_played'] ?? null,
            'best_gpid' => $result['best_gpid'] ?? null,
            'improvement_potential' => 60 - ($result['best_score'] ?? 0),
            'is_perfect' => ($result['best_score'] ?? 0) == 60
        ];
    }

    return $progress;
}

/**
 * Get Scoring System Details for Display
 * Returns human-readable info about current season's scoring
 */
function getScoringSystemInfo($pdo, $season_id) {
    $stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
    $stmt->execute([$season_id]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rules) {
        return [
            'system' => 'average_attendance',
            'name' => 'Average + Attendance',
            'description' => 'Default scoring system',
            'icon' => '📊'
        ];
    }

    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    $systems = [
        'preseason' => [
            'name' => 'Pre-Season',
            'description' => 'Simple average with 10% drop',
            'icon' => '🌟'
        ],
        'average_attendance' => [
            'name' => 'Average + Attendance',
            'description' => 'Average GP score with attendance bonuses',
            'icon' => '📊'
        ],
        'cup_based' => [
            'name' => 'Cup-Based',
            'description' => ($rules['cups_required'] ?? 12) . ' cups required',
            'icon' => '🏆'
        ],
        'best_n_gps' => [
            'name' => 'Best ' . ($rules['best_n_count'] ?? 15) . ' GPs',
            'description' => 'Sum of top GP scores',
            'icon' => '⭐'
        ],
        'drop_worst' => [
            'name' => 'Drop Worst',
            'description' => 'Drop ' . ($rules['drop_worst_count'] ?? 2) . ' worst cups',
            'icon' => '🗑️'
        ],
        'perfect_hunt' => [
            'name' => 'Perfect Hunt',
            'description' => 'Perfect 60s × ' . ($rules['perfect_multiplier'] ?? 2.0),
            'icon' => '💎'
        ],
        'top_12_unique' => [
            'name' => 'Top 12 Unique',
            'description' => 'Best 12 GPs from 12 separate cups',
            'icon' => '🎯'
        ],
        'random_cup_draw' => [
            'name' => 'Random Cup Draw',
            'description' => 'Assigned random cups',
            'icon' => '🎲'
        ],
        'black_box' => [
            'name' => 'Black Box',
            'description' => 'Classified scoring formula',
            'icon' => '⬛'
        ]
    ];

    $info = $systems[$scoringSystem] ?? $systems['average_attendance'];
    $info['system'] = $scoringSystem;
    $info['rules'] = $rules;

    return $info;
}

/**
 * Get Detailed Scoring Breakdown for a Racer
 * Returns component scores for display
 */
function getScoringBreakdown($pdo, $racer_id, $season_id) {
    $stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
    $stmt->execute([$season_id]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);

    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    $breakdown = [
        'system' => $scoringSystem,
        'total_score' => calculateGPScore($pdo, $racer_id, $season_id),
        'components' => []
    ];

    switch ($scoringSystem) {
        case 'average_attendance':
            // Get total races
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'");
            $stmt->execute([$racer_id, $season_id . '%']);
            $totalRaces = $stmt->fetchColumn();

            $dropRate = $rules['drop_rate'] ?? 10;
            $numDropped = floor($totalRaces / $dropRate);

            $breakdown['components'] = [
                'total_races' => $totalRaces,
                'races_counted' => $totalRaces - $numDropped,
                'races_dropped' => $numDropped,
                'average_component' => 'Calculated with drops',
                'attendance_component' => 'Capped by week'
            ];
            break;

        case 'cup_based':
        case 'drop_worst':
        case 'perfect_hunt':
            $cupsRequired = $rules['cups_required'] ?? 12;
            $progress = getCupProgress($pdo, $racer_id, $season_id, $cupsRequired);
            $cupsCompleted = count(array_filter($progress, fn($c) => $c['completed']));

            $breakdown['components'] = [
                'cups_required' => $cupsRequired,
                'cups_completed' => $cupsCompleted,
                'completion_rate' => round(($cupsCompleted / $cupsRequired) * 100, 1),
                'cup_details' => $progress
            ];
            break;

        case 'best_n_gps':
            $bestN = $rules['best_n_count'] ?? 15;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'");
            $stmt->execute([$racer_id, $season_id . '%']);
            $totalGPs = $stmt->fetchColumn();

            $breakdown['components'] = [
                'best_n_count' => $bestN,
                'total_gps_played' => $totalGPs,
                'gps_dropped' => max(0, $totalGPs - $bestN)
            ];
            break;

        case 'top_12_unique':
            $allCups = getMK8DCups();
            $cupsPlayed = 0;
            foreach ($allCups as $cupName) {
                $cStmt = $pdo->prepare("SELECT MAX(gp_points) as best FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' AND cup_name = ?");
                $cStmt->execute([$racer_id, $season_id . '%', $cupName]);
                $cResult = $cStmt->fetch(PDO::FETCH_ASSOC);
                if ($cResult && $cResult['best']) $cupsPlayed++;
            }
            $tiebreaker = getTop12UniqueTiebreaker($pdo, $racer_id, $season_id);

            $breakdown['components'] = [
                'cups_played' => $cupsPlayed,
                'cups_counted' => min($cupsPlayed, 12),
                'unique_60s' => $tiebreaker
            ];
            break;

        case 'black_box':
            // Deliberately opaque — reveal nothing
            $breakdown['components'] = [
                'note' => 'Classified'
            ];
            break;
    }

    return $breakdown;
}