<?php
/**
 * Season Yearbook — the printable end of a season, as an eight-page zine.
 *
 * Eight A4 sheets rendered in the browser and captured to a PDF with the same
 * jsPDF + html2canvas pipeline the trading cards use. Two of the eight carry
 * the season's own written summary (season_meta.ecology_report), split into an
 * opener and a two-column feature; the closing line lands on the back page.
 *
 * Eight is the zine number: printed two-up on landscape A4 in saddle-stitch
 * order (8·1 / 2·7 / 6·3 / 4·5) it folds into an A5 booklet, which is what the
 * "Booklet PDF" button builds.
 *
 * Archived seasons only — a yearbook is written after the fact, and the frozen
 * placements, map and fantasy champion only exist once a season closes.
 *
 * Path: /cdnmk/public_html/yearbook.php   (clean URL: /season-yearbook?season=sNN)
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/assets.php';

$sid = preg_match('/^s\d{2}$/', (string)($_GET['season'] ?? '')) ? $_GET['season'] : '';
$stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ? AND status = 'archived'");
$stmt->execute([$sid]);
$season = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$season) {
    http_response_code(404);
    $pageTitle = 'Yearbook unavailable';
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="container"><div class="empty-state"><h1>No yearbook yet</h1>'
       . '<p>A yearbook is written when a season is archived. Pick a closed season from the Hall of Fame.</p>'
       . '<p><a class="btn btn-secondary" href="/season-archives">← Hall of Fame</a></p></div></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

/**
 * Split the season's written summary into the pieces the zine needs: its own
 * title, an opening for page 2, a body for page 6 and a closing line for the
 * back page. Memo boilerplate ("To:", "From:", "Date of Record:") and horizontal
 * rules are dropped — they are archive-report furniture, not reading matter.
 */
function yearbookStory(?string $raw): array {
    $out = ['title' => '', 'lede' => [], 'body' => [], 'closing' => '', 'truncated' => false];
    $memo = '/^\*{0,3}(To|From|Date|Subject|Prepared by|Archival Designation|Date of Record|Reference|Classification|Filed)\b/i';
    $text = trim(preg_replace('/^\s*1\.?\s*HEADLINE:/i', '', (string)$raw));
    if ($text === '') return $out;

    $paras = [];
    foreach (preg_split('/\n\s*\n/', $text) as $p) {
        $lines = [];
        foreach (explode("\n", trim($p)) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^[-–—_*\s]+$/', $line)) continue;   // rules
            if (preg_match($memo, $line)) continue;
            $lines[] = $line;
        }
        if ($lines) $paras[] = implode("\n", $lines);
    }
    if (!$paras) return $out;

    // A short, fully-bold first paragraph is the report's own title.
    if (preg_match('/^\*\*(.+?)\*\*$/s', trim($paras[0]), $m) && mb_strlen($m[1]) <= 140) {
        $out['title'] = trim($m[1], " \t*_–—-");
        array_shift($paras);
    }
    if (!$paras) return $out;

    // The last substantial paragraph becomes the back page's closing line.
    for ($i = count($paras) - 1; $i >= 0 && $i >= count($paras) - 3; $i--) {
        $len = mb_strlen($paras[$i]);
        if ($len >= 60 && $len <= 600) { $out['closing'] = $paras[$i]; array_splice($paras, $i, 1); break; }
    }

    // Page budgets, in characters: what actually fits an A4 sheet at reading
    // size. Anything past the feature's budget is left to the season report,
    // which carries the report in full — the zine is an edit, not a reprint.
    $ledeUsed = 0; $bodyUsed = 0;
    foreach ($paras as $p) {
        $len = mb_strlen($p);
        // Stop BEFORE the opener overruns rather than after: a single long
        // paragraph used to push page two off the bottom of the sheet.
        if (!$out['lede'] || ($ledeUsed + $len <= 1250 && count($out['lede']) < 4)) {
            $out['lede'][] = $p; $ledeUsed += $len; continue;
        }
        if (!$out['body'] || $bodyUsed + $len <= 3300) { $out['body'][] = $p; $bodyUsed += $len; continue; }
        $out['truncated'] = true;
    }
    // Never end the opener on one of the report's section headings — it would
    // sit there introducing nothing, its text having gone to the next page.
    while ($out['lede'] && ybIsHeading(end($out['lede']))) array_unshift($out['body'], array_pop($out['lede']));
    return $out;
}

/** A paragraph that is nothing but a short bold line is one of the report's own section headings. */
function ybIsHeading(string $p): bool {
    $p = trim($p);
    return (bool)preg_match('/^\*\*[^*]+\*\*$/', $p) && mb_strlen($p) <= 94;
}

/** The first sentence worth setting large, for page two's pull quote. */
function ybPullQuote(array $paras): string {
    foreach ($paras as $p) {
        if (ybIsHeading($p)) continue;
        foreach (preg_split('/(?<=[.!?])\s+/', strip_tags($p)) as $sentence) {
            $sentence = trim(str_replace('**', '', $sentence));
            $len = mb_strlen($sentence);
            if ($len >= 70 && $len <= 190) return $sentence;
        }
    }
    return '';
}

/** **bold** and line breaks, escaped first. Paragraph-level, for the zine's prose. */
function ybProse(string $p): string {
    $p = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
    $p = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $p);
    $p = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $p);
    return nl2br($p);
}

$leagueName  = getSetting($pdo, 'league_name', 'Kartfolio League');
$scoringInfo = getScoringSystemInfo($pdo, $sid);
$rules       = getSeasonRules($pdo, $sid);
$names       = racerNamesMap($pdo);
$bySeason    = getSeasonResultsByRacer($pdo, $sid);
$story       = yearbookStory($season['ecology_report'] ?? '');

// ── Season shape ───────────────────────────────────────────────────────────
$gpRow = $pdo->prepare("SELECT COUNT(DISTINCT gpid) AS gps, COUNT(DISTINCT race_date) AS nights, COUNT(DISTINCT cup_name) AS cups, MIN(race_date) AS first_date, MAX(race_date) AS last_date, SUM(gp_points) AS points FROM results WHERE gpid LIKE ?");
$gpRow->execute([$sid . '%']);
$shape = $gpRow->fetch(PDO::FETCH_ASSOC) ?: ['gps' => 0, 'nights' => 0, 'cups' => 0, 'first_date' => null, 'last_date' => null, 'points' => 0];

// ── Final standings: the frozen placements, with live scores for display ───
$placed = [];
foreach (archivedSeasonPlacements($pdo) as $rid => $rows)
    foreach ($rows as [$s, $place, $field]) if ($s === $sid) $placed[(int)$rid] = $place;

$standings = [];
foreach ($bySeason as $rid => $rows) {
    $rid = (int)$rid;
    $pts = array_map(fn($r) => (int)$r['gp_points'], $rows);
    $standings[] = [
        'id'    => $rid,
        'name'  => $names[$rid] ?? "Racer $rid",
        'place' => $placed[$rid] ?? null,
        'score' => calculateGPScore($pdo, $rid, $sid),
        'gps'   => count($rows),
        'avg'   => $pts ? array_sum($pts) / count($pts) : 0,
        'best'  => $pts ? max($pts) : 0,
        'wins'  => count(array_filter($rows, fn($r) => (int)$r['rank'] === 1)),
        'char'  => getMostUsedCharacter($pdo, $rid, $sid),
    ];
}
usort($standings, function ($a, $b) {
    if ($a['place'] !== null && $b['place'] !== null) return $a['place'] <=> $b['place'];
    if ($a['place'] !== null) return -1;
    if ($b['place'] !== null) return 1;
    return $b['score'] <=> $a['score'];
});
$champion     = $season['champion_name'] ?: ($standings[0]['name'] ?? '—');
$championChar = $season['champion_char'] ?: ($standings[0]['char'] ?? 'Mii');

// ── Superlatives: one pass over the season cache ───────────────────────────
$agg = [];
foreach ($bySeason as $rid => $rows) {
    $rid = (int)$rid;
    $pts = array_map(fn($r) => (int)$r['gp_points'], $rows);
    $agg[$rid] = [
        'name' => $names[$rid] ?? "Racer $rid",
        'gps' => count($rows),
        'avg' => $pts ? array_sum($pts) / count($pts) : 0,
        'best' => $pts ? max($pts) : 0,
        'wins' => count(array_filter($rows, fn($r) => (int)$r['rank'] === 1)),
        'podiums' => count(array_filter($rows, fn($r) => (int)$r['rank'] <= 3)),
        'perfects' => count(array_filter($pts, fn($p) => $p === MK_MAX_GP_POINTS)),
        'lols' => count(array_filter($rows, fn($r) => !empty($r['is_lol']))),
        'bestRow' => null,
    ];
    foreach ($rows as $r) if ($agg[$rid]['bestRow'] === null || (int)$r['gp_points'] > (int)$agg[$rid]['bestRow']['gp_points']) $agg[$rid]['bestRow'] = $r;
}
$topOf = function (string $field, int $minGps = 0) use ($agg) {
    $bestRow = null;
    foreach ($agg as $a) {
        if ($a['gps'] < $minGps) continue;
        if ($bestRow === null || $a[$field] > $bestRow[$field] || ($a[$field] == $bestRow[$field] && strcmp($a['name'], $bestRow['name']) < 0)) $bestRow = $a;
    }
    return $bestRow;
};
$minForAvg = max(3, (int)($rules['min_races_threshold'] ?? 3));
$superlatives = [];
if ($t = $topOf('best'))            $superlatives[] = ['🏆', 'Highest single GP', $t['name'], (int)$t['best'] . ' pts', trim(($t['bestRow']['cup_name'] ?? '') . ' Cup · ' . date('M j', strtotime((string)$t['bestRow']['race_date'])))];
if ($t = $topOf('wins'))            $superlatives[] = ['🥇', 'Most GP wins', $t['name'], $t['wins'] . ($t['wins'] === 1 ? ' win' : ' wins'), round(100 * $t['wins'] / max(1, $t['gps'])) . '% of ' . $t['gps'] . ' GPs'];
if ($t = $topOf('podiums'))         $superlatives[] = ['🏅', 'Most podiums', $t['name'], (string)$t['podiums'], round(100 * $t['podiums'] / max(1, $t['gps'])) . '% of ' . $t['gps'] . ' GPs'];
if ($t = $topOf('avg', $minForAvg)) $superlatives[] = ['📈', 'Best average', $t['name'], number_format($t['avg'], 2) . ' pts/GP', 'over ' . $t['gps'] . ' GPs'];
if ($t = $topOf('gps'))             $superlatives[] = ['🦾', 'Most GPs raced', $t['name'], (string)$t['gps'], 'of ' . (int)$shape['gps'] . ' in the season'];
if (($t = $topOf('perfects')) && $t['perfects'] > 0) $superlatives[] = ['💯', 'Most perfect 60s', $t['name'], (string)$t['perfects'], 'a flawless Grand Prix'];
if (($t = $topOf('lols')) && $t['lols'] > 0)         $superlatives[] = ['😈', 'Most LOLs', $t['name'], (string)$t['lols'], 'Ludwig Obstruction Law'];

// ── Head to head: the top racers' grid, plus the season's tightest pair ────
$matchups  = seasonMatchups($pdo, $sid);
$gridNames = array_slice(array_values(array_filter($standings, fn($r) => $r['place'] !== null)), 0, 8);
$closest = null; $lopsided = null;
$seen = [];
foreach ($matchups as $a => $row) {
    foreach ($row as $b => $pair) {
        if ($a >= $b || (int)$pair['total'] < 8) continue;      // each pair once, meaningful sample only
        $key = $a . '-' . $b;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $ratio = $pair['wins'] / max(1, $pair['total']);
        $entry = ['a' => $names[$a] ?? '?', 'b' => $names[$b] ?? '?', 'wins' => (int)$pair['wins'], 'total' => (int)$pair['total'], 'ratio' => $ratio];
        if ($closest === null || abs($ratio - .5) < abs($closest['ratio'] - .5)) $closest = $entry;
        $far = max($ratio, 1 - $ratio);
        if ($lopsided === null || $far > max($lopsided['ratio'], 1 - $lopsided['ratio'])) $lopsided = $entry;
    }
}

// ── Awards, badges, sub-leagues, map ───────────────────────────────────────
$awStmt = $pdo->prepare("SELECT award_category, winner_name FROM season_awards WHERE season_id = ? ORDER BY id ASC");
$awStmt->execute([$sid]);
$awards = $awStmt->fetchAll(PDO::FETCH_ASSOC);

$badgeWall = [];
foreach (array_slice($standings, 0, 14) as $row) {
    $b = getRacerBadges($pdo, $row['id'], $sid);
    if ($b) $badgeWall[] = ['name' => $row['name'], 'badges' => array_slice($b, 0, 14)];
}

$finalMap = seasonMapPayload($pdo, $sid);
$mikko    = function_exists('getMikkoliigaStandings') ? array_values(array_filter(getMikkoliigaStandings($pdo, $sid), fn($m) => ($m['total_gps'] ?? 0) > 0)) : [];

$seasonName = trim((string)($season['season_name'] ?? '')) ?: 'Season ' . strtoupper($sid);
$dateRange  = $shape['first_date'] ? date('j M Y', strtotime($shape['first_date'])) . ' – ' . date('j M Y', strtotime((string)$shape['last_date'])) : '';

$pageTitle = 'Yearbook · Season ' . strtoupper($sid);
$extraCss  = '<link rel="stylesheet" href="/assets/css/yearbook.css">';
include __DIR__ . '/../private/templates/header.php';
?>
<div class="container yb-shell">
    <div class="yb-toolbar">
        <div>
            <h1 class="yb-toolbar-title">📖 Season <?= strtoupper($sid) ?> Yearbook</h1>
            <p class="yb-toolbar-sub">Eight A4 pages. Save them straight, or as a folded booklet: print the booklet double-sided on the short edge, fold in half, staple the spine.</p>
        </div>
        <div class="yb-toolbar-actions">
            <button type="button" id="yb-pdf" class="btn btn-primary">📄 PDF (8 pages)</button>
            <button type="button" id="yb-zine" class="btn btn-secondary">📕 Booklet PDF</button>
            <button type="button" onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>
            <a href="/view-season-report?season=<?= htmlspecialchars($sid) ?>" class="btn btn-secondary">Season report</a>
        </div>
    </div>

    <div class="yb-pages">

        <!-- ── 1. Cover ───────────────────────────────────────────────── -->
        <section class="yb-page yb-cover">
            <div class="yb-cover-top">
                <div class="yb-league"><?= htmlspecialchars($leagueName) ?></div>
                <div class="yb-org">Organisation Mondial du Karting</div>
            </div>
            <div class="yb-cover-mid">
                <div class="yb-season-no">SEASON <?= strtoupper(substr($sid, 1)) ?></div>
                <h2 class="yb-season-name"><?= htmlspecialchars($seasonName) ?></h2>
                <div class="yb-dates"><?= htmlspecialchars($dateRange) ?></div>
                <div class="yb-champ">
                    <img src="/assets/img/<?= rawurlencode($championChar) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="" class="yb-champ-img">
                    <div class="yb-champ-label">Champion</div>
                    <div class="yb-champ-name"><?= htmlspecialchars($champion) ?></div>
                </div>
            </div>
            <div class="yb-cover-facts">
                <div class="yb-fact"><b><?= (int)$shape['gps'] ?></b><span>Grands Prix</span></div>
                <div class="yb-fact"><b><?= (int)$shape['nights'] ?></b><span>race nights</span></div>
                <div class="yb-fact"><b><?= count($standings) ?></b><span>racers</span></div>
                <div class="yb-fact"><b><?= number_format((int)$shape['points']) ?></b><span>points scored</span></div>
            </div>
            <div class="yb-cover-foot"><?= $scoringInfo['icon'] ?> Scored on <?= htmlspecialchars($scoringInfo['name']) ?></div>
        </section>

        <!-- ── 2. The season in brief: the summary's own opening ──────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>The season in brief</h2><span>page two</span></header>
            <?php if ($story['title']): ?><p class="yb-kicker"><?= htmlspecialchars($story['title']) ?></p><?php endif; ?>
            <?php if ($story['lede']): ?>
                <div class="yb-lede">
                    <?php foreach ($story['lede'] as $i => $p): ?>
                        <?php if (ybIsHeading($p)): ?><h4 class="yb-feature-head"><?= ybProse($p) ?></h4>
                        <?php else: ?><p class="<?= $i === 0 ? 'yb-dropcap' : '' ?>"><?= ybProse($p) ?></p><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="yb-lede"><?= htmlspecialchars($seasonName) ?> ran from <?= htmlspecialchars($dateRange) ?>, over <?= (int)$shape['gps'] ?> Grands Prix on <?= (int)$shape['nights'] ?> race nights, and ended with <?= htmlspecialchars($champion) ?> on top.</p>
            <?php endif; ?>
            <div class="yb-brief-facts">
                <div><b><?= (int)$shape['cups'] ?></b><span>different cups raced</span></div>
                <div><b><?= $shape['gps'] ? number_format((int)$shape['points'] / (int)$shape['gps'], 1) : '0' ?></b><span>average GP total</span></div>
                <div><b><?= htmlspecialchars($scoringInfo['name']) ?></b><span>scoring system</span></div>
            </div>
            <?php if ($pullQuote = ybPullQuote($story['body'] ?: $story['lede'])): ?>
                <blockquote class="yb-pull"><?= htmlspecialchars($pullQuote) ?></blockquote>
            <?php endif; ?>
            <div class="yb-contents">
                <h3 class="yb-sub">Inside</h3>
                <ol class="yb-toc">
                    <li><span>Final standings</span><b>3</b></li>
                    <li><span>The season in records</span><b>4</b></li>
                    <li><span>Head to head</span><b>5</b></li>
                    <li><span>The story of the season</span><b>6</b></li>
                    <li><span>Honours</span><b>7</b></li>
                </ol>
            </div>
        </section>

        <!-- ── 3. Final standings ─────────────────────────────────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>Final standings</h2><span><?= htmlspecialchars($scoringInfo['name']) ?></span></header>
            <table class="yb-table">
                <thead><tr><th>#</th><th>Racer</th><th class="r">Score</th><th class="r">GPs</th><th class="r">Avg</th><th class="r">Best</th><th class="r">Wins</th></tr></thead>
                <tbody>
                <?php $shown = array_slice($standings, 0, 18); foreach ($shown as $row): ?>
                    <tr class="<?= $row['place'] !== null && $row['place'] <= 3 ? 'yb-medal yb-medal-' . $row['place'] : ($row['place'] === null ? 'yb-unranked' : '') ?>">
                        <td class="yb-place"><?= $row['place'] !== null ? ($row['place'] <= 3 ? ['🥇','🥈','🥉'][$row['place'] - 1] : $row['place']) : '–' ?></td>
                        <td class="yb-name"><?= htmlspecialchars($row['name']) ?><?= $row['place'] === null ? ' <em>below the threshold</em>' : '' ?></td>
                        <td class="r yb-score"><?= htmlspecialchars(scoreNum($row['score'])) ?></td>
                        <td class="r"><?= (int)$row['gps'] ?></td>
                        <td class="r"><?= number_format($row['avg'], 1) ?></td>
                        <td class="r"><?= (int)$row['best'] ?></td>
                        <td class="r"><?= (int)$row['wins'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($standings) > count($shown)): ?>
            <p class="yb-more"><?= count($standings) - count($shown) ?> more racers took part; the full table is in the season report.</p>
            <?php endif; ?>
            <?php if ($mikko): ?>
            <h3 class="yb-sub">🌟 Mikkoliiga</h3>
            <div class="yb-chips">
                <?php foreach (array_slice($mikko, 0, 8) as $i => $m): ?>
                    <span class="yb-chip"><b><?= $i + 1 ?></b> <?= htmlspecialchars($m['name']) ?> <em><?= (int)($m['score'] ?? 0) ?></em></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($season['fantasy_champion'])): ?>
            <h3 class="yb-sub">🧙 Fantasy</h3>
            <p class="yb-para"><strong><?= htmlspecialchars($season['fantasy_champion']) ?></strong> topped the season's prediction board<?= $season['fantasy_champion_points'] !== null ? ' with ' . (int)$season['fantasy_champion_points'] . ' points' : '' ?>.</p>
            <?php endif; ?>
        </section>

        <!-- ── 4. Records + awards ────────────────────────────────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>The season in records</h2><span>page four</span></header>
            <div class="yb-sup-grid">
                <?php foreach ($superlatives as [$icon, $title, $who, $value, $extra]): ?>
                <div class="yb-sup">
                    <span class="yb-sup-icon"><?= $icon ?></span>
                    <span class="yb-sup-title"><?= htmlspecialchars($title) ?></span>
                    <span class="yb-sup-who"><?= htmlspecialchars($who) ?></span>
                    <span class="yb-sup-value"><?= htmlspecialchars($value) ?></span>
                    <span class="yb-sup-extra"><?= htmlspecialchars($extra) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($awards): ?>
            <h3 class="yb-sub">🏅 Season awards</h3>
            <div class="yb-awards">
                <?php foreach (array_slice($awards, 0, 18) as $a): ?>
                    <div class="yb-award"><span class="yb-award-cat"><?= htmlspecialchars($a['award_category']) ?></span><span class="yb-award-win"><?= htmlspecialchars($a['winner_name']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- ── 5. Head to head ────────────────────────────────────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>Head to head</h2><span>who finished ahead of whom</span></header>
            <?php if (count($gridNames) >= 2): ?>
            <table class="yb-h2h">
                <thead>
                    <tr><th></th><?php foreach ($gridNames as $c): ?><th><?= htmlspecialchars(mb_substr($c['name'], 0, 6)) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($gridNames as $rowRacer): ?>
                    <tr>
                        <th class="yb-h2h-row"><?= htmlspecialchars($rowRacer['name']) ?></th>
                        <?php foreach ($gridNames as $colRacer):
                            if ($rowRacer['id'] === $colRacer['id']) { echo '<td class="yb-h2h-self">—</td>'; continue; }
                            $pair = $matchups[$rowRacer['id']][$colRacer['id']] ?? null;
                            if (!$pair || (int)$pair['total'] === 0) { echo '<td class="yb-h2h-none">·</td>'; continue; }
                            $pct = (int)round(100 * $pair['wins'] / $pair['total']);
                            $cls = $pct >= 60 ? ' yb-h2h-win' : ($pct <= 40 ? ' yb-h2h-loss' : '');
                        ?>
                            <td class="yb-h2h-cell<?= $cls ?>"><b><?= (int)$pair['wins'] ?></b><span>/<?= (int)$pair['total'] ?></span></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="yb-h2h-key">Each cell reads: times the racer on the left finished ahead of the racer on top, out of the Grands Prix they both raced.</p>
            <?php endif; ?>
            <div class="yb-sup-grid yb-sup-grid-2">
                <?php if ($closest): ?>
                <div class="yb-sup">
                    <span class="yb-sup-icon">⚔️</span>
                    <span class="yb-sup-title">Closest rivalry</span>
                    <span class="yb-sup-who"><?= htmlspecialchars($closest['a']) ?> v <?= htmlspecialchars($closest['b']) ?></span>
                    <span class="yb-sup-value"><?= $closest['wins'] ?>–<?= $closest['total'] - $closest['wins'] ?></span>
                    <span class="yb-sup-extra">over <?= $closest['total'] ?> shared Grands Prix</span>
                </div>
                <?php endif; ?>
                <?php if ($lopsided): $lw = $lopsided['ratio'] >= .5; ?>
                <div class="yb-sup">
                    <span class="yb-sup-icon">🧱</span>
                    <span class="yb-sup-title">Most one-sided</span>
                    <span class="yb-sup-who"><?= htmlspecialchars($lw ? $lopsided['a'] : $lopsided['b']) ?> over <?= htmlspecialchars($lw ? $lopsided['b'] : $lopsided['a']) ?></span>
                    <span class="yb-sup-value"><?= $lw ? $lopsided['wins'] : $lopsided['total'] - $lopsided['wins'] ?>–<?= $lw ? $lopsided['total'] - $lopsided['wins'] : $lopsided['wins'] ?></span>
                    <span class="yb-sup-extra">over <?= $lopsided['total'] ?> shared Grands Prix</span>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── 6. The story of the season ─────────────────────────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>The story of the season</h2><span>from the archives</span></header>
            <?php if ($story['body']): ?>
                <div class="yb-feature">
                    <?php foreach ($story['body'] as $p): ?>
                        <?php if (ybIsHeading($p)): ?><h4 class="yb-feature-head"><?= ybProse($p) ?></h4>
                        <?php else: ?><p><?= ybProse($p) ?></p><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($story['truncated'])): ?>
                <p class="yb-continues">The archivist's report continues in the full season report.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="yb-para">No written summary was filed for this season.</p>
            <?php endif; ?>
        </section>

        <!-- ── 7. Honours (and the map, on a Territory season) ────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2><?= $finalMap ? 'Honours and the final map' : 'Honours' ?></h2><span>badges earned this season</span></header>
            <?php if ($finalMap): ?>
            <div class="yb-map">
                <canvas id="tt-map" data-layout="landscape" aria-label="Final Territory map"></canvas>
                <div class="tt-overlay" id="tt-overlay"></div>
            </div>
            <p class="yb-para"><?= (int)$finalMap['held'] ?> of <?= count($finalMap['cups']) ?> cups held when the season closed.</p>
            <script id="tt-data" type="application/json"><?= jsonForScript($finalMap) ?></script>
            <script src="<?= assetUrl('/assets/js/overworld.js') ?>"></script>
            <script src="<?= assetUrl('/assets/js/territory_map.js') ?>"></script>
            <?php endif; ?>
            <div class="yb-badge-wall">
                <?php foreach ($badgeWall as $bw): ?>
                <div class="yb-badge-row">
                    <span class="yb-badge-name"><?= htmlspecialchars($bw['name']) ?></span>
                    <span class="yb-badge-icons"><?php foreach ($bw['badges'] as $b): ?><span title="<?= htmlspecialchars($b['title']) ?>"><?= $b['icon'] ?></span><?php endforeach; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ── 8. Back cover ─────────────────────────────────────────── -->
        <section class="yb-page yb-back">
            <div class="yb-back-top">
                <div class="yb-season-no">SEASON <?= strtoupper(substr($sid, 1)) ?></div>
                <h2 class="yb-back-name"><?= htmlspecialchars($seasonName) ?></h2>
            </div>
            <?php if ($story['closing']): ?>
                <blockquote class="yb-closing"><?= ybProse($story['closing']) ?></blockquote>
            <?php endif; ?>
            <div class="yb-back-champ">
                <img src="/assets/img/<?= rawurlencode($championChar) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="" class="yb-back-champ-img">
                <div>
                    <div class="yb-champ-label">Season champion</div>
                    <div class="yb-back-champ-name"><?= htmlspecialchars($champion) ?></div>
                    <div class="yb-back-champ-sub"><?= (int)($standings[0]['gps'] ?? 0) ?> Grands Prix · <?= (int)($standings[0]['wins'] ?? 0) ?> wins</div>
                </div>
            </div>
            <div class="yb-colophon">
                <?= htmlspecialchars($leagueName) ?> · Organisation Mondial du Karting<br>
                Season <?= strtoupper($sid) ?> yearbook · compiled <?= date('j F Y') ?>
            </div>
        </section>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
<script>
// Each .yb-page is one A4 sheet, captured at 2× (≈190 dpi on paper) as JPEG so
// an eight-page book stays a few MB rather than tens.
const ybFile = <?= jsonForScript('kartfolio-' . $sid . '-yearbook') ?>;

async function ybCapture(btn, label) {
    btn.disabled = true; btn.textContent = '⏳ Building…';
    const shots = [];
    for (const page of document.querySelectorAll('.yb-page')) {
        const canvas = await html2canvas(page, { scale: 2, backgroundColor: '#ffffff', logging: false, useCORS: true });
        shots.push(canvas.toDataURL('image/jpeg', 0.92));
    }
    btn.disabled = false; btn.textContent = label;
    return shots;
}

document.getElementById('yb-pdf').addEventListener('click', async function () {
    const shots = await ybCapture(this, '📄 PDF (8 pages)');
    const pdf = new window.jspdf.jsPDF('portrait', 'mm', 'a4');
    shots.forEach((img, i) => { if (i > 0) pdf.addPage(); pdf.addImage(img, 'JPEG', 0, 0, 210, 297); });
    pdf.save(ybFile + '.pdf');
});

// Saddle stitch: two A4 pages side by side on a landscape A4 sheet, in the
// order that folds into a booklet — 8·1 on the front of sheet one, 2·7 on its
// back, then 6·3 and 4·5. Print double-sided flipping on the SHORT edge.
document.getElementById('yb-zine').addEventListener('click', async function () {
    const shots = await ybCapture(this, '📕 Booklet PDF');
    const pdf = new window.jspdf.jsPDF('landscape', 'mm', 'a4');   // 297 × 210
    const order = [[8, 1], [2, 7], [6, 3], [4, 5]];
    order.forEach((pair, i) => {
        if (i > 0) pdf.addPage();
        pair.forEach((pageNo, side) => {
            const img = shots[pageNo - 1];
            if (img) pdf.addImage(img, 'JPEG', side * 148.5, 0, 148.5, 210);
        });
    });
    pdf.save(ybFile + '-booklet.pdf');
});
</script>
<?php include __DIR__ . '/../private/templates/footer.php'; ?>
