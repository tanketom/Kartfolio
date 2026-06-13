<?php
/**
 * Database Import Handler
 * Path: /cdnmk/public_html/admin/import-database.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['database_file'])) {
    verify_csrf();
    $uploadedFile = $_FILES['database_file'];

    // Check for upload errors
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $error = "Upload failed with error code: " . $uploadedFile['error'];
    } else {
        // Validate file type
        $allowedExtensions = ['db', 'sqlite', 'sql'];
        $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExtension, $allowedExtensions)) {
            $error = "Invalid file type. Please upload a .db, .sqlite, or .sql file.";
        } else {
            // Define paths
            $dbPath = __DIR__ . '/../../private/data/league.db';
            $backupPath = __DIR__ . '/../../private/data/cdnmk_backup_before_import_' . date('Y-m-d_H-i-s') . '.db';

            try {
                // Create a backup of the current database before replacing
                if (file_exists($dbPath)) {
                    if (!copy($dbPath, $backupPath)) {
                        throw new Exception("Failed to create safety backup of current database");
                    }
                }

                // Move uploaded file to replace the database
                if (move_uploaded_file($uploadedFile['tmp_name'], $dbPath)) {
                    // Verify the new database is valid by trying to connect
                    try {
                        $testPdo = new PDO('sqlite:' . $dbPath);
                        $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                        // Test a simple query
                        $testPdo->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 1");

                        $success = "Database restored successfully! A backup of your previous database was saved.";

                        // Redirect to settings page with success message
                        header("Location: /admin/settings?import=success");
                        exit;

                    } catch (PDOException $e) {
                        // Restore the backup if the new database is invalid
                        if (file_exists($backupPath)) {
                            copy($backupPath, $dbPath);
                        }
                        throw new Exception("Uploaded file is not a valid SQLite database. Your original database has been restored.");
                    }
                } else {
                    throw new Exception("Failed to move uploaded file");
                }

            } catch (Exception $e) {
                $error = "Import failed: " . $e->getMessage();

                // Ensure we restore the backup on any error
                if (file_exists($backupPath) && !file_exists($dbPath)) {
                    copy($backupPath, $dbPath);
                }
            }
        }
    }
}

// If we get here, there was an error
$pageTitle = "Database Import Error - Kartfolio";
require_once __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin">Admin</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin/settings">Settings</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Import Result</span>
    </nav>

    <?php if ($error): ?>
        <div class="alert alert-error admin-import-alert">
            <h2>❌ Import Failed</h2>
            <p><?= htmlspecialchars($error) ?></p>
        </div>
        <a href="/admin/settings" class="btn btn-primary">← Back to Settings</a>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success admin-import-alert">
            <h2>✓ Import Successful</h2>
            <p><?= htmlspecialchars($success) ?></p>
        </div>
        <a href="/admin/settings" class="btn btn-primary">← Back to Settings</a>
    <?php endif; ?>
</div>

<style>
.btn {
    display: inline-block;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 900;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.9rem;
    text-decoration: none;
    background: var(--nintendo-red);
    color: white;
}

.btn:hover {
    background: #c50010;
    transform: translateY(-2px);
}
</style>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
