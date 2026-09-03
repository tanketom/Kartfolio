<?php
/**
 * Hall of Fame - Champion Gallery
 * Path: /cdnmk/public_html/season_archives.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$pageTitle = "Hall of Fame - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// Fetch archived seasons
$stmt = $pdo->query("SELECT * FROM season_meta WHERE status = 'archived' ORDER BY closed_at DESC");
$archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch completed tournaments
$tournamentsStmt = $pdo->query("
    SELECT t.id, t.name, t.format, t.end_date,
           r.name as winner_name, r.id as winner_id,
           t.season_id,
           COUNT(DISTINCT tp.racer_id) as participant_count
    FROM tournaments t
    JOIN racers r ON t.winner_id = r.id
    LEFT JOIN tournament_participants tp ON t.id = tp.tournament_id
    WHERE t.status = 'completed'
    GROUP BY t.id
    ORDER BY t.end_date DESC
");
$tournaments = $tournamentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container stats-container">
    <header class="archives-header">
        <h1 class="archives-title">The Hall of Fame</h1>
        <p class="archives-subtitle">CDN DRIFTSMIDLER LEGACY ARCHIVE</p>
    </header>

    <?php if (empty($archives) && empty($tournaments)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">👑</div>
            <h2 class="empty-state-title">The Hall Awaits Its Champions</h2>
            <p class="empty-state-message">No seasons or tournaments have been completed yet. Legendary racers will be immortalized here once a champion is crowned.</p>
            <?php if (isset($_SESSION['is_admin'])): ?>
                <a href="/admin/seasons" class="btn btn-primary">Manage Seasons</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="archives-grid">
            <!-- Season Champions -->
            <?php foreach ($archives as $s): ?>
            <div class="racer-card archive-season-card">

                <div class="crown-anim">👑</div>

                <div class="archive-card-top">
                    <img src="/assets/img/<?= htmlspecialchars($s['champion_char'] ?: 'Mii') ?>.png"
                         class="archive-champion-img"
                         onerror="this.src='/assets/img/Mii.png'">

                    <h2 class="archive-champion-name">
                        <?= htmlspecialchars($s['champion_name'] ?: 'The Ghost') ?>
                    </h2>
                    <span class="archive-champion-label">
                        Season Champion
                    </span>
                </div>

                <div class="archive-card-bottom">
                    <h3 class="archive-season-id">
                        SEASON <?= strtoupper($s['season_id']) ?>
                    </h3>
                    <?php
                    $scoringInfo = getScoringSystemInfo($pdo, $s['season_id']);
                    ?>
                    <div class="archive-scoring-badge">
                        <span class="archive-scoring-icon"><?= $scoringInfo['icon'] ?></span>
                        <span class="archive-scoring-name">
                            <?= htmlspecialchars($scoringInfo['name']) ?>
                        </span>
                    </div>
                    <?php if (!empty($s['season_name'])): ?>
                    <p class="archive-season-name">
                        <?= htmlspecialchars($s['season_name']) ?>
                    </p>
                    <?php endif; ?>
                    <p class="archive-closed-date">
                        Archived <?= !empty($s['closed_at']) ? date('M Y', strtotime($s['closed_at'])) : '—' ?>
                    </p>

                    <a href="/view-season-report?season=<?= $s['season_id'] ?>" class="btn btn-primary archive-report-btn">
                        View Historical Report
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Tournament Champions -->
            <?php
            $formatLabels = [
                'single_elim' => 'Single Elimination',
                'double_elim' => 'Double Elimination',
                'gauntlet' => 'Gauntlet',
                'team_relay' => 'Team Relay',
                'survivor' => 'Survivor',
                'team_scramble' => 'Team Scramble',
                'world_cup' => 'World Cup'
            ];
            foreach ($tournaments as $t):
                $formatLabel = $formatLabels[$t['format']] ?? $t['format'];
            ?>
            <div class="racer-card archive-tournament-card">

                <div class="crown-anim">🏅</div>

                <div class="archive-card-top">
                    <div class="archive-tournament-trophy">🏆</div>

                    <h2 class="archive-champion-name">
                        <?= htmlspecialchars($t['winner_name']) ?>
                    </h2>
                    <span class="archive-tournament-champion-label">
                        Tournament Champion
                    </span>
                </div>

                <div class="archive-card-bottom">
                    <h3 class="archive-tournament-name">
                        <?= htmlspecialchars($t['name']) ?>
                    </h3>
                    <p class="archive-tournament-format">
                        <?= $formatLabel ?>
                    </p>
                    <p class="archive-tournament-date">
                        <?= date('M j, Y', strtotime($t['end_date'])) ?>
                    </p>

                    <a href="/view-tournament-report?id=<?= $t['id'] ?>" class="btn btn-primary archive-tournament-btn">
                        View Tournament Report
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>


<?php include __DIR__ . '/../private/templates/footer.php'; ?>