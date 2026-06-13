<?php
/**
 * Coaching Report — stats gathering layer.
 *
 * Pulls the per-racer signal that gets fed to the Gemini coach prompt:
 * cup performance, character pairings, H2H underperformance vs. Elo,
 * recent form vs. season form, and streaks.
 *
 * Path: /cdnmk/private/includes/coaching_stats.php
 */

require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/elo_engine.php';
require_once __DIR__ . '/mk_data.php';

/**
 * Build the full coaching payload for a racer.
 *
 * $window: how many recent GPs to consider "recent form" (default 6).
 *
 * Returns a structured array suitable for json_encode'ing into the prompt.
 */
function gatherCoachingStats(PDO $pdo, int $racer_id, int $window = 6): array {
    $racerStmt = $pdo->prepare("SELECT id, name FROM racers WHERE id = ?");
    $racerStmt->execute([$racer_id]);
    $racer = $racerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$racer) {
        return ['error' => 'Racer not found'];
    }
    $name = $racer['name'];

    // ── Basic career line ────────────────────────────────────────────────
    $basicStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                       AS gps,
            AVG(gp_points)                                 AS avg_pts,
            MAX(gp_points)                                 AS best_pts,
            MIN(gp_points)                                 AS worst_pts,
            SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END)      AS wins,
            SUM(CASE WHEN rank <= 3 THEN 1 ELSE 0 END)     AS podiums,
            SUM(CASE WHEN gp_points = 60 THEN 1 ELSE 0 END) AS perfect_60s,
            SUM(CASE WHEN is_lol = 1 THEN 1 ELSE 0 END)    AS lols
        FROM results
        WHERE racer_id = ? AND gpid LIKE 's%'
    ");
    $basicStmt->execute([$racer_id]);
    $basic = $basicStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // ── Cup performance (avg pts per cup, min 2 GPs to count) ────────────
    $cupStmt = $pdo->prepare("
        SELECT cup_name, COUNT(*) AS played, AVG(gp_points) AS avg_pts, MAX(gp_points) AS best_pts
        FROM results
        WHERE racer_id = ? AND gpid LIKE 's%' AND cup_name IS NOT NULL AND cup_name != ''
        GROUP BY cup_name
    ");
    $cupStmt->execute([$racer_id]);
    $cupRows = $cupStmt->fetchAll(PDO::FETCH_ASSOC);
    $cupRows = array_filter($cupRows, fn($r) => (int)$r['played'] >= 2);
    foreach ($cupRows as &$r) { $r['avg_pts'] = round((float)$r['avg_pts'], 1); }
    unset($r);
    usort($cupRows, fn($a, $b) => $b['avg_pts'] <=> $a['avg_pts']);
    $strongestCups = array_slice($cupRows, 0, 3);
    $weakestCups   = array_slice(array_reverse($cupRows), 0, 3);

    // Cups never played (career)
    $playedCups = array_column($cupRows, 'cup_name');
    $unplayedCups = array_values(array_diff(getMKAllCups(), $playedCups));

    // ── Character pairings (min 3 GPs to count) ──────────────────────────
    $charStmt = $pdo->prepare("
        SELECT character_used AS character, COUNT(*) AS played, AVG(gp_points) AS avg_pts
        FROM results
        WHERE racer_id = ? AND gpid LIKE 's%' AND character_used IS NOT NULL AND character_used != ''
        GROUP BY character_used
    ");
    $charStmt->execute([$racer_id]);
    $charRows = $charStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($charRows as &$r) { $r['avg_pts'] = round((float)$r['avg_pts'], 1); }
    unset($r);
    $multiUse = array_values(array_filter($charRows, fn($r) => (int)$r['played'] >= 3));
    usort($multiUse, fn($a, $b) => $b['avg_pts'] <=> $a['avg_pts']);
    $bestChars  = array_slice($multiUse, 0, 3);
    $worstChars = array_slice(array_reverse($multiUse), 0, 3);
    $charVariance = count($charRows);

    // ── Recent form vs. career form ──────────────────────────────────────
    $recentStmt = $pdo->prepare("
        SELECT gp_points, rank, race_date, gpid, cup_name, character_used
        FROM results
        WHERE racer_id = ? AND gpid LIKE 's%'
        ORDER BY race_date DESC, gpid DESC
        LIMIT ?
    ");
    $recentStmt->execute([$racer_id, $window]);
    $recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    $recentAvg = !empty($recentRows)
        ? round(array_sum(array_column($recentRows, 'gp_points')) / count($recentRows), 1)
        : 0.0;
    $careerAvg = (float)($basic['avg_pts'] ?? 0);
    $formDelta = round($recentAvg - $careerAvg, 1); // positive = trending up

    // ── Streaks (current win drought, longest podium streak) ─────────────
    $orderedStmt = $pdo->prepare("
        SELECT rank, race_date FROM results
        WHERE racer_id = ? AND gpid LIKE 's%'
        ORDER BY race_date ASC, id ASC
    ");
    $orderedStmt->execute([$racer_id]);
    $orderedRanks = $orderedStmt->fetchAll(PDO::FETCH_ASSOC);
    [$longestPodiumStreak, $currentPodiumDrought, $longestWinStreak, $currentWinDrought] =
        coachingStreaks($orderedRanks);

    // ── H2H underperformance (Elo expected vs. actual) ───────────────────
    // For each opponent the racer has met >= 5 times, compute expected win
    // rate from current Elo and compare to actual win rate.
    $rivalsStmt = $pdo->prepare("
        SELECT r.id, r.name, COUNT(*) AS shared
        FROM results res1
        JOIN results res2 ON res1.gpid = res2.gpid AND res1.racer_id != res2.racer_id
        JOIN racers r ON r.id = res2.racer_id
        WHERE res1.racer_id = ? AND res1.gpid LIKE 's%'
        GROUP BY res2.racer_id
        HAVING shared >= 5
    ");
    $rivalsStmt->execute([$racer_id]);
    $rivals = $rivalsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pull Elo ratings once.
    $eloRatings = [];
    try {
        $eloData    = calculateAllELORatings($pdo);
        $eloRatings = $eloData['ratings'] ?? [];
    } catch (Throwable $e) {
        // Non-fatal — H2H section will just lack Elo expectations.
    }
    $myElo = $eloRatings[$name] ?? null;

    $underperforming = [];
    foreach ($rivals as $opp) {
        $oppName = $opp['name'];

        // Actual H2H record
        $h2hStmt = $pdo->prepare("
            SELECT res1.rank AS my_rank, res2.rank AS their_rank
            FROM results res1
            JOIN results res2 ON res1.gpid = res2.gpid
            WHERE res1.racer_id = ? AND res2.racer_id = ? AND res1.gpid LIKE 's%'
        ");
        $h2hStmt->execute([$racer_id, $opp['id']]);
        $h2h = $h2hStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($h2h)) continue;
        $wins = 0; $total = 0;
        foreach ($h2h as $m) {
            if ($m['my_rank'] === null || $m['their_rank'] === null) continue;
            $total++;
            if ((int)$m['my_rank'] < (int)$m['their_rank']) $wins++;
        }
        if ($total === 0) continue;
        $actualWinRate = $wins / $total;

        $expected = null;
        if ($myElo !== null && isset($eloRatings[$oppName])) {
            // Standard Elo expected-score formula.
            $expected = 1.0 / (1.0 + pow(10, ($eloRatings[$oppName] - $myElo) / 400));
        }

        if ($expected !== null) {
            $delta = $actualWinRate - $expected;
            // Flag underperformance: actual notably below expected, AND at
            // least 5 meetings, AND a non-trivial gap.
            if ($delta < -0.15) {
                $underperforming[] = [
                    'opponent'           => $oppName,
                    'shared_gps'         => (int)$opp['shared'],
                    'wins'               => $wins,
                    'losses'             => $total - $wins,
                    'actual_win_rate'    => round($actualWinRate, 2),
                    'expected_win_rate'  => round($expected, 2),
                    'underperformance'   => round($delta, 2),
                ];
            }
        }
    }
    usort($underperforming, fn($a, $b) => $a['underperformance'] <=> $b['underperformance']);
    $underperforming = array_slice($underperforming, 0, 5);

    // ── Lola obstruction rate ───────────────────────────────────────────
    $lolRate = (int)($basic['gps'] ?? 0) > 0
        ? round((int)($basic['lols'] ?? 0) / max(1, (int)$basic['gps']) * 100, 1)
        : 0.0;

    return [
        'name'                => $name,
        'career' => [
            'gps_total'         => (int)($basic['gps']         ?? 0),
            'avg_pts'           => round((float)($basic['avg_pts']   ?? 0), 1),
            'best_pts'          => (int)($basic['best_pts']    ?? 0),
            'worst_pts'         => (int)($basic['worst_pts']   ?? 0),
            'wins'              => (int)($basic['wins']        ?? 0),
            'podiums'           => (int)($basic['podiums']     ?? 0),
            'perfect_60s'       => (int)($basic['perfect_60s'] ?? 0),
            'lol_rate_pct'      => $lolRate,
        ],
        'recent_form' => [
            'window_gps'         => count($recentRows),
            'recent_avg_pts'     => $recentAvg,
            'career_avg_pts'     => round($careerAvg, 1),
            'form_delta'         => $formDelta, // positive = improving
            'recent_results'     => array_map(fn($r) => [
                'cup'       => $r['cup_name'],
                'character' => $r['character_used'],
                'rank'      => (int)$r['rank'],
                'pts'       => (int)$r['gp_points'],
            ], $recentRows),
        ],
        'streaks' => [
            'longest_podium_streak' => $longestPodiumStreak,
            'current_podium_drought'=> $currentPodiumDrought,
            'longest_win_streak'    => $longestWinStreak,
            'current_win_drought'   => $currentWinDrought,
        ],
        'cups' => [
            'strongest'    => $strongestCups,
            'weakest'      => $weakestCups,
            'never_played' => $unplayedCups,
        ],
        'characters' => [
            'distinct_used'         => $charVariance,
            'best_pairings'         => $bestChars,
            'worst_pairings'        => $worstChars,
        ],
        'elo' => [
            'current'           => $myElo !== null ? (int)round($myElo) : null,
            'underperforming_vs' => $underperforming,
        ],
    ];
}

/**
 * Internal helper: compute podium/win streak metrics from oldest→newest
 * ranks. Returns [longestPodium, currentPodiumDrought, longestWin, currentWinDrought].
 */
function coachingStreaks(array $orderedRows): array {
    $longestPodium = 0; $currentPodium = 0; $podiumDrought = 0;
    $longestWin    = 0; $currentWin    = 0; $winDrought    = 0;

    foreach ($orderedRows as $r) {
        $rank = (int)$r['rank'];
        if ($rank <= 3) {
            $currentPodium++;
            $longestPodium = max($longestPodium, $currentPodium);
            $podiumDrought = 0;
        } else {
            $currentPodium = 0;
            $podiumDrought++;
        }
        if ($rank === 1) {
            $currentWin++;
            $longestWin = max($longestWin, $currentWin);
            $winDrought = 0;
        } else {
            $currentWin = 0;
            $winDrought++;
        }
    }
    return [$longestPodium, $podiumDrought, $longestWin, $winDrought];
}

/**
 * Build the Gemini prompt from a coaching stats payload.
 * Returns a single string ready to send.
 */
function buildCoachingPrompt(array $stats, string $leagueName = 'Kartfolio League'): string {
    $name = $stats['name'] ?? 'the racer';
    $payload = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $prompt  = "You are a sharp, friendly Mario Kart 8 Deluxe coach writing a personalised coaching report for {$name}, a member of the {$leagueName}.\n\n";
    $prompt .= "Below is the raw data. Use it — every claim you make must be grounded in a specific number, cup, character, or opponent from this payload. Do NOT invent stats.\n\n";
    $prompt .= "DATA:\n{$payload}\n\n";
    $prompt .= "WRITE A REPORT WITH THESE FOUR SECTIONS, each one short paragraph (2–3 sentences):\n\n";
    $prompt .= "1. **State of the union** — one-paragraph honest snapshot of where {$name} stands right now. Reference total GPs, career average, recent form delta, and current podium/win drought if notable.\n\n";
    $prompt .= "2. **What's working** — call out the strongest cup, strongest character pairing, or other positive signal. Be specific (cup name, character name, the actual numbers).\n\n";
    $prompt .= "3. **Where to grow** — point at one or two weakest cups, a weak character pairing, OR an opponent they underperform Elo against. Be honest but constructive.\n\n";
    $prompt .= "4. **One concrete thing to try next session** — a single small, actionable suggestion. (E.g., 'pick up Lightning Cup with Wario next session' or 'play a few rounds against Tom on Mushroom-class cups to chip away at that 0.27 Elo gap'.)\n\n";
    $prompt .= "STYLE:\n";
    $prompt .= "- Warm coach voice, no corporate fluff, no emojis.\n";
    $prompt .= "- Use **double asterisks** around racer names, character names, and cup names each time they appear.\n";
    $prompt .= "- Total length: 200–280 words. Do not exceed.\n";
    $prompt .= "- Do not mention 'the data' or 'the payload' — write as if you've watched them race.\n";
    $prompt .= "- Sign off with: — Coach, {$leagueName}.\n";
    return $prompt;
}
