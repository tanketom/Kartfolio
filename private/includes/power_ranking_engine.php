<?php
/**
 * Power rankings — Elo (40%), recent form (35%) and consistency (25%) blended
 * into one number per racer, plus movement since the previous GP and win /
 * podium streaks.
 *
 * Lifted out of power_rankings.php so the newscast can describe a racer's
 * current form from the same numbers the page shows. The broadcast never names
 * this as "Power Rankings" — it just uses the signals.
 *
 * @return array<int, array> rankings, best first, each with rank_pos,
 *         power_score, elo_norm, form_norm, cons_norm, movement, streaks.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/elo_engine.php';

/**
 * A racer's season rows newest first (race_date DESC, gpid DESC, id DESC),
 * regular-season GPs only (gpid LIKE 's%'), from the season cache.
 */
function powerRankingRows(PDO $pdo, int $racerId, string $season): array {
    $rows = array_values(array_filter(getRacerSeasonRows($pdo, $racerId, $season), fn($r) => str_starts_with((string)$r['gpid'], 's')));
    usort($rows, fn($a, $b) => strcmp((string)$b['race_date'], (string)$a['race_date']) ?: strcmp((string)$b['gpid'], (string)$a['gpid']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return $rows;
}

function powerRankings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // ============================================================
    // 1. Current Season & ELO Data
    // ============================================================
    $currentSeason = getCurrentSeasonNumber();

    $seasonMeta = getSeasonRules($pdo, $currentSeason);

    $elo = calculateAllELORatings($pdo);
    $eloRatings   = $elo['ratings'];       // ['Name' => float]
    $gamesPlayed  = $elo['games_played'];   // ['Name' => int]

    // ============================================================
    // 2. Get all racers who participated in the current season
    // ============================================================
    $racersStmt = $pdo->prepare("
        SELECT DISTINCT r.id, r.name
        FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
        ORDER BY r.name ASC
    ");
    $racersStmt->execute([$currentSeason . '%']);
    $seasonRacers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================================
    // 3. Compute Power Rankings
    // ============================================================
    $rankings = [];

    // Build ELO values for season participants only (for normalization)
    $seasonEloValues = [];
    foreach ($seasonRacers as $racer) {
        $name = $racer['name'];
        if (isset($eloRatings[$name])) {
            $seasonEloValues[$name] = $eloRatings[$name];
        }
    }

    $eloMin = !empty($seasonEloValues) ? min($seasonEloValues) : 0;
    $eloMax = !empty($seasonEloValues) ? max($seasonEloValues) : 1;
    $eloRange = max(1, $eloMax - $eloMin);


    foreach ($seasonRacers as $racer) {
        $racerId = $racer['id'];
        $racerName = $racer['name'];
        $racerElo = $eloRatings[$racerName] ?? 1500;

        // ----- ELO Component (normalized 0-100) -----
        $eloNorm = (($racerElo - $eloMin) / $eloRange) * 100;

        // This racer's season rows, newest first, off the season cache — the six
        // per-racer queries below all sliced this same list (6N queries → 0).
        $prRows = powerRankingRows($pdo, (int)$racerId, $currentSeason);
        $prPts  = array_map(fn($r) => (int)$r['gp_points'], $prRows);

        // ----- Recent Form: last 5 season GPs -----
        $recentPts = array_slice($prPts, 0, 5);
        $formAvg = count($recentPts) > 0 ? array_sum($recentPts) / count($recentPts) : 0;
        $formNorm = ($formAvg / MK_MAX_GP_POINTS) * 100;

        // ----- Consistency: last 10 season GPs, inverse stddev -----
        $consPts = array_slice($prPts, 0, 10);

        if (count($consPts) >= 2) {
            $mean = array_sum($consPts) / count($consPts);
            $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $consPts)) / count($consPts);
            $stddev = sqrt($variance);
            $consNorm = max(0, min(100, 100 - ($stddev / 60 * 100)));
        } else {
            $consNorm = 50; // neutral for insufficient data
        }

        // ----- Composite Power Score -----
        $powerScore = ($eloNorm * 0.40) + ($formNorm * 0.35) + ($consNorm * 0.25);

        // ----- Most-Used Character (ties: alphabetically last, as SQLite's
        //       GROUP BY … ORDER BY COUNT(*) DESC emitted — same as getMostUsedCharacter) -----
        $charTally = [];
        foreach ($prRows as $r) { $c = (string)($r['character_used'] ?? ''); $charTally[$c] = ($charTally[$c] ?? 0) + 1; }
        krsort($charTally, SORT_STRING); arsort($charTally);
        $mainChar = ($charTally ? (string)array_key_first($charTally) : '') ?: 'Mii';

        // ----- Win Streak (consecutive rank=1 from most recent) -----
        $streakResults = array_reverse(array_map(fn($r) => ['rank' => (int)$r['rank']], $prRows));
        $winStreaks    = calculateStreaks($streakResults, 'win');
        $podiumStreaks = calculateStreaks($streakResults, 'podium');

        $rankings[] = [
            'id'              => $racerId,
            'name'            => $racerName,
            'elo'             => round($racerElo, 1),
            'elo_norm'        => round($eloNorm, 1),
            'form_avg'        => round($formAvg, 1),
            'form_norm'       => round($formNorm, 1),
            'cons_norm'       => round($consNorm, 1),
            'power_score'     => round($powerScore, 1),
            'char'            => $mainChar,
            'win_streak'      => $winStreaks['current'],
            'podium_streak'   => $podiumStreaks['current'],
            'gps_played'      => count($streakResults),
            'movement'        => 0,      // populated below
            'rank_pos'        => 0,      // populated below
            'cached_commentary' => '',   // populated below
        ];
    }

    // Sort by power score descending
    usort($rankings, fn($a, $b) => $b['power_score'] <=> $a['power_score']);

    // Assign rank positions
    foreach ($rankings as $i => &$r) {
        $r['rank_pos'] = $i + 1;
    }
    unset($r);

    // ============================================================
    // 4. Movement Detection (compare with scores excluding last GP)
    // ============================================================
    $prevRankings = [];

    foreach ($seasonRacers as $racer) {
        $racerId = $racer['id'];
        $racerName = $racer['name'];
        $racerElo = $eloRatings[$racerName] ?? 1500;

        $prPts = array_map(fn($r) => (int)$r['gp_points'], powerRankingRows($pdo, (int)$racerId, $currentSeason));

        // Form without most recent GP (last 5 becomes items 2-6)
        $prevRecentPts = array_slice($prPts, 1, 5);
        $prevFormAvg = count($prevRecentPts) > 0 ? array_sum($prevRecentPts) / count($prevRecentPts) : 0;
        $prevFormNorm = ($prevFormAvg / MK_MAX_GP_POINTS) * 100;

        // Consistency without most recent GP (last 10 becomes items 2-11)
        $prevConsPts = array_slice($prPts, 1, 10);

        if (count($prevConsPts) >= 2) {
            $mean = array_sum($prevConsPts) / count($prevConsPts);
            $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $prevConsPts)) / count($prevConsPts);
            $stddev = sqrt($variance);
            $prevConsNorm = max(0, min(100, 100 - ($stddev / 60 * 100)));
        } else {
            $prevConsNorm = 50;
        }

        // Use same ELO normalization (ELO changes are small per GP, this is an approximation)
        $prevEloNorm = (($racerElo - $eloMin) / $eloRange) * 100;
        $prevPowerScore = ($prevEloNorm * 0.40) + ($prevFormNorm * 0.35) + ($prevConsNorm * 0.25);

        $prevRankings[] = [
            'id'          => $racerId,
            'power_score' => round($prevPowerScore, 1),
        ];
    }

    // Sort previous rankings and assign positions
    usort($prevRankings, fn($a, $b) => $b['power_score'] <=> $a['power_score']);
    $prevPositions = [];
    foreach ($prevRankings as $i => $pr) {
        $prevPositions[$pr['id']] = $i + 1;
    }

    // Calculate movement (positive = moved up, negative = moved down)
    foreach ($rankings as &$r) {
        $prevPos = $prevPositions[$r['id']] ?? $r['rank_pos'];
        $r['movement'] = $prevPos - $r['rank_pos']; // e.g. was 5, now 3 = +2 (moved up)
    }
    unset($r);
    return $cache = $rankings;
}
