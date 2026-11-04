<?php
/**
 * The OMK Exchange Counter — /omk-exchange[?racer=ID]
 *
 * Trade in duplicate stickers for OMKredits (Ω), and spend them on bribes and
 * sponsorships. Anyone can read it; spending takes the racer's own profile code.
 * All the rules are in private/includes/omk.php. This page only asks and answers.
 */
require_once __DIR__ . '/../private/includes/session.php';
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';
requireModule($pdo, 'omk');   // Admin → Modules
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/throttle.php';
require_once __DIR__ . '/../private/includes/profile_codes.php';
require_once __DIR__ . '/../private/includes/omk.php';
if (session_status() === PHP_SESSION_NONE) kartfolioSessionStart();

$names = racerNamesMap($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $rid = (int)($_POST['racer_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $ok = false;
    if (!isset($names[$rid]) || !in_array($action, ['trade', 'bribe', 'counter', 'sponsor'], true)) {
        $msg = 'Pick your name and what you came for.';
    } elseif (!throttleAllow($pdo, 'omk_exchange', 10, 10)) {
        $msg = 'Too many attempts from this connection. Wait ten minutes and try again.';
    } elseif (!profileHasCode($pdo, $rid)) {
        $msg = $names[$rid] . ' has no profile code yet. A commissioner issues them on the racers page.';
    } elseif (!profileCodeCheck($pdo, $rid, (string)($_POST['code'] ?? ''))) {
        $msg = "That code doesn't match.";
    } else {
        try {
            switch ($action) {
                case 'trade':
                    $t = omkTradeIn($pdo, $rid, (array)($_POST['qty'] ?? []));
                    $msg = 'Traded ' . $t['copies'] . ' duplicate' . ($t['copies'] === 1 ? '' : 's') . ' for ' . omkFormat($t['credits']) . '. A pleasure doing business.';
                    break;
                case 'bribe':
                    $f = omkBuyBribe($pdo, $rid);
                    $msg = "Kartificial has your envelope. {$f['cup']} Cup comes up about 1 draw in " . OMK_BRIBE_ODDS . " until you next race.";
                    break;
                case 'counter':
                    $b = omkBuyCounter($pdo, $rid, (int)($_POST['bribe_id'] ?? 0));
                    $msg = "{$b['name']}'s bribe is off. He will mention you both.";
                    break;
                case 'sponsor':
                    omkBuySponsor($pdo, $rid);
                    $msg = 'Sold. Your name goes in the next ad break.';
                    break;
            }
            $ok = true;
        } catch (OmkRefused $e) {
            $msg = $e->getMessage();
        } catch (Throwable $e) {
            error_log('omk exchange: ' . $e->getMessage());
            $msg = 'Something went wrong; nothing was spent.';
        }
    }
    $_SESSION['omk_flash'] = ['msg' => $msg, 'ok' => $ok];
    header('Location: /omk-exchange' . (isset($names[$rid]) ? '?racer=' . $rid : ''), true, 303);
    exit;
}

$flash = $_SESSION['omk_flash'] ?? null;
unset($_SESSION['omk_flash']);

$racerId = (int)($_GET['racer'] ?? 0);
if (!isset($names[$racerId])) $racerId = 0;

$roster = $pdo->query("SELECT id, name FROM racers WHERE COALESCE(is_retired, 0) = 0 ORDER BY name COLLATE NOCASE ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$balances = omkBalances($pdo);
$bribes = omkOpenBribes($pdo);
$pending = array_values(array_filter($bribes, fn($b) => $b['status'] === 'pending'));
$queue = omkSponsorQueue($pdo);

$dupes = []; $fav = null; $balance = 0; $hasCode = false; $copiesHeld = 0; $mostOfOne = 0;
if ($racerId) {
    $dupes = omkDuplicates($pdo, $racerId);
    $fav = omkFavouredCup($pdo, $racerId);
    $balance = $balances[$racerId] ?? 0;
    $hasCode = profileHasCode($pdo, $racerId);
    foreach (racerAlbum($pdo, $racerId) as $count) { $copiesHeld += (int)$count; $mostOfOne = max($mostOfOne, (int)$count); }
}
$counterable = array_values(array_filter($pending, fn($b) => (int)$b['racer_id'] !== $racerId));
$myBribe = null;
foreach ($pending as $b) if ((int)$b['racer_id'] === $racerId) $myBribe = $b;
$mySponsor = (bool)array_filter($queue, fn($s) => (int)$s['racer_id'] === $racerId);

$ledger = $pdo->query("SELECT l.amount, l.detail, l.created_at, l.racer_id FROM omk_ledger l
                       WHERE l.kind != 'race_week' ORDER BY l.id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'OMK Exchange Counter';
$ogTitle = 'The OMK Exchange Counter';
$ogDescription = 'Trade in your duplicates for OMKredits. Bribe the host. Sponsor the news. All sales final.';
$extraCss = '<link rel="stylesheet" href="/assets/css/omk.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container omk-root">
    <nav class="breadcrumb">
        <a href="/">← Home</a><span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">OMK Exchange Counter</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title"><span class="omk-sigil"><?= OMK_SYMBOL ?></span> OMK Exchange Counter</h1>
        <p class="page-subtitle">ALL SALES FINAL · RATES SET BY THE OMK, WHO ANSWER TO NOBODY</p>
    </header>

    <?php if ($flash): ?><div class="omk-flash <?= $flash['ok'] ? 'is-ok' : 'is-no' ?>" role="status"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

    <section class="omk-card">
        <h2 class="omk-h">How OMKredits work</h2>
        <ul class="omk-rules">
            <li><strong><?= OMK_PER_WEEK . OMK_SYMBOL ?> for every week you race</strong>, however many GPs. Paid when the GP is saved.</li>
            <li><strong>Trade in duplicate stickers</strong>: <?= omkDupeValue('common') . OMK_SYMBOL ?> each, or <?= omkDupeValue('foil') . OMK_SYMBOL ?> for a foil. You always keep one of each card.</li>
            <li>Nothing here changes your points, your rank or your Elo.</li>
        </ul>
        <form method="GET" class="omk-who">
            <label for="omk-racer">Who's at the counter?</label>
            <select id="omk-racer" name="racer" onchange="this.form.submit()">
                <option value="">Choose your name</option>
                <?php foreach ($roster as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $racerId ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-secondary btn-sm">Go</button></noscript>
        </form>
    </section>

    <?php if ($racerId): ?>
    <form method="POST" autocomplete="off" id="omk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="racer_id" value="<?= $racerId ?>">

        <section class="omk-card omk-account">
            <div>
                <div class="omk-balance"><?= omkFormat($balance) ?></div>
                <div class="omk-note"><?= htmlspecialchars($names[$racerId]) ?>'s balance</div>
            </div>
            <div class="omk-code">
                <?php if ($hasCode): ?>
                    <label for="omk-code-input">Your profile code, to spend or trade</label>
                    <input type="text" id="omk-code-input" name="code" required placeholder="Profile code" autocapitalize="characters" spellcheck="false">
                <?php else: ?>
                    <p class="omk-note"><?= htmlspecialchars($names[$racerId]) ?> has no profile code yet. A commissioner issues them on the racers page.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="omk-card">
            <h2 class="omk-h">Buy</h2>
            <div class="omk-items">
                <div class="omk-item">
                    <h3>Bribe Kartificial <span class="omk-price"><?= omkFormat(OMK_PRICES['bribe']) ?></span></h3>
                    <p>About a 1 in <?= OMK_BRIBE_ODDS ?> chance in What cup? for <?= $fav ? '<strong>' . htmlspecialchars($fav['cup']) . ' Cup</strong>, your best (' . number_format($fav['avg'], 1) . ' over ' . (int)$fav['gps'] . ' GPs)' : 'your best cup' ?>. You can't pick another. It counts in draws you're in, runs until you next race, and he says so on screen.</p>
                    <?php if (!$fav): ?><p class="omk-note">You need three GPs on a cup first.</p>
                    <?php elseif ($myBribe): ?><p class="omk-note">He already has your envelope.</p>
                    <?php else: ?><button type="submit" name="action" value="bribe" class="btn btn-primary btn-sm" <?= $hasCode ? '' : 'disabled' ?>>Hand over the envelope</button><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Counter-bribe <span class="omk-price"><?= omkFormat(OMK_PRICES['counter']) ?></span></h3>
                    <p>Cancels someone's pending bribe. They don't get their money back, and he announces both payments.</p>
                    <?php if ($counterable): ?>
                        <select name="bribe_id" aria-label="Which bribe">
                            <?php foreach ($counterable as $b): ?><option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name'] . ': ' . $b['cup_name'] . ' Cup') ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="counter" class="btn btn-primary btn-sm" <?= $hasCode ? '' : 'disabled' ?>>Outbid them</button>
                    <?php else: ?><p class="omk-note">Nobody else has a bribe pending.</p><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Sponsor a broadcast <span class="omk-price"><?= omkFormat(OMK_PRICES['sponsor']) ?></span></h3>
                    <p>The next newscast's ad break is yours: "Kart Core Team is brought to you by <?= htmlspecialchars($names[$racerId]) ?>", followed by your catchphrase.</p>
                    <?php if ($mySponsor): ?><p class="omk-note">You're already in the queue for the next one.</p>
                    <?php else: ?><button type="submit" name="action" value="sponsor" class="btn btn-primary btn-sm" <?= $hasCode ? '' : 'disabled' ?>>Buy the ad break</button><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="omk-card">
            <h2 class="omk-h">Trade in duplicates</h2>
            <?php if (!$dupes): ?>
                <p class="omk-note">You have no duplicates. <a href="/stickers/<?= $racerId ?>">Open your packs</a> and come back.</p>
            <?php else: ?>
                <p class="omk-note">Choose how many of each. You keep at least one, and anything you leave is still yours.</p>
                <div class="omk-trade-tools">
                    <button type="button" class="btn btn-secondary btn-sm" id="omk-all">Select all</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="omk-none">Clear</button>
                </div>
                <div class="omk-table-wrap">
                <table class="clean-table omk-dupes">
                    <thead><tr><th>Card</th><th class="txt-right">You hold</th><th class="txt-right">Each</th><th class="txt-right">Trade</th></tr></thead>
                    <tbody>
                    <?php foreach ($dupes as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['emoji'] . ' ' . $d['title']) ?><?= $d['rarity'] === 'foil' ? ' <span class="omk-foil">foil</span>' : '' ?></td>
                            <td class="txt-right">×<?= (int)$d['count'] ?></td>
                            <td class="txt-right"><?= omkFormat($d['value']) ?></td>
                            <td class="txt-right"><input type="number" class="omk-qty" name="qty[<?= htmlspecialchars($d['key']) ?>]" min="0" max="<?= (int)$d['spare'] ?>" value="0" data-value="<?= (int)$d['value'] ?>" data-count="<?= (int)$d['count'] ?>" aria-label="Copies of <?= htmlspecialchars($d['title']) ?> to trade"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="omk-warn" id="omk-warn" hidden></p>
                <div class="omk-trade-go">
                    <strong id="omk-total">0<?= OMK_SYMBOL ?></strong>
                    <button type="submit" name="action" value="trade" class="btn btn-primary btn-sm" id="omk-trade" <?= $hasCode ? '' : 'disabled' ?>>Trade them in</button>
                </div>
            <?php endif; ?>
        </section>
    </form>
    <?php endif; ?>

    <div class="omk-two">
        <section class="omk-card">
            <h2 class="omk-h">On the table</h2>
            <?php if (!$pending && !$queue): ?><p class="omk-note">Nobody is paying anybody. Kartificial is disappointed.</p><?php endif; ?>
            <ul class="omk-list">
                <?php foreach ($pending as $b): ?><li>✉️ <strong><?= htmlspecialchars($b['name']) ?></strong> has bribed Kartificial for <?= htmlspecialchars($b['cup_name']) ?> Cup</li><?php endforeach; ?>
                <?php foreach ($bribes as $b): if ($b['status'] !== 'countered') continue; ?><li>✉️✉️ <strong><?= htmlspecialchars((string)$b['counter_name']) ?></strong> out-bribed <?= htmlspecialchars($b['name']) ?> on <?= htmlspecialchars($b['cup_name']) ?> Cup</li><?php endforeach; ?>
                <?php foreach ($queue as $i => $s): ?><li>📺 <strong><?= htmlspecialchars($s['name']) ?></strong> <?= $i === 0 ? 'sponsors the next newscast' : 'is next in line to sponsor one' ?></li><?php endforeach; ?>
            </ul>

            <h2 class="omk-h omk-h2">Balances</h2>
            <?php if (!$balances): ?><p class="omk-note">Nobody has any OMKredits yet.</p><?php else: ?>
            <ul class="omk-list omk-balances">
                <?php foreach ($balances as $rid => $b): if (!isset($names[$rid])) continue; ?>
                    <li><a href="/omk-exchange?racer=<?= (int)$rid ?>"><?= htmlspecialchars($names[$rid]) ?></a><span><?= omkFormat($b) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>

        <section class="omk-card">
            <h2 class="omk-h">The ledger</h2>
            <?php if (!$ledger): ?><p class="omk-note">Not a single transaction. The OMK has noticed.</p><?php else: ?>
            <ul class="omk-list omk-ledger">
                <?php foreach ($ledger as $l): ?>
                    <li><span><strong><?= htmlspecialchars($names[(int)$l['racer_id']] ?? '?') ?></strong> <?= htmlspecialchars(lcfirst($l['detail'])) ?> <small><?= date('j M', strtotime($l['created_at'])) ?></small></span><span class="<?= (int)$l['amount'] > 0 ? 'is-in' : 'is-out' ?>"><?= ((int)$l['amount'] > 0 ? '+' : '−') . omkFormat(abs((int)$l['amount'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($racerId && $dupes): ?>
<script>
(function () {
    const qty = [...document.querySelectorAll('.omk-qty')];
    const total = document.getElementById('omk-total');
    const warn = document.getElementById('omk-warn');
    const held = <?= (int)$copiesHeld ?>, most = <?= (int)$mostOfOne ?>;
    const clamp = (el) => Math.max(0, Math.min(parseInt(el.max, 10), parseInt(el.value, 10) || 0));
    function update() {
        let credits = 0, copies = 0, maxAfter = 0;
        qty.forEach((el) => {
            const n = clamp(el);
            credits += n * parseInt(el.dataset.value, 10);
            copies += n;
            maxAfter = Math.max(maxAfter, parseInt(el.dataset.count, 10) - n);
        });
        total.textContent = credits + '<?= OMK_SYMBOL ?>';
        // Only cards with spares are listed, and everything else you hold is a single copy.
        const lost = [];
        if (most >= 5 && Math.max(maxAfter, 1) < 5) lost.push('Stuck With Dupes');
        if (held >= 250 && held - copies < 250) lost.push('Whale');
        warn.hidden = lost.length === 0;
        warn.textContent = lost.length ? 'This costs you a badge: ' + lost.join(' and ') + '. That is the deal.' : '';
    }
    qty.forEach((el) => el.addEventListener('input', update));
    document.getElementById('omk-all').addEventListener('click', () => { qty.forEach((el) => { el.value = el.max; }); update(); });
    document.getElementById('omk-none').addEventListener('click', () => { qty.forEach((el) => { el.value = 0; }); update(); });
    update();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
