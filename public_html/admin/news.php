<?php
/**
 * News desk — broadcasts are generated into drafts here, read, edited, then
 * published. Published stories can be unpublished (kept, hidden) or pinned
 * to the top of the signs' ticker. Nothing is deleted without asking.
 *
 * Path: /cdnmk/public_html/admin/news.php   (clean URL: /admin/news)
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/programs.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_admin();

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($id > 0) {
        switch ($action) {
            case 'publish':   $pdo->prepare("UPDATE recap_archive SET status = 'published' WHERE id = ?")->execute([$id]); $flash = 'Published.'; break;
            case 'unpublish': $pdo->prepare("UPDATE recap_archive SET status = 'draft', pinned = 0 WHERE id = ?")->execute([$id]); $flash = 'Unpublished — kept as a draft.'; break;
            case 'pin':       $pdo->exec("UPDATE recap_archive SET pinned = 0"); $pdo->prepare("UPDATE recap_archive SET pinned = 1, status = 'published' WHERE id = ?")->execute([$id]); $flash = 'Pinned to the ticker.'; break;
            case 'unpin':     $pdo->prepare("UPDATE recap_archive SET pinned = 0 WHERE id = ?")->execute([$id]); $flash = 'Unpinned.'; break;
            case 'delete':    $pdo->prepare("DELETE FROM recap_archive WHERE id = ?")->execute([$id]); $flash = 'Deleted.'; break;
        }
    }
}

$open = (int)($_GET['open'] ?? 0);
$aiPrograms = getAIProgramsCatalog();
$all = $pdo->query("SELECT id, season_id, headline, key_quote, program_key, created_at, status, pinned, LENGTH(recap_text) AS len, recap_text FROM recap_archive ORDER BY (status = 'draft') DESC, pinned DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$drafts = array_values(array_filter($all, fn($r) => $r['status'] === 'draft'));
$published = array_values(array_filter($all, fn($r) => $r['status'] !== 'draft'));
$openRow = null; foreach ($all as $r) if ((int)$r['id'] === $open) $openRow = $r;

$pageTitle = 'News desk — Admin';
$extraCss  = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';

function newsProgramLabel(string $key): string { $p = getProgramInfo($key); return (string)($p['label'] ?? $key); }
function newsActions(array $r): string {
    $id = (int)$r['id']; $t = csrf_field();
    $btn = fn(string $a, string $label, string $cls = 'btn-secondary', string $confirm = '') => '<form method="POST" class="news-act"' . ($confirm ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm)) . ');"' : '') . '>' . $t . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $a . '"><button type="submit" class="' . $cls . ' btn-sm">' . $label . '</button></form>';
    $out = '<a href="/view-recap/' . $id . '" class="btn-secondary btn-sm" target="_blank">Preview</a><a href="/admin/edit-recap?id=' . $id . '" class="btn-secondary btn-sm">Edit</a>';
    if ($r['status'] === 'draft') $out .= $btn('publish', 'Publish', 'btn-primary');
    else { $out .= $btn('unpublish', 'Unpublish'); $out .= (int)$r['pinned'] ? $btn('unpin', '📌 Unpin') : $btn('pin', '📌 Pin to ticker'); }
    $out .= $btn('delete', 'Delete', 'btn-danger', 'Delete this story for good?');
    return $out;
}
?>
<div class="container">
    <div class="admin-page-header">
        <h1>📰 News desk</h1>
        <p class="admin-page-sub">Broadcasts land here as drafts. Read them, edit if needed, then publish. Pin one story to the top of the signs' ticker.</p>
    </div>

    <?php if ($flash): ?><div class="admin-flash"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <?php if ($openRow): ?>
    <section class="news-open news-open--<?= $openRow['status'] ?>">
        <div class="news-open-head">
            <span class="news-status news-status--<?= $openRow['status'] ?>"><?= $openRow['status'] === 'draft' ? 'Draft' : 'Published' ?></span>
            <span class="news-program"><?= htmlspecialchars(newsProgramLabel($openRow['program_key'])) ?></span>
            <span class="news-date"><?= htmlspecialchars(substr($openRow['created_at'], 0, 16)) ?></span>
        </div>
        <h2 class="news-open-headline"><?= htmlspecialchars((string)$openRow['headline']) ?></h2>
        <?php if (!empty($openRow['key_quote'])): ?><p class="news-open-quote">“<?= htmlspecialchars($openRow['key_quote']) ?>”</p><?php endif; ?>
        <div class="news-open-body"><?= nl2br(htmlspecialchars((string)$openRow['recap_text'])) ?></div>
        <div class="news-actions"><?= newsActions($openRow) ?></div>
    </section>
    <?php endif; ?>

    <div class="news-grid">
        <section class="admin-card news-generate">
            <?php if (!moduleEnabled($pdo, 'broadcasts')): ?>
            <h2>Generate a draft</h2>
            <p class="admin-section-sub">AI broadcasts are switched off under <a href="/admin/modules">Modules</a>. Hand-written items still go through the <a href="/archive#press">OMK Press Office</a>.</p>
            <?php else: ?>
            <h2>Generate a draft</h2>
            <p class="admin-section-sub">The writer reads the recent nights, form and rivalries. The result lands below as a draft — nothing goes public until you publish it.</p>
            <form action="/api/gemini_recap.php" method="POST" class="admin-gen-form">
                <?= csrf_field() ?>
                <input type="hidden" name="draft" value="1">
                <div class="admin-input-group">
                    <label>Program</label>
                    <select name="program" class="admin-select">
                        <?php foreach ($aiPrograms as $val => $data): ?><option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($data['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-input-group">
                    <label>Director's notes (optional)</label>
                    <textarea name="notes" class="admin-select" rows="3" placeholder="Focus, time range or length — e.g. 'Hanna's streak', 'last night only', 'short'"></textarea>
                </div>
                <button type="submit" class="btn-primary">Generate draft</button>
            </form>
            <p class="admin-section-sub news-press-hint">Hand-written items go through the <a href="/archive#press">OMK Press Office</a> on the archive page, which can also save as a draft.</p>
            <?php endif; ?>
        </section>

        <section class="admin-card">
            <h2>Drafts <span class="news-count"><?= count($drafts) ?></span></h2>
            <?php if (!$drafts): ?><p class="admin-section-sub">No drafts waiting.</p><?php endif; ?>
            <?php foreach ($drafts as $r): ?>
            <article class="news-row news-row--draft">
                <div class="news-row-main">
                    <a class="news-row-headline" href="/admin/news?open=<?= (int)$r['id'] ?>"><?= htmlspecialchars((string)($r['headline'] ?: '(no headline)')) ?></a>
                    <div class="news-row-meta"><?= htmlspecialchars(newsProgramLabel($r['program_key'])) ?> · <?= strtoupper(htmlspecialchars($r['season_id'])) ?> · <?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?> · <?= (int)$r['len'] ?> chars</div>
                </div>
                <div class="news-actions"><?= newsActions($r) ?></div>
            </article>
            <?php endforeach; ?>
        </section>
    </div>

    <section class="admin-card">
        <h2>Published <span class="news-count"><?= count($published) ?></span></h2>
        <?php foreach ($published as $r): ?>
        <article class="news-row<?= (int)$r['pinned'] ? ' news-row--pinned' : '' ?>">
            <div class="news-row-main">
                <a class="news-row-headline" href="/admin/news?open=<?= (int)$r['id'] ?>"><?= (int)$r['pinned'] ? '📌 ' : '' ?><?= htmlspecialchars((string)($r['headline'] ?: '(no headline)')) ?></a>
                <div class="news-row-meta"><?= htmlspecialchars(newsProgramLabel($r['program_key'])) ?> · <?= strtoupper(htmlspecialchars($r['season_id'])) ?> · <?= htmlspecialchars(substr($r['created_at'], 0, 16)) ?></div>
            </div>
            <div class="news-actions"><?= newsActions($r) ?></div>
        </article>
        <?php endforeach; ?>
    </section>
</div>
<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
