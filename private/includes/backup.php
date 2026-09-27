<?php
/**
 * Automatic daily backups of the league database.
 *
 * The league is one SQLite file on a shared host. Until this existed the only
 * copy anywhere else was whatever a commissioner last downloaded by hand from
 * Admin → Export, so a bad migration, a slip on the server or a disk problem
 * would have taken every result, bet, vote and sticker with it.
 *
 * On the first web request of each day, db.php calls dailyBackup(), which
 * writes a consistent snapshot to private/data/backups/ — outside the web root
 * and blocked by .htaccess besides — and prunes old ones:
 *   - the newest BACKUP_KEEP_DAILY daily copies are kept;
 *   - of anything older, one per calendar month is kept, up to
 *     BACKUP_KEEP_MONTHLY months back.
 * At ~1 MB per copy that is roughly 40 MB, forever.
 *
 * Snapshot, not copy: the database runs in WAL mode, so copying the file while
 * someone writes can capture a torn state. VACUUM INTO writes a consistent copy
 * through SQLite itself (3.27+). Only on an older SQLite does this fall back to
 * a plain copy of the main file after a checkpoint, and it says so in the log.
 *
 * Steady-state cost: one is_file() per request. The snapshot itself runs once
 * a day and takes a few milliseconds at this size.
 */

const BACKUP_KEEP_DAILY   = 30;
const BACKUP_KEEP_MONTHLY = 12;

/** Where the backups for a given database live, and the prefix they share. */
function backupDirFor(string $dbPath): string {
    return dirname($dbPath) . '/backups';
}
function backupPrefixFor(string $dbPath): string {
    // Named after the database, so a dev copy (demo_territory.db) can never
    // be mistaken for, or pruned alongside, the real league.
    return pathinfo($dbPath, PATHINFO_FILENAME) . '-';
}

/**
 * Take today's backup if there isn't one yet. Never throws — a backup failure
 * must not take a page down with it. Returns the path written, or null.
 */
function dailyBackup(PDO $pdo, string $dbPath): ?string {
    // CLI scripts (check.sh, the fixture builder, one-off php -r) are not
    // traffic, and a test fixture has nothing worth keeping.
    if (PHP_SAPI === 'cli') return null;
    if (!is_file($dbPath)) return null;

    $dir    = backupDirFor($dbPath);
    $prefix = backupPrefixFor($dbPath);
    $target = $dir . '/' . $prefix . date('Y-m-d') . '.db';
    if (is_file($target)) return null;                 // the steady-state path

    try {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log("kartfolio backup: cannot create $dir");
            return null;
        }

        // Write to a temporary name and rename, so a half-written file is
        // never mistaken for a good backup — and two simultaneous first
        // requests of the day cannot both claim the date.
        $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));

        $viaVacuum = version_compare((string)$pdo->query('SELECT sqlite_version()')->fetchColumn(), '3.27.0', '>=');
        if ($viaVacuum) {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
        } else {
            // Older SQLite: fold the WAL back into the main file first so the
            // copy is as whole as a plain copy can be.
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            if (!@copy($dbPath, $tmp)) throw new RuntimeException('copy failed');
            error_log('kartfolio backup: SQLite < 3.27, used a checkpointed file copy instead of VACUUM INTO');
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);                              // someone else won the race
            return null;
        }
        @chmod($target, 0640);
        pruneBackups($dir, $prefix);
        return $target;
    } catch (Throwable $e) {
        if (isset($tmp) && is_file($tmp)) @unlink($tmp);
        error_log('kartfolio backup: ' . $e->getMessage());
        return null;
    }
}

/** Keep the newest dailies, then one per month for a year; delete the rest. */
function pruneBackups(string $dir, string $prefix): void {
    $files = listBackups($dir, $prefix);               // newest first
    $keep = [];
    $monthsKept = [];
    foreach ($files as $i => $f) {
        if ($i < BACKUP_KEEP_DAILY) { $keep[$f['path']] = true; continue; }
        $month = substr($f['date'], 0, 7);
        if (!isset($monthsKept[$month]) && count($monthsKept) < BACKUP_KEEP_MONTHLY) {
            // Walking newest-first, the LAST file seen in a month is its
            // earliest; keeping the first one seen is fine — any one per month.
            $monthsKept[$month] = true;
            $keep[$f['path']] = true;
        }
    }
    foreach ($files as $f) {
        if (!isset($keep[$f['path']])) @unlink($f['path']);
    }
}

/**
 * The backups on disk for one database, newest first.
 * @return array<int, array{path:string, file:string, date:string, bytes:int}>
 */
function listBackups(string $dir, string $prefix): array {
    $out = [];
    foreach (glob($dir . '/' . $prefix . '*.db') ?: [] as $path) {
        $file = basename($path);
        if (!preg_match('/(\d{4}-\d{2}-\d{2})\.db$/', $file, $m)) continue;
        $out[] = ['path' => $path, 'file' => $file, 'date' => $m[1], 'bytes' => (int)@filesize($path)];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    return $out;
}
