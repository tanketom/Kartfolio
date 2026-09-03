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
$isTerritory = (($rules['scoring_system'] ?? '') === 'territory');
$leaderboard = array_slice($standings, 0, $isTerritory ? 5 : 10);   // the map takes the top of the screen on Territory seasons
$ttMap = $isTerritory ? territoryMapPayload($pdo, $seasonId) : null;

// News Ticker Logic
$tickerLines = [];
$newsStmt = $pdo->query("SELECT headline, key_quote FROM recap_archive ORDER BY created_at DESC LIMIT 2");
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
                        <?= !$isQualifying ? '• Ineligible' : '• GPScore™ Active' ?>
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