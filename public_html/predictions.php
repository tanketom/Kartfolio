<?php
/**
 * Crystal Ball - Monte Carlo Season Predictions
 * Path: /cdnmk/public_html/predictions.php
 *
 * Hidden page (no nav link). Runs N=5000 Monte Carlo simulations
 * to estimate each racer's probability of winning the current season.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$pageTitle = "Crystal Ball - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';

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
        $neededAvg   = max(0, min(60, round($neededAvg, 1)));

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

// ─── Include header after all computation ────────────────────────────────
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">

    <!-- Header Card -->
    <div class="racer-card pred-header-card">
        <span class="pred-season-tag"><?= htmlspecialchars($seasonName) ?></span>
        <h1 class="pred-title">Crystal Ball</h1>
        <?php if (!$insufficientData && !$seasonComplete): ?>
            <p class="pred-subtitle">Season title probabilities based on <?= number_format($simulations) ?> Monte Carlo simulations</p>
            <p class="pred-disclaimer">These are statistical estimates, not guarantees. The mushroom of fate spares no one.</p>
        <?php elseif ($seasonComplete): ?>
            <p class="pred-subtitle">The season appears complete &mdash; showing final standings.</p>
        <?php endif; ?>
    </div>

    <?php if ($insufficientData): ?>
        <!-- Insufficient Data -->
        <div class="racer-card pred-message-card">
            <div class="pred-message-icon">?</div>
            <h2 class="pred-message-title">Insufficient Season Data</h2>
            <p class="pred-message-text">
                The current season is missing start or end dates. Predictions require a defined season window.
            </p>
        </div>

    <?php elseif (count($racers) < 2): ?>
        <!-- Not enough racers -->
        <div class="racer-card pred-message-card">
            <div class="pred-message-icon">?</div>
            <h2 class="pred-message-title">Not Enough Racers</h2>
            <p class="pred-message-text">
                At least 2 racers with season results are needed to run predictions.
            </p>
        </div>

    <?php else: ?>

        <!-- Chart Card -->
        <div class="racer-card pred-chart-card">
            <div class="pred-chart-label">Win Probability Distribution</div>
            <div style="position: relative; width: 100%; max-height: 400px; height: <?= max(200, min(400, count($probabilities) * 40)) ?>px;">
                <canvas id="pred-chart"></canvas>
            </div>
        </div>

        <!-- Racer Cards Grid -->
        <div class="pred-grid">
            <?php
            $rank = 0;
            foreach ($probabilities as $name => $prob):
                $rank++;
                // Find full racer data
                $racerData = null;
                foreach ($racers as $r) {
                    if ($r['name'] === $name) { $racerData = $r; break; }
                }
                if (!$racerData) continue;

                $topClass = '';
                if ($rank === 1) $topClass = ' pred-top-1';
                elseif ($rank === 2) $topClass = ' pred-top-2';
                elseif ($rank === 3) $topClass = ' pred-top-3';

                $charImg = htmlspecialchars($racerData['char']);
            ?>
            <div class="racer-card pred-racer-card<?= $topClass ?>">
                <div class="pred-racer-rank">#<?= $rank ?></div>
                <img class="pred-racer-portrait"
                     src="/assets/img/<?= $charImg ?>.png"
                     alt="<?= $charImg ?>"
                     onerror="this.src='/assets/img/Mii.png'">
                <div class="pred-racer-info">
                    <div class="pred-racer-name"><?= htmlspecialchars($name) ?></div>
                    <div class="pred-probability"><?= $prob ?>%</div>
                    <div class="pred-bar">
                        <div class="pred-bar-fill" style="width: <?= $prob ?>%"></div>
                    </div>
                </div>
                <div class="pred-racer-stats">
                    <span>GPScore: <?= round($racerData['score'], 1) ?></span>
                    <span>ELO: <?= round($racerData['elo']) ?></span>
                    <span>GPs: <?= $racerData['gps'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- What-If Scenarios -->
        <?php if (!empty($scenarios)): ?>
        <div class="racer-card pred-scenarios-card">
            <h2 class="pred-section-title">What-If Scenarios</h2>
            <?php foreach ($scenarios as $s): ?>
            <div class="pred-scenario">
                <div class="pred-scenario-icon"><?= htmlspecialchars($s['icon']) ?></div>
                <div class="pred-scenario-text"><?= $s['text'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Simulation Parameters -->
        <div class="racer-card pred-params-card">
            <h2 class="pred-section-title">Simulation Parameters</h2>
            <div class="pred-params-grid">
                <div class="pred-param">
                    <span class="pred-param-label">GPs Played</span>
                    <span class="pred-param-value"><?= $gpsPlayed ?></span>
                </div>
                <div class="pred-param">
                    <span class="pred-param-label">Est. Remaining</span>
                    <span class="pred-param-value"><?= $estimatedRemainingGPs ?></span>
                </div>
                <div class="pred-param">
                    <span class="pred-param-label">GP Pace</span>
                    <span class="pred-param-value"><?= round($gpsPerDay, 2) ?>/day</span>
                </div>
                <div class="pred-param">
                    <span class="pred-param-label">Season End</span>
                    <span class="pred-param-value"><?= htmlspecialchars($endDate) ?></span>
                </div>
                <div class="pred-param">
                    <span class="pred-param-label">Simulations</span>
                    <span class="pred-param-value"><?= number_format($simulations) ?></span>
                </div>
                <div class="pred-param">
                    <span class="pred-param-label">Racers</span>
                    <span class="pred-param-value"><?= count($racers) ?></span>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php if (!$insufficientData && count($racers) >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
(function() {
    const ctx = document.getElementById('pred-chart').getContext('2d');
    const names = <?= json_encode(array_keys($probabilities)) ?>;
    const probs = <?= json_encode(array_values($probabilities)) ?>;
    const colors = [
        'var(--nintendo-red)', '#0066CC', '#2EBD59', '#FF8C00', '#8B5CF6',
        '#EC4899', '#14B8A6', '#F59E0B', '#6366F1', '#EF4444',
        '#06B6D4', '#84CC16', '#F97316', '#A855F7', '#10B981'
    ];

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: names,
            datasets: [{
                data: probs,
                backgroundColor: names.map(function(_, i) { return colors[i % colors.length]; }),
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.85)',
                    titleFont: { weight: 'bold', size: 13 },
                    bodyFont: { size: 13 },
                    callbacks: {
                        label: function(ctx) { return ctx.parsed.x + '% chance to win'; }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Win Probability %',
                        font: { weight: 'bold', size: 12 }
                    },
                    max: Math.min(100, Math.max(50, Math.ceil(Math.max(...probs) * 1.2))),
                    grid: { color: '#f0f0f0' },
                    ticks: { font: { weight: 'bold' } }
                },
                y: {
                    ticks: {
                        font: { weight: 'bold', size: 13 }
                    },
                    grid: { display: false }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
