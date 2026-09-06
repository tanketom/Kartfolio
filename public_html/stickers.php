<?php
/**
 * Sticker Album — one racer's collection + their unopened packs.
 *
 * Anyone may open anyone's packs (trust-the-room policy, CSRF only) — the
 * contents were fixed at grant time, so there's nothing to game. Opening
 * reveals the stickers inline with a flip animation; duplicates stack ×N.
 *
 * URL: /stickers/<racerId>
 * Path: /cdnmk/public_html/stickers.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';
requireModule($pdo, 'stickers');   // Admin → Modules
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/stickers.php';

$racerId = (int)($_GET['racer'] ?? 0);
$rStmt = $pdo->prepare("SELECT id, name FROM racers WHERE id = ?");
$rStmt->execute([$racerId]);
$racer = $rStmt->fetch(PDO::FETCH_ASSOC);

if (!$racer) {
    $pageTitle = "Stickers — racer not found";
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container"><h1>🩹 No album</h1><p>Unknown racer. <a href="/">Home</a>.</p></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

// ── Launch gate: the whole collection opens at the stickers epoch ──────
// Before then: public sees a teaser; admins get an ART-PREVIEW mode (full
// catalog faces visible, nothing grantable or openable) so sticker PNGs can
// be checked as they're dropped into assets/img/stickers/.
$epoch   = stickersEpoch($pdo);
$isLive  = date('Y-m-d') >= $epoch;
$isAdmin = !empty($_SESSION['is_admin']); // csrf.php started the session
$preview = !$isLive && $isAdmin;

if (!$isLive && !$isAdmin) {
    $pageTitle = "Sticker Album — opens " . $epoch;
    $extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container" style="text-align:center;padding:60px 20px;">'
       . '<div style="font-size:4rem;">📦</div>'
       . '<h1 class="page-title">Sticker Packs</h1>'
       . '<p style="color:#aaa;font-size:1.1rem;max-width:520px;margin:16px auto;">The first packs hit the table when the new season starts on '
       . '<strong>' . date('F j, Y', strtotime($epoch)) . '</strong>. One pack per GP raced — plus a Founders Pack for everyone. 🍄</p>'
       . '<a href="/racer/' . (int)$racerId . '" class="btn btn-secondary">← Back to ' . htmlspecialchars($racer['name']) . '</a></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

// Founders Pack only exists once the collection is live.
if ($isLive) ensureFoundersPack($pdo, $racerId);

// ── Open a pack (anyone may; CSRF only — and only once live) ───────────
$revealed = null; $openNotice = '';
if ($isLive && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'open_pack') {
    verify_csrf();
    require_once __DIR__ . '/../private/includes/throttle.php';
    $packId = throttleAllow($pdo, 'open_pack', 30, 10) ? (int)($_POST['pack_id'] ?? 0) : 0;
    if ($packId === 0) $openNotice = 'Too many packs opened from this connection. Wait ten minutes.';
    // Pack must belong to this racer's page.
    $chk = $pdo->prepare("SELECT racer_id FROM racer_packs WHERE id = ?");
    $chk->execute([$packId]);
    if ((int)$chk->fetchColumn() === $racerId) {
        $revealed = openPack($pdo, $packId);
        if ($revealed === null) $openNotice = 'That pack was already opened.';
    }
}

// ── Page data ───────────────────────────────────────────────────────────
$packsStmt = $pdo->prepare("SELECT * FROM racer_packs WHERE racer_id = ? AND opened_at IS NULL ORDER BY id ASC");
$packsStmt->execute([$racerId]);
$unopened = $packsStmt->fetchAll(PDO::FETCH_ASSOC);

$catalog = stickerCatalog($pdo);
$owned   = racerAlbum($pdo, $racerId);
$prog    = albumProgress($pdo, $racerId);

$setMeta = [
    'lore'       => ['📜', 'League Lore'],
    'racers'     => ['🏎️', 'Racers'],
    'cups'       => ['🏆', 'Cups'],
    'characters' => ['🎮', 'Characters'],
    'items'      => ['🎁', 'Items'],
    'tracks'     => ['🏁', 'Legendary Tracks'],
    'moments'    => ['💎', 'Moments'],
];
$bySet = [];
foreach ($catalog as $s) $bySet[$s['set']][] = $s;

$frameClass = fn($r) => 'stk--' . $r;

$pageTitle = htmlspecialchars($racer['name']) . " — Sticker Album";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/racer/<?= $racerId ?>">← <?= htmlspecialchars($racer['name']) ?></a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Sticker Album</span>
    </nav>

    <header class="stk-hero">
        <div>
            <h1 class="page-title">🩹 <?= htmlspecialchars($racer['name']) ?>'s Album</h1>
            <p class="page-subtitle">
                <?= $prog['_album']['owned'] ?> / <?= $prog['_album']['total'] ?> STICKERS ·
                <?= $prog['_album']['total'] ? (int)round($prog['_album']['owned'] / $prog['_album']['total'] * 100) : 0 ?>% COMPLETE
            </p>
        </div>
        <div class="stk-album-bar"><div style="width: <?= $prog['_album']['total'] ? round($prog['_album']['owned'] / $prog['_album']['total'] * 100) : 0 ?>%;"></div></div>
    </header>

    <?php if ($preview): ?>
        <div class="stk-notice">⚠️ <strong>Admin art preview</strong> — the collection launches
            <?= date('F j, Y', strtotime($epoch)) ?>. All faces are shown so you can check sticker art
            as you drop files into <code>assets/img/stickers/</code>. Nothing can be opened or owned yet.</div>
    <?php endif; ?>

    <?php if ($openNotice): ?><div class="stk-notice"><?= htmlspecialchars($openNotice) ?></div><?php endif; ?>

    <?php if ($revealed): ?>
    <section class="stk-reveal">
        <h2>✨ Pack opened!</h2>
        <div class="stk-reveal-row">
            <?php foreach ($revealed as $i => $s): ?>
                <div class="stk <?= $frameClass($s['rarity']) ?> stk-pop" style="animation-delay: <?= $i * 0.25 ?>s;">
                    <div class="stk-face">
                        <img src="<?= htmlspecialchars(stickerArtUrl($s)) ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <span class="stk-emoji" style="display:none;"><?= $s['emoji'] ?></span>
                        <?php if ($s['rarity'] === 'foil'): ?><span class="stk-sheen"></span><?php endif; ?>
                    </div>
                    <div class="stk-band">
                        <span class="stk-title"><?= htmlspecialchars($s['title']) ?></span>
                        <span class="stk-num">#<?= str_pad($s['num'], 3, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <div class="stk-tag <?= $s['is_new'] ? 'stk-tag--new' : '' ?>"><?= $s['is_new'] ? '✨ NEW' : 'duplicate' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($unopened)): ?>
    <section class="stk-packs">
        <h2>📦 Unopened packs <span class="stk-pack-count"><?= count($unopened) ?></span></h2>
        <div class="stk-pack-row">
            <?php foreach ($unopened as $p): ?>
                <form method="POST" class="stk-pack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="open_pack">
                    <input type="hidden" name="pack_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="stk-pack <?= $p['source'] === 'founders' ? 'stk-pack--founders' : '' ?>">
                        <span class="stk-pack-shine"></span>
                        <span class="stk-pack-label"><?= $p['source'] === 'founders' ? "FOUNDERS PACK" : strtoupper((string)$p['gpid']) ?></span>
                        <span class="stk-pack-size"><?= (int)$p['size'] ?> stickers</span>
                        <span class="stk-pack-cta">TEAR OPEN</span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php foreach ($bySet as $set => $stickers):
        [$emoji, $label] = $setMeta[$set] ?? ['🩹', ucfirst($set)];
        $sp = $prog[$set]; ?>
    <section class="stk-set">
        <div class="stk-set-head">
            <h2><?= $emoji ?> <?= htmlspecialchars($label) ?></h2>
            <div class="stk-set-prog">
                <div class="stk-set-bar"><div style="width: <?= $sp['total'] ? round($sp['owned'] / $sp['total'] * 100) : 0 ?>%;"></div></div>
                <span><?= $sp['owned'] ?>/<?= $sp['total'] ?></span>
            </div>
        </div>
        <div class="stk-grid">
            <?php foreach ($stickers as $s):
                // In admin art-preview, every face is visible (count stays 0).
                $have = $preview ? 1 : ($owned[$s['key']] ?? 0); ?>
                <?php if ($have): ?>
                <div class="stk <?= $frameClass($s['rarity']) ?>" title="<?= htmlspecialchars($s['title']) ?> · <?= $s['rarity'] ?>">
                    <div class="stk-face">
                        <img src="<?= htmlspecialchars(stickerArtUrl($s)) ?>" loading="lazy"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <span class="stk-emoji" style="display:none;"><?= $s['emoji'] ?></span>
                        <?php if ($s['rarity'] === 'foil'): ?><span class="stk-sheen"></span><?php endif; ?>
                        <?php if ($have > 1): ?><span class="stk-dup">×<?= $have ?></span><?php endif; ?>
                    </div>
                    <div class="stk-band">
                        <span class="stk-title"><?= htmlspecialchars($s['title']) ?></span>
                        <span class="stk-num">#<?= str_pad($s['num'], 3, '0', STR_PAD_LEFT) ?></span>
                    </div>
                </div>
                <?php else: ?>
                <div class="stk stk--missing" title="Not collected yet">
                    <div class="stk-face"><span class="stk-q">?</span></div>
                    <div class="stk-band"><span class="stk-num">#<?= str_pad($s['num'], 3, '0', STR_PAD_LEFT) ?></span></div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <p class="stk-foot">
        Packs drop one per GP raced (from <?= htmlspecialchars(stickersEpoch($pdo)) ?>) — plus everyone's one-time Founders Pack.
        Anyone at the table may tear a pack open. Collect them all. 🍄
    </p>
</div>

<style>
.stk-hero { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:18px; }
.stk-album-bar { flex:1; min-width:220px; height:10px; background:#232734; border-radius:5px; overflow:hidden; }
.stk-album-bar > div { height:100%; background:linear-gradient(90deg,#2ebd59,#ffd700); }
.stk-notice { background:#fff8e1; border:1px solid #ffd54f; color:#7a5c00; border-radius:8px; padding:10px 16px; margin-bottom:14px; font-weight:700; }

.stk-reveal { background:#181b25; border:1px solid #2a2150; border-radius:12px; padding:18px 22px; margin-bottom:22px; }
.stk-reveal h2 { color:#FFD700; margin:0 0 14px; }
.stk-reveal-row { display:flex; gap:16px; flex-wrap:wrap; }
.stk-pop { animation: stkpop .45s cubic-bezier(.2,1.6,.4,1) backwards; }
@keyframes stkpop { from { transform: scale(.3) rotate(-8deg); opacity:0; } to { transform: scale(1) rotate(0); opacity:1; } }

.stk-packs { margin-bottom:24px; }
.stk-packs h2 { font-size:1.2rem; }
.stk-pack-count { background:var(--nintendo-red); color:#fff; border-radius:999px; padding:1px 10px; font-size:.85rem; vertical-align:middle; }
.stk-pack-row { display:flex; gap:14px; flex-wrap:wrap; margin-top:10px; }
.stk-pack { position:relative; overflow:hidden; width:150px; height:200px; border:none; border-radius:10px; cursor:pointer;
    background:linear-gradient(160deg,var(--nintendo-red) 0%,#8e0a14 60%,#5a060c 100%); color:#fff; display:flex; flex-direction:column;
    align-items:center; justify-content:center; gap:8px; transition:transform .15s; }
.stk-pack:hover { transform:translateY(-4px) rotate(-1deg); }
.stk-pack--founders { background:linear-gradient(160deg,#FFD700 0%,#b8860b 60%,#6d4f04 100%); color:#241a00; }
.stk-pack-shine { position:absolute; inset:0; background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.35) 45%,transparent 60%); }
.stk-pack-label { font-weight:900; letter-spacing:1px; font-size:.85rem; }
.stk-pack-size { font-size:.72rem; opacity:.85; }
.stk-pack-cta { border:2px dashed currentColor; border-radius:6px; padding:4px 10px; font-size:.72rem; font-weight:900; letter-spacing:1px; }

.stk-set { margin-bottom:26px; }
.stk-set-head { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
.stk-set-head h2 { font-size:1.15rem; margin:0; }
.stk-set-prog { display:flex; align-items:center; gap:10px; }
.stk-set-bar { width:140px; height:7px; background:#232734; border-radius:4px; overflow:hidden; }
.stk-set-bar > div { height:100%; background:#2ebd59; }
.stk-set-prog span { font-size:.82rem; color:#888; font-weight:700; }
.stk-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(104px,1fr)); gap:12px; margin-top:12px; }

.stk { position:relative; border-radius:10px; overflow:hidden; background:#fff; border:4px solid #fff; outline:1px solid #ddd; aspect-ratio:3/4; display:flex; flex-direction:column; width:100%; max-width:150px; }
.stk--uncommon { border-color:#b4b2a9; }
.stk--rare     { border-color:#efb627; }
.stk--foil     { border-color:#7f77dd; }
.stk-face { flex:1; display:flex; align-items:center; justify-content:center; background:#f4f2ec; position:relative; overflow:hidden; }
.stk-face img { width:100%; height:100%; object-fit:contain; padding:6px; box-sizing:border-box; }
.stk-emoji { font-size:2.2rem; align-items:center; justify-content:center; width:100%; height:100%; }
.stk-sheen { position:absolute; inset:0; pointer-events:none; background:linear-gradient(115deg,transparent 32%,rgba(255,255,255,.55) 46%,transparent 58%); animation: stksheen 2.8s linear infinite; }
@keyframes stksheen { from { transform:translateX(-100%);} to { transform:translateX(100%);} }
.stk-band { background:#222; padding:3px 7px; display:flex; justify-content:space-between; align-items:baseline; gap:4px; }
.stk-title { color:#fff; font-size:.66rem; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.stk-num { color:#999; font-size:.62rem; font-weight:700; flex-shrink:0; }
.stk-dup { position:absolute; top:5px; right:5px; background:rgba(0,0,0,.7); color:#fff; font-size:.68rem; font-weight:900; border-radius:999px; padding:1px 7px; }
.stk-tag { text-align:center; font-size:.68rem; font-weight:900; padding:3px 0; background:#eee; color:#999; }
.stk-tag--new { background:#2ebd59; color:#fff; }
.stk--missing { border-style:dashed; border-color:#3a3a3a; background:#181b25; outline:none; }
.stk--missing .stk-face { background:#181b25; }
.stk-q { color:var(--gray-400); font-size:2rem; font-weight:900; }
.stk--missing .stk-band { background:transparent; justify-content:center; }
.stk-foot { color:#888; font-size:.85rem; margin-top:10px; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
