<?php
/**
 * League Leaderboard - Ticker + News Grid
 * Path: /cdnmk/public_html/index.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';

$pageTitle = "Leaderboard - Kartfolio";
include __DIR__ . '/../private/templates/header.php';

$seasonId = getCurrentSeasonNumber();

// 1. Fetch Rules and Scoring System Info
$ruleStmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
$ruleStmt->execute([$seasonId]);
$rules = $ruleStmt->fetch(PDO::FETCH_ASSOC);
$minThreshold = isset($rules['min_races_threshold']) ? (int)$rules['min_races_threshold'] : 1;

// Get scoring system info for display
$scoringInfo = getScoringSystemInfo($pdo, $seasonId);

// 2. Rivalry Watch
$nemesisTicker = "";
try {
    $feudStmt = $pdo->prepare("
        SELECT r1.name as p1, r2.name as p2, COUNT(*) as meetings
        FROM results res1
        JOIN results res2 ON res1.gpid = res2.gpid AND res1.cup_name = res2.cup_name
        JOIN racers r1 ON res1.racer_id = r1.id
        JOIN racers r2 ON res2.racer_id = r2.id
        WHERE res1.racer_id < res2.racer_id AND res1.gpid LIKE ?
        GROUP BY res1.racer_id, res2.racer_id
        ORDER BY meetings DESC LIMIT 1
    ");
    $feudStmt->execute([$seasonId . "%"]);
    $topFeud = $feudStmt->fetch(PDO::FETCH_ASSOC);
    if ($topFeud && $topFeud['meetings'] >= 2) {
        $nemesisTicker = "RIVALRY WATCH: " . strtoupper($topFeud['p1']) . " VS " . strtoupper($topFeud['p2']) . " (" . $topFeud['meetings'] . " GPs)";
    }
} catch (Exception $e) {}

// 3. Fetch News for Ticker & Bottom Grid
$newsStmt = $pdo->prepare("SELECT * FROM recap_archive ORDER BY created_at DESC LIMIT 2");
$newsStmt->execute();
$latestNews = $newsStmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare Ticker Data
$tickerLines = [];
if ($nemesisTicker) $tickerLines[] = ['headline' => 'RIVALRY', 'key_quote' => $nemesisTicker];
foreach ($latestNews as $item) $tickerLines[] = $item;

// Program Definitions (For Icons)
$programs = [
    "core_team" => ["label" => "Kart Core Team", "img" => "program_core_team.png"],
    "reef_dispatch" => ["label" => "Reef’s Dispatch", "img" => "program_reef_dispatch.png"],
    "meta_report" => ["label" => "The Meta Report", "img" => "program_meta_report.png"],
    "the_rant" => ["label" => "The Rant", "img" => "program_the_rant.png"],
    "ghost_racer" => ["label" => "The Ghost Racer", "img" => "program_ghost_racer.png"],
    "situated_spectator" => ["label" => "Situated Spectator", "img" => "program_situated_spectator.png"],
    "viberacing" => ["label" => "Viberacing", "img" => "program_viberacing.png"],
    "random" => ["label" => "Special Broadcast", "img" => "program_default.png"]
];

// Helper to get GPScore breakdown for tooltip (system-aware)
function getScoreBreakdown($pdo, $racer_id, $season_id) {
    $stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
    $stmt->execute([$season_id]);
    $rules = $stmt->fetch(PDO::FETCH_ASSOC);

    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    // For top_12_unique, return cup-style breakdown
    // Black Box: reveal nothing
    if ($scoringSystem === 'black_box') {
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'");
        $stmt2->execute([$racer_id, $season_id . '%']);
        $totalGPs = $stmt2->fetchColumn();

        return [
            'avg' => 0,
            'att' => 0,
            'drop' => 0,
            'counted' => $totalGPs,
            'system' => 'black_box'
        ];
    }

    if ($scoringSystem === 'top_12_unique') {
        $allCups = getMK8DCups();
        $cupsPlayed = 0;
        $perfectCount = 0;
        foreach ($allCups as $cupName) {
            $cStmt = $pdo->prepare("SELECT MAX(gp_points) as best FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' AND cup_name = ?");
            $cStmt->execute([$racer_id, $season_id . '%', $cupName]);
            $cResult = $cStmt->fetch(PDO::FETCH_ASSOC);
            if ($cResult && $cResult['best']) {
                $cupsPlayed++;
                if ((int)$cResult['best'] === 60) $perfectCount++;
            }
        }

        return [
            'avg' => 0,
            'att' => 0,
            'drop' => max(0, $cupsPlayed - 12),
            'counted' => min($cupsPlayed, 12),
            'system' => $scoringSystem,
            'cups_completed' => $cupsPlayed,
            'cups_required' => 12,
            'perfects' => $perfectCount
        ];
    }

    // For cup-based systems, get cup completion info
    if (in_array($scoringSystem, ['cup_based', 'drop_worst', 'perfect_hunt'])) {
        $cupsRequired = $rules['cups_required'] ?? 12;
        $progress = getCupProgress($pdo, $racer_id, $season_id, $cupsRequired);
        $cupsCompleted = count(array_filter($progress, fn($c) => $c['completed']));

        return [
            'avg' => 0,
            'att' => 0,
            'drop' => 0,
            'counted' => $cupsCompleted,
            'system' => $scoringSystem,
            'cups_completed' => $cupsCompleted,
            'cups_required' => $cupsRequired
        ];
    }

    // For best_n_gps
    if ($scoringSystem === 'best_n_gps') {
        $bestN = $rules['best_n_count'] ?? 15;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'");
        $stmt->execute([$racer_id, $season_id . '%']);
        $totalGPs = $stmt->fetchColumn();

        return [
            'avg' => 0,
            'att' => 0,
            'drop' => max(0, $totalGPs - $bestN),
            'counted' => min($totalGPs, $bestN),
            'system' => $scoringSystem,
            'best_n' => $bestN,
            'total_gps' => $totalGPs
        ];
    }

    // For preseason
    if ($scoringSystem === 'preseason') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'");
        $stmt->execute([$racer_id, $season_id . '%']);
        $totalGPs = $stmt->fetchColumn();
        $dropped = floor($totalGPs * 0.1);

        return [
            'avg' => 0,
            'att' => 0,
            'drop' => $dropped,
            'counted' => $totalGPs - $dropped,
            'system' => $scoringSystem
        ];
    }

    // Default: average_attendance (legacy)
    $attWeight = $rules['attendance_weight'] ?? 1.0;
    $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
    $dropRate = $rules['drop_rate'] ?? 10;

    $stmt = $pdo->prepare("SELECT gp_points, race_date FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' ORDER BY gp_points ASC");
    $stmt->execute([$racer_id, $season_id . "%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRaces = count($results);
    $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;

    $pointsOnly = array_column($results, 'gp_points');
    $filteredPoints = array_slice($pointsOnly, $numToDrop);
    $average = (count($filteredPoints) > 0) ? array_sum($filteredPoints) / count($filteredPoints) : 0;

    $attendanceBonus = 0;
    $weeklyTracker = [];
    foreach ($results as $res) {
        $weekKey = date('Y-W', strtotime($res['race_date']));
        if (!isset($weeklyTracker[$weekKey])) {
            $weeklyTracker[$weekKey] = 0;
        }
        if ($weeklyTracker[$weekKey] < $weeklyCap) {
            $attendanceBonus += $attWeight;
            $weeklyTracker[$weekKey] += $attWeight;
        }
    }

    return [
        'avg' => $average,
        'att' => $attendanceBonus,
        'drop' => $numToDrop,
        'counted' => count($filteredPoints),
        'system' => $scoringSystem
    ];
}

// 4. Fetch Latest Grand Prix Results
// Get the most recent race date
$latestDateStmt = $pdo->prepare("SELECT MAX(race_date) as latest_date FROM results WHERE gpid LIKE ?");
$latestDateStmt->execute([$seasonId . "%"]);
$latestDateRow = $latestDateStmt->fetch(PDO::FETCH_ASSOC);
$latestDate = $latestDateRow['latest_date'];

$latestGPs = [];
if ($latestDate) {
    // Get all GPs from the latest date (could be multiple)
    $gpStmt = $pdo->prepare("
        SELECT gpid, cup_name, race_date
        FROM results
        WHERE gpid LIKE ? AND race_date = ?
        GROUP BY gpid
        ORDER BY id DESC
    ");
    $gpStmt->execute([$seasonId . "%", $latestDate]);
    $latestGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

    // If fewer than 6, get additional recent GPs to reach 6 total
    if (count($latestGPs) < 6) {
        $additionalStmt = $pdo->prepare("
            SELECT gpid, cup_name, race_date
            FROM results
            WHERE gpid LIKE ? AND race_date < ?
            GROUP BY gpid
            ORDER BY race_date DESC, id DESC
            LIMIT ?
        ");
        $additionalStmt->execute([$seasonId . "%", $latestDate, 6 - count($latestGPs)]);
        $latestGPs = array_merge($latestGPs, $additionalStmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

// 5. Calculate Previous Standings (before latest race date)
$previousStandings = [];
if ($latestDate) {
    // Get second-most recent date
    $prevDateStmt = $pdo->prepare("SELECT MAX(race_date) as prev_date FROM results WHERE gpid LIKE ? AND race_date < ?");
    $prevDateStmt->execute([$seasonId . "%", $latestDate]);
    $prevDateRow = $prevDateStmt->fetch(PDO::FETCH_ASSOC);
    $prevDate = $prevDateRow['prev_date'];

    if ($prevDate) {
        // Calculate standings as of the previous date
        $prevRacerStmt = $pdo->prepare("SELECT DISTINCT r.* FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ? AND res.race_date <= ?");
        $prevRacerStmt->execute([$seasonId . "%", $prevDate]);
        $prevActiveRacers = $prevRacerStmt->fetchAll();

        $prevStandingsTemp = [];
        foreach ($prevActiveRacers as $r) {
            // Calculate score excluding races after prevDate
            $stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
            $stmt->execute([$seasonId]);
            $rules = $stmt->fetch(PDO::FETCH_ASSOC);

            $attWeight = $rules['attendance_weight'] ?? 1.0;
            $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
            $dropRate = $rules['drop_rate'] ?? 10;

            $stmt = $pdo->prepare("SELECT gp_points, race_date FROM results WHERE racer_id = ? AND gpid LIKE ? AND race_date <= ? ORDER BY gp_points ASC");
            $stmt->execute([$r['id'], $seasonId . "%", $prevDate]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalRaces = count($results);
            if ($totalRaces > 0) {
                $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
                $pointsOnly = array_column($results, 'gp_points');
                $filteredPoints = array_slice($pointsOnly, $numToDrop);
                $average = (count($filteredPoints) > 0) ? array_sum($filteredPoints) / count($filteredPoints) : 0;

                $attendanceBonus = 0;
                $weeklyTracker = [];
                foreach ($results as $res) {
                    $weekKey = date('Y-W', strtotime($res['race_date']));
                    if (!isset($weeklyTracker[$weekKey])) {
                        $weeklyTracker[$weekKey] = 0;
                    }
                    if ($weeklyTracker[$weekKey] < $weeklyCap) {
                        $attendanceBonus += $attWeight;
                        $weeklyTracker[$weekKey] += $attWeight;
                    }
                }

                $score = $average + $attendanceBonus;
                $prevStandingsTemp[] = ['id' => $r['id'], 'score' => $score];
            }
        }

        // Sort and create rank map
        usort($prevStandingsTemp, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($prevStandingsTemp as $index => $racer) {
            $previousStandings[$racer['id']] = $index + 1;
        }
    }
}

// 6. Fetch Leaderboard
$racerStmt = $pdo->prepare("SELECT DISTINCT r.* FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
$racerStmt->execute([$seasonId . "%"]);
$activeRacers = $racerStmt->fetchAll();

$standings = [];
foreach ($activeRacers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);
    $breakdown = getScoreBreakdown($pdo, $r['id'], $seasonId);

    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$r['id'], $seasonId . "%"]);
    $char = $charStmt->fetchColumn() ?: 'Mii';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $countStmt->execute([$r['id'], $seasonId . "%"]);
    $raceCount = (int)$countStmt->fetchColumn();

    $standings[] = [
        'id'        => $r['id'],
        'name'      => $r['name'],
        'score'     => $score,
        'breakdown' => $breakdown,
        'char'      => $char,
        'badges'    => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $seasonId) : [],
        'raceCount' => $raceCount
    ];
}
// Sort standings: by score DESC, then tiebreaker for top_12_unique (most unique 60s), then name
if ($scoringInfo['system'] === 'top_12_unique') {
    // Add tiebreaker data
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
    usort($standings, fn($a, $b) => ($b['score'] == $a['score']) ? strcmp($a['name'], $b['name']) : $b['score'] <=> $a['score']);
}

// Calculate rank changes
foreach ($standings as $index => &$racer) {
    $currentRank = $index + 1;
    $previousRank = $previousStandings[$racer['id']] ?? null;

    if ($previousRank !== null) {
        $racer['rank_change'] = $previousRank - $currentRank; // Positive = moved up, Negative = moved down
    } else {
        $racer['rank_change'] = null; // New to leaderboard
    }
}
unset($racer);
?>

<?php if (!empty($tickerLines)): ?>
<div class="news-ticker-wrap">
    <div class="ticker-label">LIVE</div>
    <div class="ticker-content">
        <div class="ticker-move">
            <?php foreach (array_merge($tickerLines, $tickerLines) as $item): ?>
                <span class="ticker-item">
                    <span class="ticker-headline"><?= htmlspecialchars(strtoupper($item['headline'])) ?>:</span>
                    "<?= htmlspecialchars($item['key_quote']) ?>"
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="container stats-container">
    <header class="leaderboard-header">
        <div>
            <h1 class="leaderboard-title">Season <?= strtoupper($seasonId) ?></h1>
            <?php if ($rules && isset($rules['season_name'])): ?>
            <div class="leaderboard-subtitle">
                <?= htmlspecialchars($rules['season_name']) ?>
                <?php if ($rules['academic_year']): ?>
                    • <?= $rules['academic_year'] ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="scoring-system-label">
            <span class="scoring-icon"><?= $scoringInfo['icon'] ?></span>
            <?= htmlspecialchars($scoringInfo['name']) ?>
        </div>
    </header>

    <?php if (empty($standings)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🏁</div>
            <h2 class="empty-state-title">No Racers Yet This Season</h2>
            <p class="empty-state-message">The starting grid is empty! Add your first Grand Prix results to get the competition started.</p>
            <a href="/add-result" class="btn btn-primary">Log First Grand Prix</a>
        </div>
    <?php else: ?>
    <div class="leaderboard-grid">
        <?php foreach ($standings as $index => $row):
            $rank = $index + 1;
            $isQualifying = ($row['raceCount'] >= $minThreshold);
            $rankClass = ($isQualifying && $rank <= 3) ? ['gold', 'silver', 'bronze'][$rank-1] : "";
            $bd = $row['breakdown'];

            // Generate system-aware tooltip
            $bdSystem = $bd['system'] ?? 'average_attendance';
            if ($bdSystem === 'black_box') {
                $tooltip = sprintf("⬛ Black Box Score: %.2f (%d GPs)", $row['score'], $bd['counted']);
            } elseif ($bdSystem === 'top_12_unique') {
                $tooltip = sprintf("Top 12 Unique: %d cups played, best %d counted, %d perfects (tiebreaker) • Score: %d",
                    $bd['cups_completed'], $bd['counted'], $bd['perfects'] ?? 0, (int)$row['score']);
            } elseif ($bdSystem === 'cup_based' || $bdSystem === 'drop_worst' || $bdSystem === 'perfect_hunt') {
                $tooltip = sprintf("Cups: %d/%d completed • Score: %.2f",
                    $bd['cups_completed'], $bd['cups_required'], $row['score']);
            } elseif ($bdSystem === 'best_n_gps') {
                $tooltip = sprintf("Best %d GPs: %.2f (%d total GPs, %d dropped)",
                    $bd['best_n'], $row['score'], $bd['total_gps'], $bd['drop']);
            } elseif ($bdSystem === 'preseason') {
                $tooltip = sprintf("Average: %.2f (%d GPs, %d dropped)",
                    $row['score'], $bd['counted'] + $bd['drop'], $bd['drop']);
            } else {
                $tooltip = sprintf("Avg: %.2f (%d GPs counted, %d dropped) + Attendance: %.2f = %.2f",
                    $bd['avg'], $bd['counted'], $bd['drop'], $bd['att'], $row['score']);
            }
        ?>
        <div class="racer-card <?= $rankClass ?><?= !$isQualifying ? ' racer-card--ineligible' : '' ?>">
            <div class="rank-number">
                <?= $isQualifying ? "#$rank" : "--" ?>
                <?php if (isset($row['rank_change'])): ?>
                    <?php if ($row['rank_change'] > 0): ?>
                        <span class="rank-up" data-tooltip="Up <?= $row['rank_change'] ?> position<?= $row['rank_change'] > 1 ? 's' : '' ?>">↑<?= $row['rank_change'] ?></span>
                    <?php elseif ($row['rank_change'] < 0): ?>
                        <span class="rank-down" data-tooltip="Down <?= abs($row['rank_change']) ?> position<?= abs($row['rank_change']) > 1 ? 's' : '' ?>">↓<?= abs($row['rank_change']) ?></span>
                    <?php else: ?>
                        <span class="rank-same" data-tooltip="No change in position">–</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="racer-portrait">
                <img src="/assets/img/<?= htmlspecialchars($row['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'">
            </div>
            <div class="racer-info">
                <div class="racer-name-row">
                    <a href="/racer/<?= $row['id'] ?>" class="racer-name racer-name-link">
                        <?= htmlspecialchars($row['name']) ?>
                    </a>
                </div>
                <?php if (!empty($row['badges'])): ?>
                <div class="badge-container">
                    <?php foreach ($row['badges'] as $badge): ?>
                        <div class="badge-item" data-tooltip="<?= htmlspecialchars($badge['title']) ?>: <?= htmlspecialchars($badge['desc']) ?>"><?= $badge['icon'] ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="racer-stat-label">
                    <?= $row['raceCount'] ?> GP<?= $row['raceCount'] > 1 ? 's' : '' ?> Raced
                    <?= !$isQualifying ? '• Ineligible' : '• GPScore™ Active' ?>
                </div>
            </div>
            <div class="racer-score" data-tooltip="<?= htmlspecialchars($tooltip) ?>">
                <?php if ($bdSystem === 'top_12_unique'): ?>
                    <?= (int)$row['score'] ?>
                    <div class="cup-completion">
                        <?= $bd['counted'] ?> of 12 cups counted
                        <?php if (($bd['perfects'] ?? 0) > 0): ?>
                            &middot; <?= $bd['perfects'] ?> 🌟
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?= number_format($row['score'], 2) ?>
                <?php endif; ?>
                <?php if (in_array($bdSystem, ['cup_based', 'drop_worst', 'perfect_hunt'])): ?>
                    <?php $cupsCompleted = $bd['cups_completed']; $cupsRequired = $bd['cups_required']; ?>
                    <div class="cup-completion <?= $cupsCompleted >= $cupsRequired ? 'cup-completion--done' : 'cup-completion--pending' ?>">
                        <?= $cupsCompleted ?>/<?= $cupsRequired ?>
                        <?= $cupsCompleted < $cupsRequired ? '⚠️' : '✓' ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Scoring System Badge -->
    <div class="scoring-system-banner">
        <div class="scoring-badge">
            <span class="scoring-icon"><?= $scoringInfo['icon'] ?></span>
            <div class="scoring-details">
                <div class="scoring-name"><?= htmlspecialchars($scoringInfo['name']) ?></div>
                <div class="scoring-description"><?= htmlspecialchars($scoringInfo['description']) ?></div>
            </div>
        </div>
        <?php if ($scoringInfo['system'] === 'cup_based' || $scoringInfo['system'] === 'drop_worst' || $scoringInfo['system'] === 'perfect_hunt'): ?>
            <a href="#cup-progress" class="view-cup-progress">View Cup Progress →</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($latestGPs)): ?>
    <section class="section-divider">
        <div class="section-header">
            <h3 class="section-title">Latest Grand Prix</h3>
            <a href="/timeline" class="section-link">View Full Timeline →</a>
        </div>

        <div class="gp-results-grid">
            <?php foreach ($latestGPs as $gp):
                // Fetch results for this GP
                $resStmt = $pdo->prepare("
                    SELECT r.name, res.gp_points, res.rank
                    FROM results res
                    JOIN racers r ON res.racer_id = r.id
                    WHERE res.gpid = ?
                    ORDER BY res.rank ASC
                ");
                $resStmt->execute([$gp['gpid']]);
                $results = $resStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="gp-result-card">
                <div class="gp-card-header">
                    <span class="gp-cup-name"><?= htmlspecialchars($gp['cup_name']) ?> Cup</span>
                    <span class="gp-date"><?= date('M j', strtotime($gp['race_date'])) ?></span>
                </div>
                <div class="gp-results-list">
                    <?php foreach ($results as $p):
                        $medal = ($p['rank'] == 1) ? '🥇' : (($p['rank'] == 2) ? '🥈' : (($p['rank'] == 3) ? '🥉' : $p['rank']));
                    ?>
                    <div class="gp-result-row">
                        <span class="gp-rank"><?= $medal ?></span>
                        <a href="/racer/<?= $p['racer_id'] ?>" class="gp-racer-name"><?= htmlspecialchars($p['name']) ?></a>
                        <span class="gp-points"><?= $p['gp_points'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($latestNews)): ?>
    <section class="news-section">
        <h3 class="news-section-title">Latest Broadcasts</h3>
        <div class="news-grid">
            <?php foreach ($latestNews as $news):
                $pKey = $news['program_key'] ?? 'core_team';
                $pInfo = $programs[$pKey] ?? $programs['core_team'];
            ?>
            <a href="/view-recap/<?= $news['id'] ?>" class="news-card-home">
                <img src="/assets/img/<?= $pInfo['img'] ?>" class="news-program-icon" onerror="this.src='/assets/img/program_default.png'">
                <div class="news-text-col">
                    <div class="news-meta">
                        <span class="news-program"><?= htmlspecialchars($pInfo['label']) ?></span>
                        <span class="news-date"><?= date('M j', strtotime($news['created_at'])) ?></span>
                    </div>
                    <div class="news-headline"><?= htmlspecialchars($news['headline']) ?></div>
                    <div class="news-link">Read Report &rarr;</div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>