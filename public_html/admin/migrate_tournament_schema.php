<?php
/**
 * Tournament Schema Migration Script
 * Path: /cdnmk/public_html/admin/migrate_tournament_schema.php
 *
 * IMPORTANT: This script migrates the tournament system from 1v1 to multi-player support
 * Run this ONCE after backing up your database
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

$message = "";
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_migrate'])) {
    verify_csrf();
    try {
        $pdo->beginTransaction();

        // Step 1: Create tournament_match_participants table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tournament_match_participants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                match_id INTEGER NOT NULL,
                racer_id INTEGER NOT NULL,
                placement INTEGER,
                points INTEGER,
                character_used TEXT,
                kart_setup TEXT,
                is_winner BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (match_id) REFERENCES tournament_matches(id) ON DELETE CASCADE,
                FOREIGN KEY (racer_id) REFERENCES racers(id),
                UNIQUE(match_id, racer_id)
            )
        ");

        // Step 2: Migrate existing match data to junction table
        $stmt = $pdo->query("
            SELECT id, player1_id, player2_id, winner_id, status
            FROM tournament_matches
            WHERE player1_id IS NOT NULL OR player2_id IS NOT NULL
        ");
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $migratedMatches = 0;
        foreach ($matches as $match) {
            // Migrate player 1
            if ($match['player1_id']) {
                $isWinner = ($match['status'] === 'completed' && $match['winner_id'] == $match['player1_id']) ? 1 : 0;
                $pdo->prepare("
                    INSERT OR IGNORE INTO tournament_match_participants (match_id, racer_id, is_winner)
                    VALUES (?, ?, ?)
                ")->execute([
                    $match['id'],
                    $match['player1_id'],
                    $isWinner
                ]);
            }

            // Migrate player 2
            if ($match['player2_id']) {
                $isWinner = ($match['status'] === 'completed' && $match['winner_id'] == $match['player2_id']) ? 1 : 0;
                $pdo->prepare("
                    INSERT OR IGNORE INTO tournament_match_participants (match_id, racer_id, is_winner)
                    VALUES (?, ?, ?)
                ")->execute([
                    $match['id'],
                    $match['player2_id'],
                    $isWinner
                ]);
            }

            $migratedMatches++;
        }

        // Step 3: Add new columns to tournament_matches (if they don't exist)
        // SQLite doesn't support checking if column exists easily, so we try-catch
        try {
            $pdo->exec("ALTER TABLE tournament_matches ADD COLUMN gpid TEXT");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }

        try {
            $pdo->exec("ALTER TABLE tournament_matches ADD COLUMN num_participants INTEGER DEFAULT 2");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }

        try {
            $pdo->exec("ALTER TABLE tournament_matches ADD COLUMN num_advance INTEGER DEFAULT 1");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }

        // Step 4: Update num_participants for existing matches
        $pdo->exec("
            UPDATE tournament_matches
            SET num_participants = (
                SELECT COUNT(*)
                FROM tournament_match_participants
                WHERE tournament_match_participants.match_id = tournament_matches.id
            )
            WHERE num_participants IS NULL OR num_participants = 0
        ");

        $pdo->commit();

        $message = "✅ Migration completed successfully!<br>";
        $message .= "• Created tournament_match_participants table<br>";
        $message .= "• Migrated $migratedMatches matches to new structure<br>";
        $message .= "• Added gpid, num_participants, and num_advance columns<br>";
        $message .= "• Old columns (player1_id, player2_id, winner_id) kept for backward compatibility<br><br>";
        $message .= "<strong>You can now use the new tournament system!</strong>";

    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Migration failed: " . $e->getMessage();
    }
}

// Check current migration status
$hasTmpTable = false;
$hasNewColumns = false;

try {
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tournament_match_participants'");
    $hasTmpTable = $stmt->fetch() !== false;

    if ($hasTmpTable) {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tournament_match_participants");
        $tmpCount = $stmt->fetchColumn();
    }

    $stmt = $pdo->query("PRAGMA table_info(tournament_matches)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        if ($col['name'] === 'gpid' || $col['name'] === 'num_participants') {
            $hasNewColumns = true;
            break;
        }
    }
} catch (Exception $e) {
    $errors[] = "Status check failed: " . $e->getMessage();
}

$pageTitle = "Tournament Schema Migration";
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin/tournaments">Tournaments</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Schema Migration</span>
    </nav>

    <?php if ($message): ?>
        <div class="admin-migrate-success-alert">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="admin-migrate-error-alert">
            <strong>⚠️ Errors:</strong><br>
            <?php foreach ($errors as $error): ?>
                • <?= htmlspecialchars($error) ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="racer-card admin-migrate-card">
        <h1 class="admin-migrate-h1">
            🔄 Tournament Schema Migration
        </h1>
        <p class="admin-migrate-intro">
            This migration upgrades the tournament system from 1v1 matches to support 2-4 player matches with full GP data tracking.
        </p>
    </div>

    <!-- Migration Status -->
    <div class="racer-card admin-migrate-card">
        <h2 class="admin-migrate-h2">Migration Status</h2>
        <div class="admin-migrate-status-grid">
            <div class="admin-migrate-status-row" style="background: <?= $hasTmpTable ? '#d4edda' : '#f8f9fa' ?>;">
                <span class="admin-migrate-status-icon"><?= $hasTmpTable ? '✅' : '⏸️' ?></span>
                <div>
                    <strong>tournament_match_participants Table</strong><br>
                    <span class="admin-migrate-status-detail">
                        <?= $hasTmpTable ? "Exists (" . ($tmpCount ?? 0) . " records)" : "Not created yet" ?>
                    </span>
                </div>
            </div>

            <div class="admin-migrate-status-row" style="background: <?= $hasNewColumns ? '#d4edda' : '#f8f9fa' ?>;">
                <span class="admin-migrate-status-icon"><?= $hasNewColumns ? '✅' : '⏸️' ?></span>
                <div>
                    <strong>New Columns Added</strong><br>
                    <span class="admin-migrate-status-detail">
                        <?= $hasNewColumns ? "gpid, num_participants, num_advance" : "Not added yet" ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- What Will Change -->
    <div class="racer-card admin-migrate-card">
        <h2 class="admin-migrate-h2">What This Migration Does</h2>
        <ol class="admin-migrate-ol">
            <li><strong>Creates new table:</strong> tournament_match_participants (stores 2-4 players per match)</li>
            <li><strong>Migrates existing data:</strong> Copies player1_id/player2_id to new junction table</li>
            <li><strong>Adds new columns:</strong> gpid, num_participants, num_advance to tournament_matches</li>
            <li><strong>Preserves old data:</strong> player1_id, player2_id, winner_id columns remain for compatibility</li>
            <li><strong>Enables new features:</strong> Multi-player matches, GP integration, character/kart tracking</li>
        </ol>
    </div>

    <!-- Warning Box -->
    <?php if (!$hasTmpTable || !$hasNewColumns): ?>
    <div class="racer-card admin-migrate-warning-card">
        <h2 class="admin-migrate-warning-title">⚠️ Before You Migrate</h2>
        <ul class="admin-migrate-warning-list">
            <li><strong>Backup your database!</strong> This migration modifies schema and data.</li>
            <li><strong>Test on a copy first</strong> if you have production tournaments running.</li>
            <li><strong>No data loss:</strong> Old columns remain intact, only new structure added.</li>
            <li><strong>Can't undo:</strong> Once migrated, you'll need to restore from backup to revert.</li>
        </ul>
    </div>

    <!-- Migration Button -->
    <div class="racer-card">
        <form method="POST" onsubmit="return confirm('⚠️ Have you backed up your database?\n\nThis migration will modify the tournament schema. Make sure you have a database backup before proceeding.\n\nClick OK to continue with migration.');">
            <?= csrf_field() ?>
            <input type="hidden" name="confirm_migrate" value="1">
            <button type="submit" class="btn btn-primary admin-migrate-run-btn">
                🚀 Run Migration Now
            </button>
        </form>
    </div>
    <?php else: ?>
    <!-- Already Migrated -->
    <div class="racer-card admin-migrate-done-card">
        <div class="admin-migrate-done-icon">✅</div>
        <h2 class="admin-migrate-done-title">Migration Already Complete!</h2>
        <p class="admin-migrate-done-text">Your tournament system is ready to use the new multi-player features.</p>
        <div class="admin-migrate-done-actions">
            <a href="/admin/tournaments" class="btn btn-primary">Go to Tournaments</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.btn-primary {
    background: var(--nintendo-red);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 900;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
    text-decoration: none;
    display: inline-block;
}

.btn-primary:hover {
    background: #c50010;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(230, 0, 18, 0.3);
}
</style>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
