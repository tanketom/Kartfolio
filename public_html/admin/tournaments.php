<?php
/**
 * Tournament Management Hub
 * Path: /cdnmk/public_html/admin/tournaments.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_tournament_host($pdo);   // admins always; players when tournament mode is on

// Initialize tournament tables if they don't exist
$pdo->exec(file_get_contents(__DIR__ . '/../../private/data/tournament_schema.sql'));

$message = "";

// Deleting a tournament is destructive — admins only, even in tournament mode.
if (isset($_GET['delete_id'])) {
    if (!is_admin()) { header('Location: /login.php'); exit; }
    $stmt = $pdo->prepare("DELETE FROM tournaments WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    $message = "Tournament deleted.";
}

// Fetch all tournaments
$stmt = $pdo->query("
    SELECT t.*, r.name as winner_name,
           COUNT(DISTINCT tp.racer_id) as participant_count
    FROM tournaments t
    LEFT JOIN racers r ON t.winner_id = r.id
    LEFT JOIN tournament_participants tp ON t.id = tp.tournament_id
    GROUP BY t.id
    ORDER BY t.created_at DESC
");
$tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Tournament Manager - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin">Admin</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Tournaments</span>
    </nav>

    <header class="section-header tournaments-header">
        <h1>🏆 Tournament Manager</h1>
        <?php if($message): ?>
            <div class="badge tournaments-message-badge">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
    </header>

    <div class="tournaments-create-row">
        <a href="/admin/tournament-create" class="btn-primary tournaments-create-btn">
            ➕ Create New Tournament
        </a>
    </div>

    <?php if (empty($tournaments)): ?>
        <div class="racer-card tournaments-empty-card">
            <div class="tournaments-empty-icon">🏆</div>
            <h2 class="tournaments-empty-title">No Tournaments Yet</h2>
            <p class="tournaments-empty-text">Create your first tournament to get started!</p>
        </div>
    <?php else: ?>
        <div class="racer-card tournaments-table-card">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Tournament</th>
                        <th class="tournaments-th-center">Format</th>
                        <th class="tournaments-th-center">Status</th>
                        <th class="tournaments-th-center">Participants</th>
                        <th class="tournaments-th-center">Date</th>
                        <th class="tournaments-th-center">Winner</th>
                        <th class="tournaments-th-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tournaments as $t):
                        $statusColors = [
                            'setup' => '#ffc107',
                            'in_progress' => '#009BE0',
                            'completed' => '#2EBD59'
                        ];
                        $statusColor = $statusColors[$t['status']] ?? '#888';

                        $formatLabels = [
                            'single_elim' => 'Single Elimination',
                            'double_elim' => 'Double Elimination',
                            'gauntlet' => 'Gauntlet',
                            'team_relay' => 'Team Relay',
                            'survivor' => 'Survivor',
                            'team_scramble' => 'Team Scramble',
                            'world_cup' => 'World Cup'
                        ];
                        $formatLabel = $formatLabels[$t['format']] ?? $t['format'];
                    ?>
                    <tr>
                        <td>
                            <strong class="tournaments-name-strong"><?= htmlspecialchars($t['name']) ?></strong>
                            <?php if ($t['season_id']): ?>
                                <div class="tournaments-season-label">
                                    Season <?= htmlspecialchars($t['season_id']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="tournaments-td-center-sm">
                            <?= $formatLabel ?>
                        </td>
                        <td class="tournaments-td-center">
                            <span class="tournaments-status-badge" style="background: <?= $statusColor ?>;">
                                <?= htmlspecialchars($t['status']) ?>
                            </span>
                        </td>
                        <td class="tournaments-td-participants">
                            <?= $t['participant_count'] ?>
                        </td>
                        <td class="tournaments-td-date">
                            <?= $t['start_date'] ? date('M j, Y', strtotime($t['start_date'])) : '—' ?>
                        </td>
                        <td class="tournaments-td-winner">
                            <?= $t['winner_name'] ? htmlspecialchars($t['winner_name']) : '—' ?>
                        </td>
                        <td class="tournaments-td-actions">
                            <div class="tournaments-actions-group">
                                <?php if ($t['status'] === 'setup'): ?>
                                    <a href="/admin/tournament-bracket/<?= $t['id'] ?>" class="btn-primary tournaments-btn-sm">
                                        Start
                                    </a>
                                <?php elseif ($t['status'] === 'in_progress'): ?>
                                    <a href="/admin/tournament-bracket?id=<?= $t['id'] ?>" class="btn-primary tournaments-btn-sm">
                                        Manage
                                    </a>
                                <?php else: ?>
                                    <a href="/admin/tournament-bracket?id=<?= $t['id'] ?>" class="btn-primary tournaments-btn-sm tournaments-btn-view">
                                        View
                                    </a>
                                <?php endif; ?>
                                <?php if (is_admin()): ?>
                                <a href="?delete_id=<?= $t['id'] ?>"
                                   class="btn-danger tournaments-btn-sm"
                                   onclick="event.preventDefault(); if(confirm('Delete this tournament? This will remove all bracket data.')) window.location.href = this.href;">
                                    Delete
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
