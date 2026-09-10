<?php
/**
 * Router for PHP's built-in server (dev only): emulates the .htaccess clean
 * URLs so /power-rankings serves power_rankings.php, /season/s04 the homepage
 * with ?season=, and static files are served as-is.
 *
 *   php -S localhost:8080 -t public_html bin/dev_router.php
 */
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$root = __DIR__ . '/../public_html';
$decoded = rawurldecode($uri);   // character art has spaces in its filenames
if ($uri !== '/' && (is_file($root . $uri) || is_file($root . $decoded))) return false;            // static asset or explicit .php
if ($uri === '/') { require $root . '/index.php'; return true; }
if (preg_match('#^/season/([a-z0-9]+)$#', $uri, $m)) { $_GET['season'] = $m[1]; require $root . '/index.php'; return true; }
if ($uri === '/season-yearbook') { require $root . '/yearbook.php'; return true; }   // .htaccess names it differently from the file

// The parameterised clean URLs, mirroring the RewriteRules in .htaccess. These
// were missing, so /timeline/s05gp08 — which add_result redirects to after a
// save — 404'd on the dev server while working fine under Apache.
$paramRoutes = [
    '#^/racer/([0-9]+)$#'                   => ['racer.php',                  'id'],
    '#^/wrapped/([0-9]+)$#'                 => ['wrapped.php',                'racer'],
    '#^/stickers/([0-9]+)$#'                => ['stickers.php',               'racer'],
    '#^/view-recap/([0-9]+)$#'              => ['view_recap.php',             'id'],
    '#^/wc-pickem/([0-9]+)$#'               => ['wc_pickem.php',              'id'],
    '#^/view-tournament-report/([0-9]+)$#'  => ['view_tournament_report.php', 'id'],
    '#^/timeline/(s[0-9]+gp[0-9]+)$#'       => ['timeline_gp.php',            'gp'],
    '#^/cup/([a-z0-9-]+)$#'                 => ['cup_detail.php',             'cup'],
    '#^/lexicon/([a-z0-9-]+)$#'             => ['lexicon.php',                'term'],
    '#^/admin/tournament-bracket/([0-9]+)$#' => ['admin/tournament_bracket.php', 'id'],
];
foreach ($paramRoutes as $pattern => [$script, $param]) {
    if (preg_match($pattern, $uri, $m)) { $_GET[$param] = $m[1]; require $root . '/' . $script; return true; }
}
$file = $root . '/' . str_replace('-', '_', trim($uri, '/')) . '.php';
if (is_file($file)) { require $file; return true; }
$sub = $root . trim($uri, '/') . '.php';                              // admin/seasons → admin/seasons.php
if (is_file($root . '/' . trim($uri, '/') . '.php')) { require $root . '/' . trim($uri, '/') . '.php'; return true; }
http_response_code(404); echo "404 — no route for " . htmlspecialchars($uri);
