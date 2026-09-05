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

/**
 * A racer's season rows newest first (race_date DESC, gpid DESC, id DESC),
 * regular-season GPs only (gpid LIKE 's%'), from the season cache.
 */
function powerRankingRows(PDO $pdo, int $racerId, string $season): array {
    $rows = array_values(array_filter(getRacerSeasonRows($pdo, $racerId, $season), fn($r) => str_starts_with((string)$r['gpid'], 's')));
    usort($rows, fn($a, $b) => strcmp((string)$b['race_date'], (string)$a['race_date']) ?: strcmp((string)$b['gpid'], (string)$a['gpid']) ?: ((int)$b['id'] <=> (int)$a['id']));
    return $rows;
}

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

// ============================================================
// 5. Check for cached AI commentary
// ============================================================
$cachedCommentary = [];
try {
    $cacheStmt = $pdo->prepare("
        SELECT recap_text FROM recap_archive
        WHERE program_key = 'power_rankings' AND season_id = ?
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
