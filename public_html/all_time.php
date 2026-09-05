<?php
/**
 * Telemetry Hub - All-Time Career Statistics
 * Path: /cdnmk/public_html/all_time.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$pageTitle = "Telemetry Hub - Career Stats";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// 1. Fetch Career Aggregates
// We calculate: Total Points, Total GPs, Avg PPG, and Total LOLs across all time
$stmt = $pdo->query("
    SELECT
        r.id,
        r.name,
        r.is_retired,
        COUNT(res.id) as total_gps,
        SUM(res.gp_points) as lifetime_points,
        AVG(res.gp_points) as lifetime_ppg,
        SUM(res.is_lol) as lifetime_lols,
        SUM(res.rank = 1) as wins,
        SUM(res.rank <= 3) as podiums,
        SUM(res.gp_points = 60) as perfects,
        MAX(res.gp_points) as best,
        MIN(res.race_date) as first_date,
        MAX(res.race_date) as last_date
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%'          -- season GPs only; tournament heats (t…) stay out of careers
    GROUP BY r.id
    ORDER BY lifetime_points DESC
");
$careerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Finishing record: win and podium rates, ten GPs minimum so a good night
// doesn't top the table.
$finishing = array_values(array_filter($careerStats, fn($r) => (int)$r['total_gps'] >= 10));
usort($finishing, fn($a, $b) => ($b['wins'] / $b['total_gps'] <=> $a['wins'] / $a['total_gps']) ?: ($b['podiums'] / $b['total_gps'] <=> $a['podiums'] / $a['total_gps']) ?: strcmp($a['name'], $b['name']));

// Placement ledger: where everyone finished in every archived season, from
// the frozen snapshot (archivedSeasonPlacements), so it never shifts.
$placements = archivedSeasonPlacements($pdo);
$ledgerSeasons = $pdo->query("SELECT season_id, champion_name FROM season_meta WHERE status = 'archived' ORDER BY season_id ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$ledgerRows = [];
foreach ($careerStats as $r) {
    $cells = []; $sumPlace = 0; $n = 0; $titles = 0;
    foreach ($placements[(int)$r['id']] ?? [] as [$sid, $place, $field]) { $cells[$sid] = [$place, $field]; $sumPlace += $place; $n++; if ($place === 1) $titles++; }
    if ($n) $ledgerRows[] = ['id' => $r['id'], 'name' => $r['name'], 'retired' => !empty($r['is_retired']), 'cells' => $cells, 'seasons' => $n, 'avg' => $sumPlace / $n, 'titles' => $titles];
}
usort($ledgerRows, fn($a, $b) => ($b['titles'] <=> $a['titles']) ?: ($a['avg'] <=> $b['avg']) ?: ($b['seasons'] <=> $a['seasons']));

// Milestone club: career GPs and points, who has crossed what, who is next.
$gpMarks  = [25, 50, 100, 150, 200, 300, 400, 500];
$ptMarks  = [1000, 2500, 5000, 7500, 10000, 15000, 20000, 25000];
$nextMark = fn(array $marks, int $v) => array_values(array_filter($marks, fn($m) => $m > $v))[0] ?? null;
$milestones = [];
foreach ($careerStats as $r) {
    $g = (int)$r['total_gps']; $p = (int)$r['lifetime_points'];
    $milestones[] = ['id' => $r['id'], 'name' => $r['name'], 'gps' => $g, 'pts' => $p, 'retired' => !empty($r['is_retired']),
        'gp_done' => array_values(array_filter($gpMarks, fn($m) => $m <= $g)), 'gp_next' => $nextMark($gpMarks, $g),
        'pt_done' => array_values(array_filter($ptMarks, fn($m) => $m <= $p)), 'pt_next' => $nextMark($ptMarks, $p),
        'ppg' => (float)$r['lifetime_ppg']];
}
// Nearest to a milestone first: fewest GPs (or nights' worth of points) to go.
usort($milestones, function ($a, $b) {
    $ga = $a['gp_next'] ? $a['gp_next'] - $a['gps'] : PHP_INT_MAX; $gb = $b['gp_next'] ? $b['gp_next'] - $b['gps'] : PHP_INT_MAX;
    $pa = $a['pt_next'] && $a['ppg'] > 0 ? ($a['pt_next'] - $a['pts']) / $a['ppg'] : PHP_INT_MAX; $pb = $b['pt_next'] && $b['ppg'] > 0 ? ($b['pt_next'] - $b['pts']) / $b['ppg'] : PHP_INT_MAX;
    return min($ga, $pa) <=> min($gb, $pb) ?: ($b['gps'] <=> $a['gps']);
});

// 2. Fetch "Season Wins" (Count how many times they were Champion)
$champsStmt = $pdo->query("SELECT champion_name, COUNT(*) as titles FROM season_meta WHERE status = 'archived' GROUP BY champion_name");
$titles = $champsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 3. Fetch Tournament Wins
$tournamentWinsStmt = $pdo->query("
    SELECT r.name, COUNT(*) as tournament_wins
    FROM tournaments t
    JOIN racers r ON t.winner_id = r.id
    WHERE t.status = 'completed'
    GROUP BY r.name
");
$tournamentWins = $tournamentWinsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 4. Prepare Chart Data (Top 8 Career Points)
$chartLabels = [];
$chartData = [];
foreach (array_slice($careerStats, 0, 8) as $row) {
    $chartLabels[] = $row['name'];
    $chartData[] = $row['lifetime_points'];
}
?>

<div class="stats-container">
    <div class="alltime-page-header">
        <h1 class="alltime-page-title">Telemetry Hub</h1>
        <p class="alltime-page-tagline">LIFETIME CAREER STATISTICS & PERFORMANCE RECORDS</p>
        <p class="alltime-page-note">
            📊 All-time stats aggregate raw GP points and performance metrics across seasons. For season-specific GPScores calculated using each season's scoring system, visit individual racer profiles.
        </p>
    </div>

    <div class="racer-card stats-chart-card">
        <h3 class="alltime-chart-label">Lifetime Points Accumulation</h3>
        <div class="alltime-chart-wrap">
            <canvas id="careerChart"></canvas>
        </div>
    </div>

    <div class="alltime-sections-grid">
        
        <section class="racer-card stats-section-card">
            <h2 class="stats-section-heading">The Trophy Cabinet</h2>
            <table class="clean-table">
                <thead>
                    <tr>
                        <th>Racer</th>
                        <th class="txt-center">Championships</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Fetch detailed season and tournament win information
                    $seasonDetailsStmt = $pdo->query("
                        SELECT champion_name, season_id
                        FROM season_meta
                        WHERE status = 'archived'
                        ORDER BY season_id ASC
                    ");
                    $seasonDetails = [];
                    foreach ($seasonDetailsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $seasonDetails[$row['champion_name']][] = 'Season ' . strtoupper($row['season_id']);
                    }

                    $tournamentDetailsStmt = $pdo->query("
                        SELECT r.name, t.name as tournament_name
                        FROM tournaments t
                        JOIN racers r ON t.winner_id = r.id
                        WHERE t.status = 'completed'
                        ORDER BY t.end_date ASC
                    ");
                    $tournamentDetails = [];
                    foreach ($tournamentDetailsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $tournamentDetails[$row['name']][] = htmlspecialchars($row['tournament_name']);
                    }

                    // Merge season titles and tournament wins
                    $allChampions = [];
                    foreach ($titles as $name => $count) {
                        $allChampions[$name]['seasons'] = $count;
                        $allChampions[$name]['season_list'] = $seasonDetails[$name] ?? [];
                    }
                    foreach ($tournamentWins as $name => $count) {
                        $allChampions[$name]['tournaments'] = $count;
                        $allChampions[$name]['tournament_list'] = $tournamentDetails[$name] ?? [];
                    }

                    // Sort by total trophies
                    uasort($allChampions, function($a, $b) {
                        $aTotal = ($a['seasons'] ?? 0) + ($a['tournaments'] ?? 0);
                        $bTotal = ($b['seasons'] ?? 0) + ($b['tournaments'] ?? 0);
                        return $bTotal <=> $aTotal;
                    });

                    foreach ($allChampions as $name => $trophies):
                        $seasonCount = $trophies['seasons'] ?? 0;
                        $tournamentCount = $trophies['tournaments'] ?? 0;
                        $seasonList = $trophies['season_list'] ?? [];
                        $tournamentList = $trophies['tournament_list'] ?? [];

                        // Build tooltip text
                        $tooltipParts = [];
                        if (!empty($seasonList)) {
                            $tooltipParts[] = "🏆 " . implode(", ", $seasonList);
                        }
                        if (!empty($tournamentList)) {
                            $tooltipParts[] = "🏅 " . implode(", ", $tournamentList);
                        }
                        $tooltipText = implode("\n", $tooltipParts);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($name) ?></strong></td>
                        <td class="txt-center alltime-trophy-cell">
                            <span title="<?= htmlspecialchars($tooltipText) ?>" class="alltime-trophy-span">
                                <?php
                                if ($seasonCount > 0) {
                                    for($i=0; $i<$seasonCount; $i++) echo "🏆";
                                }
                                if ($tournamentCount > 0) {
                                    for($i=0; $i<$tournamentCount; $i++) echo "🏅";
                                }
                                if ($seasonCount == 0 && $tournamentCount == 0) {
                                    echo '<span class="alltime-no-trophy">—</span>';
                                }
                                ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach;
                    if(empty($allChampions)) echo "<tr><td colspan='2' class='txt-center alltime-empty-row'>No titles awarded yet.</td></tr>";
                    ?>
                </tbody>
            </table>
            <div class="alltime-trophy-legend">
                🏆 = Season Championship • 🏅 = Tournament Victory • Hover over trophies for details
            </div>
        </section>

        <section class="racer-card stats-section-card">
            <h2 class="stats-section-heading">Efficiency Index (Avg PPG)</h2>
            <table class="clean-table">
                <thead>
                    <tr><th>Racer</th><th>GPs</th><th class="txt-right">Lifetime PPG</th></tr>
                </thead>
                <tbody>
                    <?php 
                    $eff = $careerStats;
                    usort($eff, fn($a, $b) => $b['lifetime_ppg'] <=> $a['lifetime_ppg']);
                    foreach (array_slice($eff, 0, 10) as $row): ?>
                    <tr class="<?= !empty($row['is_retired']) ? 'racer-retired' : '' ?>">
                        <td><strong><a href="/racer/<?= $row['id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($row['name']) ?></a></strong><?php if (!empty($row['is_retired'])): ?> <span class="retired-badge" title="Retired racer">RETIRED</span><?php endif; ?></td>
                        <td><?= $row['total_gps'] ?></td>
                        <td class="txt-right alltime-ppg-val"><?= number_format($row['lifetime_ppg'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

    </div>

    <!-- Tournament Statistics Section -->
    <?php if (!empty($tournamentWins)): ?>
    <div class="racer-card stats-section-card alltime-recent-tournaments">
        <h2 class="stats-section-heading">🏅 Recent Tournaments</h2>
        <p class="alltime-section-sub">
            Latest tournament champions. <a href="/season-archives" class="alltime-link">View Hall of Fame →</a>
        </p>

        <?php
        // Fetch recent completed tournaments
        $recentTournamentsStmt = $pdo->query("
            SELECT t.id, t.name, t.format, t.end_date, r.name as winner_name,
                   COUNT(DISTINCT tp.racer_id) as participant_count
            FROM tournaments t
            JOIN racers r ON t.winner_id = r.id
            LEFT JOIN tournament_participants tp ON t.id = tp.tournament_id
            WHERE t.status = 'completed'
            GROUP BY t.id
            ORDER BY t.end_date DESC
            LIMIT 5
        ");
        $recentTournaments = $recentTournamentsStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="alltime-tournament-list">
            <?php foreach ($recentTournaments as $tournament):
                $formatLabels = [
                    'single_elim' => 'Single Elim',
                    'double_elim' => 'Double Elim',
                    'gauntlet' => 'Gauntlet',
                    'team_relay' => 'Team Relay',
                    'survivor' => 'Survivor',
                    'team_scramble' => 'Team Scramble',
                    'world_cup' => 'World Cup'
                ];
                $formatLabel = $formatLabels[$tournament['format']] ?? $tournament['format'];
            ?>
            <div class="alltime-tournament-row">
                <div>
                    <div class="alltime-tournament-name">
                        🏅 <?= htmlspecialchars($tournament['name']) ?>
                    </div>
                    <div class="alltime-tournament-meta">
                        <?= $formatLabel ?> • <?= $tournament['participant_count'] ?> participants • <?= date('M j, Y', strtotime($tournament['end_date'])) ?>
                    </div>
                </div>
                <div class="alltime-tournament-winner">
                    <div class="alltime-champion-label">Champion</div>
                    <div class="alltime-champion-name">
                        <?= htmlspecialchars($tournament['winner_name']) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="racer-card stats-section-card alltime-recent-tournaments">
        <h2 class="stats-section-heading">🥇 Finishing Record</h2>
        <p class="alltime-section-sub">Wins and podiums as a share of GPs raced. Ten GPs minimum.</p>
        <div class="alltime-table-scroll">
            <table class="clean-table alltime-finishing">
                <thead><tr><th>Racer</th><th class="txt-right">GPs</th><th class="txt-right">Wins</th><th class="txt-right">Win %</th><th class="txt-right">Podiums</th><th class="txt-right">Podium %</th><th class="txt-right">60s</th><th class="txt-right">Best</th></tr></thead>
                <tbody>
                <?php foreach ($finishing as $row): ?>
                    <tr class="<?= !empty($row['is_retired']) ? 'racer-retired' : '' ?>">
                        <td><strong><a href="/racer/<?= $row['id'] ?>" class="racer-link"><?= htmlspecialchars($row['name']) ?></a></strong></td>
                        <td class="txt-right"><?= $row['total_gps'] ?></td>
                        <td class="txt-right"><?= $row['wins'] ?></td>
                        <td class="txt-right"><span class="alltime-bar" style="--w:<?= round(100 * $row['wins'] / $row['total_gps']) ?>%"><?= round(100 * $row['wins'] / $row['total_gps']) ?>%</span></td>
                        <td class="txt-right"><?= $row['podiums'] ?></td>
                        <td class="txt-right"><span class="alltime-bar alltime-bar--podium" style="--w:<?= round(100 * $row['podiums'] / $row['total_gps']) ?>%"><?= round(100 * $row['podiums'] / $row['total_gps']) ?>%</span></td>
                        <td class="txt-right"><?= $row['perfects'] ?></td>
                        <td class="txt-right"><?= $row['best'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($ledgerRows): ?>
    <div class="racer-card stats-section-card alltime-recent-tournaments">
        <h2 class="stats-section-heading">📒 Placement Ledger</h2>
        <p class="alltime-section-sub">Where everyone finished, season by season. Frozen when each season closed, so it never shifts.</p>
        <div class="alltime-table-scroll">
            <table class="clean-table alltime-ledger">
                <thead><tr><th>Racer</th><?php foreach ($ledgerSeasons as $sid => $champ): ?><th class="txt-center" title="Champion: <?= htmlspecialchars((string)$champ) ?>"><?= strtoupper($sid) ?></th><?php endforeach; ?><th class="txt-right">Seasons</th><th class="txt-right">Avg place</th></tr></thead>
                <tbody>
                <?php foreach ($ledgerRows as $row): ?>
                    <tr class="<?= $row['retired'] ? 'racer-retired' : '' ?>">
                        <td><strong><a href="/racer/<?= $row['id'] ?>" class="racer-link"><?= htmlspecialchars($row['name']) ?></a></strong></td>
                        <?php foreach ($ledgerSeasons as $sid => $champ): $c = $row['cells'][$sid] ?? null; ?>
                            <td class="txt-center"><?php if ($c): ?><span class="alltime-place alltime-place--<?= min(4, $c[0]) ?>" title="<?= $c[0] ?> of <?= $c[1] ?>"><?= $c[0] === 1 ? '🥇' : ($c[0] === 2 ? '🥈' : ($c[0] === 3 ? '🥉' : $c[0])) ?></span><?php else: ?><span class="alltime-place alltime-place--none">·</span><?php endif; ?></td>
                        <?php endforeach; ?>
                        <td class="txt-right"><?= $row['seasons'] ?></td>
                        <td class="txt-right"><?= number_format($row['avg'], 1) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="racer-card stats-section-card alltime-recent-tournaments">
        <h2 class="stats-section-heading">🎯 Milestone Club</h2>
        <p class="alltime-section-sub">Career GP and points marks crossed, and who is closest to the next one. Sorted by nearest milestone.</p>
        <div class="alltime-table-scroll">
            <table class="clean-table alltime-milestones">
                <thead><tr><th>Racer</th><th>GPs</th><th>Next GP mark</th><th>Points</th><th>Next points mark</th></tr></thead>
                <tbody>
                <?php foreach ($milestones as $m): ?>
                    <tr class="<?= $m['retired'] ? 'racer-retired' : '' ?>">
                        <td><strong><a href="/racer/<?= $m['id'] ?>" class="racer-link"><?= htmlspecialchars($m['name']) ?></a></strong></td>
                        <td><span class="alltime-ms-val"><?= $m['gps'] ?></span> <span class="alltime-ms-chips"><?php foreach ($m['gp_done'] as $d): ?><span class="alltime-chip"><?= $d ?></span><?php endforeach; ?></span></td>
                        <td class="alltime-ms-next"><?= $m['gp_next'] ? $m['gp_next'] . ' GPs <small>' . ($m['gp_next'] - $m['gps']) . ' to go</small>' : '<small>past every mark</small>' ?></td>
                        <td><span class="alltime-ms-val"><?= number_format($m['pts']) ?></span> <span class="alltime-ms-chips"><?php foreach ($m['pt_done'] as $d): ?><span class="alltime-chip alltime-chip--pts"><?= $d >= 1000 ? ($d / 1000) . 'k' : $d ?></span><?php endforeach; ?></span></td>
                        <td class="alltime-ms-next"><?= $m['pt_next'] ? number_format($m['pt_next']) . ' <small>' . number_format($m['pt_next'] - $m['pts']) . ' to go' . ($m['ppg'] > 0 ? ', about ' . max(1, (int)ceil(($m['pt_next'] - $m['pts']) / $m['ppg'])) . ' GPs' : '') . '</small>' : '<small>past every mark</small>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="racer-card stats-section-card alltime-recent-tournaments">
        <h2 class="stats-section-heading" style="color: var(--gray-900);">Master Career Ledger</h2>
        <div class="alltime-table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Racer</th>
                        <th>GPs Run</th>
                        <th>Career Points</th>
                        <th>Points per GP</th>
                        <th>Wins</th>
                        <th>Podiums</th>
                        <th>60s</th>
                        <th>Best</th>
                        <th>Career LOLs</th>
                        <th>Active</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($careerStats as $row): ?>
                    <tr>
                        <td class="alltime-name-cell"><a href="/racer/<?= $row['id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($row['name']) ?></a></td>
                        <td><?= $row['total_gps'] ?></td>
                        <td><?= number_format($row['lifetime_points']) ?></td>
                        <td><?= number_format($row['lifetime_ppg'], 2) ?></td>
                        <td><?= $row['wins'] ?></td>
                        <td><?= $row['podiums'] ?></td>
                        <td><?= $row['perfects'] ?></td>
                        <td><?= $row['best'] ?></td>
                        <td class="alltime-lols-val"><?= $row['lifetime_lols'] ?></td>
                        <td class="alltime-active"><?= date('M Y', strtotime($row['first_date'])) ?> – <?= date('M Y', strtotime($row['last_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>Chart.defaults.color = "#6b6453"; Chart.defaults.borderColor = "#e8e0cc";</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('careerChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= jsonForScript($chartLabels) ?>,
            datasets: [{
                label: 'Total Career Points',
                data: <?= jsonForScript($chartData) ?>,
                backgroundColor: '#111',
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#eee' } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>