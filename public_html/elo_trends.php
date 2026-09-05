<?php
/**
 * ELO Rating Trends & Analytics
 * Path: /cdnmk/public_html/elo_trends.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$pageTitle = "ELO Trends - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// Calculate all ELO ratings using shared engine
$elo = calculateAllELORatings($pdo);
$ratings        = $elo['ratings'];
$games_played   = $elo['games_played'];
$rating_history = $elo['history'];
$all_changes    = $elo['all_changes'];
$gp_changelog   = $elo['gp_changelog'];
$timeline       = $elo['timeline'];

// 4. Prepare Chart Data

$chart_series = [];
$colors = ['#e60012', '#009BE0', '#F2E500', '#2EBD59', '#8F00FF', '#FF8F00', '#FF46B4', '#000000', '#444', '#00BFFF'];
$cIdx = 0;

foreach ($rating_history as $racer => $history) {
    $dataPoints = [];

    foreach ($timeline as $date) {
        $ratingForDate = null;
        foreach ($history as $h) {
            if ($h['date'] === $date) {
                // Keep overwriting — last entry for this date wins
                // (multiple GPs on same day: we want the final ELO, not the first)
                $ratingForDate = round($h['rating'], 1);
            }
        }
        $dataPoints[] = $ratingForDate;
    }

    // Only include racers with data
    if (!empty(array_filter($dataPoints, fn($x) => $x !== null))) {
        $color = $colors[$cIdx % count($colors)];
        $chart_series[] = [
            'label' => $racer,
            'data' => $dataPoints,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'borderWidth' => 3,
            'tension' => 0.3,
            'pointRadius' => 2,
            'pointHoverRadius' => 6,
            'spanGaps' => false
        ];
        $cIdx++;
    }
}

// 5. Calculate Stats
$final_ratings = [];
foreach ($ratings as $racer => $rating) {
    $final_ratings[] = [
        'name' => $racer,
        'rating' => $rating,
        'games' => $games_played[$racer],
        'peak' => max(array_column($rating_history[$racer], 'rating')),
        'change_from_start' => $rating - ELO_INITIAL_RATING
    ];
}
usort($final_ratings, fn($a, $b) => $b['rating'] <=> $a['rating']);

// Biggest Upsets (Rating Gains)
$upsets = array_filter($all_changes, fn($c) => $c['change'] > 0);
usort($upsets, fn($a, $b) => $b['change'] <=> $a['change']);
$top_upsets = array_slice($upsets, 0, 10);

// Biggest Collapses (Rating Losses)
$collapses = array_filter($all_changes, fn($c) => $c['change'] < 0);
usort($collapses, fn($a, $b) => $a['change'] <=> $b['change']);
$top_collapses = array_slice($collapses, 0, 10);

// Rating Volatility
$volatility = [];
foreach ($rating_history as $racer => $history) {
    $allRatings = array_column($history, 'rating');
    if (count($allRatings) > 1) {
        $min = min($allRatings);
        $max = max($allRatings);
        $range = $max - $min;
        $volatility[] = [
            'name' => $racer,
            'min' => $min,
            'max' => $max,
            'range' => $range
        ];
    }
}
usort($volatility, fn($a, $b) => $b['range'] <=> $a['range']);

// Current season racers (for chart filter)
$currentSeason = getCurrentSeasonNumber();
$csStmt = $pdo->prepare("
    SELECT DISTINCT r.name
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE ?
");
$csStmt->execute([$currentSeason . '%']);
$currentSeasonRacers = $csStmt->fetchAll(PDO::FETCH_COLUMN);

// Retired racers — keyed by name for quick lookup against the ELO engine's
// name-keyed output. Value of 1 means retired.
$retiredRacers = $pdo->query("SELECT name FROM racers WHERE is_retired = 1")
                     ->fetchAll(PDO::FETCH_COLUMN);
$retiredRacers = array_flip($retiredRacers);
?>

<div class="stats-container">
    <div class="racer-card elo-header-card">
        <h1 class="elo-title">All-Time ELO Ratings</h1>
        <p class="elo-subtitle">
            Skill-based rankings that carry across all seasons. Rewards beating stronger opponents.
            <span class="elo-note">(All racers start at 1500, ratings never reset)</span>
        </p>

        <div class="elo-range-buttons">
            <button class="elo-cl-btn elo-cl-btn-active" data-range="all">All Time</button>
            <button class="elo-cl-btn" data-range="month">Past Month</button>
            <button class="elo-cl-btn" data-range="week">Past Week</button>
            <label class="elo-season-toggle">
                <input type="checkbox" id="eloSeasonFilter" checked> Current season only
            </label>
        </div>

        <div class="elo-chart-wrap">
            <canvas id="eloChart"></canvas>
        </div>
    </div>

    <div class="elo-grid">

        <section class="racer-card elo-section-card">
            <h2 class="elo-section-title">🏆 Current ELO Rankings</h2>
            <p class="elo-section-desc">Dynamic skill ratings based on head-to-head performance.</p>
            <table class="clean-table">
                <thead>
                    <tr>
                        <th class="elo-th-rank">#</th>
                        <th>Racer</th>
                        <th class="elo-th-center">Rating</th>
                        <th class="elo-th-center">Peak</th>
                        <th class="elo-th-center">GPs</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rank = 1;
                    foreach($final_ratings as $r):
                        $changeSymbol = $r['change_from_start'] > 0 ? '▲' : ($r['change_from_start'] < 0 ? '▼' : '–');
                        $changeColor = $r['change_from_start'] > 0 ? '#2EBD59' : ($r['change_from_start'] < 0 ? '#e60012' : '#888');
                    ?>
                    <?php $isRetired = isset($retiredRacers[$r['name']]); ?>
                    <tr class="<?= $rank <= 3 ? 'top-three ' : '' ?><?= $isRetired ? 'racer-retired' : '' ?>">
                        <td class="elo-td-rank"><?= $rank ?></td>
                        <td>
                            <strong><?= htmlspecialchars($r['name']) ?></strong>
                            <?php if ($isRetired): ?><span class="retired-badge" title="Retired racer">RETIRED</span><?php endif; ?>
                        </td>
                        <td class="elo-td-rating">
                            <?= round($r['rating']) ?>
                            <span class="elo-change-icon" style="color:<?= $changeColor ?>;"><?= $changeSymbol ?></span>
                        </td>
                        <td class="elo-td-peak"><?= round($r['peak']) ?></td>
                        <td class="elo-td-games"><?= $r['games'] ?></td>
                    </tr>
                    <?php $rank++; endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="racer-card elo-section-card">
            <h2 class="elo-section-title">📈 Rating Volatility</h2>
            <p class="elo-section-desc">Rating range from lowest to highest point.</p>
            <table class="clean-table">
                <thead>
                    <tr><th>Racer</th><th class="elo-th-center">Range</th><th class="elo-th-right">Min-Max</th></tr>
                </thead>
                <tbody>
                    <?php
                    foreach($volatility as $v):
                        if ($v['range'] >= 300) $barColor = '#e60012';
                        elseif ($v['range'] >= 200) $barColor = '#FF8F00';
                        elseif ($v['range'] >= 100) $barColor = '#F2E500';
                        else $barColor = '#2EBD59';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($v['name']) ?></strong></td>
                        <td class="elo-td-bar">
                            <div class="elo-bar-track">
                                <div class="elo-bar-fill" style="background: <?= $barColor ?>; width: <?= min(100, ($v['range'] / 500) * 100) ?>%;"></div>
                                <span class="elo-bar-label"><?= round($v['range']) ?></span>
                            </div>
                        </td>
                        <td class="elo-td-minmax"><?= round($v['min']) ?>-<?= round($v['max']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

    </div>

    <div class="elo-grid-mt">

        <section class="racer-card elo-section-card">
            <h2 class="elo-section-title">⭐ Biggest Upsets</h2>
            <p class="elo-section-desc">Largest single-race ELO gains (beating stronger opponents).</p>
            <table class="clean-table">
                <thead>
                    <tr><th>Racer</th><th class="elo-th-center">GP</th><th class="elo-th-center">Gain</th><th class="elo-th-right">Rank</th></tr>
                </thead>
                <tbody>
                    <?php foreach($top_upsets as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['racer']) ?></strong></td>
                        <td class="elo-td-gp"><?= htmlspecialchars($u['gpid']) ?></td>
                        <td class="elo-td-gain">+<?= round($u['change'], 1) ?></td>
                        <td class="elo-td-right-gray"><?= $u['rank'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="racer-card elo-section-card">
            <h2 class="elo-section-title">💥 Biggest Collapses</h2>
            <p class="elo-section-desc">Largest single-race ELO losses (losing to weaker opponents).</p>
            <table class="clean-table">
                <thead>
                    <tr><th>Racer</th><th class="elo-th-center">GP</th><th class="elo-th-center">Loss</th><th class="elo-th-right">Rank</th></tr>
                </thead>
                <tbody>
                    <?php foreach($top_collapses as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['racer']) ?></strong></td>
                        <td class="elo-td-gp"><?= htmlspecialchars($c['gpid']) ?></td>
                        <td class="elo-td-loss"><?= round($c['change'], 1) ?></td>
                        <td class="elo-td-right-gray"><?= $c['rank'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

    </div>

    <!-- GP-by-GP ELO Changelog -->
    <section class="racer-card elo-section-card elo-changelog-card">
        <h2 class="elo-section-title">📋 GP-by-GP Changelog</h2>
        <p class="elo-section-desc">Every rating change explained — who moved up or down, and why.</p>

        <div class="elo-cl-controls">
            <button class="elo-cl-btn elo-cl-btn-active" data-filter="all">All</button>
            <button class="elo-cl-btn" data-filter="recent">Last 10</button>
            <input type="text" class="elo-cl-search" id="elo-cl-search" placeholder="Search racer or GP...">
        </div>

        <div class="elo-cl-list" id="elo-cl-list">
            <?php foreach (array_reverse($gp_changelog) as $gp): ?>
            <div class="elo-cl-gp" data-gpid="<?= htmlspecialchars($gp['gpid']) ?>">
                <div class="elo-cl-gp-header">
                    <span class="elo-cl-gpid"><?= htmlspecialchars($gp['gpid']) ?></span>
                    <span class="elo-cl-cup"><?= htmlspecialchars($gp['cup']) ?> Cup</span>
                    <span class="elo-cl-date"><?= date('M j', strtotime($gp['date'])) ?></span>
                    <span class="elo-cl-count"><?= count($gp['racers']) ?> racers</span>
                </div>
                <div class="elo-cl-rows">
                    <?php foreach ($gp['racers'] as $r):
                        $isUp = $r['change'] > 0;
                        $isDown = $r['change'] < 0;
                        $arrow = $isUp ? '▲' : ($isDown ? '▼' : '–');
                        $changeClass = $isUp ? 'elo-cl-up' : ($isDown ? 'elo-cl-down' : 'elo-cl-flat');

                        // Build explanation
                        $diff = round($r['actual'] - $r['expected'], 1);
                        if ($diff > 0) {
                            $reason = "Beat " . abs($diff) . " more than expected";
                        } elseif ($diff < 0) {
                            $reason = "Beat " . abs($diff) . " fewer than expected";
                        } else {
                            $reason = "Performed exactly as expected";
                        }
                    ?>
                    <div class="elo-cl-row <?= $changeClass ?>">
                        <div class="elo-cl-rank">#<?= $r['rank'] ?></div>
                        <div class="elo-cl-name"><?= htmlspecialchars($r['name']) ?></div>
                        <div class="elo-cl-delta">
                            <span class="elo-cl-arrow"><?= $arrow ?></span>
                            <?= ($isUp ? '+' : '') . $r['change'] ?>
                        </div>
                        <div class="elo-cl-rating"><?= $r['old'] ?> → <?= $r['new'] ?></div>
                        <div class="elo-cl-reason" title="Expected to beat <?= $r['expected'] ?>, actually beat <?= $r['actual'] ?> (K=<?= $r['k'] ?>)">
                            <?= $reason ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
(function() {
    const ctx = document.getElementById('eloChart').getContext('2d');
    const rawLabels = <?= jsonForScript(array_values($timeline)) ?>;
    const allDatasets = <?= jsonForScript($chart_series) ?>;
    const currentSeasonRacers = <?= jsonForScript(array_values($currentSeasonRacers)) ?>;

    // Convert date strings to Date objects for filtering
    const rawDates = rawLabels.map(s => new Date(s));

    let activeRange = 'all';
    const seasonCheckbox = document.getElementById('eloSeasonFilter');

    function getFilteredRange(range) {
        if (range === 'all') return 0;
        const now = new Date();
        let cutoff;
        if (range === 'month') {
            cutoff = new Date(now);
            cutoff.setDate(cutoff.getDate() - 30);
        } else if (range === 'week') {
            cutoff = new Date(now);
            cutoff.setDate(cutoff.getDate() - 7);
        }
        // Find the first index on or after cutoff
        for (let i = 0; i < rawDates.length; i++) {
            if (rawDates[i] >= cutoff) return i;
        }
        return rawDates.length - 1;
    }

    function buildChart(startIdx, seasonOnly) {
        const slicedLabels = rawLabels.slice(startIdx).map(dateStr => {
            const d = new Date(dateStr);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });

        let sourceDatasets = allDatasets;
        if (seasonOnly) {
            sourceDatasets = allDatasets.filter(ds => currentSeasonRacers.includes(ds.label));
        }

        const slicedDatasets = sourceDatasets.map(ds => {
            const slicedData = ds.data.slice(startIdx);
            // For filtered views, carry forward the last known rating before the window
            // so lines don't start from nothing
            if (startIdx > 0) {
                // Find the most recent non-null value before or at startIdx
                let carryForward = null;
                for (let i = startIdx; i >= 0; i--) {
                    if (ds.data[i] !== null) { carryForward = ds.data[i]; break; }
                }
                if (slicedData[0] === null && carryForward !== null) {
                    slicedData[0] = carryForward;
                }
            }
            return { ...ds, data: slicedData };
        }).filter(ds => ds.data.some(v => v !== null)); // Hide racers with no data in range

        return { labels: slicedLabels, datasets: slicedDatasets };
    }

    function refreshChart() {
        const startIdx = getFilteredRange(activeRange);
        const seasonOnly = seasonCheckbox.checked;
        eloChart.data = buildChart(startIdx, seasonOnly);
        eloChart.update();
    }

    let eloChart = new Chart(ctx, {
        type: 'line',
        data: buildChart(0, true),
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        font: { weight: 'bold', size: 12 },
                        padding: 25
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 13 },
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + Math.round(context.parsed.y) + ' ELO';
                        }
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: '#f0f0f0' },
                    ticks: { font: { weight: 'bold' } },
                    title: { display: true, text: 'ELO Rating', font: { weight: 'bold', size: 14 } },
                    suggestedMin: 800,
                    suggestedMax: 1800
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11, weight: 'bold' }, maxRotation: 45, minRotation: 0 }
                }
            }
        }
    });

    // Range buttons
    document.querySelectorAll('.elo-range-buttons .elo-cl-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.elo-range-buttons .elo-cl-btn').forEach(b => b.classList.remove('elo-cl-btn-active'));
            this.classList.add('elo-cl-btn-active');
            activeRange = this.dataset.range;
            refreshChart();
        });
    });

    // Season filter checkbox
    seasonCheckbox.addEventListener('change', refreshChart);
})();
</script>

<script>
(function() {
    const list = document.getElementById('elo-cl-list');
    const search = document.getElementById('elo-cl-search');
    const filterBtns = document.querySelectorAll('.elo-cl-controls .elo-cl-btn');
    const gpCards = list.querySelectorAll('.elo-cl-gp');
    let currentFilter = 'all';

    // Collapsed state — start all collapsed, click header to toggle
    gpCards.forEach(card => {
        const header = card.querySelector('.elo-cl-gp-header');
        const rows = card.querySelector('.elo-cl-rows');
        rows.style.display = 'none';
        header.addEventListener('click', () => {
            const open = rows.style.display !== 'none';
            rows.style.display = open ? 'none' : '';
            card.classList.toggle('elo-cl-gp-open', !open);
        });
    });

    // Open the most recent GP by default
    if (gpCards.length > 0) {
        gpCards[0].querySelector('.elo-cl-rows').style.display = '';
        gpCards[0].classList.add('elo-cl-gp-open');
    }

    function applyFilters() {
        const q = search.value.toLowerCase().trim();
        let visibleCount = 0;

        gpCards.forEach((card, idx) => {
            const gpid = card.dataset.gpid.toLowerCase();
            const names = card.textContent.toLowerCase();
            const matchesSearch = !q || gpid.includes(q) || names.includes(q);
            const matchesFilter = currentFilter === 'all' || (currentFilter === 'recent' && idx < 10);

            card.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
            if (matchesSearch && matchesFilter) visibleCount++;
        });
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('elo-cl-btn-active'));
            btn.classList.add('elo-cl-btn-active');
            currentFilter = btn.dataset.filter;
            applyFilters();
        });
    });

    search.addEventListener('input', applyFilters);
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
