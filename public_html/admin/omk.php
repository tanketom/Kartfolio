<?php
/**
 * OMKredits — the admin overview of the league's fake money: what is in
 * circulation, where it came from, where it went, who holds it, what the shop
 * sold, what is running now, and anything that should not have happened.
 * The full ledger lives here too (moved off the public Exchange Counter),
 * filterable, with a CSV download.
 *
 * The one write is giving or taking back OMKredits, through omkGift(). There is
 * no refund: the OMK has a no-returns policy.
 *
 * Path: /cdnmk/public_html/admin/omk.php   (clean URL: /admin/omk)
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_once __DIR__ . '/../../private/includes/csrf.php';
require_once __DIR__ . '/../../private/includes/omk.php';
require_admin();
requireModule($pdo, 'omk');

$names = racerNamesMap($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'give') {
    verify_csrf();
    $rid = (int)($_POST['racer_id'] ?? 0);
    $amount = max(-10000, min(10000, (int)($_POST['amount'] ?? 0)));
    $msg = 'Pick a racer and an amount.';
    if (isset($names[$rid]) && $amount !== 0) {
        $moved = omkGift($pdo, $rid, $amount, (string)($_POST['reason'] ?? ''));
        $msg = $moved === 0 ? $names[$rid] . ' has nothing to take back.'
             : ($moved > 0 ? 'Gave ' : 'Took back ') . omkFormat(abs($moved)) . ($moved > 0 ? ' to ' : ' from ') . $names[$rid] . '.';
    }
    $_SESSION['omk_admin_flash'] = $msg;
    header('Location: /admin/omk#balances', true, 303);
    exit;
}
$flash = $_SESSION['omk_admin_flash'] ?? null;
unset($_SESSION['omk_admin_flash']);

// ── The ledger, filtered (also the CSV) ──────────────────────────────────
$f = [
    'racer' => (int)($_GET['racer'] ?? 0),
    'kind'  => (string)($_GET['kind'] ?? ''),
    'from'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '',
    'to'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '',
];
$where = []; $args = [];
if ($f['racer']) { $where[] = 'racer_id = ?'; $args[] = $f['racer']; }
if ($f['kind'] !== '') { $where[] = 'kind = ?'; $args[] = $f['kind']; }
if ($f['from'] !== '') { $where[] = 'date(created_at) >= ?'; $args[] = $f['from']; }
if ($f['to'] !== '') { $where[] = 'date(created_at) <= ?'; $args[] = $f['to']; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (isset($_GET['csv'])) {
    $st = $pdo->prepare("SELECT id, created_at, racer_id, kind, amount, detail FROM omk_ledger $whereSql ORDER BY id DESC");
    $st->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="omk-ledger-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'time', 'racer', 'kind', 'amount', 'detail'], ',', '"', '');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, [$r['id'], $r['created_at'], $names[(int)$r['racer_id']] ?? $r['racer_id'], $r['kind'], $r['amount'], $r['detail']], ',', '"', '');
    }
    exit;
}

$perPage = 50;
$st = $pdo->prepare("SELECT COUNT(*) FROM omk_ledger $whereSql");
$st->execute($args);
$ledgerTotal = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($ledgerTotal / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$st = $pdo->prepare("SELECT id, created_at, racer_id, kind, amount, detail FROM omk_ledger $whereSql ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$ledger = $st->fetchAll(PDO::FETCH_ASSOC);
$kindsInLedger = $pdo->query("SELECT DISTINCT kind FROM omk_ledger ORDER BY kind")->fetchAll(PDO::FETCH_COLUMN);
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== 0)) . '#ledger';

// ── Everything else ──────────────────────────────────────────────────────
$econ = omkEconomy($pdo);
$t = $econ['totals'];
$live = omkLiveNow($pdo);
$bank = omkCentralBankLines($econ, $names);
$issues = omkSanity($pdo, $econ, $names);
$thisWeek = $econ['weeks'][omkWeekOf(time())] ?? ['in' => 0, 'out' => 0];
$lastWeek = $econ['weeks'][omkWeekOf(time() - 7 * 86400)] ?? ['in' => 0, 'out' => 0];

// Two small charts, drawn here as SVG: circulation over time (one series, no legend),
// and each week's in and out about a zero line (two series, legend + direct labels).
$weeks = $econ['weeks'];
$W = 640; $H = 210; $padL = 40; $padR = 12; $padT = 26; $padB = 26;
$n = max(1, count($weeks));
$step = ($W - $padL - $padR) / $n;
$labelEvery = max(1, (int)ceil($n / 8));
$wkLabel = fn(string $wk) => date('j M', strtotime($wk));

$pageTitle = 'OMKredits — Admin';
$extraCss  = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>
<div class="container omk-admin">
    <div class="admin-page-header">
        <h1><?= omkSign() ?> OMKredits</h1>
        <p class="admin-page-sub">Every OMKredit minted, spent and held. All sales are final: the OMK has a no-returns policy.</p>
    </div>
    <?php if ($flash): ?><div class="oa-flash" role="status"><?= omkHtml($flash) ?></div><?php endif; ?>

    <div class="oa-tiles">
        <div class="oa-tile"><div class="oa-tile-value"><?= omkFormatHtml($t['circulation']) ?></div><div class="oa-tile-label">In circulation</div></div>
        <div class="oa-tile"><div class="oa-tile-value"><?= omkFormatHtml($t['minted']) ?></div><div class="oa-tile-label">Minted, all time</div></div>
        <div class="oa-tile"><div class="oa-tile-value"><?= omkFormatHtml($t['spent']) ?></div><div class="oa-tile-label">Spent at the counter</div></div>
        <div class="oa-tile"><div class="oa-tile-value">+<?= (int)$thisWeek['in'] ?> / −<?= (int)$thisWeek['out'] ?></div><div class="oa-tile-label">This week, in / out</div></div>
        <div class="oa-tile"><div class="oa-tile-value">+<?= (int)$lastWeek['in'] ?> / −<?= (int)$lastWeek['out'] ?></div><div class="oa-tile-label">Last week, in / out</div></div>
    </div>
    <?php if ($t['taken'] > 0): ?><p class="oa-note">Admins have also taken back <?= omkFormatHtml($t['taken']) ?>, which counts as neither minted nor spent.</p><?php endif; ?>

    <?php if (!$weeks): ?>
    <section class="oa-card"><p class="oa-note">No OMKredits have moved yet.</p></section>
    <?php else: ?>
    <div class="oa-two">
        <section class="oa-card">
            <h2 class="oa-h">In circulation</h2>
            <?php
            $maxC = max(1, max(array_column($weeks, 'circ')));
            $y = fn($v) => $padT + ($H - $padT - $padB) * (1 - $v / $maxC);
            $pts = []; $i = 0;
            foreach ($weeks as $wk => $w) { $pts[] = [$padL + $step * ($i + .5), $y($w['circ']), $wk, $w['circ']]; $i++; }
            ?>
            <svg class="oa-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="OMKredits in circulation at the end of each week">
                <line class="oa-grid" x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= $y($maxC) ?>" y2="<?= $y($maxC) ?>"/>
                <text class="oa-axis" x="<?= $padL - 6 ?>" y="<?= $y($maxC) + 4 ?>" text-anchor="end"><?= $maxC ?></text>
                <line class="oa-base" x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= $y(0) ?>" y2="<?= $y(0) ?>"/>
                <text class="oa-axis" x="<?= $padL - 6 ?>" y="<?= $y(0) + 4 ?>" text-anchor="end">0</text>
                <?php if (count($pts) > 1): ?><polyline class="oa-line" points="<?= implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $pts)) ?>"/><?php endif; ?>
                <?php foreach ($pts as $k => [$px, $py, $wk, $v]): ?>
                    <g class="oa-hit"><title>Week of <?= $wkLabel($wk) ?>: <?= $v ?> in circulation</title>
                        <rect x="<?= round($px - $step / 2, 1) ?>" y="<?= $padT ?>" width="<?= round($step, 1) ?>" height="<?= $H - $padT - $padB ?>" fill="transparent"/>
                        <circle class="oa-dot" cx="<?= round($px, 1) ?>" cy="<?= round($py, 1) ?>" r="4"/></g>
                    <?php if ($k % $labelEvery === 0): ?><text class="oa-axis" x="<?= round($px, 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= $wkLabel($wk) ?></text><?php endif; ?>
                <?php endforeach; ?>
                <?php $lp = end($pts); ?><text class="oa-value" x="<?= round(min($lp[0], $W - $padR - 4), 1) ?>" y="<?= round($lp[1] - 9, 1) ?>" text-anchor="end"><?= $lp[3] ?></text>
            </svg>
        </section>
        <section class="oa-card">
            <h2 class="oa-h">In and out, each week</h2>
            <?php
            $maxF = max(1, max(array_column($weeks, 'in')), max(array_column($weeks, 'out')));
            $mid = $padT + ($H - $padT - $padB) / 2;
            $half = ($H - $padT - $padB) / 2;
            $bw = max(3, min(28, $step * .6));
            ?>
            <svg class="oa-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="OMKredits minted and spent each week">
                <text class="oa-axis" x="<?= $padL - 6 ?>" y="<?= $padT + 4 ?>" text-anchor="end">+<?= $maxF ?></text>
                <text class="oa-axis" x="<?= $padL - 6 ?>" y="<?= $H - $padB + 4 ?>" text-anchor="end">−<?= $maxF ?></text>
                <?php $i = 0; foreach ($weeks as $wk => $w): $cx = $padL + $step * ($i + .5); $hi = $half * $w['in'] / $maxF; $ho = $half * $w['out'] / $maxF; ?>
                    <g class="oa-hit"><title>Week of <?= $wkLabel($wk) ?>: +<?= $w['in'] ?> in, −<?= $w['out'] ?> out</title>
                        <rect x="<?= round($cx - $step / 2, 1) ?>" y="<?= $padT ?>" width="<?= round($step, 1) ?>" height="<?= $H - $padT - $padB ?>" fill="transparent"/>
                        <?php if ($hi > 0): ?><path class="oa-in" d="<?= sprintf('M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z', $cx - $bw / 2, $mid - 1, $mid - 1 - $hi + min(4, $hi), $cx - $bw / 2, $mid - 1 - $hi, $cx - $bw / 2 + min(4, $hi), $mid - 1 - $hi, $cx + $bw / 2 - min(4, $hi), $cx + $bw / 2, $mid - 1 - $hi, $cx + $bw / 2, $mid - 1 - $hi + min(4, $hi), $mid - 1) ?>"/><?php endif; ?>
                        <?php if ($ho > 0): ?><path class="oa-out" d="<?= sprintf('M%.1f %.1f V%.1f Q%.1f %.1f %.1f %.1f H%.1f Q%.1f %.1f %.1f %.1f V%.1f Z', $cx - $bw / 2, $mid + 1, $mid + 1 + $ho - min(4, $ho), $cx - $bw / 2, $mid + 1 + $ho, $cx - $bw / 2 + min(4, $ho), $mid + 1 + $ho, $cx + $bw / 2 - min(4, $ho), $cx + $bw / 2, $mid + 1 + $ho, $cx + $bw / 2, $mid + 1 + $ho - min(4, $ho), $mid + 1) ?>"/><?php endif; ?>
                    </g>
                    <?php if ($i % $labelEvery === 0): ?><text class="oa-axis" x="<?= round($cx, 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= $wkLabel($wk) ?></text><?php endif; ?>
                <?php $i++; endforeach; ?>
                <line class="oa-base" x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= $mid ?>" y2="<?= $mid ?>"/>
            </svg>
            <div class="oa-legend"><span><i class="oa-sw oa-sw--in"></i>Minted</span><span><i class="oa-sw oa-sw--out"></i>Spent or taken back</span></div>
        </section>
    </div>

    <div class="oa-two">
        <section class="oa-card">
            <h2 class="oa-h">Where it came from</h2>
            <table class="admin-table oa-table">
                <thead><tr><th>Source</th><th class="oa-num">Minted</th><th class="oa-num">Share</th></tr></thead>
                <tbody>
                <?php foreach ($econ['sources'] as $kind => $sum): ?>
                    <tr><td><?= htmlspecialchars(omkKindLabel($kind)) ?></td><td class="oa-num"><?= omkFormatHtml($sum) ?></td><td class="oa-num"><?= round(100 * $sum / max(1, $t['minted'])) ?>%</td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <section class="oa-card">
            <h2 class="oa-h">Central bank report</h2>
            <ul class="oa-bank">
                <?php foreach ($bank as $line): ?><li><?= omkHtml($line) ?></li><?php endforeach; ?>
            </ul>
        </section>
    </div>
    <?php endif; ?>

    <section class="oa-card audit-section--<?= $issues ? 'bad' : 'ok' ?>">
        <div class="audit-head"><h2 class="oa-h">Sanity checks</h2><span class="audit-count"><?= $issues ? count($issues) : '✓ clear' ?></span></div>
        <?php if (!$issues): ?>
            <p class="oa-note">No negative balances, double-paid weeks, stuck bribes or stray rows.</p>
        <?php else: ?>
        <table class="admin-table audit-table">
            <tbody>
            <?php foreach ($issues as [$what, $detail, $link, $linkText]): ?>
                <tr><td class="audit-label"><?= htmlspecialchars($what) ?></td><td><?= omkHtml($detail) ?></td>
                    <td class="audit-fix"><?php if ($link): ?><a href="<?= htmlspecialchars($link) ?>" class="btn-secondary btn-sm"><?= htmlspecialchars($linkText) ?> →</a><?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="oa-card" id="balances">
        <h2 class="oa-h">Balances</h2>
        <p class="oa-note">Click a heading to sort. A minus takes OMKredits back, never below zero.</p>
        <div class="admin-table-container">
        <table class="admin-table oa-table oa-sortable">
            <thead><tr><th data-sort="text">Racer</th><th class="oa-num" data-sort="num">Balance</th><th class="oa-num" data-sort="num">Earned</th><th class="oa-num" data-sort="num">Spent</th><th data-sort="text">Spends most on</th><th data-sort="text">Last activity</th><th>Give or take</th></tr></thead>
            <tbody>
            <?php foreach ($econ['accounts'] as $rid => $a): ?>
                <tr>
                    <td><a href="/racer/<?= (int)$rid ?>"><?= htmlspecialchars($names[$rid] ?? "racer $rid") ?></a></td>
                    <td class="oa-num" data-v="<?= $a['balance'] ?>"><strong><?= omkFormatHtml($a['balance']) ?></strong></td>
                    <td class="oa-num" data-v="<?= $a['earned'] ?>"><?= omkFormatHtml($a['earned']) ?></td>
                    <td class="oa-num" data-v="<?= $a['spent'] ?>"><?= omkFormatHtml($a['spent']) ?></td>
                    <td><?= $a['top'] ? htmlspecialchars(omkKindLabel($a['top'])) : '—' ?></td>
                    <td data-v="<?= htmlspecialchars((string)$a['last']) ?>"><?= $a['last'] ? date('j M Y', strtotime($a['last'])) : '—' ?></td>
                    <td>
                        <form method="POST" class="oa-give">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="give">
                            <input type="hidden" name="racer_id" value="<?= (int)$rid ?>">
                            <input type="number" name="amount" step="1" required placeholder="±" aria-label="Amount for <?= htmlspecialchars($names[$rid] ?? '') ?>">
                            <input type="text" name="reason" maxlength="80" placeholder="Reason" aria-label="Reason">
                            <button type="submit" class="btn-secondary btn-sm">Go</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="oa-note">Racers with no account yet, and giving to everyone at once, are on the <a href="/admin/sticker-board#omk">Sticker Board</a>.</p>
    </section>

    <div class="oa-two">
        <section class="oa-card">
            <h2 class="oa-h">Shop sales</h2>
            <?php if (!$econ['sales']): ?><p class="oa-note">Nothing sold yet.</p><?php else: ?>
            <table class="admin-table oa-table">
                <thead><tr><th>Item</th><th class="oa-num">Sold</th><th class="oa-num">Taken</th><th>Last sale</th></tr></thead>
                <tbody>
                <?php foreach ($econ['sales'] as $kind => $s): ?>
                    <tr><td><?= htmlspecialchars(omkKindLabel($kind)) ?></td><td class="oa-num"><?= $s['units'] ?></td><td class="oa-num"><?= omkFormatHtml($s['revenue']) ?></td><td><?= date('j M', strtotime($s['last'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
        <section class="oa-card">
            <h2 class="oa-h">Running now</h2>
            <?php
            $nm = fn($rid) => htmlspecialchars($names[(int)$rid] ?? '?');
            $nowLines = [];
            foreach ($live['plates'] as $rid => $p) $nowLines[] = '🏷️ ' . $nm($rid) . ': <span class="' . trim(omkPlateClass($p['colour'])) . '">' . htmlspecialchars(OMK_PLATES[$p['colour']] ?? 'Gold') . '</span> nameplate until ' . date('j M', $p['until']);
            foreach ($live['rentals'] as $rid => $u) $nowLines[] = '🌈 ' . $nm($rid) . ': holographic rental until ' . date('j M', $u);
            foreach ($live['billboards'] as $rid => $u) $nowLines[] = '📢 ' . $nm($rid) . ': on the billboard until ' . date('j M, H:i', $u);
            foreach ($live['hype'] as $rid => $left) $nowLines[] = '🎤 ' . $nm($rid) . ": hyped for $left more GP" . ($left === 1 ? '' : 's');
            foreach ($live['bribes'] as $b) $nowLines[] = '✉️ ' . htmlspecialchars($b['name']) . ': ' . htmlspecialchars($b['status']) . ' bribe for ' . htmlspecialchars($b['cup_name']) . ' Cup' . ($b['counter_name'] ? ', countered by ' . htmlspecialchars($b['counter_name']) : '');
            foreach ($live['sponsors'] as $i => $s) $nowLines[] = '📺 ' . htmlspecialchars($s['name']) . ($i === 0 ? ': sponsors the next newscast' : ': in the sponsor queue, #' . ($i + 1));
            ?>
            <?php if (!$nowLines): ?><p class="oa-note">Nothing bought is running right now.</p><?php else: ?>
            <ul class="oa-bank"><?php foreach ($nowLines as $l): ?><li><?= $l ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
    </div>

    <section class="oa-card" id="ledger">
        <h2 class="oa-h">The ledger <small class="oa-note"><?= number_format($ledgerTotal) ?> entr<?= $ledgerTotal === 1 ? 'y' : 'ies' ?></small></h2>
        <form method="GET" class="oa-filters" action="/admin/omk#ledger">
            <select name="racer" aria-label="Racer"><option value="">All racers</option>
                <?php foreach ($names as $rid => $name): ?><option value="<?= (int)$rid ?>" <?= $f['racer'] === (int)$rid ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
            </select>
            <select name="kind" aria-label="Type"><option value="">All types</option>
                <?php foreach ($kindsInLedger as $k): ?><option value="<?= htmlspecialchars($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= htmlspecialchars(omkKindLabel($k)) ?></option><?php endforeach; ?>
            </select>
            <label>From <input type="date" name="from" value="<?= htmlspecialchars($f['from']) ?>"></label>
            <label>To <input type="date" name="to" value="<?= htmlspecialchars($f['to']) ?>"></label>
            <button type="submit" class="btn-secondary btn-sm">Filter</button>
            <a href="/admin/omk#ledger" class="btn-secondary btn-sm">Clear</a>
            <a href="<?= htmlspecialchars(str_replace('#ledger', '', $qs(['csv' => 1]))) ?>" class="btn-secondary btn-sm">Download CSV</a>
        </form>
        <?php if (!$ledger): ?><p class="oa-note">No entries match.</p><?php else: ?>
        <div class="admin-table-container">
        <table class="admin-table oa-table">
            <thead><tr><th>When</th><th>Racer</th><th>Type</th><th>What</th><th class="oa-num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($ledger as $l): $amt = (int)$l['amount']; ?>
                <tr>
                    <td class="oa-when"><?= date('j M Y, H:i', strtotime($l['created_at'])) ?></td>
                    <td><?= htmlspecialchars($names[(int)$l['racer_id']] ?? '?') ?></td>
                    <td><?= htmlspecialchars(omkKindLabel((string)$l['kind'])) ?></td>
                    <td><?= omkHtml((string)$l['detail']) ?></td>
                    <td class="oa-num <?= $amt > 0 ? 'oa-plus' : ($amt < 0 ? 'oa-minus' : '') ?>"><?= ($amt > 0 ? '+' : ($amt < 0 ? '−' : '')) . omkFormatHtml(abs($amt)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="oa-pager">
            <?php if ($page > 1): ?><a href="<?= htmlspecialchars($qs(['page' => $page - 1])) ?>" class="btn-secondary btn-sm">← Newer</a><?php endif; ?>
            <span>Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= htmlspecialchars($qs(['page' => $page + 1])) ?>" class="btn-secondary btn-sm">Older →</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<script>
// Sort the balances by a heading: numbers by data-v, text by its words. Click again to reverse.
document.querySelectorAll('.oa-sortable th[data-sort]').forEach((th, col) => {
    th.addEventListener('click', () => {
        const body = th.closest('table').tBodies[0];
        const dir = th.dataset.dir === 'desc' ? 'asc' : 'desc';
        th.closest('tr').querySelectorAll('th').forEach((h) => delete h.dataset.dir);
        th.dataset.dir = dir;
        const key = (tr) => { const td = tr.cells[col]; return th.dataset.sort === 'num' ? parseFloat(td.dataset.v) : (td.dataset.v ?? td.textContent.trim().toLowerCase()); };
        [...body.rows].sort((a, b) => { const x = key(a), y = key(b); return (x < y ? -1 : x > y ? 1 : 0) * (dir === 'asc' ? 1 : -1); }).forEach((r) => body.appendChild(r));
    });
});
</script>
<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
