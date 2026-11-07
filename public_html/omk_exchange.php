<?php
/**
 * The OMK Exchange Counter — /omk-exchange[?racer=ID]
 *
 * Trade in duplicate stickers for OMKredits (Ω̈), and spend them on bribes and
 * sponsorships. Anyone can read the rules and what is on the table, because a
 * counter-bribe needs it. A racer's balance, trade-ins and purchases only appear once
 * their profile code has opened their account. The ledger and everyone's balances are
 * for admins.
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
require_once __DIR__ . '/../private/includes/card_rendering.php';   // racerCardTier(): no renting what you have earned
if (session_status() === PHP_SESSION_NONE) kartfolioSessionStart();

$names = racerNamesMap($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $rid = (int)($_POST['racer_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $ok = false;
    $msg = null;
    if ($action === 'leave') {
        unset($_SESSION['omk_counter']);
        header('Location: /omk-exchange', true, 303);
        exit;
    } elseif (!isset($names[$rid])) {
        $msg = 'Pick your name first.';
    } elseif ($action === 'unlock') {
        if (!throttleAllow($pdo, 'omk_unlock', 10, 10)) {
            $msg = 'Too many attempts from this connection. Wait ten minutes and try again.';
        } elseif (!profileHasCode($pdo, $rid)) {
            $msg = $names[$rid] . ' has no profile code yet. A commissioner issues them on the racers page.';
        } elseif (!profileCodeCheck($pdo, $rid, (string)($_POST['code'] ?? ''))) {
            $msg = "That code doesn't match.";
        } else {
            session_regenerate_id(true);
            $_SESSION['omk_counter'] = ['racer' => $rid, 'until' => time() + OMK_COUNTER_MINUTES * 60];
        }
    } elseif (!omkCounterIsOpen($_SESSION, $rid, time())) {
        $msg = 'Enter your profile code first.';
    } elseif (!in_array($action, ['trade', 'bribe', 'counter', 'sponsor', 'gold', 'offset', 'founder', 'loot', 'rental', 'hype', 'billboard'], true)) {
        $msg = 'Pick what you came for.';
    } elseif (!throttleAllow($pdo, 'omk_exchange', 30, 10)) {
        $msg = 'Too many attempts from this connection. Wait ten minutes and try again.';
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
                case 'gold':
                    $colour = (string)($_POST['plate'] ?? 'gold');
                    $until = omkBuyGold($pdo, $rid, $colour);
                    $msg = 'Your name is ' . strtolower(OMK_PLATES[$colour]) . ' until ' . date('j F', $until) . '. Congratulations on your purchase.';
                    break;
                case 'rental':
                    $until = omkBuyFoilRental($pdo, $rid, racerCardTier($pdo, $rid)['key'] === 'holo');
                    $msg = 'Your card is holographic until ' . date('j F', $until) . '. Please return it in the condition you found it.';
                    break;
                case 'hype':
                    $n = omkBuyHype($pdo, $rid);
                    $msg = "Kartificial has been briefed. He will say nice things about you in What cup? for your next $n GPs.";
                    break;
                case 'billboard':
                    $until = omkBuyBillboard($pdo, $rid);
                    $msg = 'Your card is on the rotating sign until ' . date('j F, H:i', $until) . '. Stand near it.';
                    break;
                case 'offset':
                    $r = omkBuyOffset($pdo, $rid, (int)($_POST['lol_id'] ?? 0));
                    $msg = 'The LOL in ' . strtoupper($r['gpid']) . ' is now offset. It still happened.';
                    break;
                case 'founder':
                    $msg = sprintf("Welcome, Founder #%03d. It does nothing, and it is yours.", omkBuyFounder($pdo, $rid));
                    break;
                case 'loot':
                    $msg = omkBuyLootBox($pdo, $rid)['golden']
                        ? 'A GOLDEN KARTIFICIAL. One in a million. The OMK would like a word.'
                        : 'A loot box is waiting unopened in your sticker album.';
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
    if ($msg !== null) $_SESSION['omk_flash'] = ['msg' => $msg, 'ok' => $ok];
    header('Location: /omk-exchange' . (isset($names[$rid]) ? '?racer=' . $rid : ''), true, 303);
    exit;
}

$flash = $_SESSION['omk_flash'] ?? null;
unset($_SESSION['omk_flash']);

$racerId = (int)($_GET['racer'] ?? 0);
if (!isset($names[$racerId])) $racerId = 0;

$roster = $pdo->query("SELECT id, name FROM racers WHERE COALESCE(is_retired, 0) = 0 ORDER BY name COLLATE NOCASE ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$bribes = omkOpenBribes($pdo);
$pending = array_values(array_filter($bribes, fn($b) => $b['status'] === 'pending'));
$queue = omkSponsorQueue($pdo);

// The account: only once this racer's code has opened it in this session. Nothing
// about their balance or collection is read before that.
$open = omkCounterIsOpen($_SESSION, $racerId, time());
$hasCode = $racerId ? profileHasCode($pdo, $racerId) : false;
$dupes = []; $fav = null; $balance = 0; $copiesHeld = 0; $mostOfOne = 0; $counterable = []; $myBribe = null; $mySponsor = false;
$goldUntil = 0; $myLols = []; $myFounder = null; $myPlate = null; $rentalUntil = 0; $isHolo = false; $hypeLeft = 0; $billboardUntil = 0;
$founders = omkFounders($pdo);
$billboards = omkBillboards($pdo);
if ($open) {
    $_SESSION['omk_counter']['until'] = time() + OMK_COUNTER_MINUTES * 60;   // still at the counter
    $dupes = omkDuplicates($pdo, $racerId);
    $fav = omkFavouredCup($pdo, $racerId);
    $balance = omkBalance($pdo, $racerId);
    foreach (racerAlbum($pdo, $racerId) as $count) { $copiesHeld += (int)$count; $mostOfOne = max($mostOfOne, (int)$count); }
    $counterable = array_values(array_filter($pending, fn($b) => (int)$b['racer_id'] !== $racerId));
    foreach ($pending as $b) if ((int)$b['racer_id'] === $racerId) $myBribe = $b;
    $mySponsor = (bool)array_filter($queue, fn($q) => (int)$q['racer_id'] === $racerId);
    $goldUntil = omkUntil($pdo, $racerId, 'gold_name');
    $myPlate = omkPlates($pdo)[$racerId] ?? null;
    $rentalUntil = omkUntil($pdo, $racerId, 'foil_rental');
    $isHolo = racerCardTier($pdo, $racerId)['key'] === 'holo';
    $hypeLeft = omkHyped($pdo)[$racerId] ?? 0;
    $billboardUntil = omkUntil($pdo, $racerId, 'billboard');
    $myLols = omkUnoffsetLols($pdo, $racerId);
    $myFounder = $founders[$racerId] ?? null;
}

$isAdmin = ($_SESSION['is_admin'] ?? false) === true;
$balances = $isAdmin ? omkBalances($pdo) : [];
$ledger = $isAdmin ? $pdo->query("SELECT l.amount, l.detail, l.created_at, l.racer_id FROM omk_ledger l
                                  WHERE l.kind != 'race_week' ORDER BY l.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) : [];

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
        <h1 class="page-title"><span class="omk-sigil"><?= omkSign() ?></span> OMK Exchange Counter</h1>
        <p class="page-subtitle">ALL SALES FINAL · RATES SET BY THE OMK, WHO ANSWER TO NOBODY</p>
    </header>

    <?php if ($flash): ?><div class="omk-flash <?= $flash['ok'] ? 'is-ok' : 'is-no' ?>" role="status"><?= omkHtml($flash['msg']) ?></div><?php endif; ?>

    <section class="omk-card">
        <h2 class="omk-h">How OMKredits work</h2>
        <ul class="omk-rules">
            <li><strong><?= omkFormatHtml(OMK_PER_WEEK) ?> for every week you race</strong>, however many GPs. Paid when the GP is saved.</li>
            <li><strong>Trade in duplicate stickers</strong>: <?= omkFormatHtml(omkDupeValue('common')) ?> each, or <?= omkFormatHtml(omkDupeValue('foil')) ?> for a foil. You always keep one of each card.</li>
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

    <?php if ($racerId && !$open): ?>
    <section class="omk-card">
        <h2 class="omk-h"><?= htmlspecialchars($names[$racerId]) ?>'s account</h2>
        <?php if ($hasCode): ?>
        <form method="POST" autocomplete="off" class="omk-gate">
            <?= csrf_field() ?>
            <input type="hidden" name="racer_id" value="<?= $racerId ?>">
            <input type="hidden" name="action" value="unlock">
            <label for="omk-code-input">Your profile code</label>
            <input type="text" id="omk-code-input" name="code" required autofocus placeholder="Profile code" autocapitalize="characters" spellcheck="false">
            <button type="submit" class="btn btn-primary btn-sm">Open my account</button>
        </form>
        <?php else: ?>
        <p class="omk-note"><?= htmlspecialchars($names[$racerId]) ?> has no profile code yet. A commissioner issues them on the racers page.</p>
        <?php endif; ?>
    </section>
    <?php elseif ($open): ?>
    <form method="POST" autocomplete="off" id="omk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="racer_id" value="<?= $racerId ?>">

        <section class="omk-card omk-account">
            <div>
                <div class="omk-balance"><?= omkFormatHtml($balance) ?></div>
                <div class="omk-note"><?= htmlspecialchars($names[$racerId]) ?>'s balance</div>
            </div>
            <button type="submit" name="action" value="leave" class="btn btn-secondary btn-sm" formnovalidate>Leave the counter</button>
        </section>

        <section class="omk-card">
            <h2 class="omk-h">Buy</h2>
            <div class="omk-items">
                <div class="omk-item">
                    <h3>Bribe Kartificial <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['bribe']) ?></span></h3>
                    <p>About a 1 in <?= OMK_BRIBE_ODDS ?> chance in What cup? for <?= $fav ? '<strong>' . htmlspecialchars($fav['cup']) . ' Cup</strong>, your best (' . number_format($fav['avg'], 1) . ' over ' . (int)$fav['gps'] . ' GPs)' : 'your best cup' ?>. You can't pick another. It counts in draws you're in, runs until you next race, and he says so on screen.</p>
                    <?php if (!$fav): ?><p class="omk-note">You need three GPs on a cup first.</p>
                    <?php elseif ($myBribe): ?><p class="omk-note">He already has your envelope.</p>
                    <?php else: ?><button type="submit" name="action" value="bribe" class="btn btn-primary btn-sm">Hand over the envelope</button><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Counter-bribe <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['counter']) ?></span></h3>
                    <p>Cancels someone's pending bribe. They don't get their money back, and he announces both payments.</p>
                    <?php if ($counterable): ?>
                        <select name="bribe_id" aria-label="Which bribe">
                            <?php foreach ($counterable as $b): ?><option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name'] . ': ' . $b['cup_name'] . ' Cup') ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="counter" class="btn btn-primary btn-sm">Outbid them</button>
                    <?php else: ?><p class="omk-note">Nobody else has a bribe pending.</p><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Sponsor a broadcast <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['sponsor']) ?></span></h3>
                    <p>The next newscast's ad break is yours: "Kart Core Team is brought to you by <?= htmlspecialchars($names[$racerId]) ?>", followed by your catchphrase.</p>
                    <?php if ($mySponsor): ?><p class="omk-note">You're already in the queue for the next one.</p>
                    <?php else: ?><button type="submit" name="action" value="sponsor" class="btn btn-primary btn-sm">Buy the ad break</button><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Nameplate <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['gold']) ?></span></h3>
                    <p>Your name in colour on the homepage and the signs for <?= OMK_GOLD_DAYS ?> days, with a small PAID beside it so nobody thinks you earned it.</p>
                    <div class="omk-plates" role="radiogroup" aria-label="Nameplate colour">
                        <?php foreach (OMK_PLATES as $key => $label): ?>
                        <label class="omk-plate-pick"><input type="radio" name="plate" value="<?= $key ?>" <?= ($myPlate ?? 'gold') === $key ? 'checked' : '' ?>><span class="<?= trim(omkPlateClass($key)) ?>"><?= htmlspecialchars($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($goldUntil > time()): ?><p class="omk-note"><?= htmlspecialchars(OMK_PLATES[$myPlate] ?? 'Gold') ?> until <?= date('j F', $goldUntil) ?>. Another week adds to that, in whichever colour you pick now.</p><?php endif; ?>
                    <button type="submit" name="action" value="gold" class="btn btn-primary btn-sm"><?= $goldUntil > time() ? 'Add a week' : 'Buy the nameplate' ?></button>
                </div>
                <div class="omk-item">
                    <h3>Foil rental <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['rental']) ?></span></h3>
                    <p>The Holographic finish on your trading card for <?= OMK_RENTAL_DAYS ?> days, stamped RENTAL. Shows on your profile and its download; the printed season set keeps your real tier.</p>
                    <?php if ($isHolo): ?><p class="omk-note">Your card is already holographic. You earned it, so you cannot rent it.</p>
                    <?php else: ?>
                        <?php if ($rentalUntil > time()): ?><p class="omk-note">Rented until <?= date('j F', $rentalUntil) ?>. Another week adds to that.</p><?php endif; ?>
                        <button type="submit" name="action" value="rental" class="btn btn-primary btn-sm"><?= $rentalUntil > time() ? 'Extend the lease' : 'Rent the foil' ?></button>
                    <?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Hype package <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['hype']) ?></span></h3>
                    <p>Kartificial says something nice about you in What cup? for your next <?= OMK_HYPE_GPS ?> GPs, in every draw you're in. He reads from a script, and he admits it: "<?= htmlspecialchars(str_replace('{n}', $names[$racerId], OMK_HYPE_LINES[0])) ?>"</p>
                    <?php if ($hypeLeft > 0): ?><p class="omk-note">He's briefed for <?= $hypeLeft ?> more GP<?= $hypeLeft === 1 ? '' : 's' ?>. More adds to that.</p><?php endif; ?>
                    <button type="submit" name="action" value="hype" class="btn btn-primary btn-sm">Brief him</button>
                </div>
                <div class="omk-item">
                    <h3>Billboard <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['billboard']) ?></span></h3>
                    <p>Your trading card gets its own slide on the rotating sign for <?= OMK_BILLBOARD_HOURS ?> hours, under a big SPONSORED. Everyone in the room will know you paid.</p>
                    <?php if ($billboardUntil > time()): ?><p class="omk-note">On the sign until <?= date('j F, H:i', $billboardUntil) ?>. Another day adds to that.</p><?php endif; ?>
                    <button type="submit" name="action" value="billboard" class="btn btn-primary btn-sm"><?= $billboardUntil > time() ? 'Add a day' : 'Put me up there' ?></button>
                </div>
                <div class="omk-item">
                    <h3>Carbon-offset a LOL <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['offset']) ?></span></h3>
                    <p>It stays on your record and in every count. It just gets a small tag on the GP's page: "LOL · offset by <?= htmlspecialchars($names[$racerId]) ?>".</p>
                    <?php if ($myLols): ?>
                        <select name="lol_id" aria-label="Which LOL">
                            <?php foreach ($myLols as $l): ?><option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars(strtoupper($l['gpid']) . ' · ' . ($l['cup_name'] ?: '?') . ' · ' . date('j M Y', strtotime($l['d']))) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="offset" class="btn btn-primary btn-sm">Clear my conscience</button>
                    <?php else: ?><p class="omk-note">You have no LOLs left to offset.</p><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Founder's Edition <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['founder']) ?></span></h3>
                    <p>A badge with a serial number, limited to <?= OMK_FOUNDER_LIMIT ?>. It does nothing, forever. <?= OMK_FOUNDER_LIMIT - count($founders) ?> left.</p>
                    <?php if ($myFounder): ?><p class="omk-note">You are Founder #<?= sprintf('%03d', $myFounder) ?>.</p>
                    <?php elseif (count($founders) >= OMK_FOUNDER_LIMIT): ?><p class="omk-note">Sold out.</p>
                    <?php else: ?><button type="submit" name="action" value="founder" class="btn btn-primary btn-sm" onclick="return confirm('Spend <?= OMK_PRICES['founder'] ?> OMKredits on a badge that does nothing?');">Become a Founder</button><?php endif; ?>
                </div>
                <div class="omk-item">
                    <h3>Loot box <span class="omk-price"><?= omkFormatHtml(OMK_PRICES['loot']) ?></span></h3>
                    <p>One sticker pack, at the odds published below as the OMK Gaming Commission requires. It waits unopened in your album.</p>
                    <button type="submit" name="action" value="loot" class="btn btn-primary btn-sm">Take my money</button>
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
                            <td class="txt-right"><?= omkFormatHtml($d['value']) ?></td>
                            <td class="txt-right"><input type="number" class="omk-qty" name="qty[<?= htmlspecialchars($d['key']) ?>]" min="0" max="<?= (int)$d['spare'] ?>" value="0" data-value="<?= (int)$d['value'] ?>" data-count="<?= (int)$d['count'] ?>" aria-label="Copies of <?= htmlspecialchars($d['title']) ?> to trade"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="omk-warn" id="omk-warn" hidden></p>
                <div class="omk-trade-go">
                    <strong><span id="omk-total">0</span><?= omkSign() ?></strong>
                    <button type="submit" name="action" value="trade" class="btn btn-primary btn-sm" id="omk-trade">Trade them in</button>
                </div>
            <?php endif; ?>
        </section>
    </form>
    <?php endif; ?>

    <section class="omk-card">
        <h2 class="omk-h">Loot box odds <small class="omk-note">as required by the OMK Gaming Commission</small></h2>
        <div class="omk-table-wrap">
        <table class="clean-table omk-odds">
            <thead><tr><th>Rarity</th><th class="txt-right">Per card</th><th class="txt-right">At least one in a pack of <?= STICKER_PACK_SIZE ?></th></tr></thead>
            <tbody>
                <tr><td>Golden Kartificial</td><td class="txt-right">—</td><td class="txt-right"><?= rtrim(number_format(100 / OMK_GOLDEN_ODDS, 4), '0') ?>% per box</td></tr>
                <?php foreach (omkLootOdds() as $o): ?>
                <tr><td><?= htmlspecialchars($o['label']) ?></td><td class="txt-right"><?= rtrim(rtrim(number_format($o['card'], 1), '0'), '.') ?>%</td><td class="txt-right"><?= number_format($o['pack'], 1) ?>%</td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php $goldenPulls = omkGoldenPulls($pdo); ?>
        <p class="omk-note omk-odds-note">These are the real odds. Golden Kartificials pulled to date: <?= count($goldenPulls) ?><?= $goldenPulls ? ' (' . htmlspecialchars(implode(', ', $goldenPulls)) . ')' : '' ?>. Past performance is not indicative of anything.</p>
    </section>

    <div class="<?= $isAdmin ? 'omk-two' : '' ?>">
        <section class="omk-card">
            <h2 class="omk-h">On the table</h2>
            <?php if (!$pending && !$queue && !$billboards): ?><p class="omk-note">Nobody is paying anybody. Kartificial is disappointed.</p><?php endif; ?>
            <ul class="omk-list">
                <?php foreach ($pending as $b): ?><li>✉️ <strong><?= htmlspecialchars($b['name']) ?></strong> has bribed Kartificial for <?= htmlspecialchars($b['cup_name']) ?> Cup</li><?php endforeach; ?>
                <?php foreach ($bribes as $b): if ($b['status'] !== 'countered') continue; ?><li>✉️✉️ <strong><?= htmlspecialchars((string)$b['counter_name']) ?></strong> out-bribed <?= htmlspecialchars($b['name']) ?> on <?= htmlspecialchars($b['cup_name']) ?> Cup</li><?php endforeach; ?>
                <?php foreach ($billboards as $b): ?><li>📢 <strong><?= htmlspecialchars($b['name']) ?></strong> is on the billboard until <?= date('j F, H:i', (int)$b['until_ts']) ?></li><?php endforeach; ?>
                <?php foreach ($queue as $i => $s): ?><li>📺 <strong><?= htmlspecialchars($s['name']) ?></strong> <?= $i === 0 ? 'sponsors the next newscast' : 'is next in line to sponsor one' ?></li><?php endforeach; ?>
            </ul>
        </section>

        <?php if ($isAdmin): ?>
        <section class="omk-card">
            <h2 class="omk-h">The ledger <small class="omk-note">admins only</small></h2>
            <?php if (!$ledger): ?><p class="omk-note">Nothing yet.</p><?php else: ?>
            <ul class="omk-list omk-ledger">
                <?php foreach ($ledger as $l): ?>
                    <li><span><strong><?= htmlspecialchars($names[(int)$l['racer_id']] ?? '?') ?></strong> <?= htmlspecialchars(lcfirst($l['detail'])) ?> <small><?= date('j M', strtotime($l['created_at'])) ?></small></span><span class="<?= (int)$l['amount'] > 0 ? 'is-in' : 'is-out' ?>"><?= ((int)$l['amount'] > 0 ? '+' : '−') . omkFormatHtml(abs((int)$l['amount'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <h2 class="omk-h omk-h2">Balances <small class="omk-note">admins only</small></h2>
            <?php if (!$balances): ?><p class="omk-note">Nobody has any OMKredits yet.</p><?php else: ?>
            <ul class="omk-list omk-balances">
                <?php foreach ($balances as $rid => $b): if (!isset($names[$rid])) continue; ?>
                    <li><span><?= htmlspecialchars($names[$rid]) ?></span><span><?= omkFormatHtml($b) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php if ($open && $dupes): ?>
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
        total.textContent = credits;
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
