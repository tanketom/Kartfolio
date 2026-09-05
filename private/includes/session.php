<?php
/**
 * One place to start the PHP session with a hardened cookie.
 *
 * Every session_start() in the codebase goes through kartfolioSessionStart().
 * The cookie is HttpOnly and SameSite=Lax always, and Secure whenever the
 * request is not plain local development. Directives in .htaccess only apply
 * under Apache's mod_php, and the live host runs PHP behind nginx, so setting
 * them here is what actually reaches the browser.
 *
 * Secure detection: HTTPS on this hop, or X-Forwarded-Proto https from a
 * TLS-terminating proxy, or simply "not local dev" (anything other than PHP's
 * built-in server on localhost). A plain-HTTP install can force it off with
 * 'session_cookie_secure' => false in config.php.
 */
function kartfolioSessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    if (headers_sent()) { session_start(); return; }

    $override = null;
    require_once __DIR__ . '/config.php';
    $cfg = kartfolioConfig();
    if (array_key_exists('session_cookie_secure', $cfg) && $cfg['session_cookie_secure'] !== null) $override = (bool)$cfg['session_cookie_secure'];

    $host    = strtolower(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'));
    $isLocal = PHP_SAPI === 'cli-server' || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $https   = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    $secure  = $override ?? ($https || !$isLocal);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/**
 * The client's address for throttles and logs. REMOTE_ADDR is the truth
 * unless it is a loopback or private address — then this hop is a proxy
 * (nginx in front of PHP on the live host) and the address it forwards is
 * used instead. A public REMOTE_ADDR never trusts forwarded headers, so a
 * client cannot spoof its way out of a throttle by sending one.
 */
function clientIp(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $isProxyHop = $remote === '' || filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isProxyHop) {
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            $first = trim(explode(',', (string)($_SERVER[$h] ?? ''))[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
    }
    return $remote !== '' ? $remote : 'unknown';
}

