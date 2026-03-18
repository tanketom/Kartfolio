<?php
/**
 * Individual Racer Profile Page
 * Path: /cdnmk/public_html/racer.php
 * URL: /racer?id=1 or /racer/1 (with .htaccess)
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/card_rendering.php';

// Get racer ID from URL
$racerId = $_GET['id'] ?? null;

if (!$racerId) {
    header('Location: /');
    exit;
}

// Fetch racer info
$racerStmt = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
$racerStmt->execute([$racerId]);
$racer = $racerStmt->fetch(PDO::FETCH_ASSOC);

if (!$racer) {
    header('Location: /');
    exit;
}

$pageTitle = htmlspecialchars($racer['name']) . " - Racer Profile";
$extraCss = '<link rel="stylesheet" href="/assets/css/racer.css">';
include __DIR__ . '/../private/templates/header.php';

// Get current season
$currentSeason = getCurrentSeasonNumber();

// Fetch all seasons this racer has participated in
$seasonsStmt = $pdo->prepare("
    SELECT DISTINCT SUBSTR(gpid, 1, 3) as season_id
    FROM results
    WHERE racer_id = ?
    ORDER BY season_id DESC
");
$seasonsStmt->execute([$racerId]);
$seasons = $seasonsStmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate career stats
$careerStmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_gps,
        SUM(gp_points) as total_points,
        MAX(gp_points) as personal_best,
        AVG(gp_points) as avg_points,
        SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
        SUM(CASE WHEN rank <= 3 THEN 1 ELSE 0 END) as podiums,
        MIN(rank) as best_finish,
        AVG(rank) as avg_finish
    FROM results
    WHERE racer_id = ?
");
$careerStmt->execute([$racerId]);
$careerStats = $careerStmt->fetch(PDO::FETCH_ASSOC);

// Calculate Personal Bests & Milestones
$milestonesStmt = $pdo->prepare("
    SELECT
        MIN(race_date) as first_gp_date,
        MIN(CASE WHEN rank = 1 THEN race_date END) as first_win_date,
        MIN(CASE WHEN rank <= 3 THEN race_date END) as first_podium_date,
        MIN(CASE WHEN gp_points = 60 THEN race_date END) as first_perfect_date,
        MIN(CASE WHEN gp_points = 60 THEN gpid END) as first_perfect_gpid,
        MAX(CASE WHEN gp_points = (SELECT MAX(gp_points) FROM results WHERE racer_id = ?) THEN gpid END) as best_score_gpid,
        MAX(CASE WHEN gp_points = (SELECT MAX(gp_points) FROM results WHERE racer_id = ?) THEN race_date END) as best_score_date
    FROM results
    WHERE racer_id = ?
");
$milestonesStmt->execute([$racerId, $racerId, $racerId]);
$milestones = $milestonesStmt->fetch(PDO::FETCH_ASSOC);

// Calculate streaks
$streaksStmt = $pdo->prepare("SELECT rank, race_date, gpid FROM results WHERE racer_id = ? ORDER BY race_date ASC, id ASC");
$streaksStmt->execute([$racerId]);
$allResults = $streaksStmt->fetchAll(PDO::FETCH_ASSOC);

$currentWinStreak = 0;
$maxWinStreak = 0;
$currentPodiumStreak = 0;
$maxPodiumStreak = 0;

foreach ($allResults as $result) {
    if ($result['rank'] == 1) {
        $currentWinStreak++;
        $maxWinStreak = max($maxWinStreak, $currentWinStreak);
    } else {
        $currentWinStreak = 0;
    }

    if ($result['rank'] <= 3) {
        $currentPodiumStreak++;
        $maxPodiumStreak = max($maxPodiumStreak, $currentPodiumStreak);
    } else {
        $currentPodiumStreak = 0;
    }
}

// Note: Using getScoringBreakdown() from gp_logic.php for season-aware scoring

// Get season-by-season breakdown
$seasonBreakdown = [];
foreach ($seasons as $season) {
    $score = calculateGPScore($pdo, $racerId, $season);
    $breakdown = getScoringBreakdown($pdo, $racerId, $season);
    $badges = getRacerBadges($pdo, $racerId, $season);

    // Get season's scoring system info
    $seasonScoringInfo = getScoringSystemInfo($pdo, $season);

    $seasonStatsStmt = $pdo->prepare("
        SELECT
            COUNT(*) as gps,
            SUM(gp_points) as points,
            MAX(gp_points) as best,
            SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins
        FROM results
        WHERE racer_id = ? AND gpid LIKE ?
    ");
    $seasonStatsStmt->execute([$racerId, $season . "%"]);
    $stats = $seasonStatsStmt->fetch(PDO::FETCH_ASSOC);

    // Calculate season placement
    $rankStmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
    $rankStmt->execute([$season]);
    $seasonRules = $rankStmt->fetch(PDO::FETCH_ASSOC);
    $minThreshold = $seasonRules['min_races_threshold'] ?? 3;

    // Get all racers for this season and their scores
    $allRacersStmt = $pdo->prepare("SELECT DISTINCT r.id, r.name FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
    $allRacersStmt->execute([$season . "%"]);
    $allRacers = $allRacersStmt->fetchAll();

    $seasonStandings = [];
    foreach ($allRacers as $r) {
        $rScore = calculateGPScore($pdo, $r['id'], $season);
        $rCountStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ?");
        $rCountStmt->execute([$r['id'], $season . "%"]);
        $rCount = (int)$rCountStmt->fetchColumn();

        if ($rCount >= $minThreshold) {
            $seasonStandings[] = ['id' => $r['id'], 'score' => $rScore, 'name' => $r['name']];
        }
    }
    $seasonScoringSystem = $seasonRules['scoring_system'] ?? 'average_attendance';
    if ($seasonScoringSystem === 'top_12_unique') {
        foreach ($seasonStandings as &$ss) {
            $ss['tiebreaker'] = getTop12UniqueTiebreaker($pdo, $ss['id'], $season);
        }
        unset($ss);
        usort($seasonStandings, function($a, $b) {
            if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
            if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
            return strcmp($a['name'], $b['name']);
        });
    } else {
        usort($seasonStandings, fn($a, $b) => ($b['score'] == $a['score']) ? strcmp($a['name'], $b['name']) : $b['score'] <=> $a['score']);
    }

    // Find this racer's placement
    $placement = 0;
    foreach ($seasonStandings as $index => $standing) {
        if ($standing['id'] == $racerId) {
            $placement = $index + 1;
            break;
        }
    }

    $seasonBreakdown[] = [
        'season' => $season,
        'placement' => $placement,
        'gp_score' => $score,
        'breakdown' => $breakdown,
        'stats' => $stats,
        'badges' => $badges,
        'scoring_info' => $seasonScoringInfo
    ];
}

// Get character usage
$charStmt = $pdo->prepare("
    SELECT character_used, COUNT(*) as uses
    FROM results
    WHERE racer_id = ?
    GROUP BY character_used
    ORDER BY uses DESC
    LIMIT 5
");
$charStmt->execute([$racerId]);
$characters = $charStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent results
$recentStmt = $pdo->prepare("
    SELECT *, SUBSTR(gpid, 1, 3) as season
    FROM results
    WHERE racer_id = ?
    ORDER BY race_date DESC, id DESC
    LIMIT 10
");
$recentStmt->execute([$racerId]);
$recentResults = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Get rivalries (head-to-head records)
$rivalriesStmt = $pdo->prepare("
    SELECT
        opponent_id,
        r.name as opponent_name,
        r.nickname as opponent_nickname,
        COUNT(*) as meetings,
        SUM(CASE WHEN rank < opponent_rank THEN 1 ELSE 0 END) as wins,
        SUM(CASE WHEN rank > opponent_rank THEN 1 ELSE 0 END) as losses,
        AVG(rank - opponent_rank) as avg_finish_gap
    FROM (
        SELECT
            a.gpid,
            a.rank,
            b.racer_id as opponent_id,
            b.rank as opponent_rank
        FROM results a
        JOIN results b ON a.gpid = b.gpid AND a.racer_id != b.racer_id
        WHERE a.racer_id = ?
    ) matchups
    JOIN racers r ON r.id = opponent_id
    GROUP BY opponent_id
    HAVING meetings >= 3
    ORDER BY meetings DESC, wins DESC
    LIMIT 5
");
$rivalriesStmt->execute([$racerId]);
$rivalries = $rivalriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get news mentions (headlines containing racer's name or nickname)
$newsStmt = $pdo->prepare("
    SELECT *
    FROM recap_archive
    WHERE headline LIKE ? OR headline LIKE ? OR recap_text LIKE ?
    ORDER BY created_at DESC
    LIMIT 5
");
$searchName = '%' . $racer['name'] . '%';
$searchNick = '%' . ($racer['nickname'] ?: 'XXXNOMATCHXXX') . '%';
$newsStmt->execute([$searchName, $searchNick, $searchName]);
$newsItems = $newsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container stats-container">
    <!-- Racer Header -->
    <header class="page-header racer-page-header">
        <div class="racer-page-header-info">
            <h1 class="page-title">
                <?= htmlspecialchars($racer['name']) ?>
            </h1>
            <?php if (!empty($racer['nickname'])): ?>
                <p class="page-subtitle">
                    <?= htmlspecialchars($racer['nickname']) ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($racer['catchphrase'])): ?>
                <p class="racer-catchphrase">
                    "<?= htmlspecialchars($racer['catchphrase']) ?>"
                </p>
            <?php endif; ?>
        </div>
        <a href="/" class="btn btn-secondary">← Back to Leaderboard</a>
    </header>

    <!-- Card and Career Stats Row -->
    <div class="racer-top-grid">

        <!-- Left: Trading Card (1.5x scale) -->
        <div>
            <div id="racerCard">
                <?= renderRacerCard($pdo, $racerId, $currentSeason, 1.5) ?>
            </div>
            <div class="racer-card-download">
                <button onclick="downloadCard()" class="btn btn-primary">
                    📸 Download Card
                </button>
            </div>
        </div>

        <!-- Right: Career Stats Overview -->
        <div class="card racer-career-card">
            <h2 class="card-header">Career Statistics</h2>
            <div class="career-stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total GPs</div>
                    <div class="stat-value stat-value--lg"><?= $careerStats['total_gps'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Career Points</div>
                    <div class="stat-value stat-value--lg text-red"><?= number_format($careerStats['total_points']) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Personal Best</div>
                    <div class="stat-value stat-value--lg"><?= $careerStats['personal_best'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Avg Points</div>
                    <div class="stat-value stat-value--lg"><?= number_format($careerStats['avg_points'], 1) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Wins</div>
                    <div class="stat-value stat-value--lg stat-value--gold">🏆 <?= $careerStats['wins'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Podiums</div>
                    <div class="stat-value stat-value--lg"><?= $careerStats['podiums'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Best Finish</div>
                    <div class="stat-value stat-value--lg">#<?= $careerStats['best_finish'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Avg Finish</div>
                    <div class="stat-value stat-value--lg"><?= number_format($careerStats['avg_finish'], 1) ?></div>
                </div>
            </div>
        </div>

    </div>

    <!-- Personal Bests & Milestones -->
    <div class="card">
        <h2 class="card-header">🏅 Personal Bests & Milestones</h2>
        <div class="milestones-grid">

            <!-- First GP -->
            <?php if ($milestones['first_gp_date']): ?>
            <div class="milestone-card milestone-card--first-gp">
                <div class="milestone-icon">🎮</div>
                <div class="milestone-label">First GP</div>
                <div class="milestone-value"><?= date('M j, Y', strtotime($milestones['first_gp_date'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- First Win -->
            <?php if ($milestones['first_win_date']): ?>
            <div class="milestone-card milestone-card--first-win">
                <div class="milestone-icon">🏆</div>
                <div class="milestone-label">First Win</div>
                <div class="milestone-value"><?= date('M j, Y', strtotime($milestones['first_win_date'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- First Podium -->
            <?php if ($milestones['first_podium_date']): ?>
            <div class="milestone-card milestone-card--first-pod">
                <div class="milestone-icon">🥇</div>
                <div class="milestone-label">First Podium</div>
                <div class="milestone-value"><?= date('M j, Y', strtotime($milestones['first_podium_date'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- First Perfect Game -->
            <?php if ($milestones['first_perfect_date']): ?>
            <div class="milestone-card milestone-card--perfect">
                <div class="milestone-icon">💯</div>
                <div class="milestone-label">First Perfect 60</div>
                <div class="milestone-value"><?= date('M j, Y', strtotime($milestones['first_perfect_date'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Best Score -->
            <?php if ($milestones['best_score_date'] && $careerStats['personal_best']): ?>
            <div class="milestone-card milestone-card--best-score">
                <div class="milestone-icon">⭐</div>
                <div class="milestone-label">Best Score</div>
                <div class="milestone-value--lg"><?= $careerStats['personal_best'] ?> pts</div>
                <div class="milestone-sub"><?= date('M j, Y', strtotime($milestones['best_score_date'])) ?></div>
            </div>
            <?php endif; ?>

            <!-- Longest Win Streak -->
            <?php if ($maxWinStreak > 0): ?>
            <div class="milestone-card milestone-card--win-streak">
                <div class="milestone-icon">🔥</div>
                <div class="milestone-label">Longest Win Streak</div>
                <div class="milestone-value--lg"><?= $maxWinStreak ?> GP<?= $maxWinStreak > 1 ? 's' : '' ?></div>
            </div>
            <?php endif; ?>

            <!-- Longest Podium Streak -->
            <?php if ($maxPodiumStreak >= 3): ?>
            <div class="milestone-card milestone-card--pod-streak">
                <div class="milestone-icon">📈</div>
                <div class="milestone-label">Longest Podium Streak</div>
                <div class="milestone-value--lg"><?= $maxPodiumStreak ?> GP<?= $maxPodiumStreak > 1 ? 's' : '' ?></div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Form Graph -->
    <?php
    // Get last 20 races for form graph
    $formStmt = $pdo->prepare("
        SELECT gp_points, rank, race_date, gpid, cup_name
        FROM results
        WHERE racer_id = ?
        ORDER BY race_date DESC, id DESC
        LIMIT 20
    ");
    $formStmt->execute([$racerId]);
    $formData = array_reverse($formStmt->fetchAll(PDO::FETCH_ASSOC));

    if (!empty($formData)):
        // Calculate rolling average (last 5 races)
        $rollingAvg = [];
        for ($i = 0; $i < count($formData); $i++) {
            $start = max(0, $i - 4);
            $window = array_slice($formData, $start, $i - $start + 1);
            $avg = array_sum(array_column($window, 'gp_points')) / count($window);
            $rollingAvg[] = $avg;
        }

        $maxPoints = max(array_column($formData, 'gp_points'));
        $minPoints = min(array_column($formData, 'gp_points'));
        $avgPoints = array_sum(array_column($formData, 'gp_points')) / count($formData);
    ?>
    <div class="card">
        <h2 class="card-header">📊 Recent Form (Last 20 GPs)</h2>
        <div class="racer-mt-20">
            <!-- Chart -->
            <div class="form-chart-wrapper">
                <svg width="100%" height="100%" viewBox="0 0 1000 300" preserveAspectRatio="none">
                    <!-- Grid lines -->
                    <line x1="0" y1="60" x2="1000" y2="60" stroke="#e0e0e0" stroke-width="1" stroke-dasharray="5,5"/>
                    <line x1="0" y1="150" x2="1000" y2="150" stroke="#e0e0e0" stroke-width="1" stroke-dasharray="5,5"/>
                    <line x1="0" y1="240" x2="1000" y2="240" stroke="#e0e0e0" stroke-width="1" stroke-dasharray="5,5"/>

                    <!-- Average line -->
                    <line x1="0" y1="<?= 300 - ($avgPoints / 60 * 300) ?>" x2="1000" y2="<?= 300 - ($avgPoints / 60 * 300) ?>" stroke="#009BE0" stroke-width="2" stroke-dasharray="10,5" opacity="0.5"/>

                    <!-- Data points and lines -->
                    <?php
                    $pointWidth = 1000 / (count($formData) - 1);
                    for ($i = 0; $i < count($formData); $i++):
                        $x = $i * $pointWidth;
                        $y = 300 - ($formData[$i]['gp_points'] / 60 * 300);

                        // Draw line to next point
                        if ($i < count($formData) - 1):
                            $nextX = ($i + 1) * $pointWidth;
                            $nextY = 300 - ($formData[$i + 1]['gp_points'] / 60 * 300);
                    ?>
                        <line x1="<?= $x ?>" y1="<?= $y ?>" x2="<?= $nextX ?>" y2="<?= $nextY ?>" stroke="var(--nintendo-red)" stroke-width="3"/>
                    <?php endif; ?>

                        <!-- Point circle -->
                        <circle cx="<?= $x ?>" cy="<?= $y ?>" r="6" fill="var(--nintendo-red)" stroke="white" stroke-width="2"/>

                        <!-- Tooltip on hover -->
                        <title><?= htmlspecialchars($formData[$i]['cup_name']) ?> - <?= $formData[$i]['gp_points'] ?> pts (Rank #<?= $formData[$i]['rank'] ?>) - <?= date('M j', strtotime($formData[$i]['race_date'])) ?></title>
                    <?php endfor; ?>

                    <!-- Rolling average line -->
                    <?php for ($i = 0; $i < count($rollingAvg) - 1; $i++):
                        $x = $i * $pointWidth;
                        $y = 300 - ($rollingAvg[$i] / 60 * 300);
                        $nextX = ($i + 1) * $pointWidth;
                        $nextY = 300 - ($rollingAvg[$i + 1] / 60 * 300);
                    ?>
                        <line x1="<?= $x ?>" y1="<?= $y ?>" x2="<?= $nextX ?>" y2="<?= $nextY ?>" stroke="#FFD700" stroke-width="2" opacity="0.7" stroke-dasharray="5,3"/>
                    <?php endfor; ?>
                </svg>
            </div>

            <!-- Legend -->
            <div class="form-legend">
                <div class="form-legend-item">
                    <div class="form-legend-line form-legend-line--score"></div>
                    <span class="form-legend-label">GP Score</span>
                </div>
                <div class="form-legend-item">
                    <div class="form-legend-line form-legend-line--avg"></div>
                    <span class="form-legend-label">5-Race Average</span>
                </div>
                <div class="form-legend-item">
                    <div class="form-legend-line form-legend-line--overall"></div>
                    <span class="form-legend-label">Overall Average (<?= number_format($avgPoints, 1) ?>)</span>
                </div>
            </div>

            <!-- Stats -->
            <div class="form-stats-grid">
                <div class="form-stat">
                    <div class="form-stat-label">Peak</div>
                    <div class="form-stat-value form-stat-value--peak"><?= $maxPoints ?> pts</div>
                </div>
                <div class="form-stat">
                    <div class="form-stat-label">Low</div>
                    <div class="form-stat-value"><?= $minPoints ?> pts</div>
                </div>
                <div class="form-stat">
                    <div class="form-stat-label">Current Form</div>
                    <div class="form-stat-value">
                        <?php
                        $lastFive = array_slice($formData, -5);
                        $recentAvg = array_sum(array_column($lastFive, 'gp_points')) / count($lastFive);
                        $formIndicator = $recentAvg > $avgPoints ? '🔥' : ($recentAvg < $avgPoints ? '📉' : '➡️');
                        ?>
                        <?= $formIndicator ?> <?= number_format($recentAvg, 1) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Character Usage -->
    <?php if (!empty($characters)): ?>
    <div class="card">
        <h2 class="card-header">Favorite Characters</h2>
        <div class="character-grid">
            <?php foreach ($characters as $char): ?>
                <div class="character-item">
                    <div class="character-img-wrap">
                        <img src="/assets/img/<?= htmlspecialchars($char['character_used']) ?>.png"
                             onerror="this.src='/assets/img/Mii.png'"
                             alt="<?= htmlspecialchars($char['character_used']) ?>">
                    </div>
                    <div class="character-name">
                        <?= htmlspecialchars($char['character_used']) ?>
                    </div>
                    <div class="character-uses">
                        <?= $char['uses'] ?> GP<?= $char['uses'] > 1 ? 's' : '' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Nemesis Tracker -->
    <?php if (!empty($rivalries)): ?>
    <div class="card">
        <h2 class="card-header">Nemesis Tracker</h2>
        <div class="nemesis-list">
            <?php foreach ($rivalries as $rivalry):
                $winRate = ($rivalry['meetings'] > 0) ? ($rivalry['wins'] / $rivalry['meetings']) * 100 : 0;
                $isHeated = $rivalry['meetings'] >= 10;
                $isDominant = $winRate >= 65;
                $isKryptonite = $winRate <= 35;

                // Determine indicator
                $indicator = '';
                if ($isHeated) {
                    $indicator = '🔥';
                } elseif ($isDominant) {
                    $indicator = '👑';
                } elseif ($isKryptonite) {
                    $indicator = '⚠️';
                }

                // Determine status class
                if ($winRate >= 60) {
                    $statusClass = 'nemesis-status--dominant';
                    $statusText = 'Dominant';
                } elseif ($winRate >= 45) {
                    $statusClass = 'nemesis-status--competitive';
                    $statusText = 'Competitive';
                } else {
                    $statusClass = 'nemesis-status--underdog';
                    $statusText = 'Underdog';
                }
            ?>
                <div class="nemesis-row">
                    <!-- Left: Opponent Info -->
                    <div class="nemesis-left">
                        <div class="nemesis-info-row">
                            <?php if ($indicator): ?>
                                <span class="nemesis-indicator"><?= $indicator ?></span>
                            <?php endif; ?>
                            <div>
                                <div class="nemesis-name">
                                    <?= htmlspecialchars($rivalry['opponent_name']) ?>
                                    <?php if (!empty($rivalry['opponent_nickname'])): ?>
                                        <span class="nemesis-nick">
                                            (<?= htmlspecialchars($rivalry['opponent_nickname']) ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="nemesis-status <?= $statusClass ?>">
                                    <?= $statusText ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Center: Record -->
                    <div class="nemesis-record">
                        <div class="nemesis-col-label">Record</div>
                        <div class="nemesis-record-value">
                            <span class="nemesis-record-wins"><?= $rivalry['wins'] ?></span>
                            <span class="nemesis-record-sep">-</span>
                            <span class="nemesis-record-losses"><?= $rivalry['losses'] ?></span>
                        </div>
                        <div class="nemesis-win-rate"><?= number_format($winRate, 1) ?>% win rate</div>
                    </div>

                    <!-- Right: Stats -->
                    <div class="nemesis-stats-col">
                        <div class="nemesis-col-label">Meetings</div>
                        <div class="nemesis-meetings-value"><?= $rivalry['meetings'] ?></div>
                        <div class="nemesis-avg-gap"><?= number_format($rivalry['avg_finish_gap'], 1) ?> avg gap</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="nemesis-legend">
            <strong>Legend:</strong> 🔥 Heated Rivalry (10+ meetings) • 👑 Dominant (>65% win rate) • ⚠️ Kryptonite (<35% win rate)
        </div>
    </div>
    <?php endif; ?>

    <!-- Cup Mastery Grid -->
    <?php
    $currentSeasonInfo = getScoringSystemInfo($pdo, $currentSeason);
    $currentSeasonRules = $currentSeasonInfo['rules'] ?? [];
    $currentScoringSystem = $currentSeasonInfo['system'] ?? 'average_attendance';
    $cupsRequired = (int)($currentSeasonRules['cups_required'] ?? 12);
    $cupProgress = getCupProgress($pdo, $racerId, $currentSeason, $cupsRequired);
    $dlcCupProgress = getDLCCupProgress($pdo, $racerId, $currentSeason);

    if ($currentScoringSystem === 'top_12_unique'):
        // === UNIFIED 24-CUP GRID for Top 12 Unique scoring ===
        $allCupData = array_merge($cupProgress, $dlcCupProgress);
        $playedCups = array_filter($allCupData, fn($c) => $c['completed']);

        if (!empty($playedCups)):
        // Sort: played cups by best_score DESC, then unplayed at end
        uasort($allCupData, function($a, $b) {
            if ($a['completed'] !== $b['completed']) return $b['completed'] <=> $a['completed'];
            return $b['best_score'] <=> $a['best_score'];
        });

        // Identify top 12 counted cups
        $t12Rank = 0;
        $top12Names = [];
        $twelfthScore = 0;
        foreach ($allCupData as $cName => $cData) {
            if ($cData['completed'] && $t12Rank < 12) {
                $top12Names[] = $cName;
                $twelfthScore = $cData['best_score'];
                $t12Rank++;
            }
        }

        $top12Total = 0;
        $perfectsInTop12 = 0;
        $totalPlayed = count($playedCups);
        foreach ($top12Names as $cName) {
            $top12Total += $allCupData[$cName]['best_score'];
            if ($allCupData[$cName]['is_perfect']) $perfectsInTop12++;
        }
        $top12Count = count($top12Names);
        $hasDroppedCups = $totalPlayed > 12;

        $remainingCups = [];
        foreach ($allCupData as $cName => $cData) {
            if (!in_array($cName, $top12Names)) $remainingCups[$cName] = $cData;
        }
    ?>
    <div class="card">
        <h2 class="card-header cup-mastery-header">
            <span>🏆 Cup Mastery — <?= strtoupper($currentSeason) ?></span>
            <span class="cup-mastery-meta">
                Top <?= $top12Count ?> of <?= $totalPlayed ?> cups
            </span>
        </h2>

        <!-- Top 12 Score Banner -->
        <div class="t12-score-banner">
            <div class="t12-score-main">
                <span class="t12-score-value"><?= $top12Total ?></span>
                <span class="t12-score-max">/ 720</span>
            </div>
            <div class="t12-score-detail">
                Top 12 Total &middot; <?= $perfectsInTop12 ?> perfect 60<?= $perfectsInTop12 !== 1 ? 's' : '' ?> (tiebreaker)
            </div>
        </div>

        <!-- Counted Cups -->
        <div class="t12-section-label t12-section-counted">Counted — Top <?= $top12Count ?></div>
        <div class="cup-cells-grid">
            <?php
            $cupRank = 1;
            foreach ($allCupData as $cupName => $data):
                if (!in_array($cupName, $top12Names)) continue;
                $impactValue = 60 - $data['best_score'];
            ?>
            <div class="cup-cell cup-cell--top12 <?= $data['is_perfect'] ? 'cup-cell--perfect' : 'cup-cell--done' ?>">
                <div class="cup-cell-header">
                    <div class="cup-cell-name">
                        <span class="t12-rank">#<?= $cupRank ?></span>
                        <?= htmlspecialchars($cupName) ?> Cup
                    </div>
                    <span class="cup-cell-icon"><?= $data['is_perfect'] ? '🌟' : '✓' ?></span>
                </div>
                <div class="cup-cell-score <?= $data['is_perfect'] ? 'cup-cell-score--perfect' : 'cup-cell-score--done' ?>">
                    <?= $data['best_score'] ?>
                    <span class="cup-cell-score-denom">/ 60</span>
                </div>
                <div class="cup-cell-footer">
                    <span><?= $data['attempts'] ?> attempt<?= $data['attempts'] != 1 ? 's' : '' ?></span>
                    <?php if ($impactValue > 0): ?>
                        <span class="cup-cell-improve">+<?= $impactValue ?> to total</span>
                    <?php else: ?>
                        <span class="cup-cell-maxed">Max!</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php $cupRank++; endforeach; ?>
        </div>

        <?php if ($hasDroppedCups): ?>
        <!-- Cut Line -->
        <div class="t12-cut-line">
            <span class="t12-cut-line-text">— CUT LINE — need <?= $twelfthScore + 1 ?>+ to count —</span>
        </div>
        <?php elseif ($top12Count < 12): ?>
        <div class="t12-cut-line t12-cut-line--open">
            <span class="t12-cut-line-text">— <?= 12 - $top12Count ?> slot<?= (12 - $top12Count) !== 1 ? 's' : '' ?> remaining —</span>
        </div>
        <?php endif; ?>

        <?php if (!empty($remainingCups)): ?>
        <div class="t12-section-label t12-section-dropped">
            <?= $hasDroppedCups ? 'Dropped / Not Yet Played' : 'Not Yet Played' ?>
        </div>
        <div class="cup-cells-grid">
            <?php foreach ($remainingCups as $cupName => $data): ?>
            <div class="cup-cell <?= $data['completed'] ? 'cup-cell--dropped' : 'cup-cell--pending' ?>">
                <div class="cup-cell-header">
                    <div class="cup-cell-name"><?= htmlspecialchars($cupName) ?> Cup</div>
                    <span class="cup-cell-icon"><?= $data['completed'] ? '✗' : '—' ?></span>
                </div>
                <?php if ($data['completed']): ?>
                    <div class="cup-cell-score cup-cell-score--dropped">
                        <?= $data['best_score'] ?>
                        <span class="cup-cell-score-denom">/ 60</span>
                    </div>
                    <div class="cup-cell-footer">
                        <span><?= $data['attempts'] ?> attempt<?= $data['attempts'] != 1 ? 's' : '' ?></span>
                        <span class="cup-cell-need">Need <?= $twelfthScore + 1 ?>+</span>
                    </div>
                <?php else: ?>
                    <div class="cup-cell-unplayed">Not yet played</div>
                    <?php if ($top12Count >= 12): ?>
                    <div class="cup-cell-footer">
                        <span class="cup-cell-need">Need <?= $twelfthScore + 1 ?>+</span>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Summary Row -->
        <div class="cup-summary-grid cup-summary-grid--5">
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Top 12 Total</div>
                <div class="cup-summary-value cup-summary-value--score"><?= $top12Total ?></div>
                <div class="cup-summary-max">/ 720 max</div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Cups Counted</div>
                <div class="cup-summary-value"><?= $top12Count ?>/12</div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Perfect 60s</div>
                <div class="cup-summary-value cup-summary-value--perfect">🌟 <?= $perfectsInTop12 ?></div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Cups Played</div>
                <div class="cup-summary-value"><?= $totalPlayed ?>/24</div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Bubble Line</div>
                <div class="cup-summary-value"><?= $top12Count >= 12 ? $twelfthScore : '—' ?></div>
            </div>
        </div>
    </div>
    <?php endif; // end has played cups ?>

    <?php else: // === ORIGINAL Base + DLC split for other scoring systems === ?>

    <?php if (!empty($cupProgress)):

        $cupsCompleted = count(array_filter($cupProgress, fn($c) => $c['completed']));
        $perfectCups   = count(array_filter($cupProgress, fn($c) => $c['is_perfect']));
        $totalScore    = array_sum(array_column($cupProgress, 'best_score'));
        $completedOnly = array_filter($cupProgress, fn($c) => $c['completed']);
        $avgCupScore   = count($completedOnly) > 0
            ? array_sum(array_column(array_values($completedOnly), 'best_score')) / count($completedOnly)
            : 0;
        $completionPct = round(($cupsCompleted / $cupsRequired) * 100);
    ?>
    <div class="card">
        <h2 class="card-header cup-mastery-header">
            <span>🏆 Cup Mastery — <?= strtoupper($currentSeason) ?></span>
            <span class="cup-mastery-meta">
                <?= $cupsCompleted ?>/<?= $cupsRequired ?> cups
                <?php if ($completionPct === 100): ?>
                    <span class="cup-mastery-complete">✓ Complete</span>
                <?php else: ?>
                    <span class="cup-mastery-pct"><?= $completionPct ?>%</span>
                <?php endif; ?>
            </span>
        </h2>

        <!-- Progress Bar -->
        <div class="cup-progress-bar">
            <div class="cup-progress-fill <?= $completionPct === 100 ? 'cup-progress-fill--complete' : 'cup-progress-fill--partial' ?>"
                 style="width: <?= $completionPct ?>%;"></div>
        </div>

        <!-- Cup Grid -->
        <div class="cup-cells-grid">
            <?php foreach ($cupProgress as $cupName => $data): ?>
            <?php
                if ($data['is_perfect']) {
                    $cellClass = 'cup-cell--perfect';
                    $icon = '🌟';
                } elseif ($data['completed']) {
                    $cellClass = 'cup-cell--done';
                    $icon = '✓';
                } else {
                    $cellClass = 'cup-cell--pending';
                    $icon = '—';
                }
            ?>
            <div class="cup-cell <?= $cellClass ?>">
                <div class="cup-cell-header">
                    <div class="cup-cell-name"><?= htmlspecialchars($cupName) ?> Cup</div>
                    <span class="cup-cell-icon"><?= $icon ?></span>
                </div>
                <?php if ($data['completed']): ?>
                    <div class="cup-cell-score <?= $data['is_perfect'] ? 'cup-cell-score--perfect' : 'cup-cell-score--done' ?>">
                        <?= $data['best_score'] ?>
                        <span class="cup-cell-score-denom">/ 60</span>
                    </div>
                    <div class="cup-cell-footer">
                        <span><?= $data['attempts'] ?> attempt<?= $data['attempts'] != 1 ? 's' : '' ?></span>
                        <?php if ($data['improvement_potential'] > 0): ?>
                            <span class="cup-cell-improve">+<?= $data['improvement_potential'] ?> possible</span>
                        <?php else: ?>
                            <span class="cup-cell-maxed">Max!</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="cup-cell-unplayed">Not yet played</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Summary Row -->
        <div class="cup-summary-grid">
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Total Score</div>
                <div class="cup-summary-value cup-summary-value--score"><?= $totalScore ?></div>
                <div class="cup-summary-max">/ <?= $cupsRequired * 60 ?> max</div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Cups Done</div>
                <div class="cup-summary-value"><?= $cupsCompleted ?>/<?= $cupsRequired ?></div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Perfect 60s</div>
                <div class="cup-summary-value cup-summary-value--perfect">🌟 <?= $perfectCups ?></div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Avg Cup Score</div>
                <div class="cup-summary-value"><?= $cupsCompleted > 0 ? number_format($avgCupScore, 1) : '—' ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- DLC Cup Mastery Grid -->
    <?php if (!empty($dlcCupProgress)):

        $dlcCupsCompleted = count(array_filter($dlcCupProgress, fn($c) => $c['completed']));
        $dlcPerfectCups   = count(array_filter($dlcCupProgress, fn($c) => $c['is_perfect']));
        $dlcTotalScore    = array_sum(array_column($dlcCupProgress, 'best_score'));
        $dlcCompletedOnly = array_filter($dlcCupProgress, fn($c) => $c['completed']);
        $dlcAvgCupScore   = count($dlcCompletedOnly) > 0
            ? array_sum(array_column(array_values($dlcCompletedOnly), 'best_score')) / count($dlcCompletedOnly)
            : 0;
        $dlcCompletionPct = round(($dlcCupsCompleted / 12) * 100);
    ?>
    <div class="card">
        <h2 class="card-header cup-mastery-header">
            <span>🏆 DLC Cup Mastery — <?= strtoupper($currentSeason) ?></span>
            <span class="cup-mastery-meta">
                <?= $dlcCupsCompleted ?>/12 cups
                <?php if ($dlcCompletionPct === 100): ?>
                    <span class="cup-mastery-complete">✓ Complete</span>
                <?php else: ?>
                    <span class="cup-mastery-pct"><?= $dlcCompletionPct ?>%</span>
                <?php endif; ?>
            </span>
        </h2>

        <!-- Progress Bar -->
        <div class="cup-progress-bar">
            <div class="cup-progress-fill <?= $dlcCompletionPct === 100 ? 'cup-progress-fill--complete' : 'cup-progress-fill--partial' ?>"
                 style="width: <?= $dlcCompletionPct ?>%;"></div>
        </div>

        <!-- Cup Grid -->
        <div class="cup-cells-grid">
            <?php foreach ($dlcCupProgress as $cupName => $data): ?>
            <?php
                if ($data['is_perfect']) {
                    $cellClass = 'cup-cell--perfect';
                    $icon = '🌟';
                } elseif ($data['completed']) {
                    $cellClass = 'cup-cell--done';
                    $icon = '✓';
                } else {
                    $cellClass = 'cup-cell--pending';
                    $icon = '—';
                }
            ?>
            <div class="cup-cell <?= $cellClass ?>">
                <div class="cup-cell-header">
                    <div class="cup-cell-name"><?= htmlspecialchars($cupName) ?> Cup</div>
                    <span class="cup-cell-icon"><?= $icon ?></span>
                </div>
                <?php if ($data['completed']): ?>
                    <div class="cup-cell-score <?= $data['is_perfect'] ? 'cup-cell-score--perfect' : 'cup-cell-score--done' ?>">
                        <?= $data['best_score'] ?>
                        <span class="cup-cell-score-denom">/ 60</span>
                    </div>
                    <div class="cup-cell-footer">
                        <span><?= $data['attempts'] ?> attempt<?= $data['attempts'] != 1 ? 's' : '' ?></span>
                        <?php if ($data['improvement_potential'] > 0): ?>
                            <span class="cup-cell-improve">+<?= $data['improvement_potential'] ?> possible</span>
                        <?php else: ?>
                            <span class="cup-cell-maxed">Max!</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="cup-cell-unplayed">Not yet played</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Summary Row -->
        <div class="cup-summary-grid">
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Total Score</div>
                <div class="cup-summary-value cup-summary-value--score"><?= $dlcTotalScore ?></div>
                <div class="cup-summary-max">/ 720 max</div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Cups Done</div>
                <div class="cup-summary-value"><?= $dlcCupsCompleted ?>/12</div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Perfect 60s</div>
                <div class="cup-summary-value cup-summary-value--perfect">🌟 <?= $dlcPerfectCups ?></div>
            </div>
            <div class="cup-summary-cell">
                <div class="cup-summary-label">Avg Cup Score</div>
                <div class="cup-summary-value"><?= $dlcCupsCompleted > 0 ? number_format($dlcAvgCupScore, 1) : '—' ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end scoring system check ?>

    <!-- Season Breakdown -->
    <div class="card">
        <h2 class="card-header">Season Performance</h2>
        <table class="clean-table racer-mt-20">
            <thead>
                <tr>
                    <th>Season</th>
                    <th class="scoring-system-cell">System</th>
                    <th>Placement</th>
                    <th>GPScore™</th>
                    <th>GPs</th>
                    <th>Points</th>
                    <th>Best GP</th>
                    <th>Wins</th>
                    <th>Badges</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($seasonBreakdown as $season):
                    $bd = $season['breakdown'];
                    $scoringSystem = $bd['system'] ?? 'average_attendance';
                    $c = $bd['components'] ?? [];

                    // Generate system-aware tooltip
                    if ($scoringSystem === 'average_attendance') {
                        $tooltip = sprintf("GPScore: %.2f (%d GPs counted, %d dropped)",
                            $season['gp_score'], $c['races_counted'] ?? 0, $c['races_dropped'] ?? 0);
                    } elseif ($scoringSystem === 'preseason') {
                        $tooltip = sprintf("Average: %.2f (%d GPs played)",
                            $season['gp_score'], $c['total_races'] ?? 0);
                    } elseif (in_array($scoringSystem, ['cup_based', 'drop_worst', 'perfect_hunt', 'random_cup_draw'])) {
                        $tooltip = sprintf("Cups: %d/%d completed • Score: %.2f",
                            $c['cups_completed'] ?? 0, $c['cups_required'] ?? 12, $season['gp_score']);
                    } elseif ($scoringSystem === 'best_n_gps') {
                        $tooltip = sprintf("Best %d GPs: %.2f (%d total played, %d dropped)",
                            $c['best_n_count'] ?? 0, $season['gp_score'], $c['total_gps_played'] ?? 0, $c['gps_dropped'] ?? 0);
                    } elseif ($scoringSystem === 'top_12_unique') {
                        $tooltip = sprintf("Top 12 cups: %d (%d/24 cups played, %d perfects, tiebreaker: %d)",
                            (int)$season['gp_score'], $c['cups_played'] ?? 0, $c['unique_60s'] ?? 0, $c['unique_60s'] ?? 0);
                    } else {
                        $tooltip = sprintf("Score: %.2f", $season['gp_score']);
                    }

                    // Format placement with medals for top 3
                    $placement = $season['placement'];
                    if ($placement == 1) {
                        $placementDisplay = '🥇';
                    } elseif ($placement == 2) {
                        $placementDisplay = '🥈';
                    } elseif ($placement == 3) {
                        $placementDisplay = '🥉';
                    } elseif ($placement > 0) {
                        $placementDisplay = '#' . $placement;
                    } else {
                        $placementDisplay = '--';
                    }
                ?>
                <tr>
                    <td><strong><?= strtoupper($season['season']) ?></strong></td>
                    <td class="scoring-system-cell" data-tooltip="<?= htmlspecialchars($season['scoring_info']['name']) ?>: <?= htmlspecialchars($season['scoring_info']['description']) ?>">
                        <span class="scoring-system-icon"><?= $season['scoring_info']['icon'] ?></span>
                    </td>
                    <td class="placement-cell"><?= $placementDisplay ?></td>
                    <td class="gp-score"><span class="gpscore-cell" data-tooltip="<?= htmlspecialchars($tooltip) ?>"><?= number_format($season['gp_score'], 2) ?></span></td>
                    <td><?= $season['stats']['gps'] ?></td>
                    <td><?= $season['stats']['points'] ?></td>
                    <td><?= $season['stats']['best'] ?></td>
                    <td><?= $season['stats']['wins'] ?></td>
                    <td>
                        <?php foreach ($season['badges'] as $badge): ?>
                            <span class="badge-item badge-item--sm" data-tooltip="<?= htmlspecialchars($badge['title']) ?>: <?= htmlspecialchars($badge['desc']) ?>">
                                <?= $badge['icon'] ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Recent Results -->
    <?php
    // For Top 12 Unique, precompute best-in-cup data for status indicators
    $bestInCup = [];
    $top12CupNamesRecent = [];
    $isTop12Unique = ($currentScoringSystem === 'top_12_unique');
    if ($isTop12Unique) {
        $allCupNames = getMK8DCups();
        foreach ($allCupNames as $cn) {
            $biStmt = $pdo->prepare("SELECT MAX(gp_points) as best FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' AND cup_name = ?");
            $biStmt->execute([$racerId, $currentSeason . '%', $cn]);
            $best = $biStmt->fetchColumn();
            if ($best) $bestInCup[$cn] = (int)$best;
        }
        arsort($bestInCup);
        $top12CupNamesRecent = array_slice(array_keys($bestInCup), 0, 12);
    }
    ?>
    <div class="card">
        <h2 class="card-header">Recent Grand Prix Results</h2>
        <table class="clean-table racer-mt-20">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Season</th>
                    <th>Cup</th>
                    <th>Character</th>
                    <th>Finish</th>
                    <th>Points</th>
                    <?php if ($isTop12Unique): ?><th>Status</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentResults as $result): ?>
                <tr>
                    <td><?= date('M j, Y', strtotime($result['race_date'])) ?></td>
                    <td><?= strtoupper($result['season']) ?></td>
                    <td><?= htmlspecialchars($result['cup_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($result['character_used']) ?></td>
                    <td>
                        <strong<?= $result['rank'] <= 3 ? ' class="rank-top3"' : '' ?>>
                            #<?= $result['rank'] ?>
                        </strong>
                    </td>
                    <td class="gp-score"><?= $result['gp_points'] ?></td>
                    <?php if ($isTop12Unique): ?>
                    <td class="t12-status-cell">
                        <?php
                        $cupN = $result['cup_name'] ?? '';
                        $isCurrSeason = ($result['season'] === $currentSeason);
                        $isBest = $isCurrSeason && isset($bestInCup[$cupN]) && (int)$result['gp_points'] === $bestInCup[$cupN];
                        $cupInTop12 = in_array($cupN, $top12CupNamesRecent);
                        ?>
                        <?php if ($isBest && $cupInTop12): ?>
                            <span class="t12-counts-badge" data-tooltip="Best in cup — counts toward Top 12">✦ Counts</span>
                        <?php elseif ($isBest && !$cupInTop12): ?>
                            <span class="t12-best-badge" data-tooltip="Best in cup — but cup not in Top 12">Best</span>
                        <?php elseif ($isCurrSeason): ?>
                            <span class="t12-superseded-badge" data-tooltip="Superseded by a better run in this cup">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- News Mentions -->
    <?php if (!empty($newsItems)): ?>
    <div class="card">
        <h2 class="card-header">In The News</h2>
        <div class="news-mentions-list">
            <?php foreach ($newsItems as $news): ?>
                <div class="news-mention-item">
                    <h3 class="news-mention-headline">
                        <a href="/view-recap/<?= $news['id'] ?>">
                            <?= htmlspecialchars($news['headline']) ?>
                        </a>
                    </h3>
                    <?php if (!empty($news['key_quote'])): ?>
                        <p class="news-mention-quote">
                            "<?= htmlspecialchars($news['key_quote']) ?>"
                        </p>
                    <?php endif; ?>
                    <div class="news-mention-date">
                        <?= date('F j, Y', strtotime($news['created_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
<script>
function downloadCard() {
    const card = document.getElementById('racerCard');
    const button = event.target;
    button.textContent = 'Generating...';
    button.disabled = true;

    html2canvas(card, {
        scale: 2,
        backgroundColor: null,
        logging: false
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = '<?= htmlspecialchars($racer['name']) ?>_card.png';
        link.href = canvas.toDataURL();
        link.click();

        button.textContent = '📸 Download Card';
        button.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
