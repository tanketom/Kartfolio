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
require_once __DIR__ . '/../private/includes/sim_cache.php';

$pageTitle = "Crystal Ball - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';

// ─── The whole computation lives in season_outlook.php ───────────────────
// It was ~280 lines here, and the newscast needs the same numbers; two copies
// of a Monte Carlo is how two pages start quoting different odds.
require_once __DIR__ . '/../private/includes/season_outlook.php';
extract(seasonOutlook($pdo), EXTR_OVERWRITE);
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
    const names = <?= jsonForScript(array_keys($probabilities)) ?>;
    const probs = <?= jsonForScript(array_values($probabilities)) ?>;
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
