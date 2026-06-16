<?php
/**
 * Cup Mastery Grid
 * Visual 24-cup × all-racers completion grid with best scores.
 * Path: /cdnmk/public_html/cup_mastery.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';

$currentSeason = getCurrentSeasonNumber();
$selectedSeason = $_GET['season'] ?? $currentSeason;
$isAllTime = ($selectedSeason === 'all');

$cupGroups   = getMKCupsByGroup();
$allCupNames = getMKAllCups();

// Available seasons for filter
$seasonsStmt = $pdo->query("SELECT DISTINCT SUBSTR(gpid, 1, 3) as season FROM results ORDER BY season DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_COLUMN);

// Query: best score + times played per racer per cup
$seasonFilter = $isAllTime ? '%' : ($selectedSeason . '%');
$gridStmt = $pdo->prepare("
    SELECT r.id, r.name AS racer_name, res.cup_name,
           MAX(res.gp_points) AS best_score,
           COUNT(DISTINCT res.gpid) AS times_played
    FROM racers r
    JOIN results res ON res.racer_id = r.id
    WHERE res.gpid LIKE ? AND res.cup_name IS NOT NULL
    GROUP BY r.id, res.cup_name
");
$gridStmt->execute([$seasonFilter]);
$rawRows = $gridStmt->fetchAll(PDO::FETCH_ASSOC);

// Build 2D lookup: $grid[$racer_name][$cup_name] = [best, times]
$grid = [];
foreach ($rawRows as $row) {
    $grid[$row['racer_name']][$row['cup_name']] = [
        'best'  => (int)$row['best_score'],
        'times' => (int)$row['times_played'],
    ];
}

// Only include racers who played in the selected period, sorted by completion count desc
$allRacersStmt = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC");
$allRacers = $allRacersStmt->fetchAll(PDO::FETCH_ASSOC);

$activeRacers = array_filter($allRacers, fn($r) => isset($grid[$r['name']]));
// Sort by number of unique cups completed (desc)
usort($activeRacers, function($a, $b) use ($grid) {
    return count($grid[$b['name']] ?? []) <=> count($grid[$a['name']] ?? []);
});

// Per-racer summary stats
$racerStats = [];
foreach ($activeRacers as $r) {
    $name    = $r['name'];
    $cups    = $grid[$name] ?? [];
    $perfect = count(array_filter($cups, fn($c) => $c['best'] === 60));
    $total   = count($cups);
    $racerStats[$name] = ['done' => $total, 'perfect' => $perfect];
}

// Colour class helper
function scoreBracket(int $score): string {
    if ($score === 60) return 'mc-perfect';
    if ($score >= 55)  return 'mc-great';
    if ($score >= 45)  return 'mc-good';
    if ($score >= 30)  return 'mc-mid';
    return 'mc-low';
}

$pageTitle = "Cup Mastery Grid";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">

    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Cup Mastery Grid</span>
    </nav>

    <div class="page-header-row" style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
        <div>
            <h1 class="section-title" style="margin:0;">CUP MASTERY GRID</h1>
            <p style="color:#888;margin:4px 0 0;">Best score per racer per cup. Score colour = performance bracket.</p>
        </div>

        <!-- Season Filter -->
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:0.8rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:1px;">Season</label>
            <select name="season" onchange="this.form.submit()" style="padding:6px 12px;border-radius:6px;border:1px solid var(--gray-300);background:var(--gray-50);color:var(--gray-900);font-weight:700;">
                <?php foreach ($availableSeasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $selectedSeason ? 'selected' : '' ?>>
                        <?= strtoupper($s) ?>
                    </option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllTime ? 'selected' : '' ?>>All Time</option>
            </select>
        </form>
    </div>

    <!-- Legend -->
    <div class="mastery-legend">
        <span class="ml-item mc-unplayed">— Not played</span>
        <span class="ml-item mc-low">1–29</span>
        <span class="ml-item mc-mid">30–44</span>
        <span class="ml-item mc-good">45–54</span>
        <span class="ml-item mc-great">55–59</span>
        <span class="ml-item mc-perfect">60 ✦</span>
    </div>

    <?php if (empty($activeRacers)): ?>
        <div style="text-align:center;padding:60px;color:var(--gray-600);">
            No race data for <?= $isAllTime ? 'all time' : strtoupper($selectedSeason) ?>.
        </div>
    <?php else: ?>

    <!-- Grid -->
    <div class="mastery-scroll-wrapper">
        <table class="mastery-table">
            <thead>
                <tr>
                    <th class="mc-cup-col">Cup</th>
                    <?php foreach ($activeRacers as $r): ?>
                        <th class="mc-racer-col">
                            <div class="mc-racer-header">
                                <span class="mc-racer-name"><?= htmlspecialchars($r['name']) ?></span>
                                <span class="mc-racer-sub"><?= $racerStats[$r['name']]['done'] ?>/24
                                    <?php if ($racerStats[$r['name']]['perfect'] > 0): ?>
                                        · <?= $racerStats[$r['name']]['perfect'] ?>✦
                                    <?php endif; ?>
                                </span>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cupGroups as $groupName => $cups): ?>
                    <!-- Group header row -->
                    <tr class="mc-group-row">
                        <td colspan="<?= count($activeRacers) + 1 ?>"><?= htmlspecialchars($groupName) ?></td>
                    </tr>
                    <?php foreach ($cups as $cup): ?>
                        <tr class="mc-row">
                            <td class="mc-cup-label"><?= htmlspecialchars($cup) ?></td>
                            <?php foreach ($activeRacers as $r): ?>
                                <?php
                                $cell = $grid[$r['name']][$cup] ?? null;
                                if ($cell):
                                    $bracket = scoreBracket($cell['best']);
                                    $tooltip = htmlspecialchars($r['name']) . ': ' . $cell['best'] . 'pts';
                                    if ($cell['times'] > 1) $tooltip .= ' (×' . $cell['times'] . ')';
                                    if ($cell['best'] === 60) $tooltip .= ' ✦ Perfect!';
                                ?>
                                    <td class="mc-cell <?= $bracket ?>" data-tooltip="<?= $tooltip ?>">
                                        <?= $cell['best'] ?>
                                        <?php if ($cell['best'] === 60): ?><span class="mc-star">✦</span><?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td class="mc-cell mc-unplayed" data-tooltip="<?= htmlspecialchars($r['name']) ?>: Not played">—</td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Completion summary footer -->
    <div class="mastery-summary">
        <?php
        // Cup coverage: how many racers have played each cup
        $cupCoverage = [];
        foreach ($allCupNames as $cup) {
            $count = 0;
            foreach ($activeRacers as $r) {
                if (isset($grid[$r['name']][$cup])) $count++;
            }
            $cupCoverage[$cup] = $count;
        }
        $unplayed = array_filter($cupCoverage, fn($c) => $c === 0);
        $total    = count($activeRacers);
        $nFull    = count(array_filter($cupCoverage, fn($c) => $c === $total));
        ?>
        <div class="ms-stat">
            <div class="ms-num"><?= $nFull ?></div>
            <div class="ms-label">Cups played by everyone</div>
        </div>
        <div class="ms-stat">
            <div class="ms-num"><?= count($unplayed) ?></div>
            <div class="ms-label">Cups nobody has touched</div>
        </div>
        <div class="ms-stat">
            <?php $perfects = 0; foreach ($racerStats as $rs) $perfects += $rs['perfect']; ?>
            <div class="ms-num"><?= $perfects ?></div>
            <div class="ms-label">Perfect 60s total</div>
        </div>
    </div>

    <?php endif; ?>

</div>

<style>
/* ── Mastery Grid ──────────────────────────────────────────────────── */
.mastery-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
    align-items: center;
}

.ml-item {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 700;
}

.mastery-scroll-wrapper {
    overflow-x: auto;
    border-radius: 14px;
    border: 2.5px solid var(--ink);
    margin-bottom: 30px;
}

.mastery-table {
    border-collapse: collapse;
    min-width: 100%;
    font-size: 0.82rem;
}

.mastery-table th,
.mastery-table td {
    border: 1px solid var(--gray-200);
    text-align: center;
    white-space: nowrap;
}

/* Sticky cup name column */
.mc-cup-col,
.mc-cup-label {
    position: sticky;
    left: 0;
    z-index: 2;
    background: var(--gray-100);
    text-align: left !important;
    padding: 8px 14px;
    font-weight: 700;
    color: var(--gray-600);
    border-right: 2px solid var(--gray-300) !important;
    min-width: 120px;
}

.mc-cup-col { z-index: 3; background: var(--gray-200); color: var(--gray-600); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; }

/* Racer column headers */
.mc-racer-col {
    background: var(--gray-200);
    padding: 10px 6px 6px;
    min-width: 54px;
    max-width: 80px;
}

.mc-racer-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
}

.mc-racer-name {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    font-size: 0.8rem;
    font-weight: 900;
    color: var(--gray-900);
    letter-spacing: 0.5px;
    max-height: 90px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mc-racer-sub {
    font-size: 0.65rem;
    color: var(--gray-600);
    font-weight: 600;
}

/* Group header rows */
.mc-group-row td {
    background: var(--gray-200);
    color: var(--gray-600);
    font-size: 0.7rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 2px;
    padding: 6px 14px;
    border-top: 2px solid var(--gray-300) !important;
}
.mc-group-row td:first-child {
    position: sticky;
    left: 0;
    z-index: 2;
    background: var(--gray-200);
}

/* Data cells */
.mc-cell {
    padding: 6px 4px;
    font-size: 0.8rem;
    font-weight: 800;
    min-width: 44px;
    cursor: default;
    position: relative;
    transition: filter 0.1s;
}
.mc-cell:hover { filter: brightness(0.96); }
.mc-star { font-size: 0.6rem; vertical-align: super; margin-left: 1px; }

/* Colour brackets */
.mc-unplayed { background: var(--gray-200); color: var(--gray-500); }
.mc-low      { background: #fdecec; color: #b3261e; }
.mc-mid      { background: #fff0e0; color: #b35a00; }
.mc-good     { background: #e6f6ec; color: #157347; }
.mc-great    { background: #e2f4fc; color: #0066aa; }
.mc-perfect  { background: #fff6dc; color: #9a7b00; border: 1px solid #e0c040 !important; }

/* Legend items mirror cell colours */
.ml-item.mc-unplayed { background: var(--gray-200); color: var(--gray-600); }
.ml-item.mc-low      { background: #fdecec; color: #b3261e; }
.ml-item.mc-mid      { background: #fff0e0; color: #b35a00; }
.ml-item.mc-good     { background: #e6f6ec; color: #157347; }
.ml-item.mc-great    { background: #e2f4fc; color: #0066aa; }
.ml-item.mc-perfect  { background: #fff6dc; color: #9a7b00; border: 1px solid #e0c040; }

/* Summary footer */
.mastery-summary {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 20px;
}

.ms-stat {
    background: var(--gray-50);
    border: 2.5px solid var(--ink);
    border-radius: 14px;
    box-shadow: 4px 4px 0 var(--ink);
    padding: 14px 24px;
    text-align: center;
    min-width: 140px;
}

.ms-num {
    font-size: 2rem;
    font-weight: 900;
    color: var(--nintendo-red);
}

.ms-label {
    font-size: 0.75rem;
    color: var(--gray-600);
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
