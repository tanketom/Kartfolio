<?php
/**
 * Season Chart — F1-style spaghetti showing each racer's rank in the season
 * standings after every GP, GP by GP. Crossings make rivalries pop.
 *
 * Rank is computed from cumulative gp_points (sum of every GP a racer has
 * played up to that point in the season). This is a faithful proxy for the
 * eventual scoring system in most cases — and matches the visual intuition
 * everyone has about season standings. A footnote on the page makes that
 * caveat explicit so admins running Best-N / Drop-Worst / MONSTER HUNT
 * seasons don't expect this to be exact final standings.
 *
 * Path: /cdnmk/public_html/season_chart.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$currentSeason = getCurrentSeasonNumber();
$selectedSeason = $_GET['season'] ?? $currentSeason;

// Available seasons for the picker.
$seasons = $pdo->query("SELECT DISTINCT SUBSTR(gpid, 1, 3) AS season FROM results WHERE gpid LIKE 's%' ORDER BY season ASC")->fetchAll(PDO::FETCH_COLUMN);

// ── Build chronological GP list for the selected season ────────────────
$gpStmt = $pdo->prepare("
    SELECT gpid, MIN(race_date) AS race_date, cup_name
    FROM results WHERE gpid LIKE ?
    GROUP BY gpid
    ORDER BY race_date ASC, gpid ASC
");
$gpStmt->execute([$selectedSeason . '%']);
$seasonGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Per-racer cumulative points after each GP (in chronological order) ──
// We compute everyone's rolling cumulative gp_points and use that as the
// rank basis. A racer who hasn't yet appeared in the season has 0 points
// and so doesn't appear on the chart until their first GP.
$racerStmt = $pdo->prepare("
    SELECT DISTINCT res.racer_id, r.name
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
");
$racerStmt->execute([$selectedSeason . '%']);
$seasonRacers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

// Pull every result in the season, indexed by gpid → racer_id → points.
$resStmt = $pdo->prepare("
    SELECT gpid, racer_id, gp_points
    FROM results WHERE gpid LIKE ?
");
$resStmt->execute([$selectedSeason . '%']);
$byGp = [];
foreach ($resStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $byGp[$r['gpid']][$r['racer_id']] = (int)$r['gp_points'];
}

// Walk the chronological GP list and build per-racer (gpIndex, rank) pairs.
// Racers who haven't yet appeared are excluded from that GP's ranking so
// they don't all start at rank 1 at GP 0.
$cumPoints   = [];               // racer_id → cumulative points
$hasAppeared = [];               // racer_id → bool
$racerNames  = [];               // racer_id → name
foreach ($seasonRacers as $r) { $racerNames[(int)$r['racer_id']] = $r['name']; }

$series = [];                    // racer_id → [{x, y} ...]
$gpLabels = [];

foreach ($seasonGPs as $idx => $gp) {
    $gpid = $gp['gpid'];
    $gpLabels[] = strtoupper($gpid);
    $gpResults = $byGp[$gpid] ?? [];

    foreach ($gpResults as $rid => $pts) {
        $cumPoints[$rid] = ($cumPoints[$rid] ?? 0) + $pts;
        $hasAppeared[$rid] = true;
    }

    // Rank everyone who has appeared so far by their cumulative points
    // (descending); ties broken by racer_id ascending for stability.
    $candidates = [];
    foreach ($hasAppeared as $rid => $_) {
        $candidates[] = ['id' => $rid, 'pts' => $cumPoints[$rid] ?? 0];
    }
    usort($candidates, function ($a, $b) {
        if ($a['pts'] !== $b['pts']) return $b['pts'] <=> $a['pts'];
        return $a['id'] <=> $b['id'];
    });
    foreach ($candidates as $rank0 => $c) {
        $series[$c['id']][] = ['x' => $idx + 1, 'y' => $rank0 + 1];
    }
}

// Sort racers by their final rank (last data point) so the legend reads
// top-to-bottom in the same order as the final standings on the chart.
$finalRanks = [];
foreach ($series as $rid => $points) {
    $finalRanks[$rid] = end($points)['y'] ?? 999;
}
asort($finalRanks);

$chartDatasets = [];
$colourPalette = [
    'var(--nintendo-red)','#0066cc','#2ebd59','#ff9500','#8e44ad','#1abc9c',
    '#e67e22','#9b59b6','#27ae60','#34495e','#f39c12','#c0392b',
    '#16a085','#2980b9','#d35400','#7f8c8d','#fd79a8','#00cec9',
    '#fdcb6e','#a29bfe','#55efc4','#ffeaa7','#fab1a0','#e84393',
];
$ci = 0;
foreach ($finalRanks as $rid => $_) {
    $name = $racerNames[$rid] ?? "Racer #{$rid}";
    $chartDatasets[] = [
        'label'           => $name,
        'data'            => $series[$rid],
        'borderColor'     => $colourPalette[$ci % count($colourPalette)],
        'backgroundColor' => $colourPalette[$ci % count($colourPalette)],
        'borderWidth'     => 2.4,
        'tension'         => 0.25,
        'pointRadius'     => 3,
        'pointHoverRadius'=> 6,
    ];
    $ci++;
}

$maxRank = !empty($finalRanks) ? max(array_values($finalRanks)) : 1;

$pageTitle = "Season Chart — Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Season Chart</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">📈 Season Chart</h1>
        <p class="page-subtitle">F1-STYLE POSITION-BY-GP · CROSSINGS ARE WHERE RIVALRIES LIVE</p>
    </header>

    <form method="GET" class="sc-filter">
        <label>Season
            <select name="season" onchange="this.form.submit()">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $selectedSeason ? 'selected' : '' ?>>
                        <?= strtoupper(htmlspecialchars($s)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php /* Two ways to watch one season: positions crossing GP by GP, and the
             same GPs as a bar-chart race. /animate-season used to be its own
             page; both live here now behind one season selector. */ ?>
    <div class="sc-views" role="tablist" aria-label="Chart view">
        <button type="button" class="sc-view is-on" id="sc-tab-positions" role="tab" aria-selected="true">📈 Positions</button>
        <button type="button" class="sc-view" id="sc-tab-race" role="tab" aria-selected="false">🏁 The Race</button>
    </div>

    <?php if (empty($seasonGPs)): ?>
        <div class="sc-empty">
            <p>No GPs recorded in <strong><?= strtoupper(htmlspecialchars($selectedSeason)) ?></strong> yet.</p>
        </div>
    <?php else: ?>
        <div id="sc-panel-positions" role="tabpanel">
        <div class="sc-chart-wrap">
            <canvas id="seasonChart"></canvas>
        </div>

        <p class="sc-caveat">
            Rank is computed from <strong>cumulative GP points</strong> in chronological order.
            For Average + Attendance and most cup-based scoring systems this tracks the official standings
            closely; for Best-N, Drop-Worst, MONSTER HUNT, Bounty Hunter, and Pari-Mutuel it may diverge
            from the final leaderboard. The shape of the rivalries is unchanged either way.
        </p>
        </div>

        <div id="sc-panel-race" class="anim-container" role="tabpanel" hidden>
<!-- Playback Controls -->
    <div class="anim-controls">
        <button class="anim-btn anim-btn-play" id="anim-play">▶ Play</button>
        <button class="anim-btn" id="anim-pause" disabled>⏸ Pause</button>
        <button class="anim-btn" id="anim-reset">⏮ Reset</button>
        <div class="anim-speed">
            <label>Speed</label>
            <input type="range" id="anim-speed" min="200" max="2000" value="800" step="100">
        </div>
        <div class="anim-gp-indicator" id="anim-gp-label">GP 0 / 0</div>
    </div>

    <!-- Progress Bar -->
    <div class="anim-progress-track">
        <div class="anim-progress-fill" id="anim-progress"></div>
    </div>

    <!-- D3 Chart Area -->
    <div class="anim-chart-wrapper">
        <div id="anim-chart"></div>
    </div>

    <!-- GP Info Footer -->
    <div class="anim-gp-info" id="anim-gp-info"></div>
        </div>
    <?php endif; ?>
</div>

<style>
.sc-filter {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 12px 18px;
    margin-bottom: 16px;
    display: flex;
    gap: 16px;
    color: var(--gray-700);
}
.sc-filter label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.sc-filter select {
    background: var(--gray-200);
    color: var(--gray-900);
    border: 1px solid #333;
    padding: 6px 10px;
    border-radius: 4px;
    font: inherit;
}
.sc-chart-wrap {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 16px;
    height: 600px;
}
.sc-caveat {
    margin-top: 14px;
    color: var(--gray-500);
    font-size: 0.85rem;
    font-style: italic;
    line-height: 1.5;
}
.sc-empty {
    background: var(--gray-50); border: 1px solid var(--gray-200);
    border-radius: 8px; padding: 40px; text-align: center; color: var(--gray-500);
}
</style>

<?php if (!empty($seasonGPs)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
(function () {
    const ctx = document.getElementById('seasonChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($gpLabels) ?>,
            datasets: <?= json_encode($chartDatasets) ?>,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#4a4438', boxWidth: 14, padding: 6, font: { size: 11 } },
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.dataset.label + ' — rank #' + ctx.parsed.y + ' after ' + ctx.label;
                        }
                    }
                },
            },
            scales: {
                x: {
                    title: { display: true, text: 'Grand Prix (chronological)', color: '#8a8170' },
                    ticks: { color: '#6b6453', font: { size: 10 } },
                    grid:  { color: '#e8e0cc' },
                },
                y: {
                    reverse: true,
                    min: 1,
                    max: <?= max(1, (int)$maxRank) ?>,
                    title: { display: true, text: 'Standings rank (1 = leader)', color: '#8a8170' },
                    ticks: { color: '#6b6453', stepSize: 1 },
                    grid:  { color: '#e8e0cc' },
                },
            },
        },
    });
})();
</script>
<?php endif; ?>

<script>
window.initSeasonRace = function () {
    if (window.__raceStarted) return;
    window.__raceStarted = true;
    const seasonId = <?= jsonForScript($selectedSeason) ?>;
    let animData = null;
    let currentFrame = -1;
    let playing = false;
    let timer = null;
    let maxBars = 12;

    // Assigned colors per racer (consistent across frames)
    const colorPalette = [
        'var(--nintendo-red)', '#0066CC', '#2EBD59', '#FF8C00', '#8B5CF6',
        '#EC4899', '#14B8A6', '#F59E0B', '#6366F1', '#EF4444',
        '#10B981', '#3B82F6', '#F97316', '#A855F7', '#06B6D4',
        '#D946EF', '#84CC16', '#FB923C'
    ];
    const racerColors = {};

    // DOM elements
    const playBtn    = document.getElementById('anim-play');
    const pauseBtn   = document.getElementById('anim-pause');
    const resetBtn   = document.getElementById('anim-reset');
    const speedSlider = document.getElementById('anim-speed');
    const gpLabel    = document.getElementById('anim-gp-label');
    const gpInfo     = document.getElementById('anim-gp-info');
    const progressBar = document.getElementById('anim-progress');
    const chartDiv   = document.getElementById('anim-chart');

    // Chart dimensions
    const margin = { top: 10, right: 100, bottom: 10, left: 10 };
    let width, height;
    const barHeight = 38;
    const barPad = 6;

    function calcDimensions() {
        width = chartDiv.clientWidth - margin.left - margin.right;
        height = maxBars * (barHeight + barPad) + margin.top + margin.bottom;
    }

    // Create SVG
    calcDimensions();
    const svg = d3.select('#anim-chart')
        .append('svg')
        .attr('width', '100%')
        .attr('height', height)
        .append('g')
        .attr('transform', `translate(${margin.left},${margin.top})`);

    // Fixed z-order: bars under portraits under text. d3 used to append
    // every entering <rect> to the SVG root AFTER the existing <text> nodes,
    // so each new racer painted over other racers' names and scores.
    const layers = {
        bars:      svg.append('g').attr('class', 'anim-layer-bars'),
        portraits: svg.append('g').attr('class', 'anim-layer-portraits'),
        names:     svg.append('g').attr('class', 'anim-layer-names'),
        scores:    svg.append('g').attr('class', 'anim-layer-scores'),
        gps:       svg.append('g').attr('class', 'anim-layer-gps'),
    };

    // Scales
    const x = d3.scaleLinear().range([0, width]);
    const y = d3.scaleBand().range([0, height - margin.top - margin.bottom]).padding(0.12);

    // Load data
    fetch(`/api/season-race?season=${encodeURIComponent(seasonId)}`)
        .then(r => r.json())
        .then(data => {
            animData = data;
            gpLabel.textContent = `GP 0 / ${data.totalGPs}`;
            if (data.approximate) {
                // The server couldn't replay this system GP by GP — say so
                // rather than presenting a GPScore™-style curve as the real thing.
                const sub = document.querySelector('.anim-subtitle');
                if (sub) sub.textContent += ` · ${data.systemName} can't be replayed GP by GP — frames show a GPScore™-style average as an approximation`;
            }

            // Assign colors
            const allRacers = new Set();
            data.frames.forEach(f => f.scores.forEach(s => allRacers.add(s.name)));
            // Size the canvas to the roster once, so no frame needs to grow it.
            maxBars = Math.min(30, Math.max(12, data.rosterSize || allRacers.size));
            calcDimensions();
            d3.select('#anim-chart svg').attr('height', height);
            let ci = 0;
            allRacers.forEach(name => {
                racerColors[name] = colorPalette[ci % colorPalette.length];
                ci++;
            });

            // Show first frame statically
            if (data.frames.length > 0) {
                renderFrame(0, 0);
            }
        })
        .catch(err => {
            chartDiv.innerHTML = '<p style="color:#999;text-align:center;padding:40px;">No data available for this season.</p>';
        });

    // Controls
    playBtn.addEventListener('click', () => {
        if (!animData) return;
        if (currentFrame >= animData.frames.length - 1) currentFrame = -1;
        playing = true;
        playBtn.disabled = true;
        pauseBtn.disabled = false;
        stepForward();
    });

    pauseBtn.addEventListener('click', () => {
        playing = false;
        clearTimeout(timer);
        playBtn.disabled = false;
        pauseBtn.disabled = true;
    });

    resetBtn.addEventListener('click', () => {
        playing = false;
        clearTimeout(timer);
        currentFrame = -1;
        playBtn.disabled = false;
        pauseBtn.disabled = true;
        gpLabel.textContent = `GP 0 / ${animData ? animData.totalGPs : 0}`;
        gpInfo.innerHTML = '';
        progressBar.style.width = '0%';
        svg.selectAll('*').remove();
        if (animData && animData.frames.length > 0) renderFrame(0, 0);
    });

    function stepForward() {
        if (!playing || !animData) return;
        currentFrame++;
        if (currentFrame >= animData.frames.length) {
            playing = false;
            playBtn.disabled = false;
            pauseBtn.disabled = true;
            return;
        }
        const speed = parseInt(speedSlider.value);
        renderFrame(currentFrame, speed * 0.7);
        timer = setTimeout(stepForward, speed);
    }

    function renderFrame(frameIdx, transitionMs) {
        const frame = animData.frames[frameIdx];
        if (!frame) return;

        currentFrame = frameIdx;

        // Update labels
        const gpNum = frame.gpNum;
        gpLabel.textContent = `GP ${gpNum} / ${animData.totalGPs}`;
        gpInfo.innerHTML = `<span class="anim-gp-tag">${frame.gpid}</span> <span class="anim-gp-cup">${frame.cup} Cup</span> <span class="anim-gp-date">${formatDate(frame.date)}</span>`;
        progressBar.style.width = `${(gpNum / animData.totalGPs) * 100}%`;

        // Get top N scores (filter out 0s)
        const scores = frame.scores.filter(s => s.score > 0 || s.provisional).slice(0, maxBars);
        const maxScore = d3.max(scores, d => d.score) || 1;

        // Update scales — constant bar height; the chart grows downward and the
        // SVG is already tall enough for the whole roster, so entrants rise from
        // inside the canvas instead of flying in from off-screen.
        x.domain([0, maxScore * 1.15]);
        y.range([0, scores.length * (barHeight + barPad)]).domain(scores.map(d => d.name));

        const t = d3.transition().duration(transitionMs).ease(d3.easeCubicOut);

        // Bottom of chart area for enter animations
        const chartBottom = scores.length * (barHeight + barPad) + 40;

        // === BARS ===
        const bars = layers.bars.selectAll('.anim-bar')
            .data(scores, d => d.name);

        const barsEnter = bars.enter()
            .append('rect')
            .attr('class', 'anim-bar')
            .attr('x', 0)
            .attr('y', chartBottom)
            .attr('height', y.bandwidth())
            .attr('width', d => Math.max(0, x(d.score)))
            .attr('rx', 4)
            .attr('fill', d => d.provisional ? '#b8c0c8' : (racerColors[d.name] || '#999'))
            .attr('opacity', 0);

        bars.merge(barsEnter)
            .transition(t)
            .attr('y', d => y(d.name))
            .attr('height', y.bandwidth())
            .attr('width', d => Math.max(0, x(d.score)))
            .attr('fill', d => d.provisional ? '#b8c0c8' : (racerColors[d.name] || '#999'))
            .attr('opacity', 1);

        bars.exit()
            .transition(t)
            .attr('y', chartBottom)
            .attr('opacity', 0)
            .remove();

        // === PORTRAITS ===
        const portraits = layers.portraits.selectAll('.anim-portrait')
            .data(scores, d => d.name);

        const portraitsEnter = portraits.enter()
            .append('image')
            .attr('class', 'anim-portrait')
            .attr('width', 30)
            .attr('height', 30)
            .attr('href', d => `/assets/img/${d.char}.png`)
            .attr('x', 4)
            .attr('y', chartBottom)
            .attr('opacity', 0);

        portraits.merge(portraitsEnter)
            .transition(t)
            .attr('y', d => y(d.name) + (y.bandwidth() - 30) / 2)
            .attr('x', 4)
            .attr('opacity', 1);

        portraits.exit().transition(t).attr('y', chartBottom).attr('opacity', 0).remove();

        // === NAME LABELS (inside bar, clipped to bar width) ===
        const names = layers.names.selectAll('.anim-name')
            .data(scores, d => d.name);

        const namesEnter = names.enter()
            .append('text')
            .attr('class', 'anim-name')
            .attr('x', 38)
            .attr('y', chartBottom)
            .attr('dy', '0.35em')
            .attr('fill', '#fff')
            .attr('font-size', '13px')
            .attr('font-weight', '800')
            .attr('opacity', 0)
            .text(d => d.name);

        names.merge(namesEnter)
            .transition(t)
            .attr('y', d => y(d.name) + y.bandwidth() / 2)
            .attr('opacity', d => x(d.score) > 100 ? 1 : 0)
            .text(d => d.name);

        names.exit().transition(t).attr('y', chartBottom).attr('opacity', 0).remove();

        // === SCORE LABELS (at end of bar) ===
        const scoreLabels = layers.scores.selectAll('.anim-score')
            .data(scores, d => d.name);

        const scoreEnter = scoreLabels.enter()
            .append('text')
            .attr('class', 'anim-score')
            .attr('x', d => x(d.score) + 6)
            .attr('y', chartBottom)
            .attr('dy', '0.35em')
            .attr('fill', '#333')
            .attr('font-size', '12px')
            .attr('font-weight', '700')
            .attr('opacity', 0);

        scoreLabels.merge(scoreEnter)
            .transition(t)
            .attr('x', d => x(d.score) + 6)
            .attr('y', d => y(d.name) + y.bandwidth() / 2)
            .attr('opacity', 1)
            .text(d => d.score.toFixed(1));

        scoreLabels.exit().transition(t).attr('y', chartBottom).attr('opacity', 0).remove();

        // === GP COUNT (small, after score) ===
        const gpCounts = layers.gps.selectAll('.anim-gpcount')
            .data(scores, d => d.name);

        const gpCountEnter = gpCounts.enter()
            .append('text')
            .attr('class', 'anim-gpcount')
            .attr('x', d => x(d.score) + 6)
            .attr('y', chartBottom)
            .attr('fill', '#999')
            .attr('font-size', '10px')
            .attr('font-weight', '600')
            .attr('opacity', 0);

        gpCounts.merge(gpCountEnter)
            .transition(t)
            .attr('x', d => x(d.score) + 6)
            .attr('y', d => y(d.name) + y.bandwidth() / 2 + 14)
            .attr('opacity', 1)
            .text(d => d.provisional ? `${d.gps} / ${animData.threshold} GPs to qualify` : d.gps + ' GPs');

        gpCounts.exit().transition(t).attr('y', chartBottom).attr('opacity', 0).remove();

        // Update SVG height dynamically
        const newHeight = scores.length * (barHeight + barPad) + margin.top + margin.bottom;
        d3.select('#anim-chart svg').attr('height', Math.max(newHeight, height));
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate();
    }

    // Responsive resize
    window.addEventListener('resize', () => {
        calcDimensions();
        x.range([0, width]);
        if (currentFrame >= 0 && animData) {
            renderFrame(currentFrame, 0);
        }
    });
};
</script>

<script>
// The two views share a season selector; only the one you are looking at does
// any work. D3 is fetched the first time the race is opened rather than on
// every visit to the positions chart. (d3js.org is already on the CSP
// allow-list — a new host would have to be added in http_headers.php.)
(function () {
    const tabPos   = document.getElementById('sc-tab-positions');
    const tabRace  = document.getElementById('sc-tab-race');
    const panelPos = document.getElementById('sc-panel-positions');
    const panelRace= document.getElementById('sc-panel-race');
    if (!tabPos || !tabRace || !panelPos || !panelRace) return;

    let d3Loading = null;
    function loadD3() {
        if (window.d3) return Promise.resolve();
        if (d3Loading) return d3Loading;
        d3Loading = new Promise((resolve, reject) => {
            const el = document.createElement('script');
            el.src = 'https://d3js.org/d3.v7.min.js';
            el.onload = resolve;
            el.onerror = reject;
            document.head.appendChild(el);
        });
        return d3Loading;
    }

    function show(which) {
        const race = which === 'race';
        panelRace.hidden = !race;
        panelPos.hidden  = race;
        tabRace.classList.toggle('is-on', race);
        tabPos.classList.toggle('is-on', !race);
        tabRace.setAttribute('aria-selected', race ? 'true' : 'false');
        tabPos.setAttribute('aria-selected', race ? 'false' : 'true');
        // Remember the choice across a season switch, which reloads the page.
        try { localStorage.setItem('sc-view', which); } catch (e) {}
        if (race) loadD3().then(() => window.initSeasonRace && window.initSeasonRace())
                          .catch(() => { const i = document.getElementById('anim-gp-info');
                                         if (i) i.textContent = 'Could not load the chart library.'; });
    }

    tabPos.addEventListener('click', () => show('positions'));
    tabRace.addEventListener('click', () => show('race'));

    let saved = null;
    try { saved = localStorage.getItem('sc-view'); } catch (e) {}
    if (saved === 'race') show('race');
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
