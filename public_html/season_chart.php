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

    <?php if (empty($seasonGPs)): ?>
        <div class="sc-empty">
            <p>No GPs recorded in <strong><?= strtoupper(htmlspecialchars($selectedSeason)) ?></strong> yet.</p>
        </div>
    <?php else: ?>
        <div class="sc-chart-wrap">
            <canvas id="seasonChart"></canvas>
        </div>

        <p class="sc-caveat">
            Rank is computed from <strong>cumulative GP points</strong> in chronological order.
            For Average + Attendance and most cup-based scoring systems this tracks the official standings
            closely; for Best-N, Drop-Worst, MONSTER HUNT, Bounty Hunter, and Pari-Mutuel it may diverge
            from the final leaderboard. The shape of the rivalries is unchanged either way.
        </p>
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

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
