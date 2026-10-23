<?php
/**
 * The haunted house — /haunted[?season=sNN]
 *
 * The page for a season scored under The Real Monster Was Yourself
 * ('haunted'): who is still alive (hearts, today's ghost line, soul coins),
 * who has become a ghost and who caught them, the haunting as a log, and the
 * soul shop, where a racer spends coins on a sticker pack or a heart back.
 *
 * Every number is hauntedSeason() (gp_logic.php), the same pass the standings
 * sort through, so this page cannot disagree with the homepage.
 *
 * The shop is a public write: POST + CSRF, throttled per IP, and authorised
 * by the racer's own profile code (issued on /admin/racers). The balance is
 * re-checked inside a write transaction so a double-click cannot spend the
 * same coins twice. A purchase records the season's latest GP at the time, so
 * a heart bought tonight counts from the next GP on — not retroactively.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/leaderboard.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/throttle.php';
require_once __DIR__ . '/../private/includes/profile_codes.php';
require_once __DIR__ . '/../private/includes/stickers.php';

$seasonId = getCurrentSeasonNumber();
if (preg_match('/^s[0-9]{2}$/', (string)($_GET['season'] ?? ''))) $seasonId = $_GET['season'];
$rules = getSeasonRules($pdo, $seasonId);
$isHaunted = ($rules['scoring_system'] ?? '') === 'haunted';
$names = racerNamesMap($pdo);

// The shop is open while the season runs: not archived, not past its end date.
$endDate  = substr((string)($rules['end_date'] ?? ''), 0, 10);
$shopOpen = $isHaunted && ($rules['status'] ?? '') !== 'archived'
         && ($endDate === '' || date('Y-m-d') <= $endDate);

$flash = ''; $flashOk = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isHaunted) {
    verify_csrf();
    $rid  = (int)($_POST['racer_id'] ?? 0);
    $item = (string)($_POST['item'] ?? '');
    if (!$shopOpen) {
        $flash = 'The shop is closed — the season is over.';
    } elseif (!in_array($item, ['pack', 'heart'], true) || !isset($names[$rid])) {
        $flash = 'Pick your name and something to buy.';
    } elseif (!throttleAllow($pdo, 'haunt_shop', 10, 10)) {
        $flash = 'Too many attempts from this connection. Wait ten minutes and try again.';
    } elseif (!profileHasCode($pdo, $rid)) {
        $flash = $names[$rid] . " has no profile code yet — the commissioner issues them on the racers page.";
    } elseif (!profileCodeCheck($pdo, $rid, (string)($_POST['code'] ?? ''))) {
        $flash = "That code doesn't match.";
    } else {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $s = hauntedSeason($pdo, $seasonId, $rules);
            $x = $s['racers'][$rid] ?? null;
            $k = $s['knobs'];
            $cost = $item === 'heart' ? $k['heart_cost'] : $k['pack_cost'];
            $balance = $x ? $x['coins_earned'] - $x['coins_spent'] : 0;
            if ($item === 'heart' && (!$x || $x['ghost'] !== null)) {
                $flash = 'Ghosts cannot buy their way back. That is rather the point.';
            } elseif ($item === 'heart' && $x['hearts'] >= $k['hearts']) {
                $flash = 'Your hearts are already full.';
            } elseif ($balance < $cost) {
                $flash = "Not enough soul coins: that costs $cost and you have $balance.";
            } else {
                $lastGp = $s['gps'] ? array_key_last($s['gps']) : null;
                $pdo->prepare("INSERT INTO haunt_purchases (season_id, racer_id, item, cost, after_gpid) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$seasonId, $rid, $item, $cost, $lastGp]);
                if ($item === 'pack') grantAdminPacks($pdo, $rid, 1, STICKER_PACK_SIZE, 'soul_shop');
                $flashOk = true;
                $flash = $item === 'heart'
                    ? "A heart for {$names[$rid]}. It counts from the next GP."
                    : "A sticker pack for {$names[$rid]} — open it in the sticker album.";
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            error_log('haunt shop: ' . $e->getMessage());
            $flash = 'Something went wrong; nothing was spent.';
        }
    }
}

$pageTitle = 'The haunted house';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

if (!$isHaunted): ?>
<div class="stats-container">
    <div class="card haunt-card">
        <h1 class="card-header">👻 The haunted house</h1>
        <p><?= htmlspecialchars(strtoupper($seasonId)) ?> is not a haunted season. The house is quiet.</p>
    </div>
</div>
<?php include __DIR__ . '/../private/templates/footer.php'; return; endif;

// Fresh after any purchase above.
$s = hauntedSeason($pdo, $seasonId, $rules);
$k = $s['knobs'];
$threshold = (int)($rules['min_races_threshold'] ?? 0);
$rows = leaderboardRows($pdo, $seasonId, 0, false);
$seasonName = trim((string)($rules['season_name'] ?? '')) ?: strtoupper($seasonId);
$over = $s['end'] !== null && date('Y-m-d') > $s['end'];
$day  = max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime($s['start'])) / 86400)) + 1;
$days = $s['end'] !== null ? (int)floor((strtotime($s['end']) - strtotime($s['start'])) / 86400) + 1 : null;

$alive = []; $ghosts = [];
foreach ($rows as $r) {
    $x = $s['racers'][(int)$r['id']] ?? null;
    if (!$x) continue;
    if ($x['ghost'] === null) $alive[] = $r + ['h' => $x];
    else $ghosts[] = $r + ['h' => $x];
}

/** One line of the haunting log. */
function hauntLogLine(array $e, array $names): string {
    $n = htmlspecialchars($names[$e['rid']] ?? '?');
    $gp = htmlspecialchars(strtoupper($e['gpid']));
    switch ($e['type']) {
        case 'beat':      return "😈 <strong>$n</strong> beat their ghost in $gp — {$e['points']} against " . scoreNum($e['line']) . " · +{$e['coins']} soul coins and a bonus pack";
        case 'heart':     return "💔 <strong>$n</strong> lost a heart in $gp — {$e['points']} against a ghost of " . scoreNum($e['line']);
        case 'died':      return "👻 <strong>$n</strong> ran out of hearts in $gp and became a ghost";
        case 'caught':    return "🫥 <strong>" . htmlspecialchars($names[$e['by']] ?? '?') . "</strong> finished ahead of <strong>$n</strong> in $gp and turned them into a ghost";
        case 'buy_heart': return "❤️ <strong>$n</strong> bought a heart back for {$e['coins']} soul coins";
        case 'buy_pack':  return "🎁 <strong>$n</strong> bought a sticker pack for {$e['coins']} soul coins";
    }
    return '';
}
$log = array_reverse($s['events']);
?>

<div class="stats-container haunt-page">
    <div class="card haunt-hero">
        <h1 class="haunt-title">👻 The Real Monster Was Yourself</h1>
        <p class="haunt-sub"><?= htmlspecialchars($seasonName) ?> · <?= $over ? 'the haunting is over' : 'day ' . $day . ($days ? ' of ' . $days : '') ?><?= $endDate !== '' && !$over ? ' · ends ' . htmlspecialchars(date('j F', strtotime($endDate))) : '' ?></p>
        <p class="haunt-rules">
            Everyone starts with <strong><?= $k['hearts'] ?> hearts</strong>. Your ghost is your own career average,
            and it grows <strong><?= scoreNum($k['creep']) ?> points</strong> stronger every day. Score more than
            <strong><?= $k['margin'] ?></strong> below it and you lose a heart; beat it and you earn a soul coin per point
            and a bonus sticker pack. A ghost who finishes ahead of you turns you into one.
            Survivors need <strong><?= $threshold ?> GPs</strong> — the last one alive wins.
        </p>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert <?= $flashOk ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="haunt-grid">
        <section class="card">
            <h2 class="card-header">Still alive (<?= count($alive) ?>)</h2>
            <?php if (!$alive): ?><p class="haunt-empty">Nobody. It's ghost town.</p><?php endif; ?>
            <ol class="haunt-list">
            <?php foreach ($alive as $r): $x = $r['h']; $need = max(0, $threshold - $x['gps']); ?>
                <li class="haunt-row">
                    <span class="haunt-name"><a href="/racer/<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></span>
                    <span class="haunt-hearts" aria-label="<?= $x['hearts'] ?> of <?= $k['hearts'] ?> hearts"><?= str_repeat('❤️', $x['hearts']) . str_repeat('🖤', max(0, $k['hearts'] - $x['hearts'])) ?></span>
                    <span class="haunt-meta">ghost today <?= scoreNum(hauntedLineToday($s, (int)$r['id'])) ?> · <?= $x['coins_earned'] - $x['coins_spent'] ?> coins to spend
                        <?= $need > 0 ? ' · <em>' . $need . ' more GP' . ($need === 1 ? '' : 's') . ' to count</em>' : '' ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
        </section>

        <section class="card">
            <h2 class="card-header">The ghosts (<?= count($ghosts) ?>)</h2>
            <?php if (!$ghosts): ?><p class="haunt-empty">No ghosts yet. Give it time.</p><?php endif; ?>
            <ol class="haunt-list">
            <?php foreach ($ghosts as $r): $x = $r['h']; $by = $x['ghost']['by']; ?>
                <li class="haunt-row haunt-row--ghost">
                    <span class="haunt-name"><a href="/racer/<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></span>
                    <span class="haunt-meta">👻 since <?= htmlspecialchars(strtoupper($x['ghost']['gpid'])) ?> —
                        <?= $by !== null ? 'caught by ' . htmlspecialchars($names[$by] ?? '?') : 'out of hearts' ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
        </section>
    </div>

    <?php if ($shopOpen): ?>
    <section class="card haunt-shop">
        <h2 class="card-header">The soul shop</h2>
        <p>A sticker pack costs <strong><?= $k['pack_cost'] ?></strong> soul coins, a heart back <strong><?= $k['heart_cost'] ?></strong>
           (the living only, never above <?= $k['hearts'] ?>). Pay with your profile code.</p>
        <form method="POST" class="haunt-shop-form" autocomplete="off">
            <?= csrf_field() ?>
            <select name="racer_id" required>
                <option value="">Who are you?</option>
                <?php foreach ($rows as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="item" required>
                <option value="pack">A sticker pack (<?= $k['pack_cost'] ?>)</option>
                <option value="heart">A heart back (<?= $k['heart_cost'] ?>)</option>
            </select>
            <input type="text" name="code" required placeholder="Profile code" autocapitalize="characters" spellcheck="false">
            <button type="submit" class="btn btn-primary">Buy</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <h2 class="card-header">The haunting</h2>
        <?php if (!$log): ?><p class="haunt-empty">The house is waiting for its first GP.</p><?php endif; ?>
        <ul class="haunt-log">
            <?php foreach (array_slice($log, 0, 60) as $e): $line = hauntLogLine($e, $names); if ($line === '') continue; ?>
                <li><?= $line ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
