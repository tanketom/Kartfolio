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

$seasonId = getCurrentSeasonNumber();
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$seasonTag = strtoupper($seasonId);

// 1. DATA GATHERING
$ruleStmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
$ruleStmt->execute([$seasonId]);
$rules = $ruleStmt->fetch(PDO::FETCH_ASSOC);
$minThreshold = $rules['min_races_threshold'] ?? 1;

// Get latest race date for rank change calculation
$latestDateStmt = $pdo->prepare("SELECT MAX(race_date) as latest_date FROM results WHERE gpid LIKE ?");
$latestDateStmt->execute([$seasonId . "%"]);
$latestDateRow = $latestDateStmt->fetch(PDO::FETCH_ASSOC);
$latestDate = $latestDateRow['latest_date'];

// Calculate previous standings
$previousStandings = [];
if ($latestDate) {
    $prevDateStmt = $pdo->prepare("SELECT MAX(race_date) as prev_date FROM results WHERE gpid LIKE ? AND race_date < ?");
    $prevDateStmt->execute([$seasonId . "%", $latestDate]);
    $prevDateRow = $prevDateStmt->fetch(PDO::FETCH_ASSOC);
    $prevDate = $prevDateRow['prev_date'];

    if ($prevDate) {
        $prevRacerStmt = $pdo->prepare("SELECT DISTINCT r.* FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ? AND res.race_date <= ?");
        $prevRacerStmt->execute([$seasonId . "%", $prevDate]);
        $prevActiveRacers = $prevRacerStmt->fetchAll();

        $prevStandingsTemp = [];
        foreach ($prevActiveRacers as $r) {
            $stmt = $pdo->prepare("SELECT gp_points FROM results WHERE racer_id = ? AND gpid LIKE ? AND race_date <= ? ORDER BY gp_points ASC");
            $stmt->execute([$r['id'], $seasonId . "%", $prevDate]);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($results) > 0) {
                $dropRate = $rules['drop_rate'] ?? 10;
                $numToDrop = ($dropRate > 0) ? floor(count($results) / $dropRate) : 0;
                $filteredPoints = array_slice($results, $numToDrop);
                $average = array_sum($filteredPoints) / count($filteredPoints);

                $attWeight = $rules['attendance_weight'] ?? 1.0;
                $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
                $dateStmt = $pdo->prepare("SELECT race_date FROM results WHERE racer_id = ? AND gpid LIKE ? AND race_date <= ?");
                $dateStmt->execute([$r['id'], $seasonId . "%", $prevDate]);
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

                $prevStandingsTemp[] = ['id' => $r['id'], 'score' => $average + $attendanceBonus];
            }
        }

        usort($prevStandingsTemp, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($prevStandingsTemp as $index => $racer) {
            $previousStandings[$racer['id']] = $index + 1;
        }
    }
}

$racerStmt = $pdo->prepare("SELECT DISTINCT r.* FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
$racerStmt->execute([$seasonId . "%"]);
$activeRacers = $racerStmt->fetchAll();

$currentScoringSystem = $rules['scoring_system'] ?? 'average_attendance';
$standings = [];
foreach ($activeRacers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);
    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$r['id'], $seasonId . "%"]);
    $char = $charStmt->fetchColumn() ?: 'Mii';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $countStmt->execute([$r['id'], $seasonId . "%"]);
    $raceCount = (int)$countStmt->fetchColumn();

    if ($raceCount > 0) {
        $entry = [
            'id'        => $r['id'],
            'name'      => $r['name'],
            'score'     => $score,
            'char'      => $char,
            'badges'    => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $seasonId) : [],
            'raceCount' => $raceCount
        ];
        if ($currentScoringSystem === 'top_12_unique') {
            $cupStmt = $pdo->prepare("SELECT COUNT(DISTINCT cup_name) FROM results WHERE racer_id = ? AND gpid LIKE ?");
            $cupStmt->execute([$r['id'], $seasonId . "%"]);
            $entry['cupsCounted'] = min((int)$cupStmt->fetchColumn(), 12);
        }
        $standings[] = $entry;
    }
}
if ($currentScoringSystem === 'top_12_unique') {
    foreach ($standings as &$s) {
        $s['tiebreaker'] = getTop12UniqueTiebreaker($pdo, $s['id'], $seasonId);
    }
    unset($s);
    usort($standings, function($a, $b) {
        if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
} else {
    usort($standings, fn($a, $b) => $b['score'] <=> $a['score']);
}

// Calculate rank changes
foreach ($standings as $index => &$racer) {
    $currentRank = $index + 1;
    $previousRank = $previousStandings[$racer['id']] ?? null;
    $racer['rank_change'] = ($previousRank !== null) ? ($previousRank - $currentRank) : null;
}
unset($racer);
$leaderboard = array_slice($standings, 0, 10);

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
    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/screen-v.css">
</head>
<body>

    <div class="signage-container">
        <header class="signage-header">
            <div class="header-title"><?= htmlspecialchars($leagueName) ?> Mario Kart League</div>
            <div class="header-season"><?= $seasonTag ?></div>
        </header>

        

        <main class="signage-main">
            <?php foreach ($leaderboard as $idx => $row): 
                $rank = $idx + 1;
                $isQualifying = ($row['raceCount'] >= $minThreshold);
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
                        <div class="badge-container">
                            <?php foreach ($row['badges'] as $badge): ?>
                                <span class="badge-icon"><?= $badge['icon'] ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="racer-stat-label">
                        <?= $row['raceCount'] ?> GP<?= $row['raceCount'] > 1 ? 's' : '' ?> Raced 
                        <?= !$isQualifying ? '• Ineligible' : '• GPScore™ Active' ?>
                    </div>
                </div>

                <div class="racer-score">
                    <?php if ($currentScoringSystem === 'top_12_unique'): ?>
                        <?= (int)$row['score'] ?>
                        <div class="cup-completion"><?= $row['cupsCounted'] ?> of 12 cups counted</div>
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