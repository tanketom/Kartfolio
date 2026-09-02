<?php
/**
 * Animated GPScore Race - D3.js Bar Chart Race
 * Shows cumulative GPScore evolving GP-by-GP through the season
 * Path: /cdnmk/public_html/animate_season.php
 * URL: /animate-season
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();

// === JSON API MODE: Return GP-by-GP animation data ===
if (isset($_GET['data'])) {
    header('Content-Type: application/json');

    // Fetch season rules
    $rules = getSeasonRules($pdo, $seasonId);
    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    // Get all GPs in order
    $gpStmt = $pdo->prepare("
        SELECT DISTINCT gpid, MIN(race_date) as race_date,
               (SELECT cup_name FROM results r2 WHERE r2.gpid = r1.gpid LIMIT 1) as cup_name
        FROM results r1
        WHERE gpid LIKE ? AND gpid LIKE 's%'
        GROUP BY gpid
        ORDER BY gpid ASC
    ");
    $gpStmt->execute([$seasonId . '%']);
    $allGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all racers who participated in this season
    $racerStmt = $pdo->prepare("
        SELECT DISTINCT r.id, r.name
        FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
        ORDER BY r.name
    ");
    $racerStmt->execute([$seasonId . '%']);
    $racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get character portrait per racer (most used in season)
    $racerChars = [];
    foreach ($racers as $r) {
        $charStmt = $pdo->prepare("
            SELECT character_used, COUNT(*) as c
            FROM results
            WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
            GROUP BY character_used ORDER BY c DESC LIMIT 1
        ");
        $charStmt->execute([$r['id'], $seasonId . '%']);
        $racerChars[$r['id']] = $charStmt->fetchColumn() ?: 'Mii';
    }

    // Pre-fetch ALL results for performance (avoid N*M queries)
    $allResultsStmt = $pdo->prepare("
        SELECT gpid, racer_id, gp_points, race_date, cup_name, rank, id
        FROM results
        WHERE gpid LIKE ? AND gpid LIKE 's%'
        ORDER BY gpid ASC
    ");
    $allResultsStmt->execute([$seasonId . '%']);
    $allResults = $allResultsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Index results by racer => [gpid => {points, date, cup}]
    $racerResults = [];
    foreach ($allResults as $row) {
        $racerResults[$row['racer_id']][$row['gpid']] = $row;
    }

    // Build frames: for each GP checkpoint, calculate GPScore for every racer
    // using only results up to and including that GP
    $frames = [];
    $gpsSoFar = [];

    foreach ($allGPs as $gpIdx => $gp) {
        $gpsSoFar[] = $gp['gpid'];
        $gpIdSet = $gpsSoFar; // GPs up to this point

        $scores = [];
        foreach ($racers as $r) {
            $rid = $r['id'];

            // Collect this racer's results up to this GP
            $racerPointsSoFar = [];
            $racerDatesSoFar = [];
            $racerCupsSoFar = [];
            $racerRowsSoFar = [];

            foreach ($gpIdSet as $gpid) {
                if (isset($racerResults[$rid][$gpid])) {
                    $res = $racerResults[$rid][$gpid];
                    $racerPointsSoFar[] = (int)$res['gp_points'];
                    $racerDatesSoFar[] = $res['race_date'];
                    $racerRowsSoFar[] = $res;
                    $racerCupsSoFar[$res['cup_name']] = max(
                        $racerCupsSoFar[$res['cup_name']] ?? 0,
                        (int)$res['gp_points']
                    );
                }
            }

            $totalRaces = count($racerPointsSoFar);
            if ($totalRaces === 0) continue;

            // Calculate score based on scoring system using data up to this GP
            $score = 0;
            $provisional = false;

            switch ($scoringSystem) {
                case 'positional_points':
                case 'median':
                case 'form':
                case 'preseason':
                    // Exact replays from the racer's own rows (gp_logic).
                    $score = progressiveScoreFromRows($scoringSystem, $racerRowsSoFar, $rules);
                    break;

                case 'top_12_unique':
                    $cupBests = array_values($racerCupsSoFar);
                    rsort($cupBests);
                    $top12 = array_slice($cupBests, 0, 12);
                    $score = array_sum($top12);
                    break;

                case 'average_attendance':
                default:
                    // Any other system lands here as an APPROXIMATION — the
                    // payload carries 'approximate' so the page can say so.
                    // Below the qualifying threshold the racer is PROVISIONAL:
                    // shown greyed with their running average instead of being
                    // hidden (score 0) and then popping in at full value.
                    $threshold   = (int)($rules['min_races_threshold'] ?? 3);
                    $provisional = ($threshold > 0 && $totalRaces < $threshold);
                    $score = aaFromRows($racerRowsSoFar, $rules)['score'];
                    break;
            }

            $scores[] = [
                'id'    => $rid,
                'name'  => $r['name'],
                'score' => $score,
                'char'  => $racerChars[$rid] ?? 'Mii',
                'gps'         => $totalRaces,
                'provisional' => $provisional,
            ];
        }

        // Sort by score descending
        // Qualified racers first (score desc), then provisional ones; ties by
        // name so two level racers stop swapping between frames.
        usort($scores, fn($a, $b) => ((int)$a['provisional'] <=> (int)$b['provisional']) ?: ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']));

        $frames[] = [
            'gpid'     => $gp['gpid'],
            'gpNum'    => $gpIdx + 1,
            'cup'      => $gp['cup_name'],
            'date'     => $gp['race_date'],
            'scores'   => $scores
        ];
    }

    // Season info
    $seasonName = $rules['season_name'] ?? 'Season ' . strtoupper($seasonId);

    echo json_encode([
        'season'       => $seasonId,
        'seasonName'   => $seasonName,
        'scoringSystem' => $scoringSystem,
        'systemName'   => getScoringSystemDef($scoringSystem)['name'] ?? $scoringSystem,
        'approximate'  => !in_array($scoringSystem, ['average_attendance', 'preseason', 'top_12_unique', 'positional_points', 'median', 'form'], true),
        'totalGPs'     => count($allGPs),
        'threshold'    => (int)($rules['min_races_threshold'] ?? 3),
        'rosterSize'   => count($racers),
        'frames'       => $frames
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// === HTML PAGE MODE ===

// Fetch available seasons
$seasonsStmt = $pdo->query("
    SELECT sm.season_id, sm.season_name,
           (SELECT COUNT(DISTINCT gpid) FROM results WHERE gpid LIKE sm.season_id || '%' AND gpid LIKE 's%') as gp_count
    FROM season_meta sm
    ORDER BY sm.season_id DESC
");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

$meta = getSeasonRules($pdo, $seasonId);
$seasonName = $meta['season_name'] ?? '';

$pageTitle = "Season Race - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="anim-container">
    <div class="anim-header">
        <div class="anim-title-row">
            <h1 class="anim-title">GPScore Race</h1>
            <form class="anim-season-form" method="GET" action="/animate-season">
                <select name="season" onchange="this.form.submit()" class="anim-season-select">
                    <?php foreach ($availableSeasons as $s): ?>
                        <option value="<?= $s['season_id'] ?>" <?= $s['season_id'] === $seasonId ? 'selected' : '' ?>>
                            <?= strtoupper($s['season_id']) ?>: <?= htmlspecialchars($s['season_name']) ?> (<?= $s['gp_count'] ?> GPs)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <p class="anim-subtitle"><?= strtoupper($seasonId) ?>: <?= htmlspecialchars($seasonName) ?></p>
    </div>

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

<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
(function() {
    const seasonId = <?= json_encode($seasonId) ?>;
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
    fetch(`/animate-season?season=${seasonId}&data=1`)
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
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
