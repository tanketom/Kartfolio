<?php
/**
 * Ghost Screen (Vertical) - Component Mirror Edition
 * Style: Rounded index.php racer-cards | Layout: 2048x2560 Edge-to-Edge
 * Background: Transparent
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/assets.php';

$seasonId = getCurrentSeasonNumber();
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$seasonTag = strtoupper($seasonId);

// 1. DATA GATHERING
$rules = getSeasonRules($pdo, $seasonId);

$latestDate       = getLatestRaceDate($pdo, $seasonId);
$previousStandings = calculatePreviousStandings($pdo, $seasonId, $latestDate, $rules);

$standings = [];
foreach (getActiveRacers($pdo, $seasonId) as $r) {
    $raceCount = getRaceCount($pdo, $r['id'], $seasonId);
    $standings[] = [
        'id'        => $r['id'],
        'name'      => $r['name'],
        'score'     => calculateGPScore($pdo, $r['id'], $seasonId),
        'char'      => getMostUsedCharacter($pdo, $r['id'], $seasonId),
        'badges'    => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $seasonId) : [],
        'raceCount' => $raceCount,
        // "N of 12 cups counted" for Top-12 seasons — read from the breakdown
        // like index.php does. This key was printed but never set (always 0).
        'cupsCounted' => (int)(getScoringBreakdown($pdo, $r['id'], $seasonId)['components']['cups_counted'] ?? 0),
    ];
}
sortStandingsByScoring($standings, $rules['scoring_system'] ?? 'average_attendance', $pdo, $seasonId);

// Calculate rank changes
foreach ($standings as $index => &$racer) {
    $previousRank = $previousStandings[$racer['id']] ?? null;
    $racer['rank_change'] = ($previousRank !== null) ? ($previousRank - ($index + 1)) : null;
}
unset($racer);
$scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
$systemName    = getScoringSystemInfo($pdo, $seasonId)['name'] ?? 'GPScore™';
$isTerritory   = ($scoringSystem === 'territory');
$ttMap = $isTerritory ? territoryMapPayload($pdo, $seasonId) : null;

// Systems with their own panel in the map's slot: Kart Bingo's card wall and
// The Price Is Right's price board. Everything they need comes from the season
// cache; the standings below shrink to five like on Territory seasons.
$signPanel = in_array($scoringSystem, ['kart_bingo', 'price_is_right'], true) ? $scoringSystem : null;
$leaderboard = array_slice($standings, 0, ($isTerritory || $signPanel) ? 5 : 10);
$racerColour = territoryRacerColors(array_column($standings, 'id'));
$names = racerNamesMap($pdo);

$bingoCards = []; $bingoChasers = [];
if ($signPanel === 'kart_bingo') {
    $withCards = array_values(array_filter($standings, fn($r) => $r['raceCount'] > 0));
    foreach ($withCards as $i => $r) {
        $b = bingoProgress($pdo, (int)$r['id'], $seasonId, $rules) + ['name' => $r['name'], 'id' => (int)$r['id']];
        if ($i < 3) $bingoCards[] = $b; elseif (count($bingoChasers) < 5) $bingoChasers[] = $b;
    }
}
$priceGps = []; $priceLadder = [];
if ($signPanel === 'price_is_right') {
    $pir = priceIsRightSeason($pdo, $seasonId, $rules);
    $cups = []; foreach (seasonGpGroups($pdo, $seasonId) as $gpid => $g) $cups[$gpid] = reset($g)['cup_name'];
    foreach (array_reverse(array_slice($pir['gps'], -3, 3, true), true) as $gpid => $g) $priceGps[] = $g + ['gpid' => $gpid, 'num' => (int)substr($gpid, 5), 'cup' => $cups[$gpid] ?? ''];
    foreach ($standings as $r) { if (!racerQualifies($r['raceCount'], $rules) || count($priceLadder) >= 5) continue; $x = $pir['racers'][(int)$r['id']] ?? null; if ($x) $priceLadder[] = ['name' => $r['name'], 'score' => (int)$x['score'], 'hits' => (int)$x['hits'], 'busts' => (int)$x['busts']]; }
}
/** Card square label, shortened for the sign. */
function signSquareLabel(string $label): string {
    $s = str_replace(['Finish directly behind ', 'Finish ahead of ', 'Finish last of the humans (2+ racing)', 'Post a perfect 60', 'Score between 50 and 55', 'Score exactly ', 'Score under ', 'Three GPs in one night', 'Two podiums in a row', 'Finish ', ' Cup'], ['Behind ', 'Ahead of ', 'Last human', 'A 60', '50–55', 'Exactly ', 'Under ', '3 GPs one night', '2 podiums in a row', '', ' Cup'], $label);
    return $s;
}

// News Ticker Logic
$tickerLines = [];
$newsStmt = $pdo->query("SELECT headline, key_quote FROM recap_archive WHERE status = 'published' ORDER BY pinned DESC, created_at DESC LIMIT 2");
while($row = $newsStmt->fetch()) {
    $tickerLines[] = strtoupper($row['headline']) . ": \"" . $row['key_quote'] . "\"";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vertical Signage - <?= htmlspecialchars($leagueName) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/global.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/screen-v.css') ?>">
</head>
<body>

    <div class="signage-container">
        <header class="signage-header">
            <div class="header-title"><?= htmlspecialchars($leagueName) ?> Mario Kart League</div>
            <div class="header-season"><?= $seasonTag ?></div>
        </header>

        

        <?php if ($ttMap): ?>
        <div class="tt-map-card tt-map-card--signage">
            <canvas id="tt-map" data-layout="portrait" aria-label="Territory map: who holds each cup"></canvas>
            <div class="tt-overlay" id="tt-overlay"></div>
        </div>
        <script id="tt-data" type="application/json"><?= json_encode($ttMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
        <script src="<?= assetUrl('/assets/js/overworld.js') ?>"></script>
        <script src="<?= assetUrl('/assets/js/territory_map.js') ?>"></script>
        <?php endif; ?>

        <?php if ($signPanel === 'kart_bingo'): ?>
        <section class="sign-panel">
            <div class="sp-title"><h3>🎱 Kart Bingo</h3><span><?= (int)($rules['bg_line_pts'] ?? 100) ?> a line · <?= (int)($rules['bg_card_pts'] ?? 500) ?> the card</span></div>
            <?php if (!$bingoCards): ?>
                <p class="sp-empty">Cards are dealt with the first result of the season.</p>
            <?php else: ?>
            <div class="sp-wall">
                <?php $best = max(array_column($bingoCards, 'done')); foreach ($bingoCards as $b): ?>
                <div class="sp-card<?= $b['done'] === $best && $best > 0 ? ' sp-card--hot' : '' ?>">
                    <div class="sp-card-head"><b><span class="sp-dot" style="background:<?= $racerColour[$b['id']] ?? '#999' ?>"></span><?= htmlspecialchars($b['name']) ?></b><i><?= $b['lines'] ?> line<?= $b['lines'] === 1 ? '' : 's' ?></i></div>
                    <div class="sp-grid"><?php foreach ($b['card'] as $sq): ?><div class="sp-sq<?= $sq['done'] ? ' on' : '' ?>"><?= htmlspecialchars(signSquareLabel($sq['label'])) ?></div><?php endforeach; ?></div>
                    <div class="sp-card-foot"><?= $b['done'] ?> / 9<?= $b['full'] ? ' · FULL CARD' : '' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="sp-chase">
                <?php foreach ($bingoChasers as $b): ?>
                <div class="sp-chase-row"><b><span class="sp-dot" style="background:<?= $racerColour[$b['id']] ?? '#999' ?>"></span><?= htmlspecialchars($b['name']) ?></b><span class="sp-pips"><?php foreach ($b['card'] as $sq): ?><i class="<?= $sq['done'] ? 'on' : '' ?>"></i><?php endforeach; ?></span><em><?= $b['lines'] ?> line<?= $b['lines'] === 1 ? '' : 's' ?> · <?= $b['done'] ?>/9</em></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php elseif ($signPanel === 'price_is_right'): ?>
        <section class="sign-panel sign-panel--price">
            <div class="sp-title"><h3>🏷️ The Price Is Right</h3><span>closest under the <?= ($rules['pir_target'] ?? 'median') === 'mean' ? 'mean' : 'median' ?> wins</span></div>
            <?php if (!$priceGps): ?>
                <div class="sp-hero"><div class="sp-tag"><small>GP 1</small><b>??</b><span class="sp-cup">revealed when the last human finishes</span></div></div>
            <?php else: $hero = array_shift($priceGps); ?>
            <div class="sp-hero">
                <div class="sp-tag"><small>GP <?= $hero['num'] ?></small><b><?= rtrim(rtrim(number_format($hero['target'], 1), '0'), '.') ?></b><span class="sp-cup"><?= htmlspecialchars($hero['cup']) ?> Cup</span></div>
                <div class="sp-bids" style="grid-template-columns: repeat(<?= max(2, min(4, count($hero['bids']))) ?>, 1fr)">
                    <?php foreach (array_slice($hero['bids'], 0, 4) as $bd): ?>
                    <div class="sp-bid<?= $bd['over'] ? ' over' : ($bd['rank'] === 1 ? ' win' : '') ?>"><span class="n"><?= htmlspecialchars($names[$bd['rid']] ?? '') ?></span><span class="pts"><?= $bd['pts'] ?></span><span class="lad"><?= $bd['over'] ? 'over by ' . rtrim(rtrim(number_format($bd['gap'], 1), '0'), '.') : ($bd['rank'] === 1 ? 'on the nose' : ordinal($bd['rank'])) ?> · <?= $bd['ladder'] ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="sp-prev">
                <?php foreach ($priceGps as $g): ?>
                <div class="sp-prev-row"><span class="t"><small>GP <?= $g['num'] ?></small><?= rtrim(rtrim(number_format($g['target'], 1), '0'), '.') ?> · <?= htmlspecialchars($g['cup']) ?></span><span class="b"><?php foreach ($g['bids'] as $bd): ?><?= $bd['over'] ? '<s>' : ($bd['rank'] === 1 ? '<span class="w">' : '<span>') ?><?= htmlspecialchars($names[$bd['rid']] ?? '') ?> <?= $bd['pts'] ?><?= $bd['over'] ? '</s>' : '</span>' ?><?php endforeach; ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($priceLadder): ?>
            <div class="sp-ladder">
                <?php foreach ($priceLadder as $i => $l): ?><div><span class="r"><?= $i + 1 ?></span><span class="n"><?= htmlspecialchars($l['name']) ?></span><span class="m"><?= $l['score'] ?></span><small><?= $l['hits'] ?> on the nose · <?= $l['busts'] ?> over</small></div><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="sp-hint">A GP pays <?= implode(' · ', array_slice(MK_POINTS_SCALE, 0, 4)) ?> down the Mario Kart ladder. Best <?= (int)($rules['pir_best_n'] ?? 15) ?> GPs count. The price is revealed when the last human finishes.</div>
        </section>
        <?php endif; ?>

        <main class="signage-main">
            <?php foreach ($leaderboard as $idx => $row): 
                $rank = $idx + 1;
                $isQualifying = racerQualifies($row['raceCount'], $rules);
                $rankClass = ($isQualifying && $rank <= 3) ? ['gold', 'silver', 'bronze'][$rank-1] : "";
            ?>
            <div class="racer-card <?= $rankClass ?> <?= !$isQualifying ? 'racer-ineligible' : '' ?>">
                <div class="rank-number">
                    <?= $isQualifying ? "#$rank" : "--" ?>
                    <?php if (isset($row['rank_change'])): ?>
                        <?php if ($row['rank_change'] > 0): ?>
                            <span class="rank-up">↑<?= $row['rank_change'] ?></span>
                        <?php elseif ($row['rank_change'] < 0): ?>
                            <span class="rank-down">↓<?= abs($row['rank_change']) ?></span>
                        <?php else: ?>
                            <span class="rank-same">–</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <div class="racer-portrait">
                    <img src="/assets/img/<?= htmlspecialchars($row['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'">
                </div>

                <div class="racer-info">
                    <div class="racer-name-row">
                        <div class="racer-name"><?= htmlspecialchars($row['name']) ?></div>
                    </div>
                    <?php if (!empty($row['badges'])): ?>
                    <div class="badge-container badge-container--below">
                        <?php foreach ($row['badges'] as $badge): ?>
                            <span class="badge-icon"><?= $badge['icon'] ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="racer-stat-label">
                        <?= $row['raceCount'] ?> GP<?= $row['raceCount'] > 1 ? 's' : '' ?> Raced
                        <?= !$isQualifying ? '• Ineligible' : '• ' . htmlspecialchars($systemName) . ' Active' ?>
                    </div>
                </div>

                <div class="racer-score">
                    <?php if (($rules['scoring_system'] ?? '') === 'top_12_unique'): ?>
                        <?= (int)$row['score'] ?>
                        <div class="cup-completion"><?= $row['cupsCounted'] ?? 0 ?> of 12 cups counted</div>
                    <?php else: ?>
                        <?= number_format($row['score'], 2) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </main>

        <footer class="signage-footer">
            <div class="news-ticker-wrap">
                <div class="ticker-label">LIVE</div>
                <div class="ticker-content">
                    <div class="ticker-move">
                        <?php foreach (array_merge($tickerLines, $tickerLines) as $line): ?>
                            <span class="ticker-item"><?= htmlspecialchars($line) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script>
        setTimeout(() => { location.reload(); }, 60000);
    </script>
</body>
</html>