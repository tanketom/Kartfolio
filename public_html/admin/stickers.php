<?php
/**
 * Sticker Admin Board
 * Path: /cdnmk/public_html/admin/stickers.php   Route: /admin/sticker-board
 *
 * Six panels: grant packs · grant a specific card · per-racer inspector ·
 * league scarcity · completion board · catalog browser + art coverage.
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/csrf.php';
require_once __DIR__ . '/../../private/includes/stickers.php';
require_admin();

$racers = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── POST actions (PRG) ──────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $msg = '';

    if ($action === 'grant_packs') {
        $count  = max(1, min(20, (int)($_POST['count'] ?? 1)));
        $size   = max(1, min(10, (int)($_POST['size'] ?? STICKER_PACK_SIZE)));
        $source = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['source'] ?? 'gift')) ?: 'gift';
        $scope  = $_POST['scope'] ?? 'one';
        $targets = $scope === 'all'
            ? array_map(fn($r) => (int)$r['id'], $racers)
            : [max(0, (int)($_POST['racer_id'] ?? 0))];
        $targets = array_filter($targets);
        $packs = 0;
        foreach ($targets as $rid) $packs += grantAdminPacks($pdo, $rid, $count, $size, $source);
        $who = $scope === 'all' ? count($targets) . ' racers' : 'one racer';
        $msg = "Granted $packs pack" . ($packs === 1 ? '' : 's') . " (size $size, “$source”) to $who.";
    } elseif ($action === 'grant_sticker') {
        $rid = (int)($_POST['racer_id'] ?? 0);
        $key = $_POST['sticker_key'] ?? '';
        $ok  = $rid > 0 && grantSticker($pdo, $rid, $key);
        $defs = stickerByKey($pdo);
        $msg = $ok ? "Awarded “" . ($defs[$key]['title'] ?? $key) . "”." : "Could not award that card.";
    }
    header('Location: /admin/sticker-board?msg=' . rawurlencode($msg) . (isset($_POST['racer_id']) ? '&racer=' . (int)$_POST['racer_id'] : ''));
    exit;
}

// ── Data ────────────────────────────────────────────────────────────────
$catalog = stickerCatalog($pdo);
$byKey   = stickerByKey($pdo);
$bySet   = [];
foreach ($catalog as $s) $bySet[$s['set']][] = $s;

$own       = stickerOwnershipStats($pdo);
$board     = stickerCompletionBoard($pdo);
$unopened  = unopenedPackCounts($pdo);
$totalCards = count($catalog);

// Art coverage — bespoke-art cards are those with no existing asset (img null).
$needArt = array_filter($catalog, fn($s) => empty($s['img']));
$missingArt = array_values(array_filter($needArt, fn($s) => !stickerHasArt($s)));
$bespokeTotal = count($needArt);
$bespokeHave  = $bespokeTotal - count($missingArt);

// Per-racer inspector
$inspectId = (int)($_GET['racer'] ?? 0);
$inspect = null;
if ($inspectId > 0) {
    $r = $pdo->prepare("SELECT id, name FROM racers WHERE id = ?"); $r->execute([$inspectId]);
    if ($who = $r->fetch(PDO::FETCH_ASSOC)) {
        $album = racerAlbum($pdo, $inspectId);
        $prog  = albumProgress($pdo, $inspectId);
        $dupes = array_filter($album, fn($c) => $c > 1);
        $inspect = ['racer' => $who, 'album' => $album, 'prog' => $prog,
                    'dupes' => $dupes, 'unopened' => $unopened[$inspectId] ?? 0];
    }
}

$setMeta = ['lore'=>'📜 Lore','racers'=>'🏎️ Racers','cups'=>'🏆 Cups','characters'=>'🎮 Characters','items'=>'🎁 Items','tracks'=>'🏁 Tracks','moments'=>'💎 Moments'];
$flash = trim((string)($_GET['msg'] ?? ''));
$setIconFor = fn($s) => stickerArtUrl($s);

$pageTitle = "Sticker Board - Admin";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a><span class="breadcrumb-separator">/</span>
        <a href="/admin">Admin</a><span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Sticker Board</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🩹 Sticker Board</h1>
        <p class="page-subtitle"><?= $totalCards ?> CARDS · <?= $bespokeHave ?>/<?= $bespokeTotal ?> BESPOKE ARTS · LAUNCHES <?= htmlspecialchars(stickersEpoch($pdo)) ?></p>
    </header>

    <?php if ($flash): ?><div class="sb-flash">✅ <?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <nav class="sb-subnav">
        <a href="#grant">Give packs</a><a href="#award">Award a card</a><a href="#inspect">Racer inspector</a>
        <a href="#scarcity">Scarcity</a><a href="#completion">Completion</a><a href="#catalog">Catalog &amp; art</a>
    </nav>

    <!-- ── GIVE PACKS ─────────────────────────────────────────────── -->
    <section class="sb-card" id="grant">
        <h2>🎁 Give packs</h2>
        <form method="POST" class="sb-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="grant_packs">
            <div class="sb-row">
                <label>Who
                    <select name="scope" id="sb-scope" onchange="document.getElementById('sb-one').style.display=this.value==='one'?'block':'none';">
                        <option value="one">One racer</option>
                        <option value="all">Everyone (<?= count($racers) ?>)</option>
                    </select>
                </label>
                <label id="sb-one">Racer
                    <select name="racer_id">
                        <?php foreach ($racers as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Packs each <input type="number" name="count" value="1" min="1" max="20"></label>
                <label>Size
                    <select name="size"><option value="<?= STICKER_PACK_SIZE ?>"><?= STICKER_PACK_SIZE ?> (standard)</option><option value="<?= STICKER_FOUNDERS_SIZE ?>"><?= STICKER_FOUNDERS_SIZE ?> (founders)</option></select>
                </label>
                <label>Label <input type="text" name="source" value="gift" maxlength="20" placeholder="gift / event"></label>
            </div>
            <button type="submit" class="btn btn-primary">Grant packs</button>
            <small class="sb-note">Packs land unopened in each racer's queue; contents are fixed at grant time and revealed when they open them.</small>
        </form>
    </section>

    <!-- ── AWARD A SPECIFIC CARD ──────────────────────────────────── -->
    <section class="sb-card" id="award">
        <h2>🎯 Award a specific card</h2>
        <form method="POST" class="sb-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="grant_sticker">
            <div class="sb-row">
                <label>Racer
                    <select name="racer_id">
                        <?php foreach ($racers as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Card
                    <select name="sticker_key">
                        <?php foreach ($bySet as $set => $items): ?>
                            <optgroup label="<?= htmlspecialchars($setMeta[$set] ?? $set) ?>">
                                <?php foreach ($items as $s): ?>
                                    <option value="<?= htmlspecialchars($s['key']) ?>">#<?= str_pad($s['num'],3,'0',STR_PAD_LEFT) ?> <?= htmlspecialchars($s['title']) ?> (<?= $s['rarity'] ?>)</option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <button type="submit" class="btn btn-primary">Award card</button>
            <small class="sb-note">Drops the exact card straight into their album (duplicates stack). Use for achievement cards like Tournament Champion.</small>
        </form>
    </section>

    <!-- ── RACER INSPECTOR ────────────────────────────────────────── -->
    <section class="sb-card" id="inspect">
        <h2>🔍 Racer inspector</h2>
        <form method="GET" class="sb-form">
            <label>Pick a racer
                <select name="racer" onchange="this.form.submit()">
                    <option value="0">— choose —</option>
                    <?php foreach ($racers as $r): ?><option value="<?= $r['id'] ?>" <?= $inspectId===(int)$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if ($inspect): $a = $inspect['prog']['_album']; ?>
            <div class="sb-inspect-head">
                <strong><?= htmlspecialchars($inspect['racer']['name']) ?></strong> ·
                <?= $a['owned'] ?>/<?= $a['total'] ?> cards (<?= $a['total'] ? round($a['owned']/$a['total']*100) : 0 ?>%) ·
                <?= count($inspect['dupes']) ?> with duplicates ·
                <?= $inspect['unopened'] ?> unopened pack<?= $inspect['unopened']===1?'':'s' ?> ·
                <a href="/stickers/<?= (int)$inspect['racer']['id'] ?>" target="_blank">open album →</a>
            </div>
            <div class="sb-setbars">
                <?php foreach ($inspect['prog'] as $set => $p): if ($set === '_album') continue; ?>
                    <div class="sb-setbar"><span><?= htmlspecialchars($setMeta[$set] ?? $set) ?></span>
                        <span class="sb-setbar-track"><span style="width:<?= $p['total']?round($p['owned']/$p['total']*100):0 ?>%"></span></span>
                        <span class="sb-setbar-n"><?= $p['owned'] ?>/<?= $p['total'] ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="sb-note">Choose a racer to see completion, per-set progress, duplicates, and unopened packs.</p><?php endif; ?>
    </section>

    <!-- ── COMPLETION BOARD ───────────────────────────────────────── -->
    <section class="sb-card" id="completion">
        <h2>🏅 Completion leaderboard</h2>
        <div class="sb-complete-grid">
            <?php foreach ($board as $i => $b): if ($b['owned'] == 0 && $i > 12) continue; ?>
                <a class="sb-complete-row" href="?racer=<?= (int)$b['id'] ?>#inspect">
                    <span class="sb-cr-rank">#<?= $i+1 ?></span>
                    <span class="sb-cr-name"><?= htmlspecialchars($b['name']) ?></span>
                    <span class="sb-cr-track"><span style="width:<?= round($b['owned']/max(1,$totalCards)*100) ?>%"></span></span>
                    <span class="sb-cr-n"><?= $b['owned'] ?>/<?= $totalCards ?><?= ($unopened[$b['id']]??0) ? ' · '.$unopened[$b['id']].'📦' : '' ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ── SCARCITY ───────────────────────────────────────────────── -->
    <section class="sb-card" id="scarcity">
        <h2>💠 Scarcity — who holds what</h2>
        <p class="sb-note">Owners = distinct racers holding it · Copies = total across the league. Rarest first within each set.</p>
        <?php foreach ($bySet as $set => $items):
            usort($items, fn($x,$y) => ($own[$x['key']]['owners']??0) <=> ($own[$y['key']]['owners']??0));
        ?>
        <details class="sb-scar-set">
            <summary><?= htmlspecialchars($setMeta[$set] ?? $set) ?> <span class="sb-scar-count"><?= count($items) ?></span></summary>
            <table class="sb-scar-table">
                <thead><tr><th>#</th><th>Card</th><th>Rarity</th><th>Owners</th><th>Copies</th></tr></thead>
                <tbody>
                <?php foreach ($items as $s): $o = $own[$s['key']] ?? ['owners'=>0,'copies'=>0]; ?>
                    <tr class="<?= $o['owners']===0?'sb-uncollected':'' ?>">
                        <td><?= str_pad($s['num'],3,'0',STR_PAD_LEFT) ?></td>
                        <td><?= htmlspecialchars($s['title']) ?></td>
                        <td><span class="sb-rar sb-rar--<?= $s['rarity'] ?>"><?= $s['rarity'] ?></span></td>
                        <td><?= $o['owners'] ?></td>
                        <td><?= $o['copies'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endforeach; ?>
    </section>

    <!-- ── CATALOG + ART COVERAGE ─────────────────────────────────── -->
    <section class="sb-card" id="catalog">
        <h2>🖼️ Catalog &amp; art coverage</h2>
        <p class="sb-note">
            <strong><?= $bespokeHave ?>/<?= $bespokeTotal ?></strong> bespoke arts in place.
            <?php if ($missingArt): ?>Still on emoji fallback (<?= count($missingArt) ?>): drop a PNG named as shown into <code>assets/img/stickers/</code>.<?php else: ?>All bespoke art present 🎉<?php endif; ?>
        </p>
        <?php if ($missingArt): ?>
        <details class="sb-missing">
            <summary>Missing-art filenames (<?= count($missingArt) ?>)</summary>
            <ul class="sb-missing-list">
                <?php foreach ($missingArt as $s): ?><li><code><?= htmlspecialchars($s['slug']) ?>.png</code> <span class="sb-em">(or .jpg)</span> — <?= htmlspecialchars($s['title']) ?> <span class="sb-em"><?= $s['emoji'] ?></span></li><?php endforeach; ?>
            </ul>
        </details>
        <?php endif; ?>
        <?php foreach ($bySet as $set => $items): ?>
            <h3 class="sb-set-h"><?= htmlspecialchars($setMeta[$set] ?? $set) ?> <span class="sb-scar-count"><?= count($items) ?></span></h3>
            <div class="sb-cat-grid">
                <?php foreach ($items as $s): $hasArt = stickerHasArt($s); ?>
                    <div class="sb-cat-cell <?= $hasArt?'':'sb-noart' ?> sb-rarbox--<?= $s['rarity'] ?>" title="<?= htmlspecialchars($s['title']) ?> · <?= $s['rarity'] ?><?= $hasArt?'':' · emoji fallback' ?>">
                        <div class="sb-cat-face">
                            <img src="<?= htmlspecialchars($setIconFor($s)) ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <span class="sb-cat-emoji" style="display:none"><?= $s['emoji'] ?></span>
                        </div>
                        <div class="sb-cat-num">#<?= str_pad($s['num'],3,'0',STR_PAD_LEFT) ?><?= $hasArt?'':' ⚠️' ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>
</div>

<style>
.sb-flash { background:var(--success-bg,#e6f6ec); color:var(--success-text,#157347); border:2px solid var(--ink); border-radius:10px; padding:10px 16px; margin-bottom:16px; font-weight:700; box-shadow:3px 3px 0 var(--ink); }
.sb-subnav { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
.sb-subnav a { background:var(--gray-50); border:2px solid var(--ink); border-radius:999px; padding:5px 14px; text-decoration:none; font-weight:700; font-size:0.85rem; box-shadow:2px 2px 0 var(--ink); color:var(--gray-900); }
.sb-subnav a:hover { background:#fff6dc; }
.sb-card { background:var(--gray-50); border:2.5px solid var(--ink); border-radius:16px; box-shadow:4px 4px 0 var(--ink); padding:20px 24px; margin-bottom:22px; }
.sb-card h2 { margin:0 0 14px; font-size:1.3rem; }
.sb-form { display:flex; flex-direction:column; gap:12px; }
.sb-row { display:flex; flex-wrap:wrap; gap:14px; align-items:end; }
.sb-form label { display:flex; flex-direction:column; gap:4px; font-size:0.78rem; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; color:var(--gray-600); }
.sb-form select, .sb-form input { background:var(--gray-50); border:2px solid var(--gray-300); border-radius:8px; padding:9px 11px; font:inherit; font-size:15px; }
.sb-note { color:var(--gray-600); font-size:0.8rem; font-style:italic; }
.sb-inspect-head { margin:6px 0 14px; font-size:0.95rem; }
.sb-setbars { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
.sb-setbar { display:flex; align-items:center; gap:8px; font-size:0.82rem; }
.sb-setbar > span:first-child { min-width:110px; }
.sb-setbar-track, .sb-cr-track { flex:1; height:9px; background:var(--gray-200); border:1.5px solid var(--ink); border-radius:5px; overflow:hidden; }
.sb-setbar-track > span, .sb-cr-track > span { display:block; height:100%; background:linear-gradient(90deg,#2ebd59,#ffd700); }
.sb-setbar-n, .sb-cr-n { min-width:60px; text-align:right; font-family:var(--font-mono); font-size:0.78rem; }
.sb-complete-grid { display:flex; flex-direction:column; gap:6px; }
.sb-complete-row { display:flex; align-items:center; gap:12px; text-decoration:none; color:var(--gray-900); padding:6px 10px; border:2px solid var(--gray-300); border-radius:8px; }
.sb-complete-row:hover { border-color:var(--ink); background:#fff6dc; }
.sb-cr-rank { font-family:var(--font-display); font-weight:700; color:var(--gray-500); min-width:36px; }
.sb-cr-name { font-weight:800; min-width:120px; }
.sb-scar-set, .sb-missing { margin-bottom:10px; }
.sb-scar-set summary, .sb-missing summary { cursor:pointer; font-weight:800; padding:6px 0; }
.sb-scar-count { background:var(--gray-200); border-radius:999px; padding:1px 9px; font-size:0.75rem; font-weight:700; }
.sb-scar-table { width:100%; border-collapse:collapse; font-size:0.85rem; margin:6px 0 12px; }
.sb-scar-table th { text-align:left; color:var(--gray-500); font-size:0.7rem; text-transform:uppercase; border-bottom:2px solid var(--gray-200); padding:6px 8px; }
.sb-scar-table td { padding:6px 8px; border-bottom:1px solid var(--gray-200); }
.sb-scar-table .sb-uncollected { color:var(--gray-500); }
.sb-rar { font-size:0.68rem; font-weight:800; text-transform:uppercase; padding:1px 7px; border-radius:999px; border:1px solid var(--ink); }
.sb-rar--common{background:var(--gray-100)} .sb-rar--uncommon{background:#e2f4fc} .sb-rar--rare{background:#efe9fb} .sb-rar--foil{background:#fff6dc}
.sb-missing-list { columns:2; font-size:0.82rem; line-height:1.7; } .sb-missing-list code { background:var(--gray-100); padding:1px 5px; border-radius:4px; }
.sb-set-h { font-size:1rem; margin:18px 0 8px; }
.sb-cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(76px,1fr)); gap:8px; }
.sb-cat-cell { border:2px solid var(--ink); border-radius:8px; overflow:hidden; background:#fff; }
.sb-cat-cell.sb-noart { border-style:dashed; opacity:0.85; }
.sb-cat-face { aspect-ratio:1; display:flex; align-items:center; justify-content:center; background:#f4f2ec; }
.sb-cat-face img { width:100%; height:100%; object-fit:contain; padding:4px; box-sizing:border-box; }
.sb-cat-emoji { font-size:1.8rem; align-items:center; justify-content:center; width:100%; height:100%; }
.sb-cat-num { font-family:var(--font-mono); font-size:0.6rem; text-align:center; padding:2px; color:var(--gray-600); }
.sb-rarbox--foil { box-shadow:inset 0 0 0 2px var(--gold); }
.sb-em { opacity:0.7; }
@media (max-width:600px){ .sb-missing-list{columns:1} }
</style>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
