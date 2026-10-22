#!/usr/bin/env php
<?php
/**
 * Set an API key in private/config/config.php from a hidden terminal prompt.
 *
 *   php bin/set_api_key.php anthropic           Claude — the News desk's writer
 *   php bin/set_api_key.php gemini              Gemini — the backup, and every other AI feature
 *   php bin/set_api_key.php --show              which keys are set (masked)
 *   echo "$KEY" | php bin/set_api_key.php anthropic --stdin      scripted, no prompt
 *
 * The key is never echoed, logged or passed on a command line. config.php is
 * gitignored and survives a deploy (CLAUDE.md §12), so for the live league run
 * this ON THE HOST. ssh needs -t: a command given on the ssh line runs with no
 * terminal, and the hidden prompt has nothing to attach to without one.
 *
 *   ssh -t <user>@<host> 'cd ~/www/cdnmk && php bin/set_api_key.php anthropic'
 *
 * Honours KARTFOLIO_CONFIG like the app does, so a test can target a scratch
 * file. A missing config.php is created from config.example.php first.
 *
 * Path: /cdnmk/bin/set_api_key.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$keys  = ['anthropic' => 'anthropic_api_key', 'gemini' => 'gemini_api_key'];
$args  = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$which = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')))[0] ?? 'anthropic';

$path    = getenv('KARTFOLIO_CONFIG') ?: __DIR__ . '/../private/config/config.php';
$example = __DIR__ . '/../private/config/config.example.php';

function mask(string $v): string {
    $n = strlen($v);
    return $n <= 12 ? str_repeat('•', $n) : substr($v, 0, 7) . str_repeat('•', 6) . substr($v, -4);
}

if (in_array('--show', $flags, true)) {
    $cfg = is_file($path) ? (array)(require $path) : [];
    foreach ($keys as $name => $k) {
        $v = trim((string)($cfg[$k] ?? ''));
        printf("%-10s %s\n", $name, $v === '' ? '(not set)' : mask($v));
    }
    printf("%-10s %s\n", 'config', is_file($path) ? realpath($path) : "$path (missing)");
    exit(0);
}

if (!isset($keys[$which])) {
    fwrite(STDERR, "Unknown key '{$which}'. Use: anthropic | gemini   (or --show)\n");
    exit(2);
}
$configKey = $keys[$which];

// ── Read the key: hidden prompt on a terminal, one line from stdin with --stdin ──
if (in_array('--stdin', $flags, true)) {
    $key = fgets(STDIN);
} else {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "No terminal to prompt on. Over ssh, add -t (ssh -t host '…'); or pipe the key in with --stdin.\n");
        exit(2);
    }
    fwrite(STDOUT, "Paste the " . ucfirst($which) . " API key (typing is hidden), then press Enter: ");
    $saved = trim((string)shell_exec('stty -g 2>/dev/null'));
    $restore = function () use ($saved) { if ($saved !== '') shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null'); };
    register_shutdown_function($restore);   // Ctrl-C mid-prompt must not leave echo off
    shell_exec('stty -echo 2>/dev/null');
    $key = fgets(STDIN);
    $restore();
    fwrite(STDOUT, "\n");
}

$key = trim((string)$key);
if ($key === '') { fwrite(STDERR, "Nothing entered — config unchanged.\n"); exit(1); }
if (preg_match('/\s/', $key)) { fwrite(STDERR, "That key contains whitespace — config unchanged.\n"); exit(1); }

// ── Bootstrap config.php on a fresh install ──
if (!is_file($path)) {
    if (!is_file($example) || !copy($example, $path)) {
        fwrite(STDERR, "Could not create {$path} from config.example.php.\n");
        exit(1);
    }
    @chmod($path, 0640);
    fwrite(STDOUT, "Created " . realpath($path) . " from config.example.php.\n");
}
$src = file_get_contents($path);
if ($src === false) { fwrite(STDERR, "Could not read {$path}\n"); exit(1); }

// ── Replace the line if present, else add it before the closing "];" ──
$literal = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $key) . "'";
$line    = "    '{$configKey}' => {$literal},   // set with bin/set_api_key.php";
$pattern = "/^[ \\t]*'" . preg_quote($configKey, '/') . "'\\s*=>[^\\n]*$/m";

if (preg_match($pattern, $src)) {
    $new = preg_replace_callback($pattern, fn() => $line, $src, 1);
} else {
    $pos = strrpos($src, '];');
    if ($pos === false) {
        fwrite(STDERR, "{$path} does not look like a returned array — add '{$configKey}' by hand.\n");
        exit(1);
    }
    $new = substr($src, 0, $pos) . $line . "\n" . substr($src, $pos);
}

// ── Prove the result parses and carries the key before touching the real file ──
$tmp = $path . '.tmp-' . getmypid();
if (file_put_contents($tmp, $new) === false) { fwrite(STDERR, "Could not write {$tmp}\n"); exit(1); }
try {
    $check = (array)(include $tmp);
} catch (\Throwable $e) {
    @unlink($tmp);
    fwrite(STDERR, "The edited config would not parse (" . $e->getMessage() . ") — nothing changed.\n");
    exit(1);
}
if (($check[$configKey] ?? null) !== $key) {
    @unlink($tmp);
    fwrite(STDERR, "The edited config did not read the key back — nothing changed.\n");
    exit(1);
}
@chmod($tmp, fileperms($path) & 0777);
if (!rename($tmp, $path)) { @unlink($tmp); fwrite(STDERR, "Could not replace {$path}\n"); exit(1); }

fwrite(STDOUT, "Saved {$configKey} (" . mask($key) . ") to " . realpath($path) . "\n");
if ($which === 'anthropic') {
    fwrite(STDOUT, "The News desk now writes with Claude; Gemini stays as the backup. php bin/set_api_key.php --show to confirm.\n");
}
