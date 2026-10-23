<?php
/**
 * The racers — /racers
 *
 * The public roster: everyone who has ever been on the league's books, each
 * linking to their profile. The only list of racers used to be /admin/racers,
 * behind the login.
 *
 *   Racing this season  — in current standings order (leaderboardRows(), so
 *                         the order matches the homepage)
 *   The rest of the roster — most recently raced first; never-raced last
 *   Retired             — racers.is_retired
 *
 * Cost: one career aggregate over results, one most-used-character query,
 * one champions query, plus the current season's (cached) standings — never
 * a query per racer (§9).
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/leaderboard.php';

$seasonId = getCurrentSeasonNumber();

$racers = $pdo->query("SELECT id, name, nickname, catchphrase, in_mikkoliiga, is_retired FROM racers ORDER BY name COLLATE NOCASE ASC, id ASC")
              ->fetchAll(PDO::FETCH_ASSOC);

// Career numbers, season GPs only (tournament results are t…-prefixed).
$career = [];
foreach ($pdo->query("
    SELECT racer_id, COUNT(*) AS gps, SUM(rank = 1) AS wins, MAX(gp_points) AS best,
           COUNT(DISTINCT substr(gpid, 1, 3)) AS seasons,
           MIN(date(race_date)) AS first, MAX(date(race_date)) AS last
    FROM results WHERE gpid LIKE 's%' GROUP BY racer_id
")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $career[(int)$c['racer_id']] = $c;
}

// Each racer's most-used character over their career (ties alphabetical, §10).
$character = [];
foreach ($pdo->query("
    SELECT racer_id, character_used, COUNT(*) AS n FROM results
    WHERE gpid LIKE 's%' AND character_used IS NOT NULL AND character_used != ''
    GROUP BY racer_id, character_used
    ORDER BY racer_id ASC, n DESC, character_used ASC
")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $character[(int)$c['racer_id']] ??= $c['character_used'];
}

// Season titles, from the champion frozen into each archived season.
$titles = [];
foreach ($pdo->query("SELECT champion_name FROM season_meta WHERE status = 'archived' AND champion_name IS NOT NULL AND champion_name != ''")
             ->fetchAll(PDO::FETCH_COLUMN) as $champ) {
    $titles[mb_strtolower($champ)] = ($titles[mb_strtolower($champ)] ?? 0) + 1;
}

// This season, in the homepage's order.
$current = [];
foreach (leaderboardRows($pdo, $seasonId, 0, false) as $i => $row) {
    $current[(int)$row['id']] = $row + ['order' => $i];
}
$systemName = getScoringSystemInfo($pdo, $seasonId)['name'] ?? '';

$now = []; $rest = []; $retired = [];
foreach ($racers as $r) {
    $id = (int)$r['id'];
    if (!empty($r['is_retired']))  $retired[] = $r;
    elseif (isset($current[$id]))  $now[] = $r;
    else                           $rest[] = $r;
}
usort($now, fn($a, $b) => $current[(int)$a['id']]['order'] <=> $current[(int)$b['id']]['order']);
$lastRaced = fn($r) => $career[(int)$r['id']]['last'] ?? '';
usort($rest, fn($a, $b) => strcmp($lastRaced($b), $lastRaced($a)) ?: strcasecmp($a['name'], $b['name']));
usort($retired, fn($a, $b) => strcmp($lastRaced($b), $lastRaced($a)) ?: strcasecmp($a['name'], $b['name']));

/** One roster card. */
function rosterCard(array $r, array $career, array $character, array $titles, array $current, string $seasonId): string {
    $id   = (int)$r['id'];
    $c    = $career[$id] ?? null;
    $char = $character[$id] ?? 'Mii';
    $t    = $titles[mb_strtolower($r['name'])] ?? 0;
    $cur  = $current[$id] ?? null;
    $search = mb_strtolower($r['name'] . ' ' . ($r['nickname'] ?? ''));

    $stats = $c
        ? sprintf('%d GP%s · %d win%s · best %d · %d season%s · since %s',
            $c['gps'], $c['gps'] == 1 ? '' : 's', $c['wins'], $c['wins'] == 1 ? '' : 's', $c['best'],
            $c['seasons'], $c['seasons'] == 1 ? '' : 's', date('M Y', strtotime($c['first'])))
        : 'Yet to race';

    $chips = '';
    if ($t > 0) $chips .= '<span class="roster-chip roster-chip--title" title="Season champion">🏆' . ($t > 1 ? ' ×' . $t : '') . '</span>';
    if (!empty($r['in_mikkoliiga'])) $chips .= '<span class="roster-chip" title="Mikkoliiga member">🌟</span>';
    if ($cur) {
        $label = $cur['rank'] !== null ? '#' . $cur['rank'] . ' in ' . strtoupper($seasonId) : strtoupper($seasonId) . ' · not yet ranked';
        $chips .= '<span class="roster-chip roster-chip--now" title="' . htmlspecialchars((string)$cur['tooltip']) . '">' . htmlspecialchars($label) . '</span>';
    }

    return '<a class="roster-card" href="/racer/' . $id . '" data-search="' . htmlspecialchars($search) . '">'
         . '<img class="roster-portrait" src="/assets/img/' . rawurlencode($char) . '.png" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'/assets/img/Mii.png\'">'
         . '<span class="roster-body">'
         .   '<span class="roster-name">' . htmlspecialchars($r['name']) . '</span>'
         .   (trim((string)$r['nickname']) !== '' ? '<span class="roster-nick">“' . htmlspecialchars($r['nickname']) . '”</span>' : '')
         .   (trim((string)$r['catchphrase']) !== '' ? '<span class="roster-quote">' . htmlspecialchars($r['catchphrase']) . '</span>' : '')
         .   '<span class="roster-stats">' . htmlspecialchars($stats) . '</span>'
         .   ($chips !== '' ? '<span class="roster-chips">' . $chips . '</span>' : '')
         . '</span></a>';
}

$pageTitle = 'The racers';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container roster-page">
    <div class="roster-head">
        <h1 class="roster-title">The racers</h1>
        <p class="roster-sub"><?= count($racers) ?> on the books · <?= count($now) ?> racing in <?= htmlspecialchars(strtoupper($seasonId)) ?><?= $systemName !== '' ? ' (' . htmlspecialchars($systemName) . ')' : '' ?></p>
        <input type="search" id="roster-filter" class="roster-filter" placeholder="Find a racer…" aria-label="Find a racer by name or nickname">
    </div>

    <?php foreach ([
        ['Racing this season', $now, 'In the order of the current standings.'],
        ['The rest of the roster', $rest, 'Most recently raced first.'],
        ['Retired', $retired, ''],
    ] as [$heading, $list, $note]): if (!$list) continue; ?>
    <section class="roster-section">
        <h2 class="roster-heading"><?= htmlspecialchars($heading) ?> <span class="roster-count"><?= count($list) ?></span></h2>
        <?php if ($note !== ''): ?><p class="roster-note"><?= htmlspecialchars($note) ?></p><?php endif; ?>
        <div class="roster-grid">
            <?php foreach ($list as $r) echo rosterCard($r, $career, $character, $titles, $current, $seasonId); ?>
        </div>
    </section>
    <?php endforeach; ?>
    <p class="roster-none" hidden>No racer by that name.</p>
</div>

<script>
(function () {
    const box = document.getElementById('roster-filter');
    const none = document.querySelector('.roster-none');
    box.addEventListener('input', function () {
        const q = box.value.trim().toLowerCase();
        let shown = 0;
        document.querySelectorAll('.roster-section').forEach(function (sec) {
            let inSec = 0;
            sec.querySelectorAll('.roster-card').forEach(function (card) {
                const hit = q === '' || card.dataset.search.indexOf(q) !== -1;
                card.hidden = !hit;
                if (hit) inSec++;
            });
            sec.hidden = inSec === 0;
            shown += inSec;
        });
        none.hidden = shown !== 0;
    });
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
