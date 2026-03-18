<?php
/**
 * Database Migration: Create Season Awards Table
 * Path: /cdnmk/private/includes/create_awards_table.php
 *
 * Run this once to create the season_awards table
 */
require_once __DIR__ . '/db.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS season_awards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            season_id TEXT NOT NULL,
            award_category TEXT NOT NULL,
            winner_name TEXT NOT NULL,
            votes INTEGER DEFAULT 0,
            voters INTEGER DEFAULT 0,
            status TEXT DEFAULT 'final',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(season_id, award_category)
        )
    ");

    echo "✅ season_awards table created successfully!\n";
} catch (PDOException $e) {
    echo "❌ Error creating table: " . $e->getMessage() . "\n";
}
