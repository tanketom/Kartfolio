<?php
/**
 * Wrapped — Spotify-style year-in-review for a racer, as a tap-through slide
 * deck (15 slides) ending in a downloadable summary card.
 *
 * Access:
 *   /wrapped            → ADMIN ONLY (preview index / roster grid).
 *   /wrapped/<racerId>  → public, but gated to December (admins preview any
 *                         time). Linked from the top of the racer profile in
 *                         December. ?year=YYYY overrides the year.
 *
 * Calendar-year scoped (filters on race_date, not season). All reads are
 * batched — at most two queries plus the cached Elo engine.
 *
 * Path: /cdnmk/public_html/wrapped.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/auth.php';        // require_admin + session
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';
require_once __DIR__ . '/../private/includes/mk_data.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/wrapped_personas.php';

$isAdmin    = !empty($_SESSION['is_admin']);
$year       = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? $_GET['year'] : date('Y');
$racerId    = isset($_GET['racer']) ? (int)$_GET['racer'] : 0;
$isDecember = ((int)date('n') === 12);

/**
 * Per-racer year aggregates from ONE query, grouped in PHP (used for the
 * roster grid and for percentile / attendance ranking on the card).
 */
function wrappedYearData($pdo, $year) {
    $stmt = $pdo->prepare("
        SELECT res.racer_id, r.name, res.gp_points, res.rank, res.character_used
        FROM results res JOIN racers r ON r.id = res.racer_id
        WHERE res.gpid LIKE 's%' AND strftime('%Y', res.race_date) = ?
    ");
    $stmt->execute([$year]);
    $acc = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['racer_id'];
        if (!isset($acc[$rid])) $acc[$rid] = ['id' => $rid, 'name' => $row['name'], 'gps' => 0, 'points' => 0, 'wins' => 0, '_chars' => []];
        $acc[$rid]['gps']++;
        $acc[$rid]['points'] += (int)$row['gp_points'];
        if ((int)$row['rank'] === 1) $acc[$rid]['wins']++;
        if ($row['character_used']) $acc[$rid]['_chars'][$row['character_used']] = ($acc[$rid]['_chars'][$row['character_used']] ?? 0) + 1;
    }
    foreach ($acc as &$a) { arsort($a['_chars']); $a['char'] = array_key_first($a['_chars']) ?: 'Mii'; unset($a['_chars']); }
    unset($a);
    uasort($acc, fn($x, $y) => $y['points'] <=> $x['points']);
    return $acc;
}

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio') ?? 'Kartfolio';

// ============================================================================
// ROSTER GRID  (/wrapped) — ADMIN ONLY
// ============================================================================
if ($racerId === 0) {
    require_admin(); // redirects non-admins to /login
    $roster = wrappedYearData($pdo, $year);
    $pageTitle = "$year Wrapped — Admin index";
    $extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
    include __DIR__ . '/../private/templates/header.php';
    ?>
    <div class="stats-container">
        <header class="page-header">
            <h1 class="page-title">🎁 <?= htmlspecialchars($year) ?> Wrapped</h1>
            <p class="page-subtitle">ADMIN INDEX · INDIVIDUAL CARDS GO PUBLIC EACH DECEMBER<?= $isDecember ? ' · LIVE NOW' : ' · (preview)' ?></p>
        </header>
        <?php if (empty($roster)): ?>
            <div style="text-align:center;padding:50px;color:#888;"><p>No GPs raced in <?= htmlspecialchars($year) ?>.</p></div>
        <?php else: ?>
            <div class="wr-grid">
                <?php foreach ($roster as $r): ?>
                    <a href="/wrapped/<?= $r['id'] ?>?year=<?= htmlspecialchars($year) ?>" class="wr-mini">
                        <img src="/assets/img/<?= htmlspecialchars($r['char']) ?>.png" class="wr-mini-portrait" onerror="this.src='/assets/img/Mii.png'">
                        <div class="wr-mini-name"><?= htmlspecialchars($r['name']) ?></div>
                        <div class="wr-mini-stat"><?= $r['gps'] ?> GPs · <?= $r['wins'] ?>🥇</div>
                        <div class="wr-mini-cta">Open Wrapped →</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <style>
    .wr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; margin-top:18px; }
    .wr-mini { background:linear-gradient(160deg,#1a1430,#0a0a0a); border:1px solid #2a2150; border-radius:12px; padding:18px 12px; text-align:center; text-decoration:none; color:#fff; transition:transform .15s,border-color .15s; }
    .wr-mini:hover { transform:translateY(-3px); border-color:#FFD700; }
    .wr-mini-portrait { width:72px; height:72px; object-fit:contain; }
    .wr-mini-name { font-weight:900; text-transform:uppercase; margin-top:6px; }
    .wr-mini-stat { color:#bba6ff; font-size:.82rem; margin-top:2px; }
    .wr-mini-cta { color:#FFD700; font-size:.78rem; margin-top:8px; font-weight:700; }
    </style>
    <?php
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

// ============================================================================
// INDIVIDUAL CARD  (/wrapped/<id>) — public in December, admin preview anytime
// ============================================================================
$gateOpen  = $isDecember || $isAdmin;
$isPreview = $gateOpen && !$isDecember;

if (!$gateOpen) {
    $pageTitle = "Wrapped — Coming in December";
    $extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container" style="text-align:center;padding:60px 20px;">'
       . '<div style="font-size:4rem;">🎁</div>'
       . '<h1 class="page-title">' . htmlspecialchars($year) . ' Wrapped</h1>'
       . '<p style="color:#aaa;font-size:1.1rem;max-width:520px;margin:16px auto;">Your year in karts is being tallied. '
       . '<strong>Wrapped unlocks in December</strong> — come back then for the full highlight reel.</p>'
       . '<a href="/" class="btn btn-secondary">← Back to standings</a></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

$roster = wrappedYearData($pdo, $year);
if (!isset($roster[$racerId])) {
    $pageTitle = "Wrapped — not found";
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container"><h1>🎁 No Wrapped</h1><p>No GPs in ' . htmlspecialchars($year)
       . ' for that racer.</p></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

// ── Rich per-racer year stats (one query) ──────────────────────────────
$rowStmt = $pdo->prepare("
    SELECT gp_points, rank, gpid, cup_name, race_date, character_used, is_lol
    FROM results
    WHERE racer_id = ? AND gpid LIKE 's%' AND strftime('%Y', race_date) = ?
    ORDER BY race_date ASC, id ASC
");
$rowStmt->execute([$racerId, $year]);
$rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

$name    = $roster[$racerId]['name'];
$gps     = count($rows);
$points  = array_sum(array_map(fn($r) => (int)$r['gp_points'], $rows));
$avg     = $gps ? round($points / $gps, 1) : 0;
$wins    = 0; $podiums = 0; $lols = 0; $best = ['pts' => -1];
$charTally = []; $cupTally = []; $ranksChrono = [];
foreach ($rows as $r) {
    if ((int)$r['rank'] === 1) $wins++;
    if ((int)$r['rank'] <= 3) $podiums++;
    $lols += (int)$r['is_lol'];
    if ((int)$r['gp_points'] > $best['pts']) {
        $best = ['pts' => (int)$r['gp_points'], 'gpid' => $r['gpid'], 'cup' => $r['cup_name'], 'date' => $r['race_date']];
    }
    if ($r['character_used']) $charTally[$r['character_used']] = ($charTally[$r['character_used']] ?? 0) + 1;
    if ($r['cup_name'])       $cupTally[$r['cup_name']]        = ($cupTally[$r['cup_name']] ?? 0) + 1;
    $ranksChrono[] = (int)$r['rank'];
}
arsort($charTally); arsort($cupTally);
$topChars  = array_slice($charTally, 0, 5, true);
$sigChar   = array_key_first($charTally) ?: 'Mii';
$favCup    = array_key_first($cupTally) ?: '—';
$cupConc   = $gps ? (max($cupTally ?: [0]) / $gps) : 0;
$distinctChars = count($charTally);
$cupsRaced     = count($cupTally);
$hasPerfect    = ($best['pts'] === 60);
$lolRate       = $gps ? $lols / $gps : 0;

// std dev of gp_points
$ptVals = array_map(fn($r) => (int)$r['gp_points'], $rows);
$mean   = $gps ? array_sum($ptVals) / $gps : 0;
$stdDev = $gps ? sqrt(array_sum(array_map(fn($p) => ($p - $mean) ** 2, $ptVals)) / $gps) : 0;

// longest podium streak (max run of consecutive rank<=3)
$longestPodium = 0; $run = 0;
foreach ($ranksChrono as $rk) { if ($rk <= 3) { $run++; $longestPodium = max($longestPodium, $run); } else $run = 0; }

// comeback: a win immediately after a rank>=10 finish
$comeback = false;
for ($i = 1; $i < count($ranksChrono); $i++) {
    if ($ranksChrono[$i] === 1 && $ranksChrono[$i - 1] >= 10) { $comeback = true; break; }
}

// second-half scoring jump
$half = (int)floor($gps / 2);
$secondHalfJump = 0;
if ($half >= 1) {
    $first = array_slice($ptVals, 0, $half);
    $second = array_slice($ptVals, $half);
    $secondHalfJump = (array_sum($second) / count($second)) - (array_sum($first) / count($first));
}

// dominant character group
$groups = getCharacterGroups();
$groupCounts = array_fill_keys(array_keys($groups), 0);
foreach ($charTally as $char => $cnt) {
    $norm = normalizeCharacterName($char);
    foreach ($groups as $gk => $members) {
        if (in_array($norm, $members, true) || in_array($char, $members, true)) $groupCounts[$gk] += $cnt;
    }
}
arsort($groupCounts);
$topGroup = (max($groupCounts) > 0) ? array_key_first($groupCounts) : null;

// Character evolution: dominant char in each chronological third.
$phases = [];
if ($gps >= 3) {
    $third = (int)ceil($gps / 3);
    for ($p = 0; $p < 3; $p++) {
        $slice = array_slice($rows, $p * $third, $third);
        if (empty($slice)) continue;
        $t = [];
        foreach ($slice as $r) if ($r['character_used']) $t[$r['character_used']] = ($t[$r['character_used']] ?? 0) + 1;
        arsort($t);
        if (!empty($t)) $phases[] = array_key_first($t);
    }
}

// Elo: peak, year delta, and monthly end-of-month rating for "The Climb".
$eloData = calculateAllELORatings($pdo);
$peakElo = null; $eloFirst = null; $eloLast = null; $monthlyElo = [];
foreach ($eloData['gp_changelog'] ?? [] as $gpLog) {
    if (substr($gpLog['date'], 0, 4) !== (string)$year) continue;
    foreach ($gpLog['racers'] as $rc) {
        if ($rc['name'] !== $name) continue;
        if ($eloFirst === null) $eloFirst = $rc['old'];
        $eloLast = $rc['new'];
        $peakElo = $peakElo === null ? $rc['new'] : max($peakElo, $rc['new']);
        $monthlyElo[substr($gpLog['date'], 0, 7)] = $rc['new']; // YYYY-MM => last new that month
    }
}
$eloDelta = ($eloFirst !== null && $eloLast !== null) ? (int)round($eloLast - $eloFirst) : 0;

// Nemesis: most-faced rival + head-to-head (one query).
$nemStmt = $pdo->prepare("
    SELECT rb.name, a.rank AS my_rank, b.rank AS their_rank
    FROM results a
    JOIN results b ON a.gpid = b.gpid AND b.racer_id != a.racer_id
    JOIN racers rb ON rb.id = b.racer_id
    WHERE a.racer_id = ? AND a.gpid LIKE 's%' AND strftime('%Y', a.race_date) = ?
");
$nemStmt->execute([$racerId, $year]);
$rivals = [];
foreach ($nemStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $rivals[$r['name']] ??= ['faced' => 0, 'ahead' => 0, 'behind' => 0];
    $rivals[$r['name']]['faced']++;
    if ((int)$r['my_rank'] < (int)$r['their_rank']) $rivals[$r['name']]['ahead']++; else $rivals[$r['name']]['behind']++;
}
uasort($rivals, fn($x, $y) => $y['faced'] <=> $x['faced']);
$nemesis = !empty($rivals) ? (['name' => array_key_first($rivals)] + reset($rivals)) : null;

// Badges earned this year (union across the year's seasons).
$seasonsThisYear = $pdo->prepare("SELECT DISTINCT SUBSTR(gpid,1,3) FROM results WHERE gpid LIKE 's%' AND strftime('%Y', race_date) = ?");
$seasonsThisYear->execute([$year]);
$yearBadges = [];
foreach ($seasonsThisYear->fetchAll(PDO::FETCH_COLUMN) as $sid) {
    foreach (getRacerBadges($pdo, $racerId, $sid) as $b) $yearBadges[$b['title']] = $b;
}
$yearBadges = array_values($yearBadges);

// Percentile + attendance rank from the roster.
$rosterList = array_values($roster);
$ahead = 0;
foreach ($rosterList as $other) if ((float)$other['points'] < (float)$points) $ahead++;
$pointsPercentile = count($rosterList) > 1 ? round($ahead / (count($rosterList) - 1) * 100) : 100;
$attendanceRank = 1;
foreach ($rosterList as $other) if ((int)$other['gps'] > $gps) $attendanceRank++;

// ── Pick Aura / Club / Personality ─────────────────────────────────────
$statBag = [
    'gps' => $gps, 'wins' => $wins, 'podiums' => $podiums, 'avg' => $avg, 'points' => $points,
    'win_rate' => $gps ? $wins / $gps : 0, 'podium_rate' => $gps ? $podiums / $gps : 0,
    'std_dev' => $stdDev, 'best_pts' => $best['pts'], 'has_perfect' => $hasPerfect,
    'peak_elo' => $peakElo ?? 0, 'elo_delta' => $eloDelta, 'lols' => $lols, 'lol_rate' => $lolRate,
    'distinct_chars' => $distinctChars, 'cups_raced' => $cupsRaced, 'cup_concentration' => $cupConc,
    'longest_podium_streak' => $longestPodium, 'comeback' => $comeback, 'attendance_rank' => $attendanceRank,
    'second_half_jump' => $secondHalfJump, 'top_group' => $topGroup, 'points_percentile' => $pointsPercentile,
];
$aura        = wrappedPick(wrappedAuras(), $statBag);
$club        = wrappedPick(wrappedClubs(), $statBag);
$personality = wrappedPick(wrappedPersonalities(), $statBag);

$pageTitle = htmlspecialchars($name) . " — $year Wrapped";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// Helper: portrait URL.
$portrait = fn($c) => '/assets/img/' . rawurlencode($c) . '.png';
?>

<?php if ($isPreview): ?>
<div style="background:#3a2a00;color:#ffd700;text-align:center;padding:6px;font-size:.8rem;">
    ⚠️ Admin preview — public sees this only in December.
</div>
<?php endif; ?>

<div class="wr-deck" id="wr-deck" style="--aura1:<?= $aura['grad'][0] ?>;--aura2:<?= $aura['grad'][1] ?>;">

    <!-- 1. Intro -->
    <section class="wr-slide wr-intro">
        <div class="wr-kicker"><?= htmlspecialchars($year) ?> WRAPPED</div>
        <div class="wr-big"><?= htmlspecialchars($name) ?>,</div>
        <div class="wr-lead">here's your year in karts.</div>
        <div class="wr-hint">tap / scroll ↓</div>
    </section>

    <!-- 2. GPs raced (+ estimated time played) -->
    <?php
        // Rough wheel-time estimate: a GP is 4 races plus character/track
        // select and results screens — call it ~18 min start to finish.
        $estMinutes = $gps * 18;
    ?>
    <section class="wr-slide">
        <div class="wr-kicker">YOU SHOWED UP</div>
        <div class="wr-huge"><?= $gps ?></div>
        <div class="wr-lead">Grand Prix raced this year.</div>
        <div class="wr-sub"><?= number_format($gps * 4) ?> individual races. <?= number_format($points) ?> points scored.</div>
        <div class="wr-sub">≈ <?= number_format($estMinutes) ?> minutes behind the wheel — about <?= number_format(round($estMinutes / 60)) ?> hours.</div>
    </section>

    <!-- 3. #1 racer -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR #1 RACER</div>
        <img src="<?= $portrait($sigChar) ?>" class="wr-portrait" onerror="this.src='/assets/img/Mii.png'">
        <div class="wr-big"><?= htmlspecialchars($sigChar) ?></div>
        <div class="wr-lead">rode shotgun <?= (int)reset($charTally) ?> times.</div>
    </section>

    <!-- 4. Character Evolution -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR EVOLUTION</div>
        <?php if (count(array_unique($phases)) <= 1): ?>
            <div class="wr-big"><?= htmlspecialchars($phases[0] ?? $sigChar) ?></div>
            <div class="wr-lead">all year long. Loyal to the end.</div>
        <?php else: ?>
            <div class="wr-phases">
                <?php foreach ($phases as $pi => $ph): ?>
                    <?php if ($pi > 0): ?><span class="wr-arrow">→</span><?php endif; ?>
                    <span class="wr-phase"><?= htmlspecialchars($ph) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="wr-lead">the racers that defined your phases.</div>
        <?php endif; ?>
    </section>

    <!-- 5. Character top 5 -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR TOP RACERS</div>
        <ol class="wr-list">
            <?php $i = 0; foreach ($topChars as $c => $n): $i++; ?>
                <li><span class="wr-list-rank"><?= $i ?></span><span class="wr-list-name"><?= htmlspecialchars($c) ?></span><span class="wr-list-val"><?= $n ?></span></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- 6. Favourite cup -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR FAVOURITE CUP</div>
        <div class="wr-emoji"><?= getMKCupEmoji($favCup) ?></div>
        <div class="wr-big"><?= htmlspecialchars($favCup) ?> Cup</div>
        <div class="wr-lead">raced <?= (int)reset($cupTally) ?> times — your home turf.</div>
    </section>

    <!-- 7. Best night (song of the year) -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR PEAK</div>
        <div class="wr-huge"><?= (int)$best['pts'] ?></div>
        <div class="wr-lead">
            points<?= $best['pts'] === 60 ? ' — a flawless 60!' : '' ?>
            <?php if (!empty($best['cup'])): ?><br><?= htmlspecialchars($best['cup']) ?> Cup<?php endif; ?>
            <?php if (!empty($best['date'])): ?> · <?= date('M j', strtotime($best['date'])) ?><?php endif; ?>
        </div>
    </section>

    <!-- 8. Nemesis -->
    <?php if ($nemesis): ?>
    <section class="wr-slide">
        <div class="wr-kicker">YOUR NEMESIS</div>
        <div class="wr-big"><?= htmlspecialchars($nemesis['name']) ?></div>
        <div class="wr-lead">
            <?= $nemesis['faced'] ?> meetings this year.<br>
            You finished ahead <strong><?= $nemesis['ahead'] ?></strong>, behind <strong><?= $nemesis['behind'] ?></strong>.
        </div>
    </section>
    <?php endif; ?>

    <!-- 9. The Climb -->
    <section class="wr-slide">
        <div class="wr-kicker">THE CLIMB</div>
        <?php if (count($monthlyElo) >= 2):
            $vals = array_values($monthlyElo); $lo = min($vals); $hi = max($vals); $span = max(1, $hi - $lo); ?>
            <div class="wr-climb">
                <?php foreach ($monthlyElo as $ym => $e): $h = 30 + (($e - $lo) / $span) * 90; ?>
                    <div class="wr-climb-col">
                        <div class="wr-climb-bar" style="height:<?= round($h) ?>px;"></div>
                        <div class="wr-climb-m"><?= date('M', strtotime($ym . '-01')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="wr-lead">
                Elo <?= (int)round($eloFirst) ?> → <strong><?= (int)round($eloLast) ?></strong>
                <span class="wr-delta <?= $eloDelta >= 0 ? 'pos' : 'neg' ?>">(<?= ($eloDelta >= 0 ? '+' : '') . $eloDelta ?>)</span>
            </div>
        <?php else: ?>
            <div class="wr-big"><?= $peakElo !== null ? (int)round($peakElo) : '—' ?></div>
            <div class="wr-lead">your peak Elo this year.</div>
        <?php endif; ?>
    </section>

    <!-- 10. Racing Personality -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR RACING PERSONALITY</div>
        <div class="wr-big wr-accent"><?= htmlspecialchars($personality['label']) ?></div>
        <div class="wr-lead"><?= htmlspecialchars($personality['blurb']) ?></div>
    </section>

    <!-- 11. Aura -->
    <section class="wr-slide wr-aura-slide">
        <div class="wr-kicker">YOUR AURA</div>
        <div class="wr-aura-orb"></div>
        <div class="wr-big"><?= htmlspecialchars($aura['label']) ?></div>
        <div class="wr-lead"><?= htmlspecialchars($aura['meaning'] ?? 'The colour your season gives off — equal parts how you raced and how it felt to race you.') ?></div>
    </section>

    <!-- 12. Percentile -->
    <section class="wr-slide">
        <div class="wr-kicker">THE FIELD</div>
        <div class="wr-huge"><?= (int)$pointsPercentile ?>%</div>
        <div class="wr-lead">
            You scored more than <?= (int)$pointsPercentile ?>% of the league this year.
            <?php if ($attendanceRank === 1): ?><br>And nobody raced more than you. 🏁<?php endif; ?>
        </div>
    </section>

    <!-- 13. Club -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR CLUB</div>
        <div class="wr-big wr-accent"><?= htmlspecialchars($club['name']) ?></div>
        <div class="wr-lead"><?= htmlspecialchars($club['blurb']) ?></div>
    </section>

    <!-- 14. Badges -->
    <section class="wr-slide">
        <div class="wr-kicker">YOUR HARDWARE</div>
        <div class="wr-huge"><?= count($yearBadges) ?></div>
        <div class="wr-lead">badge<?= count($yearBadges) === 1 ? '' : 's' ?> earned this year.</div>
        <?php if (!empty($yearBadges)): ?>
        <div class="wr-badge-fan">
            <?php foreach (array_slice($yearBadges, 0, 12) as $b): ?>
                <span class="wr-badge" title="<?= htmlspecialchars($b['desc']) ?>"><?= $b['icon'] ?> <?= htmlspecialchars($b['title']) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- 15. Summary card (shareable) -->
    <section class="wr-slide wr-summary-slide">
        <div class="wr-card" id="wr-card">
            <div class="wr-card-top"><span class="wr-year"><?= htmlspecialchars($year) ?></span><span class="wr-brand">WRAPPED</span></div>
            <div class="wr-card-hero">
                <img src="<?= $portrait($sigChar) ?>" class="wr-card-portrait" onerror="this.src='/assets/img/Mii.png'">
                <div>
                    <div class="wr-card-name"><?= htmlspecialchars($name) ?></div>
                    <div class="wr-card-persona"><?= htmlspecialchars($personality['label']) ?> · <?= htmlspecialchars($aura['label']) ?></div>
                </div>
            </div>
            <div class="wr-card-stats">
                <div><b><?= $gps ?></b><span>GPs</span></div>
                <div><b><?= $wins ?></b><span>wins</span></div>
                <div><b><?= $podiums ?></b><span>podiums</span></div>
                <div><b><?= $avg ?></b><span>avg</span></div>
            </div>
            <div class="wr-card-rows">
                <div><span>🏁 Signature</span><b><?= htmlspecialchars($sigChar) ?></b></div>
                <div><span>🏆 Cup</span><b><?= htmlspecialchars($favCup) ?></b></div>
                <div><span>🚀 Best night</span><b><?= (int)$best['pts'] ?> pts</b></div>
                <?php if ($peakElo !== null): ?><div><span>📈 Peak Elo</span><b><?= (int)round($peakElo) ?></b></div><?php endif; ?>
                <?php if ($nemesis): ?><div><span>⚔️ Nemesis</span><b><?= htmlspecialchars($nemesis['name']) ?></b></div><?php endif; ?>
                <div><span>🎟️ Club</span><b><?= htmlspecialchars($club['name']) ?></b></div>
            </div>
            <div class="wr-card-foot">🍄 <?= htmlspecialchars($leagueName) ?> · <?= htmlspecialchars($year) ?> Wrapped</div>
        </div>
        <button type="button" id="wr-dl" class="btn btn-primary" style="margin-top:18px;">📸 Download card</button>
        <div class="wr-restart"><a href="#wr-deck">↺ replay</a></div>
    </section>

    <!-- progress dots -->
    <div class="wr-progress" id="wr-progress"></div>
</div>

<style>
.wr-deck {
    position: relative;
    height: calc(100vh - 60px);
    overflow-y: scroll;
    scroll-snap-type: y mandatory;
    scroll-behavior: smooth;
    background: #0a0a0a;
    margin: -20px 0; /* bleed past the container padding */
}
.wr-slide {
    scroll-snap-align: start;
    height: calc(100vh - 60px);
    display: flex; flex-direction: column; justify-content: center; align-items: center;
    text-align: center; padding: 24px 28px; color: #fff; gap: 14px;
    background: radial-gradient(circle at 50% 35%, var(--aura1, #2a2150) -10%, #0a0a0a 70%);
}
.wr-intro { background: linear-gradient(160deg, var(--aura1,#241a48), var(--aura2,#120c28) 60%, #0a0a0a); }
.wr-kicker { font-size: .8rem; letter-spacing: 4px; font-weight: 800; color: #bba6ff; text-transform: uppercase; }
.wr-big  { font-size: clamp(2.4rem, 9vw, 4.2rem); font-weight: 900; line-height: 1.02; text-transform: uppercase; letter-spacing: -1px; }
.wr-huge { font-size: clamp(4rem, 22vw, 9rem); font-weight: 900; line-height: .9; color: #FFD700; }
.wr-accent { color: #FFD700; }
.wr-lead { font-size: 1.15rem; color: #e8e3ff; max-width: 30ch; line-height: 1.4; }
.wr-sub  { font-size: .9rem; color: #a99ed0; }
.wr-hint { position: absolute; bottom: 28px; font-size: .8rem; color: #8a7bc0; animation: wrbob 1.6s ease-in-out infinite; }
@keyframes wrbob { 0%,100%{transform:translateY(0);opacity:.6} 50%{transform:translateY(5px);opacity:1} }
.wr-portrait { width: 150px; height: 150px; object-fit: contain; filter: drop-shadow(0 8px 20px rgba(0,0,0,.6)); }
.wr-emoji { font-size: 5rem; }
.wr-list { list-style: none; padding: 0; margin: 0; width: min(420px, 90vw); }
.wr-list li { display: flex; align-items: center; gap: 14px; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,.1); font-size: 1.3rem; }
.wr-list-rank { font-weight: 900; color: #FFD700; width: 1.4em; }
.wr-list-name { flex: 1; text-align: left; font-weight: 700; }
.wr-list-val { color: #a99ed0; font-size: 1rem; }
.wr-phases { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: center; }
.wr-phase { font-size: clamp(1.3rem,6vw,2.2rem); font-weight: 900; text-transform: uppercase; }
.wr-arrow { color: #FFD700; font-size: 1.6rem; }
.wr-climb { display: flex; align-items: flex-end; gap: 8px; height: 130px; margin-bottom: 6px; }
.wr-climb-col { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.wr-climb-bar { width: 26px; border-radius: 5px 5px 0 0; background: linear-gradient(to top, var(--aura2,#1f6feb), #FFD700); }
.wr-climb-m { font-size: .7rem; color: #a99ed0; }
.wr-delta.pos { color: #2EBD59; } .wr-delta.neg { color: #ff6b6b; }
.wr-aura-orb {
    width: 160px; height: 160px; border-radius: 50%;
    background: radial-gradient(circle at 35% 30%, var(--aura1), var(--aura2));
    box-shadow: 0 0 60px 8px var(--aura1); animation: wrpulse 3s ease-in-out infinite;
}
@keyframes wrpulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.06)} }
.wr-badge-fan { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; max-width: 460px; margin-top: 6px; }
.wr-badge { background: rgba(255,215,0,.12); border: 1px solid rgba(255,215,0,.3); color: #ffe9a8; border-radius: 999px; padding: 3px 10px; font-size: .78rem; }
.wr-progress { position: fixed; top: 70px; left: 50%; transform: translateX(-50%); display: flex; gap: 5px; z-index: 50; }
.wr-progress span { width: 7px; height: 7px; border-radius: 50%; background: rgba(255,255,255,.25); transition: background .2s, transform .2s; }
.wr-progress span.on { background: #FFD700; transform: scale(1.3); }

/* Summary card (also the downloadable artifact) */
.wr-summary-slide { background: linear-gradient(160deg, var(--aura1,#241a48), #0a0a0a 70%); }
.wr-card { width: min(420px, 92vw); background: linear-gradient(160deg, #241a48, #120c28 55%, #0a0a0a); border: 1px solid #3a2d70; border-radius: 18px; padding: 22px 24px; box-shadow: 0 12px 40px rgba(80,40,160,.3); text-align: left; }
.wr-card-top { display: flex; justify-content: space-between; align-items: baseline; }
.wr-year { font-size: 1.8rem; font-weight: 900; color: #FFD700; }
.wr-brand { font-size: .9rem; font-weight: 900; letter-spacing: 4px; color: #bba6ff; }
.wr-card-hero { display: flex; align-items: center; gap: 14px; margin: 14px 0 16px; }
.wr-card-portrait { width: 72px; height: 72px; object-fit: contain; }
.wr-card-name { font-size: 1.5rem; font-weight: 900; text-transform: uppercase; line-height: 1; }
.wr-card-persona { color: #cbb9ff; font-size: .85rem; margin-top: 4px; font-style: italic; }
.wr-card-stats { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; margin-bottom: 14px; }
.wr-card-stats div { background: rgba(255,255,255,.05); border-radius: 9px; padding: 10px 4px; text-align: center; }
.wr-card-stats b { display: block; font-size: 1.4rem; color: #FFD700; line-height: 1; }
.wr-card-stats span { font-size: .65rem; text-transform: uppercase; color: #b6a8e0; }
.wr-card-rows { display: flex; flex-direction: column; gap: 7px; margin-bottom: 14px; }
.wr-card-rows div { display: flex; justify-content: space-between; font-size: .9rem; border-bottom: 1px solid rgba(255,255,255,.07); padding-bottom: 6px; }
.wr-card-rows span { color: #b6a8e0; } .wr-card-rows b { color: #fff; }
.wr-card-foot { text-align: center; color: #8a7bc0; font-size: .75rem; border-top: 1px solid rgba(255,255,255,.08); padding-top: 10px; }
.wr-restart { margin-top: 10px; } .wr-restart a { color: #8a7bc0; font-size: .85rem; text-decoration: none; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
<script>
(function () {
    const deck   = document.getElementById('wr-deck');
    const slides = Array.from(deck.querySelectorAll('.wr-slide'));
    const prog   = document.getElementById('wr-progress');
    slides.forEach(() => { const d = document.createElement('span'); prog.appendChild(d); });
    const dots = Array.from(prog.children);

    function current() {
        const top = deck.scrollTop;
        let idx = Math.round(top / deck.clientHeight);
        return Math.max(0, Math.min(slides.length - 1, idx));
    }
    function paint() { const c = current(); dots.forEach((d, i) => d.classList.toggle('on', i === c)); }
    function go(i) { const t = Math.max(0, Math.min(slides.length - 1, i)); deck.scrollTo({ top: t * deck.clientHeight, behavior: 'smooth' }); }

    deck.addEventListener('scroll', paint, { passive: true });
    paint();

    // Tap right half = next, left half = prev (ignore taps on links/buttons).
    deck.addEventListener('click', (e) => {
        if (e.target.closest('a,button')) return;
        const x = e.clientX - deck.getBoundingClientRect().left;
        go(current() + (x > deck.clientWidth / 2 ? 1 : -1));
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); go(current() + 1); }
        if (e.key === 'ArrowUp'   || e.key === 'ArrowLeft')                    { e.preventDefault(); go(current() - 1); }
    });

    document.getElementById('wr-dl')?.addEventListener('click', () => {
        const card = document.getElementById('wr-card');
        html2canvas(card, { backgroundColor: null, scale: 2 }).then(canvas => {
            const a = document.createElement('a');
            a.download = <?= json_encode($name . '-' . $year . '-wrapped.png') ?>;
            a.href = canvas.toDataURL('image/png');
            a.click();
        });
    });
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
