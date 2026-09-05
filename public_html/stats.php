<?php
/**
 * League Analytics & Power Rankings
 * Path: /cdnmk/public_html/stats.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$currentSeason = $_GET['season'] ?? getCurrentSeasonNumber();
$isAllTime = ($currentSeason === 'all');
$pageTitle = "Season Stats - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// 0. Fetch Available Seasons
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// 1. Fetch Season Rules and Scoring Info
if (!$isAllTime) {
    $rules = getSeasonRules($pdo, $currentSeason);

    $scoringInfo = getScoringSystemInfo($pdo, $currentSeason);
    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    $attWeight = $rules['attendance_weight'] ?? 1.0;
    $dropRate  = $rules['drop_rate'] ?? 10;
    $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
    $threshold = $rules['min_races_threshold'] ?? 3;
} else {
    $scoringInfo = ['name' => 'All-Time Legacy', 'description' => 'All seasons combined', 'icon' => '🏆', 'system' => 'average_attendance'];
    $scoringSystem = 'average_attendance';
    $attWeight = 1.0; $dropRate = 10; $weeklyCap = 2; $threshold = 3;
}

// 2. Fetch Raw Results
if (!$isAllTime) {
    $stmt = $pdo->prepare("
        SELECT r.id as racer_id, r.name, res.race_date, res.gp_points, res.cup_name, res.gpid, res.rank, res.id AS result_id
        FROM results res
        JOIN racers r ON res.racer_id = r.id
        WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
        ORDER BY res.race_date ASC, res.id ASC
    ");
    $stmt->execute([$currentSeason . "%"]);
} else {
    $stmt = $pdo->query("
        SELECT r.id as racer_id, r.name, res.race_date, res.gp_points, res.cup_name, res.gpid, res.rank, res.id AS result_id
        FROM results res
        JOIN racers r ON res.racer_id = r.id
        WHERE res.gpid LIKE 's%'
        ORDER BY res.race_date ASC, res.id ASC
    ");
}
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Prepare Data
$all_dates = [];
$racer_raw_data = [];
$racer_ids = []; // Map names to IDs

foreach ($raw_data as $row) {
    $date = $row['race_date'];
    $name = $row['name'];
    $all_dates[] = $date;
    $racer_raw_data[$name][] = ['date' => $date, 'points' => $row['gp_points'], 'cup_name' => $row['cup_name'] ?? '', 'gpid' => $row['gpid'] ?? '', 'rank' => (int)($row['rank'] ?? 0), 'id' => (int)($row['result_id'] ?? 0)];
    $racer_ids[$name] = $row['racer_id']; // Store ID mapping
}

$timeline = array_values(array_unique($all_dates));
sort($timeline);

// 3b. Pre-compute MONSTER HUNT XP per GP per racer (needs all participants + Elo)
$mhXpSeries = []; // racer_name => [gpid => xp_earned]
$gpDates     = []; // gpid => race_date
foreach ($raw_data as $row) {
    if (!isset($gpDates[$row['gpid']])) $gpDates[$row['gpid']] = $row['race_date'];
}
if (!$isAllTime && $scoringSystem === 'monster_hunt') {
    // Per-GP XP from the MONSTER HUNT engine. The copy that lived here picked
    // the Monster by raw Elo and ignored the is_monster admin flag, so it
    // credited XP to the wrong racer on 12 of s03's 31 GPs.
    foreach (mhSeasonHunts($pdo, $currentSeason, $rules) as $h) {
        if ($h['solo']) continue;
        foreach ($h['xp'] as $name => $xp) $mhXpSeries[$name][$h['gpid']] = $xp;
    }
}

// 4. THE REPLAY ENGINE
// Can the chart reproduce this system GP by GP, or only approximate it?
$chartApprox  = !$isAllTime && !in_array($scoringSystem, progressiveReplayableSystems(), true);
$chart_series = [];
$stats_summary = [];
$peak_performances = [];
$volatility_data = [];
$all_racers = array_keys($racer_raw_data);

foreach ($all_racers as $racer) {
    $running_bag = [];
    $chart_points = [];
    $racerId = $racer_ids[$racer];

    // A. Chart Generation (Use Season-Aware Scoring)
    // For black_box, pre-fetch career average for this racer
    $careerAvg = 40;
    if ($scoringSystem === 'black_box') {
        $careerStmt = $pdo->prepare("SELECT AVG(gp_points) as career_avg FROM results WHERE racer_id = ? AND gpid LIKE 's%'");
        $careerStmt->execute([$racerId]);
        $careerAvg = (float)($careerStmt->fetchColumn() ?: 40);
    }

    foreach ($timeline as $currentDate) {
        $running_bag = [];
        foreach ($racer_raw_data[$racer] as $entry) {
            if ($entry['date'] <= $currentDate) {
                $running_bag[] = $entry;
            }
        }

        $count = count($running_bag);

        if ($count > 0) {
            $pointsOnly = array_column($running_bag, 'points');
            $bagRows    = array_map(fn($e) => ['gp_points' => $e['points'], 'race_date' => $e['date'], 'rank' => $e['rank'], 'id' => $e['id']], $running_bag);

            switch ($scoringSystem) {
                case 'average_attendance':
                case 'preseason':
                case 'positional_points':
                case 'median':
                case 'form':
                    // Exact replays from the racer's own rows (gp_logic).
                    $chart_points[] = progressiveScoreFromRows($scoringSystem, $bagRows, $rules);
                    break;

                default:
                    // No replay for this system — a GPScore™-style
                    // Average+Attendance APPROXIMATION; the subtitle says so
                    // ($chartApprox). It used to be silently labelled as the
                    // season's real system.
                    $chart_points[] = aaFromRows($bagRows, $rules)['score'];
                    break;

                case 'best_n_gps':
                    // Sum of best N scores
                    $bestN = $rules['best_n_count'] ?? 15;
                    $sorted = $pointsOnly;
                    rsort($sorted);
                    $top = array_slice($sorted, 0, $bestN);
                    $chart_points[] = round(array_sum($top), 2);
                    break;

                case 'cup_based':
                    // Best score per required cup, sum
                    $cupsRequired = $rules['cups_required'] ?? 12;
                    $allCups = array_slice(getMKAllCups(), 0, $cupsRequired);
                    $cupBests = [];
                    foreach ($running_bag as $e) {
                        $cn = $e['cup_name'];
                        if ($cn && in_array($cn, $allCups)) {
                            $cupBests[$cn] = max($cupBests[$cn] ?? 0, $e['points']);
                        }
                    }
                    $chart_points[] = round(array_sum($cupBests), 2);
                    break;

                case 'drop_worst':
                    // Best per cup, drop N worst, sum
                    $cupsRequired = $rules['cups_required'] ?? 12;
                    $dropWorstCount = $rules['drop_worst_count'] ?? 2;
                    $allCups = array_slice(getMKAllCups(), 0, $cupsRequired);
                    $cupBests = [];
                    foreach ($running_bag as $e) {
                        $cn = $e['cup_name'];
                        if ($cn && in_array($cn, $allCups)) {
                            $cupBests[$cn] = max($cupBests[$cn] ?? 0, $e['points']);
                        }
                    }
                    $scores = array_values($cupBests);
                    sort($scores);
                    $filtered = array_slice($scores, min($dropWorstCount, max(0, count($scores) - 1)));
                    $chart_points[] = round(array_sum($filtered), 2);
                    break;

                case 'perfect_hunt':
                    // Cup-based with multiplier for 60s
                    $cupsRequired = $rules['cups_required'] ?? 12;
                    $perfectMultiplier = $rules['perfect_multiplier'] ?? 2.0;
                    $allCups = array_slice(getMKAllCups(), 0, $cupsRequired);
                    $cupBests = [];
                    foreach ($running_bag as $e) {
                        $cn = $e['cup_name'];
                        if ($cn && in_array($cn, $allCups)) {
                            $cupBests[$cn] = max($cupBests[$cn] ?? 0, $e['points']);
                        }
                    }
                    $total = 0;
                    foreach ($cupBests as $score) {
                        $total += ($score == MK_MAX_GP_POINTS) ? ($score * $perfectMultiplier) : $score;
                    }
                    $chart_points[] = round($total, 2);
                    break;

                case 'top_12_unique':
                    // Best score per cup, take top 12
                    $allCups = getMKAllCups();
                    $cupBests = [];
                    foreach ($running_bag as $e) {
                        $cn = $e['cup_name'];
                        if ($cn) {
                            $cupBests[$cn] = max($cupBests[$cn] ?? 0, $e['points']);
                        }
                    }
                    $scores = array_values($cupBests);
                    rsort($scores);
                    $top12 = array_slice($scores, 0, 12);
                    $chart_points[] = round(array_sum($top12), 2);
                    break;

                case 'black_box':
                    // Replicate black box formula in-memory
                    if ($count < 3) {
                        $chart_points[] = 0;
                        break;
                    }
                    $comebackMultiplier = 1.0 + max(0, (50 - $careerAvg) / 50) * 0.45;
                    $comebackMultiplier = min($comebackMultiplier, 1.35);

                    $mean = array_sum($pointsOnly) / $count;
                    $variance = 0;
                    foreach ($pointsOnly as $p) { $variance += ($p - $mean) ** 2; }
                    $stdDev = sqrt($variance / $count);
                    $consistencyFactor = max(0.92, 1.0 - max(0, ($stdDev - 10) * 0.008));

                    $runningTotal = 0;
                    $recentScores = [];
                    foreach ($running_bag as $i => $res) {
                        $pts = (float)$res['points'];
                        $baseScore = sqrt($pts) * 7.7;
                        $momentumBonus = 0;
                        if (count($recentScores) >= 3) {
                            $recentAvg = array_sum(array_slice($recentScores, -3)) / 3;
                            if ($pts > $recentAvg) {
                                $momentumBonus = ($pts - $recentAvg) * 0.3;
                            }
                        }
                        $dateHash = crc32($racerId . $res['date'] . $i);
                        $chaosPoints = (($dateHash % 500) / 100) - 1.5;
                        $gpContribution = ($baseScore + $momentumBonus + $chaosPoints) * $comebackMultiplier;
                        $runningTotal += max(0, $gpContribution);
                        $recentScores[] = $pts;
                    }
                    $runningTotal *= $consistencyFactor;
                    $attendanceBonus = log($count + 1, 2) * 3.3;
                    $chart_points[] = round($runningTotal / $count + $attendanceBonus, 2);
                    break;

                case 'random_cup_draw':
                    // Score from assigned cups only
                    $racerCups = randomCupDrawAssignments($pdo, $currentSeason, (array)$rules)[(int)$racerId] ?? [];
                    if (empty($racerCups)) {
                        $chart_points[] = 0;
                        break;
                    }
                    $cupBests = [];
                    foreach ($running_bag as $e) {
                        $cn = $e['cup_name'];
                        if ($cn && in_array($cn, $racerCups)) {
                            $cupBests[$cn] = max($cupBests[$cn] ?? 0, $e['points']);
                        }
                    }
                    $chart_points[] = round(array_sum($cupBests), 2);
                    break;

                case 'monster_hunt':
                    // Running Best X XP sum as of $currentDate
                    $mhBestX   = max(1, (int)($rules['mh_best_x'] ?? 20));
                    $mhSeries  = $mhXpSeries[$racer] ?? [];
                    $mhRunning = [];
                    foreach ($mhSeries as $gpid => $xp) {
                        $gpDate = $gpDates[$gpid] ?? '';
                        if ($gpDate && $gpDate <= $currentDate) $mhRunning[] = $xp;
                    }
                    if (count($mhRunning) > 0) {
                        rsort($mhRunning);
                        $topX = array_slice($mhRunning, 0, $mhBestX);
                        $chart_points[] = round(array_sum($topX), 2);
                    } else {
                        $chart_points[] = null;
                    }
                    break;
            }
        } else {
            $chart_points[] = null;
        }
    }
    $chart_series[$racer] = $chart_points;
    
    // B. Stats Calculation (Loose Rules - Form doesn't care about thresholds)
    $full_bag = $racer_raw_data[$racer]; 
    $pointsOnly = array_column($full_bag, 'points');
    
    if (count($full_bag) > 0) {
        // 1. Form (Last 5)
        $last5 = array_slice($pointsOnly, -5);
        $formAvg = array_sum($last5) / count($last5);

        // 2. Peak Performance
        $maxScore = max($pointsOnly);
        $maxDate = '';
        foreach($full_bag as $entry) {
            if ($entry['points'] === $maxScore) {
                $maxDate = $entry['date'];
                break; 
            }
        }
        $peak_performances[] = ['name' => $racer, 'racer_id' => $racer_ids[$racer], 'score' => $maxScore, 'date' => $maxDate];

        // 3. Volatility
        if (count($pointsOnly) > 1) {
            $minScore = min($pointsOnly);
            $range = $maxScore - $minScore;
            $volatility_data[] = ['name' => $racer, 'racer_id' => $racer_ids[$racer], 'min' => $minScore, 'max' => $maxScore, 'range' => $range];
        }

        $stats_summary[] = [
            'name' => $racer,
            'racer_id' => $racer_ids[$racer],
            'form' => round($formAvg, 2),
            'gps_played' => count($full_bag)
        ];
    }
}

// 6. Chart Config
$colors = ['#e60012', '#009BE0', '#F2E500', '#2EBD59', '#8F00FF', '#FF8F00', '#FF46B4', '#000000', '#444'];
$jsonDatasets = [];
$cIdx = 0;

foreach ($chart_series as $name => $dataPoints) {
    if (empty(array_filter($dataPoints))) continue;
    $color = $colors[$cIdx % count($colors)];
    $jsonDatasets[] = [
        'label' => $name,
        'data' => $dataPoints, 
        'borderColor' => $color,
        'backgroundColor' => $color,
        'borderWidth' => 3,
        'tension' => 0.3,
        'pointRadius' => 2,
        'pointHoverRadius' => 6,
        'spanGaps' => true 
    ];
    $cIdx++;
}
?>

<div class="stats-container">
    <?php if (!$isAllTime): ?>
    <div class="stats-scoring-banner">
        <div class="stats-scoring-inner">
            <span class="stats-scoring-icon"><?= $scoringInfo['icon'] ?></span>
            <div class="stats-scoring-text">
                <div class="stats-scoring-name"><?= htmlspecialchars($scoringInfo['name']) ?></div>
                <div class="stats-scoring-desc"><?= htmlspecialchars($scoringInfo['description']) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="stats-filter-row">
        <form method="GET" action="/stats" class="stats-filter-form">
            <label for="seasonSelect" class="stats-filter-label">Filter:</label>
            <select name="season" id="seasonSelect" onchange="this.form.submit()" class="stats-filter-select">
                <?php foreach ($availableSeasons as $season):
                    $label = 'Season ' . strtoupper($season['season_id']) . ($season['status'] === 'archived' ? ' (Archived)' : '');
                ?>
                    <option value="<?= htmlspecialchars($season['season_id']) ?>" <?= ($season['season_id'] === $currentSeason) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllTime ? 'selected' : '' ?>>All-Time Stats</option>
            </select>
        </form>
    </div>

    <div class="racer-card stats-chart-card">
        <h1 class="stats-chart-title">Historical Power Rankings</h1>
        <p class="stats-chart-subtitle">
            <?php if ($scoringSystem === 'monster_hunt'): ?>
                Tracking the best-<?= max(1, (int)($rules['mh_best_x'] ?? 20)) ?> XP sum over the season — the number the leaderboard ranks on. Title and level are shown on the leaderboard.
            <?php elseif (!empty($chartApprox)): ?>
                <?= htmlspecialchars($scoringInfo['name']) ?> can't be replayed GP by GP (it depends on the rest of the field), so this chart shows a GPScore™-style average as an approximation.
            <?php else: ?>
                Tracking <?= htmlspecialchars($scoringInfo['name']) ?> progression over the season.
            <?php endif; ?>
            <span class="stats-threshold-note">(All racers shown)</span>
        </p>

        <div class="stats-chart-wrap">
            <canvas id="seasonChart"></canvas>
        </div>
    </div>

    <div class="stats-sections-grid">

        <section class="racer-card stats-section-card">
            <h2 class="stats-section-heading">🏆 Peak Performance</h2>
            <p class="stats-section-sub">Each racer's career-best GP score this season.</p>
            <table class="clean-table">
                <thead>
                    <tr><th>Racer</th><th class="txt-center">Best Score</th><th class="txt-right">Date</th></tr>
                </thead>
                <tbody>
                    <?php
                    usort($peak_performances, fn($a, $b) => $b['score'] <=> $a['score']);
                    foreach($peak_performances as $p): ?>
                    <tr>
                        <td><strong><a href="/racer/<?= $p['racer_id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($p['name']) ?></a></strong></td>
                        <td class="txt-center stats-highlight-val"><?= $p['score'] ?></td>
                        <td class="txt-right stats-muted-val"><?= date('M j', strtotime($p['date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="racer-card stats-section-card">
            <h2 class="stats-section-heading">🎲 Score Volatility</h2>
            <p class="stats-section-sub">Floor to ceiling performance range. Wider = more chaotic.</p>
            <table class="clean-table">
                <thead>
                    <tr><th>Racer</th><th class="txt-center">Range</th><th class="txt-right">Min-Max</th></tr>
                </thead>
                <tbody>
                    <?php
                    usort($volatility_data, fn($a, $b) => $b['range'] <=> $a['range']);
                    foreach($volatility_data as $v):
                        if ($v['range'] >= 30) $barColor = '#e60012'; 
                        elseif ($v['range'] >= 20) $barColor = '#FF8F00';
                        elseif ($v['range'] >= 10) $barColor = '#F2E500';
                        else $barColor = '#2EBD59';
                    ?>
                    <tr>
                        <td><strong><a href="/racer/<?= $v['racer_id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($v['name']) ?></a></strong></td>
                        <td class="txt-center">
                            <div class="vol-bar-track">
                                <div style="background: <?= $barColor ?>; height: 100%; width: <?= min(100, ($v['range'] / 40) * 100) ?>%; border-radius: 4px;"></div>
                                <span class="vol-bar-label"><?= $v['range'] ?></span>
                            </div>
                        </td>
                        <td class="txt-right stats-muted-val"><?= $v['min'] ?>-<?= $v['max'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

    </div>

    <?php
    // Consistency vs Ceiling scatter — only meaningful for a single season.
    $ccPoints = [];
    if (!$isAllTime) {
        foreach (getActiveRacers($pdo, $currentSeason) as $ccr) {
            $cs = racerSeasonStats($pdo, (int)$ccr['id'], $currentSeason);
            if ($cs['gps'] < 3) continue; // need a few GPs to be meaningful
            $ccPoints[] = ['x' => $cs['best'], 'y' => round($cs['stddev'], 1), 'name' => $ccr['name']];
        }
    }
    if (!empty($ccPoints)):
        $ccMedX = (function ($a) { sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n,2)] : ($a[$n/2-1]+$a[$n/2])/2; })(array_column($ccPoints, 'x'));
        $ccMedY = (function ($a) { sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n,2)] : ($a[$n/2-1]+$a[$n/2])/2; })(array_column($ccPoints, 'y'));
    ?>
    <section class="racer-card stats-section-card stats-section-card--top">
        <h2 class="stats-section-heading">📐 Consistency vs Ceiling</h2>
        <p class="stats-section-sub">
            Every racer plotted by their best GP (ceiling, →) and score spread (consistency, ↑ = steadier).
            Lines mark the field median. Top-right = complete; bottom-right = boom-or-bust.
        </p>
        <div class="stats-chart-wrap">
            <canvas id="ccScatter"></canvas>
        </div>
    </section>
    <?php endif; ?>

    <section class="racer-card stats-section-card stats-section-card--top">
        <h2 class="stats-section-heading">📈 Current Form Rankings</h2>
        <p class="stats-section-sub">Ranked by average performance over the last 5 GPs.</p>
        <table class="clean-table">
            <thead>
                <tr>
                    <th class="txt-center" style="width:40px;">#</th>
                    <th>Racer</th>
                    <th class="txt-center">Form (Last 5)</th>
                    <th class="txt-center">GPs Played</th>
                    <th class="txt-right">Trend</th>
                </tr>
            </thead>
            <tbody>
                <?php
                usort($stats_summary, fn($a, $b) => $b['form'] <=> $a['form']);
                $rank = 1;
                foreach($stats_summary as $s):
                    $trend = $s['form'] > 35 ? '🔥' : ($s['form'] < 20 ? '❄️' : '➡️');
                ?>
                <tr <?= $rank <= 3 ? 'class="top-three"' : '' ?>>
                    <td class="txt-center stats-rank-num"><?= $rank ?></td>
                    <td><strong><a href="/racer/<?= $s['racer_id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($s['name']) ?></a></strong></td>
                    <td class="txt-center stats-highlight-val"><?= $s['form'] ?></td>
                    <td class="txt-center stats-muted-val"><?= $s['gps_played'] ?></td>
                    <td class="txt-right stats-trend-icon"><?= $trend ?></td>
                </tr>
                <?php $rank++; endforeach; ?>
            </tbody>
        </table>
    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
    const ctx = document.getElementById('seasonChart').getContext('2d');
    const rawLabels = <?= json_encode(array_values($timeline)) ?>;
    const datasets = <?= json_encode($jsonDatasets) ?>;

    const displayLabels = rawLabels.map(dateStr => {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: displayLabels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, font: { weight: 'bold', size: 12 }, padding: 25 } },
                tooltip: { 
                    backgroundColor: 'rgba(0,0,0,0.8)', 
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 13 }
                }
            },
            scales: {
                y: { 
                    grid: { color: '#e8e0cc' },
                    ticks: { font: { weight: 'bold' } },
                    title: { display: true, text: 'GPScore™', font: { weight: 'bold' } }
                },
                x: { 
                    grid: { display: false }, 
                    ticks: { font: { size: 11, weight: 'bold' }, maxRotation: 45, minRotation: 0 } 
                }
            }
        }
    });
</script>

<?php if (!$isAllTime && !empty($ccPoints)): ?>
<script>
(function () {
    const pts = <?= json_encode($ccPoints) ?>;
    const medX = <?= json_encode($ccMedX) ?>;   // median ceiling
    const medY = <?= json_encode($ccMedY) ?>;   // median spread
    const el = document.getElementById('ccScatter');
    if (!el) return;

    // Colour by quadrant: high ceiling = red family, low = blue family;
    // steadier (low spread) = darker/stronger.
    const colored = pts.map(p => {
        const hi = p.x >= medX, steady = p.y <= medY;
        const color = hi ? (steady ? '#c0102a' : '#ff7a59') : (steady ? '#0066cc' : '#7fb3e6');
        return { x: p.x, y: p.y, name: p.name, color };
    });

    new Chart(el.getContext('2d'), {
        type: 'scatter',
        data: { datasets: [{
            data: colored,
            pointBackgroundColor: colored.map(p => p.color),
            pointBorderColor: '#fff', pointBorderWidth: 1.5,
            pointRadius: 7, pointHoverRadius: 9,
        }]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => {
                    const p = c.raw;
                    return `${p.name}: ceiling ${p.x}, spread ±${p.y}`;
                } } },
                // Median crosshair via annotation-free approach: draw using grid lines below.
            },
            scales: {
                x: { title: { display: true, text: 'Ceiling — best GP →', font: { weight: 'bold' } }, grid: { color: '#e8e0cc' }, suggestedMin: 0, suggestedMax: 60 },
                y: { reverse: true, title: { display: true, text: '↑ steadier (lower spread)', font: { weight: 'bold' } }, grid: { color: '#e8e0cc' }, suggestedMin: 0 }
            }
        },
        plugins: [{
            // Median crosshair + datapoint labels.
            id: 'ccGuides',
            afterDraw(chart) {
                const { ctx, chartArea: a, scales } = chart;
                const mx = scales.x.getPixelForValue(medX);
                const my = scales.y.getPixelForValue(medY);
                ctx.save();
                ctx.strokeStyle = '#c4b896'; ctx.setLineDash([5, 4]); ctx.lineWidth = 1;
                ctx.beginPath(); ctx.moveTo(mx, a.top); ctx.lineTo(mx, a.bottom); ctx.stroke();
                ctx.beginPath(); ctx.moveTo(a.left, my); ctx.lineTo(a.right, my); ctx.stroke();
                ctx.setLineDash([]);
                ctx.fillStyle = '#4a4438'; ctx.font = 'bold 11px sans-serif';
                chart.getDatasetMeta(0).data.forEach((pt, i) => {
                    ctx.fillText(colored[i].name, pt.x + 9, pt.y + 4);
                });
                ctx.restore();
            }
        }]
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>