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
        COUNT(res.id) as total_gps,
        SUM(res.gp_points) as lifetime_points,
        AVG(res.gp_points) as lifetime_ppg,
        SUM(res.is_lol) as lifetime_lols
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    GROUP BY r.id
    ORDER BY lifetime_points DESC
");
$careerStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                    <tr>
                        <td><strong><a href="/racer/<?= $row['id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($row['name']) ?></a></strong></td>
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
                    'team_relay' => 'Team Relay'
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
        <h2 class="stats-section-heading" style="color: #111;">Master Career Ledger</h2>
        <div class="alltime-table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Racer</th>
                        <th>GPs Run</th>
                        <th>Career Points</th>
                        <th>Career LOLs</th>
                        <th>Points per GP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($careerStats as $row): ?>
                    <tr>
                        <td class="alltime-name-cell"><a href="/racer/<?= $row['id'] ?>" class="racer-link" onmouseover="this.style.color='var(--nintendo-red)'" onmouseout="this.style.color='inherit'"><?= htmlspecialchars($row['name']) ?></a></td>
                        <td><?= $row['total_gps'] ?></td>
                        <td><?= number_format($row['lifetime_points']) ?></td>
                        <td class="alltime-lols-val"><?= $row['lifetime_lols'] ?></td>
                        <td><?= number_format($row['lifetime_ppg'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.js" integrity="sha384-hfkuqrKeWFmnTMWN31VWyoe8xgdTADD11kgxmdpx2uyE6j5Az5uZq6u6AKYYmAOw" crossorigin="anonymous"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('careerChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Total Career Points',
                data: <?= json_encode($chartData) ?>,
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