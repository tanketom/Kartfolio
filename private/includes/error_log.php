<?php
/**
 * The site's own error log, and the reader behind /admin/errors.
 *
 * A lot of this codebase catches its own failures on purpose (a shop query must not
 * break the standings) and writes them to error_log(). On the host that went to a
 * log nobody reads, so a wrong require path sat silent until an end-to-end run
 * found it. kartfolioErrorLogSetup() points PHP's log at a file under
 * private/data/logs/ (gitignored, outside the web root), keeps it to two files of
 * ERRORLOG_MAX_BYTES, and /admin/errors groups what is in it.
 *
 * Not under the CLI: bin/check.sh treats warnings on stderr as failures, and a CLI
 * script's messages belong on its own terminal.
 */

const ERRORLOG_MAX_BYTES = 2 * 1024 * 1024;   // then it rotates to .1, replacing the old one

function kartfolioErrorLogPath(): string {
    return getenv('KARTFOLIO_ERROR_LOG') ?: dirname(__DIR__) . '/data/logs/php-errors.log';
}

/** Called once per request from db.php. One stat in steady state; never throws. */
function kartfolioErrorLogSetup(): void {
    if (PHP_SAPI === 'cli') return;
    $path = kartfolioErrorLogPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
    if (!is_writable($dir)) return;   // leave the host's own log in charge
    $size = @filesize($path);
    if ($size !== false && $size > ERRORLOG_MAX_BYTES) @rename($path, $path . '.1');
    ini_set('log_errors', '1');
    ini_set('error_log', $path);
}

/**
 * The newest entries, newest last: [['ts' => int|null, 'level' => string, 'message' => first line,
 * 'more' => following lines (a stack trace, say)], …]. Reads only the last $maxBytes, from
 * the current file and, if that is short, the rotated one.
 */
function kartfolioErrorEntries(?string $path = null, int $maxBytes = 512 * 1024): array {
    $path ??= kartfolioErrorLogPath();
    $text = '';
    foreach ([$path . '.1', $path] as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        $size = filesize($f);
        $fh = fopen($f, 'rb');
        if ($size > $maxBytes) fseek($fh, $size - $maxBytes);
        $text .= stream_get_contents($fh) . "\n";
        fclose($fh);
    }
    if (strlen($text) > $maxBytes) $text = substr($text, -$maxBytes);
    return kartfolioParseErrorLog($text);
}

/** Pure: PHP's log format, "[07-Nov-2026 21:42:23 Europe/Oslo] message", lines without a stamp belong to the entry above. */
function kartfolioParseErrorLog(string $text): array {
    $out = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        if (preg_match('/^\[([^\]]{8,40})\]\s?(.*)$/', $line, $m) && ($ts = strtotime($m[1])) !== false) {
            $msg = $m[2];
            $level = 'Logged';
            if (preg_match('/^PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error):\s+(.*)$/', $msg, $l)) {
                $level = ['Recoverable fatal error' => 'Fatal error'][$l[1]] ?? $l[1];
                $msg = $l[2];
            }
            $out[] = ['ts' => $ts, 'level' => $level, 'message' => $msg, 'more' => []];
        } elseif ($out && trim($line) !== '') {
            $out[count($out) - 1]['more'][] = $line;   // a stack trace or a wrapped message
        }
    }
    return $out;
}

/**
 * Entries grouped by what went wrong: numbers and quoted values are folded, so "omk gold: …
 * id 41" and "… id 42" are one problem. Most recent first. Pure.
 * [['key','level','message' (the newest example),'more','count','first','last'], …]
 */
function kartfolioGroupErrors(array $entries): array {
    $groups = [];
    foreach ($entries as $e) {
        $key = $e['level'] . '|' . preg_replace(['/\d+/', "/'[^']*'/", '/"[^"]*"/'], ['N', "'…'", '"…"'], $e['message']);
        $g = &$groups[$key];
        $g ??= ['key' => $key, 'level' => $e['level'], 'count' => 0, 'first' => $e['ts']];
        $g['count']++;
        $g['message'] = $e['message']; $g['more'] = $e['more']; $g['last'] = $e['ts'];
        unset($g);
    }
    usort($groups, fn($a, $b) => $b['last'] <=> $a['last']);
    return $groups;
}

/** How many entries in the last $hours, for the Admin menu. Reads only the file's tail. */
function kartfolioRecentErrorCount(int $hours = 24, ?int $now = null): int {
    $now ??= time();
    return count(array_filter(kartfolioErrorEntries(null, 64 * 1024), fn($e) => $e['ts'] >= $now - $hours * 3600));
}
