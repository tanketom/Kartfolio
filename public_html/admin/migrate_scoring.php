<?php
/**
 * One-Time Migration: Add Scoring System Columns
 * Run this once after uploading to server, then delete this file
 * Path: /admin/migrate_scoring.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';

require_admin();

$results = [];
$errors = [];

// List of columns to add (in case they don't exist)
$columns = [
    "scoring_system TEXT DEFAULT 'average_attendance'",
    "academic_term TEXT",
    "academic_year INTEGER",
    "start_week INTEGER",
    "end_week INTEGER",
    "start_date DATE",
    "end_date DATE",
    "grace_period_end DATE",
    "finals_week INTEGER",
    "cups_required INTEGER DEFAULT 12",
    "allow_retries BOOLEAN DEFAULT 1",
    "best_n_count INTEGER DEFAULT 15",
    "drop_worst_count INTEGER DEFAULT 2",
    "perfect_multiplier FLOAT DEFAULT 2.0",
    "random_cups_assigned TEXT",
    "season_name TEXT",
    "season_description TEXT"
];

foreach ($columns as $columnDef) {
    $columnName = explode(' ', $columnDef)[0];

    try {
        $pdo->exec("ALTER TABLE season_meta ADD COLUMN $columnDef");
        $results[] = "✅ Added column: $columnName";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            $results[] = "⏭️ Column already exists: $columnName";
        } else {
            $errors[] = "❌ Error adding $columnName: " . $e->getMessage();
        }
    }
}

// Update existing seasons with default values
try {
    $pdo->exec("UPDATE season_meta SET scoring_system = 'average_attendance' WHERE scoring_system IS NULL");
    $pdo->exec("UPDATE season_meta SET season_name = 'Season ' || UPPER(season_id) WHERE season_name IS NULL OR season_name = ''");
    $results[] = "✅ Updated existing seasons with defaults";
} catch (PDOException $e) {
    $errors[] = "❌ Error updating defaults: " . $e->getMessage();
}

// Verify table structure
try {
    $stmt = $pdo->query("PRAGMA table_info(season_meta)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columnCount = count($columns);
    $results[] = "✅ Final table has $columnCount columns";
} catch (PDOException $e) {
    $errors[] = "❌ Error checking table: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - Scoring Systems</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #e60012;
            margin-top: 0;
        }
        .result {
            padding: 10px 15px;
            margin: 8px 0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .summary {
            margin-top: 30px;
            padding: 20px;
            background: #e7f3ff;
            border-radius: 8px;
            border-left: 4px solid #0066cc;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #e60012;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin-top: 20px;
        }
        .btn:hover {
            background: #c00010;
        }
        .danger {
            background: #721c24;
            color: white;
            padding: 15px;
            border-radius: 6px;
            margin-top: 20px;
        }
        .admin-migrate-errors-title {
            color: #dc3545;
        }
        .admin-migrate-success-text {
            color: #28a745;
            font-weight: bold;
        }
        .admin-migrate-error-text {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Scoring System Migration</h1>
        <p><strong>Status:</strong> Migration Complete</p>

        <h2>Results:</h2>
        <?php foreach ($results as $result): ?>
            <?php
                $class = 'info';
                if (strpos($result, '✅') !== false) $class = 'success';
                if (strpos($result, '⏭️') !== false) $class = 'info';
            ?>
            <div class="result <?= $class ?>">
                <?= htmlspecialchars($result) ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($errors)): ?>
            <h2 class="admin-migrate-errors-title">Errors:</h2>
            <?php foreach ($errors as $error): ?>
                <div class="result error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="summary">
            <h3>✅ Migration Summary</h3>
            <p>
                <strong>Total Operations:</strong> <?= count($results) ?><br>
                <strong>Errors:</strong> <?= count($errors) ?>
            </p>

            <?php if (empty($errors)): ?>
                <p class="admin-migrate-success-text">
                    ✅ All done! Your database is ready for the new scoring systems.
                </p>
            <?php else: ?>
                <p class="admin-migrate-error-text">
                    ⚠️ Some errors occurred. Please check the error messages above.
                </p>
            <?php endif; ?>
        </div>

        <a href="/admin/seasons" class="btn">Go to Season Management →</a>

        <div class="danger">
            <strong>⚠️ IMPORTANT:</strong> After confirming everything works, DELETE this file (migrate_scoring.php) for security.
        </div>
    </div>
</body>
</html>
