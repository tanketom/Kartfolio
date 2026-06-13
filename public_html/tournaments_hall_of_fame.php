<?php
/**
 * Tournament Hall of Fame
 * Path: /cdnmk/public_html/tournaments_hall_of_fame.php
 */
require_once __DIR__ . '/../private/includes/db.php';

$pageTitle = "Tournament Hall of Fame - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// Fetch all completed tournaments
$stmt = $pdo->query("
    SELECT t.id, t.name, t.format, t.start_date, t.end_date,
           r.name as winner_name, r.id as winner_id,
           COUNT(DISTINCT tp.racer_id) as participant_count,
           t.season_id
    FROM tournaments t
    JOIN racers r ON t.winner_id = r.id
    LEFT JOIN tournament_participants tp ON t.id = tp.tournament_id
    WHERE t.status = 'completed'
    GROUP BY t.id
    ORDER BY t.end_date DESC, t.id DESC
");
$tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by year
$tournamentsByYear = [];
foreach ($tournaments as $tournament) {
    $year = date('Y', strtotime($tournament['end_date']));
    $tournamentsByYear[$year][] = $tournament;
}
krsort($tournamentsByYear); // Sort years descending
?>

<div class="stats-container">
    <div class="hof-page-header">
        <h1 class="hof-page-title">Tournament Hall of Fame</h1>
        <p class="hof-page-tagline">CELEBRATING ALL TOURNAMENT CHAMPIONS</p>
    </div>

    <?php if (empty($tournaments)): ?>
        <div class="racer-card hof-empty-card">
            <div class="hof-empty-icon">🏅</div>
            <h2 class="hof-empty-heading">No Tournaments Completed Yet</h2>
            <p class="hof-empty-sub">Complete your first tournament to see it here!</p>
        </div>
    <?php else: ?>
        <?php foreach ($tournamentsByYear as $year => $yearTournaments): ?>
            <div class="racer-card hof-year-card">
                <h2 class="hof-year-heading">
                    <?= $year ?> Tournaments
                </h2>

                <div class="hof-cards-grid">
                    <?php foreach ($yearTournaments as $tournament):
                        $formatLabels = [
                            'single_elim' => 'Single Elimination',
                            'double_elim' => 'Double Elimination',
                            'gauntlet' => 'Gauntlet',
                            'team_relay' => 'Team Relay',
                            'survivor' => 'Survivor',
                            'team_scramble' => 'Team Scramble',
                            'world_cup' => 'World Cup'
                        ];
                        $formatLabel = $formatLabels[$tournament['format']] ?? $tournament['format'];
                        $formatEmoji = [
                            'single_elim' => '🎯',
                            'double_elim' => '⚔️',
                            'gauntlet' => '👑',
                            'team_relay' => '🤝',
                            'survivor' => '💀',
                            'team_scramble' => '🤝',
                            'world_cup' => '🌍'
                        ];
                        $emoji = $formatEmoji[$tournament['format']] ?? '🏆';
                    ?>
                    <div class="hof-tournament-card"
                         onmouseover="this.style.borderColor='var(--nintendo-red)'; this.style.boxShadow='0 4px 12px rgba(230,0,18,0.1)';"
                         onmouseout="this.style.borderColor='#eee'; this.style.boxShadow='none';"
                         onclick="window.location.href='/view-tournament-report?id=<?= $tournament['id'] ?>'">

                        <div class="hof-card-top">
                            <div class="hof-card-info">
                                <div class="hof-format-label">
                                    <?= $emoji ?> <?= $formatLabel ?>
                                </div>
                                <h3 class="hof-card-title">
                                    <?= htmlspecialchars($tournament['name']) ?>
                                </h3>
                                <div class="hof-card-date">
                                    <?= date('F j, Y', strtotime($tournament['end_date'])) ?>
                                    <?php if ($tournament['season_id']): ?>
                                        • <span class="hof-season-link">Season <?= htmlspecialchars($tournament['season_id']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="hof-champion-col">
                                <div class="hof-champion-label">
                                    Champion
                                </div>
                                <div class="hof-champion-name">
                                    🏅 <?= htmlspecialchars($tournament['winner_name']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="hof-card-footer">
                            <div>
                                <strong class="hof-participant-count"><?= $tournament['participant_count'] ?></strong> participants
                            </div>
                            <div class="hof-view-report-link">
                                View Report →
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
