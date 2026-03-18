<?php
/**
 * Lounge Screen (Horizontal Display) - Vertical Column Flow
 * Optimized for 1920x1080 TV - 16:9 Aspect Ratio
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/settings.php';

$selectedSeason = getCurrentSeasonNumber();
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');

// 1. Fetch Season Rules
$ruleStmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
$ruleStmt->execute([$selectedSeason]);
$rules = $ruleStmt->fetch(PDO::FETCH_ASSOC);
$minThreshold = $rules['min_races_threshold'] ?? 1;

// 2. Calculate previous standings for rank change
$latestDateStmt = $pdo->prepare("SELECT MAX(race_date) as latest_date FROM results WHERE gpid LIKE ?");
$latestDateStmt->execute([$selectedSeason . "%"]);
$latestDate = $latestDateStmt->fetchColumn();

$previousStandings = [];
if ($latestDate) {
    $prevDateStmt = $pdo->prepare("SELECT MAX(race_date) as prev_date FROM results WHERE gpid LIKE ? AND race_date < ?");
    $prevDateStmt->execute([$selectedSeason . "%", $latestDate]);
    $prevDate = $prevDateStmt->fetchColumn();

    if ($prevDate) {
        $prevRacers = $pdo->query("SELECT * FROM racers")->fetchAll();
        $prevTemp = [];
        foreach ($prevRacers as $r) {
            $stmt = $pdo->prepare("SELECT gp_points FROM results WHERE racer_id = ? AND gpid LIKE ? AND race_date <= ? ORDER BY gp_points ASC");
            $stmt->execute([$r['id'], $selectedSeason . "%", $prevDate]);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($results) > 0) {
                $dropRate = $rules['drop_rate'] ?? 10;
                $numToDrop = ($dropRate > 0) ? floor(count($results) / $dropRate) : 0;
                $filteredPoints = array_slice($results, $numToDrop);
                $average = array_sum($filteredPoints) / count($filteredPoints);

                $attWeight = $rules['attendance_weight'] ?? 1.0;
                $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
                $dateStmt = $pdo->prepare("SELECT race_date FROM results WHERE racer_id = ? AND gpid LIKE ? AND race_date <= ?");
                $dateStmt->execute([$r['id'], $selectedSeason . "%", $prevDate]);
                $dates = $dateStmt->fetchAll(PDO::FETCH_COLUMN);

                $attendanceBonus = 0;
                $weeklyTracker = [];
                foreach ($dates as $date) {
                    $weekKey = date('Y-W', strtotime($date));
                    if (!isset($weeklyTracker[$weekKey])) $weeklyTracker[$weekKey] = 0;
                    if ($weeklyTracker[$weekKey] < $weeklyCap) {
                        $attendanceBonus += $attWeight;
                        $weeklyTracker[$weekKey] += $attWeight;
                    }
                }

                $prevTemp[] = ['id' => $r['id'], 'score' => $average + $attendanceBonus];
            }
        }

        usort($prevTemp, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($prevTemp as $index => $racer) {
            $previousStandings[$racer['id']] = $index + 1;
        }
    }
}

// 3. Fetch All Racers & Process Scores
$currentScoringSystem = $rules['scoring_system'] ?? 'average_attendance';
$racers = $pdo->query("SELECT * FROM racers")->fetchAll();
$allRacers = [];

foreach ($racers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $selectedSeason);
    $stmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? ORDER BY race_date DESC LIMIT 1");
    $stmt->execute([$r['id'], $selectedSeason . "%"]);
    $lastChar = $stmt->fetchColumn();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $countStmt->execute([$r['id'], $selectedSeason . "%"]);
    $raceCount = (int)$countStmt->fetchColumn();

    if ($raceCount > 0) {
        $entry = [
            'id' => $r['id'],
            'name' => $r['name'],
            'score' => $score,
            'char' => $lastChar ?: 'Mii',
            'raceCount' => $raceCount,
            'qualifies' => ($raceCount >= $minThreshold),
            'badges' => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $selectedSeason) : []
        ];
        if ($currentScoringSystem === 'top_12_unique') {
            $cupStmt = $pdo->prepare("SELECT COUNT(DISTINCT cup_name) FROM results WHERE racer_id = ? AND gpid LIKE ?");
            $cupStmt->execute([$r['id'], $selectedSeason . "%"]);
            $entry['cupsCounted'] = min((int)$cupStmt->fetchColumn(), 12);
        }
        $allRacers[] = $entry;
    }
}

usort($allRacers, fn($a, $b) => $b['score'] <=> $a['score']);

// Calculate rank changes
foreach ($allRacers as $index => &$racer) {
    $currentRank = $index + 1;
    $previousRank = $previousStandings[$racer['id']] ?? null;
    $racer['rank_change'] = ($previousRank !== null) ? ($previousRank - $currentRank) : null;
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
    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/screen-h.css">
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
                            <div class="cup-completion"><?= $entry['cupsCounted'] ?> of 12 cups counted</div>
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
                            <div class="cup-completion"><?= $entry['cupsCounted'] ?> of 12 cups counted</div>
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