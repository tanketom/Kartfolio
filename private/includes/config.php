<?php
/**
 * Site config loader — shared by db.php and session.php. Kept out of db.php so
 * the session bootstrap can read config without pulling the database into a
 * function scope (which would make $pdo local and break every page).
 */

/**
 * Site config (Gemini key, admin password, model). KARTFOLIO_CONFIG overrides
 * the path (bin/check.sh points it at nothing so checks never read a real key).
 * A clone with no config.php yet gets the example's defaults with the admin
 * login disabled, so every public page renders before the commissioner has
 * copied the file — the login page says what to do.
 */
/** json_encode for a <script> block: a closing tag or quote in the data cannot break out. */
function jsonForScript($value): string {
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: 'null';
}

function kartfolioConfig(): array {
    static $c = null;
    if ($c !== null) return $c;
    $path = getenv('KARTFOLIO_CONFIG') ?: __DIR__ . '/../config/config.php';
    if (is_file($path)) return $c = (array)(require $path);
    $c = (array)(require __DIR__ . '/../config/config.example.php');
    $c['admin_password'] = '';   // no config file = no admin login
    $c['_missing'] = true;
    return $c;
}
