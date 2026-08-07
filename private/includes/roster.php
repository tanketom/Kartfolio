<?php
/**
 * Roster paste parsing — shared by the first-run setup page and the
 * "add several at once" box on /admin/racers.
 * Path: /cdnmk/private/includes/roster.php
 */

/**
 * Parse a pasted roster block into racer rows.
 *
 * One racer per line. An optional nickname may follow a comma, which is what
 * you get pasting two columns out of a spreadsheet:
 *
 *     Hanna
 *     Tom, The Wall
 *
 * Blank lines are skipped, whitespace trimmed, and duplicate names dropped
 * (case-insensitively, first occurrence wins) so a double-paste can't create
 * two of the same racer. Returns [['name' => ..., 'nickname' => ...], ...].
 */
function parseRosterLines(string $raw): array {
    $out  = [];
    $seen = [];
    foreach (preg_split('/\R/u', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts    = explode(',', $line, 2);
        $name     = trim($parts[0]);
        $nickname = isset($parts[1]) ? trim($parts[1]) : '';
        if ($name === '') continue;

        // Keep names to a sane length — the column is TEXT, but a pasted
        // essay shouldn't become a racer.
        $name     = mb_substr($name, 0, 60);
        $nickname = mb_substr($nickname, 0, 60);

        $key = mb_strtolower($name);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $out[] = ['name' => $name, 'nickname' => $nickname];
    }
    return $out;
}

/**
 * Insert parsed roster rows, skipping any name already on the roster.
 * Returns [inserted, skipped].
 */
function insertRosterRows(PDO $pdo, array $rows): array {
    if (empty($rows)) return [0, 0];

    $existing = [];
    foreach ($pdo->query("SELECT name FROM racers")->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $existing[mb_strtolower(trim($n))] = true;
    }

    $stmt = $pdo->prepare("INSERT INTO racers (name, nickname, catchphrase, in_mikkoliiga, is_retired) VALUES (?, ?, '', 0, 0)");
    $inserted = 0; $skipped = 0;
    foreach ($rows as $r) {
        if (isset($existing[mb_strtolower($r['name'])])) { $skipped++; continue; }
        $stmt->execute([$r['name'], $r['nickname']]);
        $existing[mb_strtolower($r['name'])] = true;
        $inserted++;
    }
    return [$inserted, $skipped];
}
