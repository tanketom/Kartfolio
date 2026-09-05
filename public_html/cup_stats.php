<?php
/**
 * Cup Statistics & Track Intelligence
 * Path: /cdnmk/public_html/cup_stats.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';
require_once __DIR__ . '/../private/includes/track_ranking.php';

$currentSeason = getCurrentSeasonNumber();

// Get selected season from URL or default to current
$selectedSeason = $_GET['season'] ?? $currentSeason;
$isAllTime = ($selectedSeason === 'all');

// Fetch available seasons for filter
$seasonsStmt = $pdo->query("SELECT DISTINCT SUBSTR(gpid, 1, 3) as season FROM results ORDER BY season DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Cup Analysis - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// 1. Track Intelligence — canonical 24-cup catalog from mk_data.php.
$cupTracks = getMKTracksByCup();

// Per-track preference Elo from track_favourites votes.
$trackRankings = trackRankings($pdo);

// Per-cup rollup: average track Elo + total preference votes across the
// cup's 4 tracks. Feeds the "Fan Favourite Cups" panel below.
$cupPreferences = [];
foreach ($cupTracks as $cup => $tracks) {
    $eloSum = 0;
    $voteSum = 0;
    foreach ($tracks as $t) {
        $r = $trackRankings[$t] ?? null;
        if (!$r) continue;
        $eloSum  += $r['elo'];
        $voteSum += $r['votes_total'];
    }
    $cupPreferences[$cup] = [
        'avg_elo'      => count($tracks) > 0 ? (int)round($eloSum / count($tracks)) : 1500,
        'votes_total'  => $voteSum,
    ];
}
uasort($cupPreferences, fn($a, $b) => $b['avg_elo'] <=> $a['avg_elo']);
$totalTrackVotes = trackPrefTotalVotes($pdo);

// 2. Fetch Aggregated Cup Data
$seasonFilter = $isAllTime ? "%" : ($selectedSeason . "%");

$stmt = $pdo->prepare("
    SELECT cup_name,
           COUNT(DISTINCT gpid) as races_run,
           AVG(gp_points) as avg_score,
           MAX(gp_points) as high_score,
           SUM(is_lol) as total_lols
    FROM results
    WHERE gpid LIKE ?
    GROUP BY cup_name
    ORDER BY avg_score ASC
");
$stmt->execute([$seasonFilter]);
$cupStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2b. Single fetch of every in-scope result row, grouped by cup. Feeds the
// podium, variance, and unique-winner computations below — replacing the
// ~3-queries-per-cup N+1 loops with one query plus PHP grouping.
$allRowsStmt = $pdo->prepare("
    SELECT res.cup_name, res.racer_id, r.name, res.gp_points, res.rank
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE ?
");
$allRowsStmt->execute([$seasonFilter]);
$rowsByCup = [];
foreach ($allRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rowsByCup[$r['cup_name']][] = $r;
}

// 3. Podium Records per Cup (top-3 by per-cup PPG), computed from $rowsByCup.
$cupPodiums = [];
foreach ($cupStats as $cup) {
    $cName = $cup['cup_name'];
    $perRacer = []; // racer_id => ['name', 'sum', 'count']
    foreach ($rowsByCup[$cName] ?? [] as $r) {
        $rid = $r['racer_id'];
        if (!isset($perRacer[$rid])) {
            $perRacer[$rid] = ['name' => $r['name'], 'sum' => 0, 'count' => 0];
        }
        $perRacer[$rid]['sum'] += (int)$r['gp_points'];
        $perRacer[$rid]['count']++;
    }
    $podium = [];
    foreach ($perRacer as $p) {
        $podium[] = ['name' => $p['name'], 'ppg' => $p['sum'] / $p['count']];
    }
    // PPG DESC; ties broken by name ASC. (The old SQL had no tiebreak, so its
    // tie order was query-plan dependent — this is deterministic instead.)
    usort($podium, fn($a, $b) => ($b['ppg'] <=> $a['ppg']) ?: strcmp($a['name'], $b['name']));
    $cupPodiums[$cName] = array_slice($podium, 0, 3);
}

// 4. Prepare Heatmap Chart Data
$chartLabels = [];
$chartPPG = [];
$chartLOLs = [];
foreach ($cupStats as $row) {
    $chartLabels[] = $row['cup_name'];
    $chartPPG[] = round($row['avg_score'], 1);
    $chartLOLs[] = $row['total_lols'];
}

// 5. Cup Difficulty Analysis
$cupDifficulty = [];
foreach ($cupStats as $row) {
    $cName = $row['cup_name'];

    // All individual scores for this cup, from the single fetch above.
    $cupRows = $rowsByCup[$cName] ?? [];
    $scores = array_map('intval', array_column($cupRows, 'gp_points'));
    $totalGPs = (int)$row['races_run'];
    $avgScore = (float)$row['avg_score'];
    $lolRate = $totalGPs > 0 ? (int)$row['total_lols'] / max(count($scores), 1) * 100 : 0;

    // Score variance (standard deviation)
    $variance = 0;
    if (count($scores) > 1) {
        $mean = array_sum($scores) / count($scores);
        $variance = array_sum(array_map(fn($s) => pow($s - $mean, 2), $scores)) / count($scores);
    }
    $stdDev = sqrt($variance);

    // Win concentration: how many unique winners vs total GPs, from $cupRows.
    $winnerIds = [];
    foreach ($cupRows as $r) {
        if ((int)$r['rank'] === 1) $winnerIds[$r['racer_id']] = true;
    }
    $uniqueWinners = count($winnerIds);
    $winSpread = $totalGPs > 0 ? $uniqueWinners / $totalGPs : 0; // Higher = more competitive

    // Perfect 60 rate
    $perfectCount = count(array_filter($scores, fn($s) => $s === MK_MAX_GP_POINTS));
    $perfectRate = count($scores) > 0 ? $perfectCount / count($scores) * 100 : 0;

    // Difficulty index: lower avg + higher variance + more LOLs + fewer perfects = harder
    // Normalized to 0-100 scale
    $difficultyScore = 0;
    $difficultyScore += (MK_MAX_GP_POINTS - $avgScore) * 1.5;     // Lower avg = harder (0-90)
    $difficultyScore += min($stdDev, 20) * 0.5;     // Higher variance = harder (0-10)
    $difficultyScore += min($lolRate, 50) * 0.3;    // More LOLs = harder (0-15)
    $difficultyScore -= $perfectRate * 0.5;          // More perfects = easier
    $difficultyScore = max(0, min(100, $difficultyScore));

    // Tier assignment
    if ($difficultyScore >= 55) { $tier = 'S'; $tierColor = '#e60012'; $tierLabel = 'Brutal'; }
    elseif ($difficultyScore >= 45) { $tier = 'A'; $tierColor = '#e67e22'; $tierLabel = 'Hard'; }
    elseif ($difficultyScore >= 35) { $tier = 'B'; $tierColor = '#f1c40f'; $tierLabel = 'Medium'; }
    elseif ($difficultyScore >= 25) { $tier = 'C'; $tierColor = '#2ebd59'; $tierLabel = 'Easy'; }
    else { $tier = 'D'; $tierColor = '#3498db'; $tierLabel = 'Chill'; }

    $cupDifficulty[$cName] = [
        'avg_score' => round($avgScore, 1),
        'std_dev' => round($stdDev, 1),
        'lol_rate' => round($lolRate, 1),
        'perfect_rate' => round($perfectRate, 1),
        'win_spread' => round($winSpread * 100, 0),
        'unique_winners' => $uniqueWinners,
        'difficulty' => round($difficultyScore, 1),
        'tier' => $tier,
        'tier_color' => $tierColor,
        'tier_label' => $tierLabel,
        'total_races' => $totalGPs,
        'total_results' => count($scores),
    ];
}

// Sort by difficulty (hardest first)
uasort($cupDifficulty, fn($a, $b) => $b['difficulty'] <=> $a['difficulty']);

// Prepare radar chart data (top 6 hardest cups)
$radarCups = array_slice(array_keys($cupDifficulty), 0, min(8, count($cupDifficulty)));
$radarData = [];
foreach ($radarCups as $rc) {
    $d = $cupDifficulty[$rc];
    $radarData[] = [
        'cup' => $rc,
        'difficulty' => $d['difficulty'],
        'volatility' => min($d['std_dev'] / 20 * 100, 100),
        'lol_danger' => min($d['lol_rate'] * 2, 100),
        'competitiveness' => $d['win_spread'],
        'scoring' => ($d['avg_score'] / MK_MAX_GP_POINTS) * 100,
    ];
}
?>

<div class="stats-container">
    <div class="cup-stats-page-header">
        <h1 class="cup-stats-page-title">Track Telemetry</h1>
        <p class="cup-stats-page-subtitle">Analyzing cross-cup difficulty and podium dominance.</p>
    </div>

    <!-- Season Filter -->
    <div class="cup-filter-box">
        <form method="GET" action="/cup-stats" class="cup-filter-form">
            <label class="cup-filter-label">Filter by Season:</label>
            <select name="season" onchange="this.form.submit()" class="cup-filter-select">
                <option value="all" <?= $isAllTime ? 'selected' : '' ?>>All-Time</option>
                <?php foreach ($availableSeasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= ($selectedSeason === $s) ? 'selected' : '' ?>>
                        Season <?= strtoupper($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="cup-filter-hint">
                <?= $isAllTime ? 'Showing all seasons' : 'Showing ' . strtoupper($selectedSeason) ?>
            </span>
        </form>
    </div>

    <?php if (empty($cupStats)): ?>
        <div class="racer-card cup-empty-state">
            <p>Awaiting first Grand Prix data to generate telemetry...</p>
        </div>
    <?php else: ?>

        <div class="racer-card telemetry-card">
            <h3 class="telemetry-label">League Performance Heatmap</h3>
            <div class="telemetry-chart-wrap">
                <canvas id="telemetryChart"></canvas>
            </div>
        </div>

        <!-- Fan Favourite Cups (from /track-favourites preference voting) -->
        <div class="racer-card difficulty-analysis-card fav-cups-card">
            <h3 class="telemetry-label">Fan Favourite Cups</h3>
            <?php if ($totalTrackVotes > 0): ?>
            <p class="diff-description">
                Average Elo across each cup's four tracks, derived from <strong><?= $totalTrackVotes ?></strong> head-to-head votes on
                <a href="/track-favourites">Track Favourites</a>. Cups with no votes sit at the 1500 baseline.
            </p>
            <?php else: ?>
            <p class="diff-description">
                No votes yet — every cup sits at the 1500 baseline.
                <a href="/track-favourites"><strong>Head over to Track Favourites</strong></a> and vote on head-to-head matchups to populate this ranking.
            </p>
            <?php endif; ?>

            <div class="difficulty-ranking">
                <?php
                    $maxElo = max(array_column($cupPreferences, 'avg_elo'));
                    $minElo = min(array_column($cupPreferences, 'avg_elo'));
                    $eloSpan = max(1, $maxElo - $minElo);
                    $rank = 1;
                    foreach ($cupPreferences as $cupName => $pref):
                    $pctFromBottom = (($pref['avg_elo'] - $minElo) / $eloSpan) * 100;
                    $eloDelta = $pref['avg_elo'] - 1500;
                    // Tier coloring: gold for top quartile, then descending.
                    if      ($pctFromBottom >= 80) { $color = '#FFD700'; $tier = '★★★'; }
                    elseif  ($pctFromBottom >= 60) { $color = '#e8a82a'; $tier = '★★';  }
                    elseif  ($pctFromBottom >= 40) { $color = '#b8b8b8'; $tier = '★';   }
                    elseif  ($pctFromBottom >= 20) { $color = '#888';    $tier = '·';   }
                    else                           { $color = '#5a5a5a'; $tier = '·';   }
                ?>
                <div class="diff-row" title="<?= htmlspecialchars($cupName) ?> Cup — <?= $pref['votes_total'] ?> votes across its four tracks">
                    <div class="diff-rank">#<?= $rank ?></div>
                    <div class="diff-tier" style="background: <?= $color ?>;"><?= $tier ?></div>
                    <div class="diff-cup-name">
                        <?= getMKCupEmoji($cupName) ?> <?= htmlspecialchars($cupName) ?> Cup
                    </div>
                    <div class="diff-bar-wrap">
                        <div class="diff-bar" style="width: <?= max(2, $pctFromBottom) ?>%; background: <?= $color ?>;"></div>
                    </div>
                    <div class="diff-score" style="color: <?= $color ?>;">
                        <?= $pref['avg_elo'] ?>
                        <small style="color:#888; font-weight:400;"><?= $eloDelta >= 0 ? '+' : '' ?><?= $eloDelta ?></small>
                    </div>
                </div>
                <?php $rank++; endforeach; ?>
            </div>
            <p class="diff-description" style="margin-top:10px; font-size:0.8rem;">
                💡 Use the top of this list as a seed for tournament cup pools.
            </p>
        </div>

        <!-- Cup Difficulty Analysis -->
        <?php if (!empty($cupDifficulty)): ?>
        <div class="racer-card difficulty-analysis-card">
            <h3 class="telemetry-label">Cup Difficulty Index</h3>
            <p class="diff-description">
                Composite difficulty score based on average scoring, volatility, LOL frequency, and perfect rate.
            </p>

            <div class="difficulty-ranking">
                <?php $rank = 1; foreach ($cupDifficulty as $cupName => $d): ?>
                <div class="diff-row" title="Avg: <?= $d['avg_score'] ?> · Volatility: <?= $d['std_dev'] ?> · LOL: <?= $d['lol_rate'] ?>% · Perfects: <?= $d['perfect_rate'] ?>%">
                    <div class="diff-rank">#<?= $rank ?></div>
                    <div class="diff-tier" style="background: <?= $d['tier_color'] ?>;">
                        <?= $d['tier'] ?>
                    </div>
                    <div class="diff-cup-name"><?= htmlspecialchars($cupName) ?> Cup</div>
                    <div class="diff-bar-wrap">
                        <div class="diff-bar" style="width: <?= $d['difficulty'] ?>%; background: <?= $d['tier_color'] ?>;"></div>
                    </div>
                    <div class="diff-score"><?= $d['difficulty'] ?></div>
                </div>
                <?php $rank++; endforeach; ?>
            </div>

            <!-- Tier Legend -->
            <div class="diff-legend">
                <span class="diff-legend-item diff-legend--s">S — Brutal</span>
                <span class="diff-legend-item diff-legend--a">A — Hard</span>
                <span class="diff-legend-item diff-legend--b">B — Medium</span>
                <span class="diff-legend-item diff-legend--c">C — Easy</span>
                <span class="diff-legend-item diff-legend--d">D — Chill</span>
            </div>
        </div>

        <!-- Difficulty Radar Chart -->
        <?php if (count($radarCups) >= 3): ?>
        <div class="racer-card telemetry-card">
            <h3 class="telemetry-label">Difficulty Profile Radar</h3>
            <p class="diff-description diff-description--sm">
                Comparing top <?= count($radarCups) ?> hardest cups across difficulty dimensions. Hover for details.
            </p>
            <div class="telemetry-chart-wrap diff-radar-wrap">
                <canvas id="difficultyRadar"></canvas>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="cup-analysis-grid">
            <?php foreach ($cupStats as $row):
                $cName = $row['cup_name'];
                $podium = $cupPodiums[$cName] ?? [];
                $tracks = $cupTracks[$cName] ?? ["Unknown Track Data"];

                // Difficulty Logic
                $diffLabel = 'Standard'; $diffColor = '#666';
                if ($row['avg_score'] < 32) { $diffLabel = 'High Intensity'; $diffColor = '#e60012'; }
                if ($row['avg_score'] > 42) { $diffLabel = 'High Scoring'; $diffColor = '#2ebd59'; }
            ?>
            <div class="cup-telemetry-card">
                <div class="cup-card-header">
                    <div class="cup-meta-top">
                        <span class="difficulty-badge" style="background: <?= $diffColor ?>"><?= $diffLabel ?></span>
                        <span class="run-tag"><?= $row['races_run'] ?> GPs Run</span>
                    </div>
                    <h4>
                        <a href="/cup/<?= htmlspecialchars(getMKCupSlug($cName)) ?>" class="cup-card-link">
                            <?= htmlspecialchars($cName) ?> Cup →
                        </a>
                    </h4>
                </div>

                <div class="track-intel-list">
                    <?php foreach ($tracks as $t):
                        $tr   = $trackRankings[$t] ?? null;
                        $slug = getMKTrackImageSlug($t);
                    ?>
                        <span class="track-chip" title="<?= htmlspecialchars($t) ?><?= $tr ? ' · ' . $tr['elo'] . ' Elo · ' . $tr['votes_total'] . ' votes' : '' ?>">
                            <img class="track-chip-thumb" src="/assets/img/tracks/<?= htmlspecialchars($slug) ?>.png"
                                 alt="" onerror="this.style.display='none';">
                            <span class="track-chip-name"><?= htmlspecialchars($t) ?></span>
                            <?php if ($tr && $tr['votes_total'] > 0): ?>
                                <span class="track-chip-elo"><?= $tr['elo'] ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <div class="cup-stats-row">
                    <div class="c-stat">
                        <span class="c-label">Avg PPG</span>
                        <span class="c-val"><?= number_format($row['avg_score'], 1) ?></span>
                    </div>
                    <div class="c-stat">
                        <span class="c-label">Best GP</span>
                        <span class="c-val"><?= $row['high_score'] ?></span>
                    </div>
                    <div class="c-stat">
                        <span class="c-label">LOLs</span>
                        <span class="c-val c-val--red"><?= $row['total_lols'] ?></span>
                    </div>
                </div>

                <div class="cup-podium-box">
                    <h5>Podium Records</h5>
                    <?php foreach ($podium as $i => $r):
                        $colors = ['#ffd700', '#c0c0c0', '#cd7f32'];
                    ?>
                        <div class="podium-entry">
                            <span class="pod-rank" style="color: <?= $colors[$i] ?>;">#<?= $i+1 ?></span>
                            <span class="pod-name"><?= htmlspecialchars($r['name']) ?></span>
                            <span class="pod-ppg"><?= number_format($r['ppg'], 1) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('telemetryChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= jsonForScript($chartLabels) ?>,
            datasets: [
                {
                    label: 'Avg Points per Racer',
                    data: <?= jsonForScript($chartPPG) ?>,
                    backgroundColor: 'rgba(230, 0, 18, 0.8)',
                    borderColor: 'var(--nintendo-red)',
                    borderWidth: 2,
                    yAxisID: 'y',
                    borderRadius: 5
                },
                {
                    label: 'Total LOLs Triggered',
                    data: <?= json_encode($chartLOLs) ?>,
                    type: 'line',
                    borderColor: '#333',
                    borderWidth: 4,
                    pointBackgroundColor: '#fff',
                    pointRadius: 4,
                    yAxisID: 'y1',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { font: { weight: 'bold' } } }
            },
            scales: {
                y: { beginAtZero: true, max: 60, title: { display: true, text: 'Points', font: { weight: 'bold' } } },
                y1: { beginAtZero: true, position: 'right', grid: { display: false }, title: { display: true, text: 'LOLs Count', font: { weight: 'bold' } } }
            }
        }
    });

    // Difficulty Radar Chart
    const radarEl = document.getElementById('difficultyRadar');
    if (radarEl) {
        const radarData = <?= json_encode($radarData) ?>;
        const radarColors = [
            'rgba(230, 0, 18, 0.7)',
            'rgba(230, 126, 34, 0.7)',
            'rgba(241, 196, 15, 0.7)',
            'rgba(46, 189, 89, 0.7)',
            'rgba(52, 152, 219, 0.7)',
            'rgba(155, 89, 182, 0.7)',
            'rgba(108, 92, 231, 0.7)',
            'rgba(0, 184, 148, 0.7)',
        ];
        const radarBg = [
            'rgba(230, 0, 18, 0.1)',
            'rgba(230, 126, 34, 0.1)',
            'rgba(241, 196, 15, 0.1)',
            'rgba(46, 189, 89, 0.1)',
            'rgba(52, 152, 219, 0.1)',
            'rgba(155, 89, 182, 0.1)',
            'rgba(108, 92, 231, 0.1)',
            'rgba(0, 184, 148, 0.1)',
        ];

        const datasets = radarData.map((cup, i) => ({
            label: cup.cup + ' Cup',
            data: [cup.difficulty, cup.volatility, cup.lol_danger, cup.competitiveness, 100 - cup.scoring],
            borderColor: radarColors[i % radarColors.length],
            backgroundColor: radarBg[i % radarBg.length],
            borderWidth: 2,
            pointRadius: 3,
        }));

        new Chart(radarEl.getContext('2d'), {
            type: 'radar',
            data: {
                labels: ['Difficulty', 'Volatility', 'LOL Danger', 'Win Spread', 'Score Depression'],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    r: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { stepSize: 25, font: { size: 10 } },
                        pointLabels: { font: { weight: 'bold', size: 12 } }
                    }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { font: { weight: 'bold', size: 11 } } }
                }
            }
        });
    }
});
</script>

<?php
// Cup History Matrix — shown for any specific season (not all-time)
if (!$isAllTime):
    $seasonScoringInfo = getScoringSystemInfo($pdo, $selectedSeason);
    $isCupBasedSeason = in_array($seasonScoringInfo['system'], ['cup_based', 'drop_worst', 'perfect_hunt']);

    // For cup-based seasons use the configured required count; otherwise show all 24
    $seasonRules = $seasonScoringInfo['rules'] ?? [];
    $cupsRequired = $isCupBasedSeason ? (int)($seasonRules['cups_required'] ?? 12) : 24;

    // Get all racers who participated this season
    $racerStmt = $pdo->prepare("
        SELECT DISTINCT r.id, r.name
        FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ?
        ORDER BY r.name ASC
    ");
    $racerStmt->execute([$selectedSeason . '%']);
    $seasonRacers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

    // Only show cups that were actually raced this season
    $racedCupsStmt = $pdo->prepare("SELECT DISTINCT cup_name FROM results WHERE gpid LIKE ?");
    $racedCupsStmt->execute([$selectedSeason . '%']);
    $racedCupNames = $racedCupsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Keep MK8D order but filter to only cups raced this season
    $allCupNames = array_values(array_filter(getMKAllCups(), fn($c) => in_array($c, $racedCupNames)));

if (!empty($seasonRacers) && !empty($allCupNames)):
    // Build progress matrix
    $matrix = [];
    foreach ($seasonRacers as $r) {
        $matrix[$r['id']] = [
            'name' => $r['name'],
            'progress' => getCupProgress($pdo, $r['id'], $selectedSeason, $cupsRequired)
        ];
    }

    // Subtitle adapts based on scoring system
    if ($isCupBasedSeason) {
        $matrixSubtitle = $cupsRequired . ' cups required · ' . $seasonScoringInfo['name'];
    } else {
        $matrixSubtitle = count($allCupNames) . ' cup' . (count($allCupNames) !== 1 ? 's' : '') . ' played this season · ' . $seasonScoringInfo['name'];
    }
?>
<div class="stats-container cup-matrix-section">
    <div class="cup-matrix-header">
        <div>
            <h2 class="cup-matrix-title">
                Cup History — <?= strtoupper($selectedSeason) ?>
            </h2>
            <p class="cup-matrix-subtitle">
                <?= $matrixSubtitle ?>
            </p>
        </div>
        <div class="cup-matrix-legend">
            <span>🌟 Perfect (60)</span>
            <span class="cup-matrix-legend--complete">✓ Completed</span>
            <span class="cup-matrix-legend--pending">— Not played</span>
        </div>
    </div>

    <div class="cup-matrix-scroll">
        <table class="cup-matrix-table">
            <thead>
                <tr>
                    <th class="racer-col">Racer</th>
                    <?php foreach ($allCupNames as $cup): ?>
                        <th><?= htmlspecialchars($cup) ?></th>
                    <?php endforeach; ?>
                    <?php if ($isCupBasedSeason): ?>
                    <th class="done-col">Done</th>
                    <?php endif; ?>
                    <th class="score-col">Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matrix as $racerId => $data):
                    $progress = $data['progress'];
                    $cupsCompleted = count(array_filter($progress, fn($c) => $c['completed']));
                    $totalScore = array_sum(array_column($progress, 'best_score'));
                    $allDone = $isCupBasedSeason ? ($cupsCompleted >= $cupsRequired) : false;
                    $rowClass = $allDone ? 'matrix-row--complete' : 'matrix-row--normal';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="racer-name-col <?= $rowClass ?>">
                        <?= htmlspecialchars($data['name']) ?>
                    </td>
                    <?php foreach ($allCupNames as $cupName):
                        $cup = $progress[$cupName] ?? ['completed' => false, 'is_perfect' => false, 'best_score' => 0, 'attempts' => 0];
                    ?>
                        <?php
                        if ($cup['is_perfect']) {
                            $cellClass = 'matrix-cell--perfect'; $cellText = '🌟';
                        } elseif ($cup['completed']) {
                            $cellClass = 'matrix-cell--done'; $cellText = $cup['best_score'];
                        } else {
                            $cellClass = 'matrix-cell--pending'; $cellText = '—';
                        }
                        $title = $cup['completed']
                            ? $cup['best_score'] . ' pts · ' . $cup['attempts'] . ' attempt' . ($cup['attempts'] != 1 ? 's' : '')
                            : 'Not yet played';
                        ?>
                        <td class="<?= $cellClass ?>" title="<?= htmlspecialchars($cupName) ?>: <?= $title ?>">
                            <?= $cellText ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ($isCupBasedSeason): ?>
                    <td class="done-count-col <?= $allDone ? 'done-complete' : 'done-partial' ?>">
                        <?= $cupsCompleted ?>/<?= $cupsRequired ?>
                        <?= !$allDone ? '⚠️' : '' ?>
                    </td>
                    <?php endif; ?>
                    <td class="score-total-col">
                        <?= $totalScore ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; // !empty($seasonRacers) && !empty($allCupNames) ?>
<?php endif; // !$isAllTime ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
