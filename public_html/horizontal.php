<?php
/**
 * Lounge Screen (Horizontal Display) - Vertical Column Flow
 * Optimized for 1920x1080 TV - 16:9 Aspect Ratio
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/assets.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/settings.php';

$selectedSeason = getCurrentSeasonNumber();
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');

// 1. Fetch Season Rules
$rules = getSeasonRules($pdo, $selectedSeason);

// 2. Calculate previous standings for rank change
$latestDate        = getLatestRaceDate($pdo, $selectedSeason);
$previousStandings = calculatePreviousStandings($pdo, $selectedSeason, $latestDate, $rules);

// 3. Build standings
$allRacers = [];
foreach (getActiveRacers($pdo, $selectedSeason) as $r) {
    $raceCount = getRaceCount($pdo, $r['id'], $selectedSeason);
    $allRacers[] = [
        'id'        => $r['id'],
        'name'      => $r['name'],
        'score'     => calculateGPScore($pdo, $r['id'], $selectedSeason),
        'char'      => getMostUsedCharacter($pdo, $r['id'], $selectedSeason),
        'raceCount' => $raceCount,
        'qualifies' => racerQualifies($raceCount, $rules),
        'badges'    => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $selectedSeason) : [],
        // "N of 12 cups counted" for Top-12 seasons — read from the breakdown
        // like index.php does. This key was printed but never set (a PHP 8
        // undefined-key warning and an empty number on the sign).
        'cupsCounted' => (int)(getScoringBreakdown($pdo, $r['id'], $selectedSeason)['components']['cups_counted'] ?? 0),
    ];
}
$currentScoringSystem = $rules['scoring_system'] ?? 'average_attendance';
sortStandingsByScoring($allRacers, $currentScoringSystem, $pdo, $selectedSeason);

// Calculate rank changes
foreach ($allRacers as $index => &$racer) {
    $previousRank = $previousStandings[$racer['id']] ?? null;
    $racer['rank_change'] = ($previousRank !== null) ? ($previousRank - ($index + 1)) : null;
}
unset($racer);

// Split into Left (1-3) and Right (4-10)
$podium = array_slice($allRacers, 0, 3);
$field  = array_slice($allRacers, 3, 7);

// 3. Ticker Logic
$tickerLines = [];
$newsStmt = $pdo->query("SELECT headline, key_quote FROM recap_archive ORDER BY created_at DESC LIMIT 2");
while($row = $newsStmt->fetch()) { $tickerLines[] = ['h' => $row['headline'], 'q' => $row['key_quote']]; }

// 4. QR Code
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$addScoreUrl = $protocol . $_SERVER['HTTP_HOST'] . "/add_result.php";
$qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($addScoreUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lounge Display - Horizontal</title>
    <meta http-equiv="refresh" content="60">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/global.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/screen-h.css') ?>">
</head>
<body class="lounge-body">
    
    <div class="h-container">
        <header class="h-header">
            <div class="h-logo"><?= htmlspecialchars($leagueName) ?> League <span class="h-season">S<?= strtoupper(substr($selectedSeason, 1)) ?></span></div>
            <div class="h-telemetry">● LIVE TELEMETRY</div>
        </header>

        <div class="h-main-grid">
            <div class="h-col-podium">
                <?php foreach ($podium as $idx => $entry): 
                    $rank = $idx + 1;
                    $rankClass = ($entry['qualifies']) ? ['gold', 'silver', 'bronze'][$idx] : "";
                ?>
                <div class="racer-card podium-card <?= $rankClass ?> <?= !$entry['qualifies'] ? 'h-ineligible' : '' ?>">
                    <div class="rank-number">
                        #<?= $rank ?>
                        <?php if (isset($entry['rank_change'])): ?>
                            <?php if ($entry['rank_change'] > 0): ?>
                                <span class="rank-up">↑<?= $entry['rank_change'] ?></span>
                            <?php elseif ($entry['rank_change'] < 0): ?>
                                <span class="rank-down">↓<?= abs($entry['rank_change']) ?></span>
                            <?php else: ?>
                                <span class="rank-same">–</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="racer-portrait"><img src="/assets/img/<?= $entry['char'] ?>.png" onerror="this.src='/assets/img/Mii.png'"></div>
                    <div class="racer-info">
                        <div class="racer-name-row">
                            <div class="racer-name"><?= $entry['name'] ?></div>
                            <div class="badge-container">
                                <?php foreach ($entry['badges'] as $b): ?><span class="badge-icon"><?= $b['icon'] ?></span><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="racer-stat-label"><?= $entry['raceCount'] ?> GPs</div>
                    </div>
                    <div class="racer-score">
                        <?php if ($currentScoringSystem === 'top_12_unique'): ?>
                            <?= (int)$entry['score'] ?>
                            <div class="cup-completion"><?= (int)($entry['cupsCounted'] ?? 0) ?> of 12 cups counted</div>
                        <?php else: ?>
                            <?= number_format($entry['score'], 2) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="h-col-field">
                <?php foreach ($field as $idx => $entry): 
                    $rank = $idx + 4;
                ?>
                <div class="racer-card field-card <?= !$entry['qualifies'] ? 'h-ineligible' : '' ?>">
                    <div class="rank-number">
                        #<?= $rank ?>
                        <?php if (isset($entry['rank_change'])): ?>
                            <?php if ($entry['rank_change'] > 0): ?>
                                <span class="rank-up">↑<?= $entry['rank_change'] ?></span>
                            <?php elseif ($entry['rank_change'] < 0): ?>
                                <span class="rank-down">↓<?= abs($entry['rank_change']) ?></span>
                            <?php else: ?>
                                <span class="rank-same">–</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="racer-portrait"><img src="/assets/img/<?= $entry['char'] ?>.png" onerror="this.src='/assets/img/Mii.png'"></div>
                    <div class="racer-info">
                        <div class="racer-name"><?= $entry['name'] ?></div>
                        <div class="racer-stat-label"><?= $entry['raceCount'] ?> GPs</div>
                    </div>
                    <div class="racer-score">
                        <?php if ($currentScoringSystem === 'top_12_unique'): ?>
                            <?= (int)$entry['score'] ?>
                            <div class="cup-completion"><?= (int)($entry['cupsCounted'] ?? 0) ?> of 12 cups counted</div>
                        <?php else: ?>
                            <?= number_format($entry['score'], 2) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <footer class="h-footer">
            <div class="news-ticker-wrap h-ticker">
                <div class="ticker-label">LATEST WIRE</div>
                <div class="ticker-content">
                    <div class="ticker-move">
                        <?php foreach (array_merge($tickerLines, $tickerLines) as $item): ?>
                            <span class="ticker-item"><strong><?= strtoupper($item['h']) ?>:</strong> "<?= $item['q'] ?>"</span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="h-qr-box">
                <div class="h-qr-text">ADD SCORES</div>
                <img src="<?= $qrApiUrl ?>" class="h-qr-img">
            </div>
        </footer>
    </div>

</body>
</html>