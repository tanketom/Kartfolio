<?php
/**
 * Modules — switch whole features on or off for this league.
 *
 * Each switch is a boolean row in the settings table (enable_*). A module
 * that is off hides its links and its pages answer "switched off" (404) —
 * see moduleEnabled() / requireModule() in settings.php. Admin pages keep
 * working so a commissioner can still manage data behind a closed door.
 *
 * Path: /cdnmk/public_html/admin/modules.php   (clean URL: /admin/modules)
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_admin();

$modules = moduleCatalog();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $changed = 0;
    foreach ($modules as $key => $m) {
        $want = isset($_POST['module_' . $key]) ? '1' : '0';
        if ((moduleEnabled($pdo, $key) ? '1' : '0') !== $want) { updateSetting($pdo, $m['setting'], $want); $changed++; }
    }
    $message = $changed ? "✓ Saved — $changed module" . ($changed === 1 ? '' : 's') . " changed." : 'No changes.';
}

$pageTitle = 'Modules — Admin';
$extraCss  = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>
<div class="container">
    <div class="admin-page-header">
        <h1>🧩 Modules</h1>
        <p class="admin-page-sub">Switch features on or off for this league. Off hides the links and the pages; the data underneath is untouched, so switching back on brings everything back.</p>
    </div>

    <?php if ($message): ?><div class="admin-flash"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <form method="POST" class="modules-form">
        <?= csrf_field() ?>
        <div class="modules-grid">
            <?php foreach ($modules as $key => $m): $on = moduleEnabled($pdo, $key); ?>
            <label class="module-card<?= $on ? ' module-card--on' : '' ?>">
                <div class="module-card-head">
                    <span class="module-card-icon"><?= $m['icon'] ?></span>
                    <span class="module-card-title"><?= htmlspecialchars($m['title']) ?></span>
                    <span class="setting-toggle">
                        <input type="checkbox" name="module_<?= $key ?>" <?= $on ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </span>
                </div>
                <p class="module-card-desc"><?= htmlspecialchars($m['desc']) ?></p>
                <p class="module-card-hides"><strong>Off hides:</strong> <?= htmlspecialchars($m['hides']) ?></p>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="modules-actions">
            <button type="submit" class="btn-primary">Save modules</button>
            <a href="/admin/settings" class="btn-secondary">All settings</a>
        </div>
    </form>
</div>
<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
