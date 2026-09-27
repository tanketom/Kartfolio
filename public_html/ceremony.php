<?php
/**
 * Awards night — /display/ceremony[?season=sNN]
 *
 * A presenter screen for the last night of a season: Kartificial hosts, the
 * season's awards are opened one envelope at a time, then the standings are
 * read out from the bottom up and the podium is revealed third, second, first.
 *
 * Driven by a clicker or the keyboard: → / Space / PageDown / click to go on,
 * ← / PageUp to go back, F for fullscreen, Home to start over. The step lives
 * in the URL hash, so a reload (or a browser that crashes mid-ceremony) comes
 * back to the same envelope rather than the title card.
 *
 * Everything shown is read, never computed differently from the rest of the
 * site: the standings are leaderboardRows() (the homepage's builder, qualifiers
 * only — a racer below the threshold holds no place to read out), the awards
 * are season_awards rows marked final, Mikkoliiga is getMikkoliigaStandings()
 * and fantasy is the frozen champion on an archived season or
 * fantasyLeaderboard() on a live one. Kartificial's lines are built here from
 * those facts, not by a model, so nothing can stall a room full of people.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/assets.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/leaderboard.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/fantasy.php';

$seasonId = getCurrentSeasonNumber();
$asked = strtolower((string)($_GET['season'] ?? ''));
if (preg_match('/^s[0-9]{2}$/', $asked)) {
    $st = $pdo->prepare("SELECT 1 FROM season_meta WHERE season_id = ?");
    $st->execute([$asked]);
    if ($st->fetchColumn()) $seasonId = $asked;
}

$st = $pdo->prepare("SELECT status, season_name, fantasy_champion, fantasy_champion_points FROM season_meta WHERE season_id = ?");
$st->execute([$seasonId]);
$meta = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$archived   = ($meta['status'] ?? '') === 'archived';
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$seasonName = trim((string)($meta['season_name'] ?? '')) ?: 'Season ' . (int)substr($seasonId, 1);
$systemName = getScoringSystemInfo($pdo, $seasonId)['name'] ?? '';

$rows   = leaderboardRows($pdo, $seasonId, 0, false);
$ranked = array_values(array_filter($rows, fn($r) => $r['rank'] !== null));
$unranked = count($rows) - count($ranked);
$charOf = [];
foreach ($rows as $r) $charOf[mb_strtolower($r['name'])] = (string)($r['char'] ?? '');

/** Scores are whole numbers on most systems; show decimals only when there are some. */
function ceremonyScore($score): string {
    $f = (float)$score;
    return abs($f - round($f)) < 0.005 ? number_format($f, 0) : number_format($f, 2);
}
function ceremonyOrdinal(int $n): string {
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

// ── The honours ──────────────────────────────────────────────────────────
$honours = [];
try {
    $st = $pdo->prepare("SELECT award_category, winner_name FROM season_awards
                         WHERE season_id = ? AND status = 'final' AND award_category != 'Champion'
                         ORDER BY id ASC");
    $st->execute([$seasonId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $honours[] = ['category' => $a['award_category'], 'winner' => $a['winner_name'], 'sub' => ''];
    }
} catch (PDOException $e) {}

try {
    $mikko = getMikkoliigaStandings($pdo, $seasonId);
    if (count($mikko) >= 2 && (int)$mikko[0]['score'] > 0) {
        $honours[] = ['category' => 'Mikkoliiga Champion', 'winner' => $mikko[0]['name'],
                      'sub' => $mikko[0]['score'] . ' points from ' . $mikko[0]['gps_counted'] . ' GPs'];
    }
} catch (Throwable $e) {}

$fantasyName = null; $fantasyPts = 0;
if ($archived && !empty($meta['fantasy_champion'])) {
    $fantasyName = (string)$meta['fantasy_champion'];
    $fantasyPts  = (int)$meta['fantasy_champion_points'];
} else {
    try {
        $board = fantasyLeaderboard($pdo, $seasonId);
        if ($board && (float)$board[0]['total_points'] > 0) {
            $top = (float)$board[0]['total_points'];
            $w = [];
            foreach ($board as $b) { if ((float)$b['total_points'] < $top) break; $w[] = $b['display_name']; }
            $fantasyName = implode(' & ', $w);
            $fantasyPts  = (int)$top;
        }
    } catch (Throwable $e) {}
}
if ($fantasyName) {
    $honours[] = ['category' => 'Fantasy Champion', 'winner' => $fantasyName, 'sub' => $fantasyPts . ' fantasy points'];
}

// ── The running order ────────────────────────────────────────────────────
// Kartificial's lines are picked by position, so a rehearsal and the real
// thing say the same words.
$pick = fn(array $lines, int $i) => $lines[$i % count($lines)];
$steps = [];

$steps[] = [
    'type' => 'title', 'kicker' => $archived ? 'Awards night' : 'Awards night · provisional',
    'title' => $seasonName, 'sub' => $leagueName,
    'host' => "Good evening. I'm Kartificial. I have been handed a stack of envelopes and told not to open them early. I opened them early.",
];

$askLines = [
    'The envelope, please.',
    "I'd like to thank the Academy. Wrong speech. The envelope.",
    'No peeking. I peeked.',
    'Drumroll. Somebody. Anybody.',
    'This one was close. I am told they were all close.',
];
if ($honours) {
    $steps[] = ['type' => 'section', 'kicker' => 'Part one', 'title' => 'The honours',
                'sub' => count($honours) . ' envelopes',
                'host' => 'First, the awards. Applause after each one, please — I have a quota.'];
    foreach ($honours as $i => $h) {
        $steps[] = ['type' => 'ask', 'kicker' => 'The award for', 'title' => $h['category'],
                    'host' => $pick($askLines, $i)];
        $steps[] = ['type' => 'reveal', 'kicker' => $h['category'], 'title' => $h['winner'],
                    'sub' => $h['sub'], 'char' => $charOf[mb_strtolower($h['winner'])] ?? '',
                    'host' => 'Congratulations, ' . $h['winner'] . '.'];
    }
}

$n = count($ranked);
if ($n > 0) {
    $excused = $unranked > 0
        ? ' ' . $unranked . ($unranked === 1 ? ' racer' : ' racers') . " didn't reach the threshold and can relax."
        : '';
    $steps[] = ['type' => 'section', 'kicker' => $honours ? 'Part two' : 'The main event',
                'title' => $archived ? 'The final standings' : 'The standings',
                'sub' => $systemName !== '' ? 'Scored by ' . $systemName : '',
                'host' => "From the bottom up. $n places to read out." . $excused];

    // The board: everyone below the podium, one row per click. On a very big
    // roster the tail arrives together so the room isn't clicking for ages.
    $board = [];
    foreach (array_reverse(array_slice($ranked, 3)) as $r) {
        $board[] = ['rank' => ceremonyOrdinal((int)$r['rank']), 'name' => $r['name'],
                    'score' => ceremonyScore($r['score']), 'char' => (string)($r['char'] ?? ''),
                    'gps' => (int)$r['raceCount'], 'tie' => $r['tie'] ?? null];
    }
    $batch = max(0, count($board) - 9);
    if ($batch > 0) {
        $steps[] = ['type' => 'board', 'upto' => $batch, 'host' => 'The back of the grid, all at once. You all know who you are.'];
    }
    for ($i = $batch; $i < count($board); $i++) {
        $steps[] = ['type' => 'board', 'upto' => $i + 1,
                    'host' => $board[$i]['rank'] . ': ' . $board[$i]['name'] . '.'
                              . ($board[$i]['tie'] ? ' ' . $board[$i]['tie'] . '.' : '')];
    }

    $podiumHost = [
        3 => 'Third. Close enough to see the trophy. Not close enough to lick it.',
        2 => 'Second. The first of the losers — my words, not the league\'s. Actually, the league\'s.',
        1 => 'Your champion.',
    ];
    foreach ([3, 2, 1] as $place) {
        $r = $ranked[$place - 1] ?? null;
        if (!$r) continue;
        if ($place === 1) {
            $steps[] = ['type' => 'drum', 'kicker' => 'And the champion of', 'title' => $seasonName,
                        'host' => $n > 1
                            ? 'One name left. ' . $ranked[1]['name'] . ' finished second, so it isn\'t them.'
                            : 'One name left.'];
        }
        $steps[] = ['type' => 'podium', 'place' => $place,
                    'kicker' => ['1' => 'Champion', '2' => 'Second place', '3' => 'Third place'][(string)$place],
                    'title' => $r['name'], 'char' => (string)($r['char'] ?? ''),
                    'sub' => ceremonyScore($r['score']) . ($systemName !== '' ? ' · ' . $systemName : '')
                             . ' · ' . (int)$r['raceCount'] . ' GPs',
                    'tie' => $r['tie'] ?? null,
                    'host' => $podiumHost[$place]];
    }

    $podium = array_map(fn($r) => ['name' => $r['name'], 'char' => (string)($r['char'] ?? ''),
                                   'score' => ceremonyScore($r['score'])], array_slice($ranked, 0, 3));
    $steps[] = ['type' => 'finale', 'kicker' => $seasonName, 'title' => $ranked[0]['name'],
                'podium' => $podium,
                'host' => "That's the season. Thank you all for coming. Please take your karts with you."];
} else {
    $steps[] = ['type' => 'section', 'kicker' => $seasonName, 'title' => 'No standings yet',
                'sub' => 'Nobody has qualified this season.',
                'host' => "I prepared a whole speech. It'll keep."];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Awards night — <?= htmlspecialchars($seasonName) ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/omk-favicon-32.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/global.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/ceremony.css') ?>">
</head>
<body class="cer-body">
    <div class="cer-stage" id="stage">
        <div class="cer-top">
            <span class="cer-league"><?= htmlspecialchars($leagueName) ?></span>
            <span class="cer-season"><?= htmlspecialchars(strtoupper($seasonId)) ?></span>
        </div>

        <main class="cer-main" id="main" aria-live="polite"></main>

        <div class="cer-host">
            <img class="cer-host-face" src="<?= assetUrl('/assets/img/kartificial.png') ?>" alt="Kartificial">
            <div class="cer-host-bubble" id="host"></div>
        </div>

        <div class="cer-progress" id="progress"></div>
        <div class="cer-confetti" id="confetti" aria-hidden="true"></div>
    </div>

    <script id="cer-steps" type="application/json"><?= jsonForScript($steps) ?></script>
    <script id="cer-board" type="application/json"><?= jsonForScript($board ?? []) ?></script>
    <script src="<?= assetUrl('/assets/js/ceremony.js') ?>"></script>
</body>
</html>
