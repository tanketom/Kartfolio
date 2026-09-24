<?php
/**
 * The season outlook — where the title race stands, what the odds are, and
 * what still has to happen.
 *
 * This was the whole top half of predictions.php. The newscast needs exactly
 * the same numbers, and a second copy of a 5000-run Monte Carlo is how two
 * surfaces start quoting different odds for the same season, so it lives here
 * and both callers read it.
 *
 * The simulation is memoised in sim_cache on (season, results signature, day,
 * GPs remaining, N), so the second caller in a day pays nothing.
 *
 * @return array everything predictions.php's view and the broadcast need.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/elo_engine.php';
require_once __DIR__ . '/sim_cache.php';

function seasonOutlook(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // ─── 1. Season Info ─────────────────────────────────────────────────────
    $currentSeason = getCurrentSeasonNumber();
    $seasonMeta = getSeasonRules($pdo, $currentSeason);
    $startDate = $seasonMeta['start_date'] ?? null;
    $endDate   = $seasonMeta['end_date']   ?? null;
    $seasonName = $seasonMeta['season_name'] ?? strtoupper($currentSeason);

    $insufficientData = false;
    $seasonComplete   = false;

    if (!$startDate || !$endDate) {
        $insufficientData = true;
    }

    // ─── 2. Current Standings ────────────────────────────────────────────────
    $racers = [];
    if (!$insufficientData) {
        $racerStmt = $pdo->prepare("
            SELECT DISTINCT r.id, r.name
            FROM racers r
            JOIN results res ON r.id = res.racer_id
            WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
            ORDER BY r.name
        ");
        $racerStmt->execute([$currentSeason . '%']);
        $racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($racers as &$r) {
            $r['score'] = calculateGPScore($pdo, $r['id'], $currentSeason);

            // Most-used character
            $charStmt = $pdo->prepare("
                SELECT character_used FROM results
                WHERE racer_id = ? AND gpid LIKE ?
                GROUP BY character_used
                ORDER BY COUNT(*) DESC LIMIT 1
            ");
            $charStmt->execute([$r['id'], $currentSeason . '%']);
            $r['char'] = $charStmt->fetchColumn() ?: 'Mii';
        }
        unset($r);
    }

    // ─── 3. GP Pace & Remaining ─────────────────────────────────────────────
    $gpsPlayed           = 0;
    $estimatedRemainingGPs = 0;
    $gpsPerDay           = 0;

    if (!$insufficientData && count($racers) > 0) {
        $paceStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT gpid) as gps_played,
                   MIN(race_date) as first_race,
                   MAX(race_date) as last_race
            FROM results
            WHERE gpid LIKE ? AND gpid LIKE 's%'
        ");
        $paceStmt->execute([$currentSeason . '%']);
        $paceData  = $paceStmt->fetch(PDO::FETCH_ASSOC);
        $gpsPlayed = (int)$paceData['gps_played'];
        $firstRace = $paceData['first_race'];
        $lastRace  = $paceData['last_race'];

        $daysElapsed = max(1, (strtotime($lastRace) - strtotime($firstRace)) / 86400);
        $gpsPerDay   = $gpsPlayed / $daysElapsed;

        $remainingDays       = max(0, (strtotime($endDate) - strtotime('today')) / 86400);
        $estimatedRemainingGPs = max(0, round($gpsPerDay * $remainingDays));

        if ($estimatedRemainingGPs === 0) {
            $seasonComplete = true;
        }
    }

    // ─── 4. Participation Rates ─────────────────────────────────────────────
    if (!$insufficientData) {
        foreach ($racers as &$r) {
            $partStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT gpid)
                FROM results
                WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
            ");
            $partStmt->execute([$r['id'], $currentSeason . '%']);
            $racerGPs = (int)$partStmt->fetchColumn();
            $r['participation_rate'] = $gpsPlayed > 0 ? $racerGPs / $gpsPlayed : 0.5;
            $r['gps'] = $racerGPs;
        }
        unset($r);
    }

    // ─── 5. ELO Ratings ─────────────────────────────────────────────────────
    if (!$insufficientData) {
        $elo = calculateAllELORatings($pdo);
        $eloRatings = $elo['ratings'];
        foreach ($racers as &$r) {
            $r['elo'] = $eloRatings[$r['name']] ?? 1500;
        }
        unset($r);
    }

    // ─── 6. League Averages ─────────────────────────────────────────────────
    $leagueAvg = 0;
    if (!$insufficientData) {
        $avgStmt = $pdo->prepare("
            SELECT AVG(gp_points) as avg_pts,
                   MIN(gp_points) as min_pts,
                   MAX(gp_points) as max_pts
            FROM results
            WHERE gpid LIKE ? AND gpid LIKE 's%'
        ");
        $avgStmt->execute([$currentSeason . '%']);
        $avgData   = $avgStmt->fetch(PDO::FETCH_ASSOC);
        $leagueAvg = (float)$avgData['avg_pts'];
    }

    // ─── 7. Monte Carlo Simulation (N=5000) ─────────────────────────────────
    $simulations  = 5000;
    $probabilities = [];
    $wins = [];

    if (!$insufficientData && !$seasonComplete && count($racers) >= 2) {
        $wins = array_fill_keys(array_column($racers, 'name'), 0);

        // Pre-fetch existing GP points per racer
        $existingPoints = [];
        foreach ($racers as $r) {
            $ptsStmt = $pdo->prepare("
                SELECT gp_points
                FROM results
                WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
                ORDER BY race_date ASC
            ");
            $ptsStmt->execute([$r['id'], $currentSeason . '%']);
            $existingPoints[$r['name']] = $ptsStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // The simulation is a pure function of: the racers' Elo, participation
        // rate and existing points (all fixed by the results table), the number
        // of GPs left (fixed by today's date) and N. Cache on exactly that, so
        // the 430 ms Monte Carlo runs once per new GP or new day rather than on
        // every view — and the odds stop changing between two reloads.
        $simInputs = [];
        foreach ($racers as $r) $simInputs[$r['name']] = [round($r['elo'], 3), round($r['participation_rate'], 4), $existingPoints[$r['name']]];
        $simKey = 'predictions:' . $currentSeason . ':' . date('Y-m-d') . ':' . $estimatedRemainingGPs . ':' . $simulations . ':' . md5(json_encode($simInputs));
        $simHit = simCacheGet($pdo, $simKey);
        if ($simHit !== null && isset($simHit['wins']) && array_keys($simHit['wins']) == array_keys($wins)) {
            $wins = $simHit['wins'];
        } else {
        for ($sim = 0; $sim < $simulations; $sim++) {
            // Copy existing points
            $simPoints = [];
            foreach ($racers as $r) {
                $simPoints[$r['name']] = $existingPoints[$r['name']];
            }

            // Simulate remaining GPs
            for ($gp = 0; $gp < $estimatedRemainingGPs; $gp++) {
                // Determine participants based on participation rate
                $participants = [];
                foreach ($racers as $r) {
                    if (mt_rand(1, 1000) <= (int)($r['participation_rate'] * 1000)) {
                        $participants[] = $r;
                    }
                }
                if (count($participants) < 2) continue;

                // Simulate finishing order: ELO + random noise
                usort($participants, function ($a, $b) {
                    $scoreA = $a['elo'] + mt_rand(-200, 200);
                    $scoreB = $b['elo'] + mt_rand(-200, 200);
                    return $scoreB <=> $scoreA;
                });

                $n = count($participants);
                foreach ($participants as $rank0 => $p) {
                    $rank = $rank0 + 1;
                    $pts  = max(10, round(60 - ($rank - 1) * (50 / max(1, $n - 1))));
                    $simPoints[$p['name']][] = $pts;
                }
            }

            // Calculate final scores (simplified: average of all points)
            $finalScores = [];
            foreach ($racers as $r) {
                $pts = $simPoints[$r['name']];
                $finalScores[$r['name']] = count($pts) > 0 ? array_sum($pts) / count($pts) : 0;
            }

            // Find winner
            arsort($finalScores);
            $winner = array_key_first($finalScores);
            $wins[$winner]++;
        }
        simCachePut($pdo, $simKey, ['wins' => $wins]);
        }   // end cache miss

        // Calculate probabilities
        foreach ($wins as $name => $winCount) {
            $probabilities[$name] = round(($winCount / $simulations) * 100, 1);
        }
        arsort($probabilities);
    }

    // If season is complete, rank by current score
    if ($seasonComplete && count($racers) >= 2) {
        // Sort racers by current score descending
        usort($racers, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        // Leader gets 100%, others get 0%
        $probabilities = [];
        foreach ($racers as $i => $r) {
            $probabilities[$r['name']] = $i === 0 ? 100.0 : 0.0;
        }
    }

    // ─── 8. What-If Scenarios ───────────────────────────────────────────────
    $scenarios = [];
    if (!$insufficientData && count($racers) >= 2) {
        // Sort racers by current score
        $sorted = $racers;
        usort($sorted, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $leader = $sorted[0] ?? null;
        $second = $sorted[1] ?? null;

        if ($leader && !$seasonComplete) {
            // Leader's average points per GP
            $leaderPts = $existingPoints[$leader['name']] ?? [];
            $leaderAvg = count($leaderPts) > 0 ? array_sum($leaderPts) / count($leaderPts) : 0;
            $projectedFinal = count($leaderPts) > 0
                ? (array_sum($leaderPts) + $leaderAvg * $estimatedRemainingGPs) / (count($leaderPts) + $estimatedRemainingGPs)
                : 0;
            $scenarios[] = [
                'icon' => '1',
                'text' => htmlspecialchars($leader['name']) . ' is projected to finish with a ' . round($projectedFinal, 1) . ' average if they maintain their current pace.'
            ];
        }

        if ($second && $leader && !$seasonComplete && $estimatedRemainingGPs > 0) {
            $leaderPts = $existingPoints[$leader['name']] ?? [];
            $secondPts = $existingPoints[$second['name']] ?? [];
            $leaderTotal = array_sum($leaderPts);
            $secondTotal = array_sum($secondPts);
            $leaderCount = count($leaderPts);
            $secondCount = count($secondPts);

            // What average does #2 need to match leader's projected average?
            $leaderAvg = $leaderCount > 0 ? $leaderTotal / $leaderCount : 0;
            $leaderProjectedTotal = $leaderTotal + $leaderAvg * $estimatedRemainingGPs;
            $leaderProjectedCount = $leaderCount + $estimatedRemainingGPs;
            $leaderProjectedAvg   = $leaderProjectedCount > 0 ? $leaderProjectedTotal / $leaderProjectedCount : 0;

            // #2 needs: (secondTotal + X * remaining) / (secondCount + remaining) >= leaderProjectedAvg
            $neededTotal = $leaderProjectedAvg * ($secondCount + $estimatedRemainingGPs) - $secondTotal;
            $neededAvg   = $estimatedRemainingGPs > 0 ? $neededTotal / $estimatedRemainingGPs : 0;
            $neededAvg   = max(0, min(MK_MAX_GP_POINTS, round($neededAvg, 1)));

            $scenarios[] = [
                'icon' => '2',
                'text' => htmlspecialchars($second['name']) . ' needs to average ' . $neededAvg . ' pts/GP over the remaining ' . $estimatedRemainingGPs . ' GPs to overtake ' . htmlspecialchars($leader['name']) . '.'
            ];
        }

        // ELO favorite scenario
        if (!$seasonComplete) {
            $eloSorted = $racers;
            usort($eloSorted, function ($a, $b) {
                return $b['elo'] <=> $a['elo'];
            });
            $eloFav = $eloSorted[0] ?? null;
            if ($eloFav) {
                $scenarios[] = [
                    'icon' => 'E',
                    'text' => htmlspecialchars($eloFav['name']) . ' has the highest ELO (' . round($eloFav['elo']) . '), making them the skill favourite regardless of current standings.'
                ];
            }
        }
    }

    return $cache = compact(
        'currentSeason', 'seasonName', 'startDate', 'endDate', 'insufficientData',
        'seasonComplete', 'racers', 'probabilities', 'scenarios',
        'estimatedRemainingGPs', 'gpsPlayed', 'gpsPerDay', 'simulations', 'existingPoints'
    );
}
