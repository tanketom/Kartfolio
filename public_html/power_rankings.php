<?php
require_once __DIR__ . '/../private/includes/csrf.php';
/**
 * Power Rankings - Composite Skill Metric
 * Path: /cdnmk/public_html/power_rankings.php
 *
 * Blends ELO (40%), Recent Form (35%), and Consistency (25%)
 * into a single power score per racer.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$pageTitle = "Power Rankings - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';

// ============================================================
// 3. Power rankings — computed in power_ranking_engine.php so the newscast
//    can describe current form from the same numbers this page shows.
// ============================================================
require_once __DIR__ . '/../private/includes/power_ranking_engine.php';
$currentSeason = getCurrentSeasonNumber();   // the view and the commentary cache still need it
$rankings      = powerRankings($pdo);

// ============================================================
// 5. Check for cached AI commentary
// ============================================================
$cachedCommentary = [];
try {
    $cacheStmt = $pdo->prepare("
        SELECT recap_text FROM recap_archive
        WHERE program_key = 'power_rankings' AND season_id = ? AND status = 'published'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $cacheStmt->execute([$currentSeason]);
    $cachedRow = $cacheStmt->fetch(PDO::FETCH_ASSOC);
    if ($cachedRow && !empty($cachedRow['recap_text'])) {
        $decoded = json_decode($cachedRow['recap_text'], true);
        if (is_array($decoded)) {
            $cachedCommentary = $decoded;
        }
    }
} catch (Exception $e) {
    // Fail silently
}

// Apply cached commentary to rankings
foreach ($rankings as &$r) {
    $r['cached_commentary'] = $cachedCommentary[$r['id']] ?? '';
}
unset($r);

// ============================================================
// 6. Render Page
// ============================================================
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">

    <!-- Header Card -->
    <div class="racer-card pwr-header-card">
        <h1 class="pwr-title">Power Rankings</h1>
        <p class="pwr-subtitle">Composite skill metric blending ELO, recent form, and consistency</p>
        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
        <button id="pwr-ai-btn" class="pwr-ai-btn">Generate AI Analysis</button>
        <?php endif; ?>
        <div id="pwr-ai-status" class="pwr-ai-status"></div>
    </div>

    <?php if (empty($rankings)): ?>
    <div class="racer-card">
        <p style="text-align:center; padding: 40px 20px; color: #888;">No racers found for the current season. Play some GPs first!</p>
    </div>
    <?php else: ?>

    <!-- Radar Chart: Top 3 Comparison -->
    <?php if (count($rankings) >= 3): ?>
    <div class="racer-card pwr-radar-card">
        <h2 class="pwr-section-title">Top 3 Comparison</h2>
        <canvas id="pwr-radar" height="300"></canvas>
    </div>
    <?php elseif (count($rankings) >= 1): ?>
    <div class="racer-card pwr-radar-card">
        <h2 class="pwr-section-title">Top <?= count($rankings) ?> Comparison</h2>
        <canvas id="pwr-radar" height="300"></canvas>
    </div>
    <?php endif; ?>

    <!-- Rankings List -->
    <div class="pwr-rankings-list">
        <?php $rank = 0; foreach ($rankings as $r): $rank++; ?>
        <div class="racer-card pwr-rank-card <?= $rank <= 3 ? 'pwr-rank-top' : '' ?>">
            <div class="pwr-card-main">
                <div class="pwr-position">
                    <span class="pwr-pos-num">#<?= $rank ?></span>
                    <?php if ($r['movement'] > 0): ?>
                        <span class="pwr-movement pwr-up">&#9650;<?= $r['movement'] ?></span>
                    <?php elseif ($r['movement'] < 0): ?>
                        <span class="pwr-movement pwr-down">&#9660;<?= abs($r['movement']) ?></span>
                    <?php else: ?>
                        <span class="pwr-movement pwr-flat">&ndash;</span>
                    <?php endif; ?>
                </div>
                <img class="pwr-portrait" src="/assets/img/<?= urlencode($r['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="<?= htmlspecialchars($r['char']) ?>">
                <div class="pwr-info">
                    <div class="pwr-name"><?= htmlspecialchars($r['name']) ?></div>
                    <div class="pwr-meta">
                        <?php if ($r['win_streak'] > 0): ?>
                            <span class="pwr-streak"><?= $r['win_streak'] ?> win<?= $r['win_streak'] > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                        <?php if ($r['podium_streak'] > 1): ?>
                            <span class="pwr-streak pwr-streak-podium"><?= $r['podium_streak'] ?> podiums</span>
                        <?php endif; ?>
                        <span class="pwr-gps-played"><?= $r['gps_played'] ?> GPs</span>
                    </div>
                </div>
                <div class="pwr-score-value"><?= round($r['power_score'], 1) ?></div>
            </div>
            <div class="pwr-bar-row">
                <!-- Three 0–100 meters, one per component. The old single stacked
                     bar showed the WEIGHTED contributions, so a perfect consistency
                     score was a sliver and the labels clipped — it read as a
                     percentage but wasn't one. Weights live in the methodology card. -->
                <div class="pwr-meters">
                    <?php foreach ([['elo', 'Elo', $r['elo_norm']], ['form', 'Form', $r['form_norm']], ['cons', 'Consistency', $r['cons_norm']]] as [$mk, $ml, $mv]): ?>
                    <div class="pwr-meter pwr-meter-<?= $mk ?>" title="<?= $ml ?> <?= round($mv) ?> / 100">
                        <span class="pwr-meter-label"><?= $ml ?></span>
                        <div class="pwr-meter-track"><div class="pwr-meter-fill" style="width:<?= max(0, min(100, round($mv, 1))) ?>%"></div></div>
                        <span class="pwr-meter-val"><?= round($mv) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!empty($r['cached_commentary'])): ?>
            <div class="pwr-commentary" id="pwr-comm-<?= $r['id'] ?>"><?= htmlspecialchars($r['cached_commentary']) ?></div>
            <?php else: ?>
            <div class="pwr-commentary" id="pwr-comm-<?= $r['id'] ?>"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <!-- Methodology -->
    <div class="racer-card pwr-methodology-card">
        <h2 class="pwr-section-title">Methodology</h2>
        <div class="pwr-method-grid">
            <div class="pwr-method">
                <div class="pwr-method-icon">&#129504;</div>
                <h3>ELO Rating (40%)</h3>
                <p>All-time skill rating based on head-to-head performance. Rewards beating stronger opponents.</p>
            </div>
            <div class="pwr-method">
                <div class="pwr-method-icon">&#128293;</div>
                <h3>Recent Form (35%)</h3>
                <p>Average GP points over the last 5 races. Captures who's hot right now.</p>
            </div>
            <div class="pwr-method">
                <div class="pwr-method-icon">&#127919;</div>
                <h3>Consistency (25%)</h3>
                <p>Inverse of score variance over last 10 races. Steady performers score higher.</p>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($rankings)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
(function() {
    // ================================================================
    // Radar Chart - Top racers comparison
    // ================================================================
    const allRankings = <?= jsonForScript($rankings) ?>;
    const topN = allRankings.slice(0, Math.min(3, allRankings.length));
    const radarColors = ['var(--nintendo-red)', '#0066CC', '#2EBD59'];

    if (topN.length > 0 && document.getElementById('pwr-radar')) {
        // Compute dynamic min so close values are visually distinguishable
        var allVals = [];
        topN.forEach(function(r) { allVals.push(r.elo_norm, r.form_norm, r.cons_norm); });
        var dataMin = Math.min.apply(null, allVals);
        var dataMax = Math.max.apply(null, allVals);
        var range = dataMax - dataMin;
        // Floor at 20% below the lowest value (rounded down to nearest 10), but never below 0
        var scaleMin = Math.max(0, Math.floor((dataMin - range * 0.5) / 10) * 10);
        var scaleMax = Math.min(100, Math.ceil((dataMax + range * 0.2) / 10) * 10);
        // Ensure at least 30-point range so the chart isn't overly zoomed
        if (scaleMax - scaleMin < 30) {
            scaleMin = Math.max(0, scaleMax - 30);
        }
        var stepSize = Math.max(5, Math.round((scaleMax - scaleMin) / 5));

        new Chart(document.getElementById('pwr-radar').getContext('2d'), {
            type: 'radar',
            data: {
                labels: ['ELO', 'Form', 'Consistency'],
                datasets: topN.map(function(r, i) {
                    return {
                        label: r.name,
                        data: [r.elo_norm, r.form_norm, r.cons_norm],
                        borderColor: radarColors[i] || '#888',
                        backgroundColor: (radarColors[i] || '#888') + '33',
                        borderWidth: 2,
                        pointRadius: 4
                    };
                })
            },
            options: {
                responsive: true,
                scales: {
                    r: {
                        min: scaleMin,
                        max: scaleMax,
                        ticks: { stepSize: stepSize, color: '#888', backdropColor: 'transparent' },
                        grid: { color: 'rgba(0,0,0,0.08)' },
                        angleLines: { color: 'rgba(0,0,0,0.08)' },
                        pointLabels: { font: { size: 13, weight: '700' }, color: '#333' }
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12, weight: '600' }, padding: 20 }
                    }
                }
            }
        });
    }

    // ================================================================
    // AI Commentary Generation (admin-only)
    // ================================================================
    var aiBtn = document.getElementById('pwr-ai-btn');
    if (aiBtn) {
        aiBtn.addEventListener('click', async function() {
            var btn = this;
            var status = document.getElementById('pwr-ai-status');
            btn.disabled = true;
            status.textContent = 'Generating analysis...';
            status.style.color = '#888';

            try {
                var res = await fetch('/api/gemini-power-rankings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
                    body: JSON.stringify({ rankings: allRankings })
                });
                var data = await res.json();
                if (data.commentaries) {
                    Object.entries(data.commentaries).forEach(function(entry) {
                        var id = entry[0];
                        var text = entry[1];
                        var el = document.getElementById('pwr-comm-' + id);
                        if (el) el.textContent = text;
                    });
                    status.textContent = 'Analysis generated and cached!';
                    status.style.color = '#2EBD59';
                } else {
                    status.textContent = 'Error: ' + (data.error || 'Unknown error');
                    status.style.color = 'var(--nintendo-red)';
                }
            } catch(e) {
                status.textContent = 'Failed to connect to API.';
                status.style.color = 'var(--nintendo-red)';
            }
            btn.disabled = false;
        });
    }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
