<?php
/**
 * Auto-Rotator Broadcast Screen (Vertical)
 * Optimized for 2048x2560 Portrait Monitor
 * Cycles: Rankings -> Nemesis Spotlight -> Latest Recap
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/assets.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$seasonId = getCurrentSeasonNumber();

// 1. Fetch Season Rules
$rules = getSeasonRules($pdo, $seasonId);

// 2. Previous standings for the rank-change arrows — the shared helper
//    (one Average+Attendance formula, season cache). This page carried its
//    own copy with two queries per racer and a score-only sort.
$latestDate        = getLatestRaceDate($pdo, $seasonId);
$previousStandings = $latestDate ? calculatePreviousStandings($pdo, $seasonId, $latestDate, $rules) : [];

// 3. Fetch Leaderboard Data
$racerStmt = $pdo->prepare("SELECT DISTINCT r.* FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
$racerStmt->execute([$seasonId . "%"]);
$activeRacers = $racerStmt->fetchAll();

$standings = [];
foreach ($activeRacers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);
    $char      = getMostUsedCharacter($pdo, $r['id'], $seasonId) ?: 'Mii';   // season cache, same tiebreak as the old GROUP BY
    $raceCount = getRaceCount($pdo, $r['id'], $seasonId);

    $standings[] = [
        'id' => $r['id'],
        'name' => $r['name'],
        'score' => $score,
        'char' => $char,
        'count' => $raceCount,
        'qualifies' => racerQualifies($raceCount, $rules)
    ];
}
$currentScoringSystem = $rules['scoring_system'] ?? 'average_attendance';
// Sort through the registry — this page carried a stale copy of the Top-12
// sort and a score-only sort for every other system (no tiebreak at all).
sortStandingsByScoring($standings, $currentScoringSystem, $pdo, $seasonId);

// Calculate rank changes
foreach ($standings as $index => &$racer) {
    $currentRank = $index + 1;
    $previousRank = $previousStandings[$racer['id']] ?? null;
    $racer['rank_change'] = ($previousRank !== null) ? ($previousRank - $currentRank) : null;
}
unset($racer);
$leaderboard = array_slice($standings, 0, 11);

// 3. Fetch Nemesis Logic
$feudStmt = $pdo->prepare("
    SELECT r1.name as p1, r2.name as p2, COUNT(*) as meetings,
           SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) as p1_wins
    FROM results res1
    JOIN results res2 ON res1.gpid = res2.gpid AND res1.cup_name = res2.cup_name
    JOIN racers r1 ON res1.racer_id = r1.id
    JOIN racers r2 ON res2.racer_id = r2.id
    WHERE res1.racer_id < res2.racer_id AND res1.gpid LIKE ?
    GROUP BY res1.racer_id, res2.racer_id
    HAVING meetings >= 2
    ORDER BY (COUNT(*) * (1.0 - ABS((CAST(SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) AS FLOAT) / COUNT(*)) - 0.5) * 2.0)) DESC
    LIMIT 1
");
$feudStmt->execute([$seasonId . "%"]);
$topFeud = $feudStmt->fetch(PDO::FETCH_ASSOC);

// 4. Fetch Latest News Recap
$newsStmt = $pdo->prepare("SELECT headline, key_quote FROM recap_archive ORDER BY created_at DESC LIMIT 1");
$newsStmt->execute();
$latestNews = $newsStmt->fetch(PDO::FETCH_ASSOC);

// 5. Ticker Lines (Common for all slides)
$tickerLines = [];
if ($topFeud) $tickerLines[] = ['h' => 'RIVALRY WATCH', 'q' => strtoupper($topFeud['p1'])." VS ".strtoupper($topFeud['p2'])." IS LOCKED IN!"];
if ($latestNews) $tickerLines[] = ['h' => $latestNews['headline'], 'q' => $latestNews['key_quote']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Auto-Rotator - <?= htmlspecialchars($leagueName) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/global.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/screen-v.css') ?>">
</head>
<body class="ghost-body">
    <div class="v-container">
        
        <div class="slide active" id="slide-rankings">
            <header class="v-header">
                <div class="v-logo">LEADERBOARD</div>
                <div class="v-season-label">S<?= strtoupper(substr($seasonId, 1)) ?> STANDINGS</div>
            </header>
            <main class="v-main-large">
                <?php foreach ($leaderboard as $idx => $entry): 
                    $rank = $idx + 1;
                    $rankClass = ($entry['qualifies'] && $rank <= 3) ? ['gold', 'silver', 'bronze'][$rank-1] : "";
                ?>
                <div class="v-card <?= $rankClass ?> <?= !$entry['qualifies'] ? 'v-ineligible' : '' ?>">
                    <div class="v-rank">
                        <?= $entry['qualifies'] ? "#$rank" : "--" ?>
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
                    <div class="v-portrait-wrap"><img src="/assets/img/<?= $entry['char'] ?>.png"></div>
                    <div class="v-name-box">
                        <div class="v-name"><?= $entry['name'] ?></div>
                        <div class="v-meta-small"><?= $entry['count'] ?> GPs Raced</div>
                    </div>
                    <div class="v-score"><?= number_format($entry['score'], 2) ?></div>
                </div>
                <?php endforeach; ?>
            </main>
        </div>

        <div class="slide" id="slide-nemesis">
            <header class="v-header">
                <div class="v-logo">NEMESIS INDEX</div>
                <div class="v-season-label">TOP SEASONAL RIVALRY</div>
            </header>
            <main class="v-main-large full-center">
                <?php if ($topFeud): ?>
                    <h2 class="huge-name"><?= htmlspecialchars($topFeud['p1']) ?></h2>
                    <div class="vs-label">VS</div>
                    <h2 class="huge-name"><?= htmlspecialchars($topFeud['p2']) ?></h2>
                    <p class="v-nemesis-tagline">
                        LOCKED IN COMBAT OVER <?= $topFeud['meetings'] ?> MEETINGS
                    </p>
                <?php else: ?>
                    <h2 class="huge-name">FEUDING SOON</h2>
                <?php endif; ?>
            </main>
        </div>

        <div class="slide" id="slide-recap">
            <header class="v-header">
                <div class="v-logo">THE ARCHIVE</div>
                <div class="v-season-label">LATEST FIELD REPORT</div>
            </header>
            <main class="v-main-large">
                <?php if ($latestNews): ?>
                <div class="recap-box">
                    <div class="recap-h"><?= htmlspecialchars($latestNews['headline']) ?></div>
                    <div class="recap-q">"<?= htmlspecialchars($latestNews['key_quote']) ?>"</div>
                </div>
                <?php else: ?>
                    <div class="recap-box"><div class="recap-h">AWAITING NEWS WIRE...</div></div>
                <?php endif; ?>
            </main>
        </div>

        <footer class="v-footer-ticker">
            <div class="news-ticker-wrap">
                <div class="ticker-label">LIVE</div>
                <div class="ticker-content">
                    <div class="ticker-move">
                        <?php foreach (array_merge($tickerLines, $tickerLines, $tickerLines) as $item): ?>
                            <span class="ticker-item">
                                <strong><?= htmlspecialchars(strtoupper($item['h'])) ?>:</strong> 
                                "<?= htmlspecialchars($item['q']) ?>"
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script>
        let currentSlide = 0;
        const slides = document.querySelectorAll('.slide');
        
        function rotateSlides() {
            slides[currentSlide].classList.remove('active');
            currentSlide = (currentSlide + 1) % slides.length;
            slides[currentSlide].classList.add('active');
        }

        // Rotate every 20 seconds
        setInterval(rotateSlides, 20000);
        
        // Full page refresh every 10 minutes to grab new DB data
        setTimeout(() => { location.reload(); }, 600000);
    </script>
</body>
</html>