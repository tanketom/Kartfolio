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
if (preg_match('#^/racer/(\d+)$#', $uri, $m)) { $_GET['id'] = $m[1]; require $root . '/racer.php'; return true; }
if ($uri === '/season-yearbook') { require $root . '/yearbook.php'; return true; }   // .htaccess names it differently from the file
$file = $root . '/' . str_replace('-', '_', trim($uri, '/')) . '.php';
if (is_file($file)) { require $file; return true; }
$sub = $root . trim($uri, '/') . '.php';                              // admin/seasons → admin/seasons.php
if (is_file($root . '/' . trim($uri, '/') . '.php')) { require $root . '/' . trim($uri, '/') . '.php'; return true; }
http_response_code(404); echo "404 — no route for " . htmlspecialchars($uri);
