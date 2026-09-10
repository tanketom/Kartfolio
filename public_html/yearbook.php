<?php
/**
 * Season Yearbook — the printable end of a season.
 *
 * Four A4 pages rendered in the browser and captured to a PDF with the same
 * jsPDF + html2canvas pipeline the trading cards use. It also prints straight
 * from the browser: the print stylesheet hides the site chrome and breaks a
 * page between each sheet.
 *
 * Archived seasons only — a yearbook is written after the fact, and the
 * frozen placements / map / fantasy champion only exist once a season closes.
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

$leagueName  = getSetting($pdo, 'league_name', 'Kartfolio League');
$scoringInfo = getScoringSystemInfo($pdo, $sid);
$rules       = getSeasonRules($pdo, $sid);
$names       = racerNamesMap($pdo);
$bySeason    = getSeasonResultsByRacer($pdo, $sid);

// ── Season shape ───────────────────────────────────────────────────────────
$gpRow = $pdo->prepare("SELECT COUNT(DISTINCT gpid) AS gps, COUNT(DISTINCT race_date) AS nights, MIN(race_date) AS first_date, MAX(race_date) AS last_date, SUM(gp_points) AS points FROM results WHERE gpid LIKE ?");
$gpRow->execute([$sid . '%']);
$shape = $gpRow->fetch(PDO::FETCH_ASSOC) ?: ['gps' => 0, 'nights' => 0, 'first_date' => null, 'last_date' => null, 'points' => 0];

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
// Ranked racers first in their frozen order, then everyone who fell short of the threshold.
usort($standings, function ($a, $b) {
    if ($a['place'] !== null && $b['place'] !== null) return $a['place'] <=> $b['place'];
    if ($a['place'] !== null) return -1;
    if ($b['place'] !== null) return 1;
    return $b['score'] <=> $a['score'];
});
$champion = $season['champion_name'] ?: ($standings[0]['name'] ?? '—');
$championChar = $season['champion_char'] ?: ($standings[0]['char'] ?? 'Mii');

// ── Superlatives: one pass over the season cache ───────────────────────────
$best = ['score' => null, 'wins' => null, 'podiums' => null, 'avg' => null, 'attendance' => null, 'perfects' => null, 'lols' => null];
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
/** Top of one aggregate field: [name, value, extra]. Ties go to the earlier name. */
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
if ($t = $topOf('best'))                 $superlatives[] = ['🏆', 'Highest single GP', $t['name'], (int)$t['best'] . ' pts', trim(($t['bestRow']['cup_name'] ?? '') . ' Cup · ' . date('M j', strtotime((string)$t['bestRow']['race_date'])))];
if ($t = $topOf('wins'))                 $superlatives[] = ['🥇', 'Most GP wins', $t['name'], $t['wins'] . ($t['wins'] === 1 ? ' win' : ' wins'), round(100 * $t['wins'] / max(1, $t['gps'])) . '% of ' . $t['gps'] . ' GPs'];
if ($t = $topOf('podiums'))              $superlatives[] = ['🏅', 'Most podiums', $t['name'], (string)$t['podiums'], round(100 * $t['podiums'] / max(1, $t['gps'])) . '% of ' . $t['gps'] . ' GPs'];
if ($t = $topOf('avg', $minForAvg))      $superlatives[] = ['📈', 'Best average', $t['name'], number_format($t['avg'], 2) . ' pts/GP', 'over ' . $t['gps'] . ' GPs'];
if ($t = $topOf('gps'))                  $superlatives[] = ['🦾', 'Most GPs raced', $t['name'], (string)$t['gps'], 'of ' . (int)$shape['gps'] . ' in the season'];
if (($t = $topOf('perfects')) && $t['perfects'] > 0) $superlatives[] = ['💯', 'Most perfect 60s', $t['name'], (string)$t['perfects'], 'a flawless Grand Prix'];
if (($t = $topOf('lols')) && $t['lols'] > 0)         $superlatives[] = ['😈', 'Most LOLs', $t['name'], (string)$t['lols'], 'Ludwig Obstruction Law'];

// ── Awards, badges, sub-leagues, map ───────────────────────────────────────
$awStmt = $pdo->prepare("SELECT award_category, winner_name FROM season_awards WHERE season_id = ? ORDER BY id ASC");
$awStmt->execute([$sid]);
$awards = $awStmt->fetchAll(PDO::FETCH_ASSOC);

$badgeWall = [];
foreach (array_slice($standings, 0, 12) as $row) {
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
            <p class="yb-toolbar-sub">Four pages, A4. Print it straight from the browser, or save the PDF.</p>
        </div>
        <div class="yb-toolbar-actions">
            <button type="button" id="yb-pdf" class="btn btn-primary">📄 Download PDF</button>
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
                    <img src="/assets/img/<?= htmlspecialchars($championChar) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="" class="yb-champ-img">
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

        <!-- ── 2. Final standings ─────────────────────────────────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>Final standings</h2><span>Season <?= strtoupper($sid) ?> · <?= htmlspecialchars($scoringInfo['name']) ?></span></header>
            <table class="yb-table">
                <thead><tr><th>#</th><th>Racer</th><th class="r">Score</th><th class="r">GPs</th><th class="r">Avg</th><th class="r">Best</th><th class="r">Wins</th></tr></thead>
                <tbody>
                <?php foreach ($standings as $i => $row): ?>
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

        <!-- ── 3. Superlatives + awards ───────────────────────────────── -->
        <section class="yb-page">
            <header class="yb-head"><h2>The season in records</h2><span>Season <?= strtoupper($sid) ?></span></header>
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
                <?php foreach ($awards as $a): ?>
                    <div class="yb-award"><span class="yb-award-cat"><?= htmlspecialchars($a['award_category']) ?></span><span class="yb-award-win"><?= htmlspecialchars($a['winner_name']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($season['ecology_report'])): ?>
            <h3 class="yb-sub">📜 The commissioner's note</h3>
            <p class="yb-para yb-note"><?= nl2br(htmlspecialchars(mb_substr(trim((string)$season['ecology_report']), 0, 900))) ?></p>
            <?php endif; ?>
        </section>

        <!-- ── 4. Badges (and the map, if this was a Territory season) ── -->
        <section class="yb-page">
            <header class="yb-head"><h2><?= $finalMap ? 'Honours and the final map' : 'Honours' ?></h2><span>Season <?= strtoupper($sid) ?></span></header>
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
            <div class="yb-colophon">
                <?= htmlspecialchars($leagueName) ?> · Season <?= strtoupper($sid) ?> · compiled <?= date('j F Y') ?>
            </div>
        </section>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>
<script>
// One A4 sheet per .yb-page, captured at 2× (≈190 dpi on paper) as JPEG so a
// four-page book stays a couple of MB rather than tens.
document.getElementById('yb-pdf').addEventListener('click', async function () {
    const btn = this;
    btn.disabled = true; btn.textContent = '⏳ Building…';
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF('portrait', 'mm', 'a4');
    const pages = document.querySelectorAll('.yb-page');
    for (let i = 0; i < pages.length; i++) {
        const canvas = await html2canvas(pages[i], { scale: 2, backgroundColor: '#ffffff', logging: false, useCORS: true });
        if (i > 0) pdf.addPage();
        pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 210, 297);
    }
    pdf.save(<?= jsonForScript('kartfolio-' . $sid . '-yearbook.pdf') ?>);
    btn.disabled = false; btn.textContent = '📄 Download PDF';
});
</script>
<?php include __DIR__ . '/../private/templates/footer.php'; ?>
