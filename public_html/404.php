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

/**
 * The league already has a culprit for a run that was going fine until it
 * wasn't: the Ludwig Obstruction, logged per result as is_lol. A missing page
 * is just one more of those. Everything here is Kartfolio's own vocabulary —
 * the LOL flag, the twelve-kart field, GPIDs, the OMK — so it reads the same
 * in any commissioner's league, with no league's name baked in.
 */
// The third element marks the excuse that actually blames Ludwig — the only
// one the tally belongs under. It used to print beneath every excuse, so
// "Finished 13th" sat above a Ludwig counter that had nothing to do with it.
$excuses = [
    ['Ludwig got here first.',
     'The page was blocking, item-spamming, or worse. Logged as a Ludwig Obstruction and filed with the stewards.', true],
    ['Blue shell.',
     'The page was leading comfortably right up until it wasn\'t. Nothing anyone could have done.'],
    ['Finished 13th.',
     'In a twelve-kart field. Take a moment with that.'],
    ['No GPID matches that.',
     'The OMK has reviewed the request, found no corresponding Grand Prix, and considers the matter closed.'],
];
$excuse = $excuses[random_int(0, count($excuses) - 1)];

// A real number from the league's own record — only fetched when the excuse
// on screen is one that blames Ludwig, and only shown when he has a record.
$lols = 0;
if (!empty($excuse[2])) {
    try { $lols = (int)$pdo->query("SELECT COALESCE(SUM(is_lol), 0) FROM results")->fetchColumn(); }
    catch (PDOException $e) {}
}

include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <div class="notfound">
        <div class="notfound-code">404</div>
        <h1 class="notfound-title"><?= htmlspecialchars($excuse[0]) ?></h1>
        <p class="notfound-line"><?= htmlspecialchars($excuse[1]) ?></p>
        <p class="notfound-path">
            Nothing lives at <code><?= htmlspecialchars($path !== '' ? $path : '/') ?></code>.
        </p>
        <?php if ($lols > 0): ?>
        <p class="notfound-tally">
            🐢 Ludwig has obstructed <strong><?= number_format($lols) ?></strong>
            otherwise-decent run<?= $lols === 1 ? '' : 's' ?> on record.
            Consider this one <?= number_format($lols + 1) ?>.
        </p>
        <?php endif; ?>
        <div class="notfound-links">
            <a class="btn btn-primary" href="/">Standings</a>
            <a class="btn" href="/timeline">Timeline</a>
            <a class="btn" href="/records">Records</a>
            <a class="btn" href="/vault">The Vault</a>
            <a class="btn" href="/map">Site map</a>
        </div>
        <p class="notfound-omk">— filed by the OMK Press Office</p>
    </div>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
