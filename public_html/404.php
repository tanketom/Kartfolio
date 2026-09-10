<?php
/**
 * The 404 handler (.htaccess: ErrorDocument 404 /404.php).
 *
 * This used to be /index.php, which meant every miss served the homepage —
 * and because a PHP ErrorDocument returns its own status, that homepage came
 * back as **200 OK**. A typo'd stylesheet, a renamed image or a dead link all
 * looked like a working page to anything that checks status codes, and the
 * broken reference stayed invisible.
 *
 * Two shapes of answer:
 *   - A request that looks like a static asset gets a plain-text 404 and
 *     nothing else. It is a stylesheet or an image that is missing; rendering
 *     a full HTML page (session, DB, settings, the whole header) to be thrown
 *     away by the browser is pure waste.
 *   - Anything else gets a real page, with the status still 404.
 */

http_response_code(404);

// Apache puts the original path in REDIRECT_URL when serving an ErrorDocument.
$missing = (string)($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '');
$path    = (string)(parse_url($missing, PHP_URL_PATH) ?? $missing);

if (preg_match('/\.(css|js|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|mp4|webm|json|txt|xml)$/i', $path)) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "404 Not Found\n";
    exit;
}

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$pageTitle  = 'Page not found — ' . $leagueName;
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <div class="notfound">
        <div class="notfound-code">404</div>
        <h1 class="notfound-title">No such page</h1>
        <p class="notfound-line">
            Nothing lives at <code><?= htmlspecialchars($path !== '' ? $path : '/') ?></code>.
            It may have been renamed, or the link that sent you here is out of date.
        </p>
        <div class="notfound-links">
            <a class="btn btn-primary" href="/">Standings</a>
            <a class="btn" href="/timeline">Timeline</a>
            <a class="btn" href="/records">Records</a>
            <a class="btn" href="/archive">News</a>
            <a class="btn" href="/map">Site map</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
