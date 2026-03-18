<?php
/**
 * Database Export Handler
 * Path: /cdnmk/public_html/admin/export-database.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

// Get database path
$dbPath = __DIR__ . '/../../private/data/league.db';

if (!file_exists($dbPath)) {
    die("Error: Database file not found");
}

// Generate filename with timestamp
$filename = 'cdnmk_backup_' . date('Y-m-d_H-i-s') . '.db';

// Set headers for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($dbPath));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Output the database file
readfile($dbPath);
exit;
