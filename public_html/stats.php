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
} else {
    $scoringInfo = ['name' => 'All-Time Legacy', 'description' => 'All seasons combined', 'icon' => '🏆', 'system' => 'average_attendance'];
    $scoringSystem = 'average_attendance';
    // The All-Time curve is GPScore™ over every season's rows, on default knobs.
    $rules = ['scoring_system' => 'average_attendance', 'attendance_weight' => 1.0, 'drop_rate' => 10,
              'weekly_bonus_cap' => 2, 'min_races_threshold' => 3];
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

// 4. THE CHART — one point per race night: each racer's score after that
// night's last GP. A season is seasonReplay() (gp_logic.php), the same replay
// /season-chart and the season report draw, scored under the season's own
// system; 'exact' => false means a plain points average stands in for a system
// that can't be replayed, and the subtitle says so. All-Time has no system of
// its own: a GPScore™ curve over every season's rows.
$chart_series = [];
$chartApprox  = false;
if (!$isAllTime) {
    $replay = seasonReplay($pdo, $currentSeason);
    $chartApprox = !$replay['exact'];
    $nightFrame = [];   // race_date => standings after that night's last GP
    foreach ($replay['gps'] as $gp) $nightFrame[$gp['race_date']] = $replay['frames'][$gp['gpid']] ?? [];
    $frames = [];
    $frame = [];
    foreach ($timeline as $d) $frames[] = $frame = $nightFrame[$d] ?? $frame;
    foreach ($racer_ids as $name => $rid) {
        $chart_series[$name] = array_map(fn($f) => $f[(int)$rid]['score'] ?? null, $frames);
    }
} else {
    foreach ($racer_raw_data as $name => $entries) {
        $bag = []; $i = 0; $n = count($entries);
        foreach ($timeline as $d) {
            for (; $i < $n && $entries[$i]['date'] <= $d; $i++) {
                $bag[] = ['gp_points' => $entries[$i]['points'], 'race_date' => $entries[$i]['date'], 'id' => $entries[$i]['id']];
            }
            $chart_series[$name][] = $bag ? aaFromRows($bag, $rules)['score'] : null;
        }
    }
}

// 5. Stats tables
$stats_summary = [];
$peak_performances = [];
$volatility_data = [];
foreach (array_keys($racer_raw_data) as $racer) {
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
                <?= htmlspecialchars($scoringInfo['name']) ?> can't be replayed GP by GP, so this chart shows each racer's plain points average as an approximation.
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
                    title: { display: true, text: <?= jsonForScript($isAllTime ? 'GPScore™' : ($chartApprox ? 'Points average' : $scoringInfo['name'])) ?>, font: { weight: 'bold' } }
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
