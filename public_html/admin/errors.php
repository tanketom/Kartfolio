<?php
/**
 * Errors — what the site has written to its own PHP log, grouped by problem,
 * newest first. Fatal errors and warnings from PHP itself, and the messages the
 * code logs when it catches a failure so a page can still render.
 *
 * The one write is clearing the log (POST + CSRF), once whatever was in it is fixed.
 *
 * Path: /cdnmk/public_html/admin/errors.php   (clean URL: /admin/errors)
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/csrf.php';
require_once __DIR__ . '/../../private/includes/error_log.php';
require_admin();

$path = kartfolioErrorLogPath();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    verify_csrf();
    foreach ([$path, $path . '.1'] as $f) if (is_file($f)) @file_put_contents($f, '');
    header('Location: /admin/errors', true, 303);
    exit;
}

$entries = kartfolioErrorEntries();
$groups = kartfolioGroupErrors($entries);
$now = time();
$last24 = count(array_filter($entries, fn($e) => $e['ts'] >= $now - 86400));
$last7 = count(array_filter($entries, fn($e) => $e['ts'] >= $now - 7 * 86400));
$writable = is_dir(dirname($path)) ? is_writable(dirname($path)) : false;
$levelClass = ['Fatal error' => 'bad', 'Parse error' => 'bad', 'Warning' => 'warn', 'Logged' => 'info', 'Notice' => 'info', 'Deprecated' => 'info'];
$ago = function (int $ts) use ($now): string {
    $d = $now - $ts;
    if ($d < 3600) return max(1, (int)floor($d / 60)) . ' min ago';
    if ($d < 86400) return (int)floor($d / 3600) . ' h ago';
    return date('j M, H:i', $ts);
};

$pageTitle = 'Errors — Admin';
$extraCss  = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>
<div class="container">
    <div class="admin-page-header">
        <h1>🧯 Errors</h1>
        <p class="admin-page-sub">
            <?php if (!$entries): ?>Nothing in the log. Every page has run cleanly since it was last cleared.
            <?php else: ?><?= $last24 ?> in the last 24 hours, <?= $last7 ?> in the last 7 days, <?= count($groups) ?> different problem<?= count($groups) === 1 ? '' : 's' ?> in all.<?php endif; ?>
        </p>
    </div>

    <?php if (!$writable): ?>
    <section class="audit-section audit-section--warn">
        <p class="audit-blurb">The log folder <code><?= htmlspecialchars(dirname($path)) ?></code> cannot be written to, so errors are going to the host's own log instead and will not show here.</p>
    </section>
    <?php endif; ?>

    <?php foreach ($groups as $g): $cls = $levelClass[$g['level']] ?? 'info'; ?>
    <section class="audit-section audit-section--<?= $cls ?> err-group">
        <div class="audit-head">
            <h2 class="err-level"><?= htmlspecialchars($g['level']) ?></h2>
            <span class="audit-count" title="Times seen"><?= $g['count'] ?>×</span>
        </div>
        <p class="err-message"><code><?= htmlspecialchars($g['message']) ?></code></p>
        <?php if ($g['more']): ?>
        <details class="err-more"><summary><?= count($g['more']) ?> more line<?= count($g['more']) === 1 ? '' : 's' ?></summary><pre><?= htmlspecialchars(implode("\n", array_slice($g['more'], 0, 40))) ?></pre></details>
        <?php endif; ?>
        <p class="audit-blurb">Last seen <?= $ago($g['last']) ?><?= $g['count'] > 1 ? ' · first seen ' . $ago($g['first']) : '' ?></p>
    </section>
    <?php endforeach; ?>

    <?php if ($entries): ?>
    <form method="POST" class="err-clear" onsubmit="return confirm('Clear the error log? Do this once whatever is in it is fixed.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="btn-secondary">Clear the log</button>
        <small class="audit-blurb">Reads the newest 512 KB. The log keeps two files of 2 MB at most.</small>
    </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
