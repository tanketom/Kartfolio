<?php
/**
 * Database Connection
 * Path: /private/includes/db.php
 */

// Define the path to the SQLite database file
// Since this file is in /private/includes/, we go up two levels to reach /private/data/
$dbPath = __DIR__ . '/../data/league.db';

try {
    // Create (or open) the SQLite database
    $pdo = new PDO("sqlite:" . $dbPath);

    // Set error mode to exceptions for better debugging
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enable Foreign Key constraints in SQLite (disabled by default)
    $pdo->exec('PRAGMA foreign_keys = ON;');

} catch (PDOException $e) {
    // If the connection fails, stop the script and show the error
    // In a production environment, you might want to log this instead of echoing it
    die("CRITICAL ERROR: Could not connect to the League Database. " . $e->getMessage());
}

/**
 * You can now use the $pdo variable in any file that includes this script:
 * include_once(__DIR__ . '/../private/includes/db.php');
 */
?>