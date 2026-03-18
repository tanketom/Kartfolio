<?php
/**
 * Database Patch - Add Linked GPIDs column
 * Path: /cdnmk/public_html/api/patch_recap_gpids.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_admin();

try {
    // Add linked_gpids column (Text format to store comma-separated IDs)
    $pdo->exec("ALTER TABLE recap_archive ADD COLUMN linked_gpids TEXT DEFAULT ''");
    
    echo "<div style='font-family:sans-serif; padding:20px;'>";
    echo "<h2 style='color:green;'>✔ Patch Successful</h2>";
    echo "<p>Added column <strong>linked_gpids</strong> to table <em>recap_archive</em>.</p>";
    echo "<p>You can now manually tag specific GPs in the database, or wait for the updated generator.</p>";
    echo "<br><a href='/archive'>Return to Archive</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='font-family:sans-serif; padding:20px;'>";
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "<h2 style='color:orange;'>Notice: Column Already Exists</h2>";
    } else {
        echo "<h2 style='color:red;'>Error</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
    echo "<br><a href='/archive'>Return to Archive</a>";
    echo "</div>";
}
?>