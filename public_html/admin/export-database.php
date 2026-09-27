<?php
/**
 * Database download — a fresh snapshot, or one of the automatic daily backups.
 *
 *   /admin/export-database                    a consistent snapshot, right now
 *   /admin/export-database?file=league-…db    one of the automatic backups
 *
 * This used to readfile() the live league.db. The database runs in WAL mode,
 * so anything committed since the last checkpoint lives in league.db-wal and
 * was simply not in the download: an export taken the evening of a race night
 * could be missing that night's results, and nothing about the file said so.
 * Proven by writing a row and copying the main file the old way — the live
 * database saw it, the export did not. The download is now a VACUUM INTO
 * snapshot, which goes through SQLite and is complete and consistent.
 *
 * Path: /cdnmk/public_html/admin/export-database.php
 */
require_once __DIR__ . '/../../private/includes/db.php';     // defines $pdo and $dbPath
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/backup.php';
require_admin();

function streamDbFile(string $path, string $downloadName): void {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}

// ── One of the automatic backups ─────────────────────────────────────────
// Only a name that listBackups() itself returned is accepted, so nothing in
// the query string can ever become a path.
$wanted = (string)($_GET['file'] ?? '');
if ($wanted !== '') {
    foreach (listBackups(backupDirFor($dbPath), backupPrefixFor($dbPath)) as $b) {
        if (hash_equals($b['file'], $wanted)) {
            streamDbFile($b['path'], $b['file']);
            exit;
        }
    }
    http_response_code(404);
    exit('No such backup.');
}

// ── A fresh, consistent snapshot ─────────────────────────────────────────
$tmp = sys_get_temp_dir() . '/kartfolio-export-' . bin2hex(random_bytes(6)) . '.db';
try {
    if (version_compare((string)$pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.27.0', '>=')) {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
    } else {
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');   // fold the WAL in before a plain copy
        if (!copy($dbPath, $tmp)) throw new RuntimeException('copy failed');
    }
    streamDbFile($tmp, pathinfo($dbPath, PATHINFO_FILENAME) . '-' . date('Y-m-d_H-i-s') . '.db');
} catch (Throwable $e) {
    http_response_code(500);
    error_log('kartfolio export: ' . $e->getMessage());
    echo 'Export failed: ' . htmlspecialchars($e->getMessage());
} finally {
    if (is_file($tmp)) @unlink($tmp);
}
exit;
