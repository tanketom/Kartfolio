<?php
/**
 * Scoring Explainer - How GPScore is Calculated
 * Path: /cdnmk/public_html/scoring.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$pageTitle = "Scoring Explained - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();

// Fetch season meta
$ruleStmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
$ruleStmt->execute([$seasonId]);
$rules = $ruleStmt->fetch(PDO::FETCH_ASSOC);

$scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
$scoringInfo = getScoringSystemInfo($pdo, $seasonId);

// Fetch all seasons for selector
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all racers with results this season
$racersStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
    ORDER BY r.name
");
$racersStmt->execute([$seasonId . '%']);
$racers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

// Build per-racer breakdown
$racerBreakdowns = [];
foreach ($racers as $racer) {
    $rid = $racer['id'];

    // Fetch all GPs sorted by points ascending (matching the scoring logic)
    $gpStmt = $pdo->prepare("
        SELECT res.gpid, res.gp_points, res.race_date, res.cup_name, res.rank
        FROM results res
        WHERE res.racer_id = ? AND res.gpid LIKE ? AND res.gpid LIKE 's%'
        ORDER BY res.gp_points ASC
    ");
    $gpStmt->execute([$rid, $seasonId . '%']);
    $gps = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRaces = count($gps);
    $finalScore = calculateGPScore($pdo, $rid, $seasonId);

    $entry = [
        'name' => $racer['name'],
        'gps' => $gps,
        'total' => $totalRaces,
        'score' => $finalScore,
        'dropped' => [],
        'counted' => [],
    ];

    if ($scoringSystem === 'average_attendance') {
        $dropRate = $rules['drop_rate'] ?? 10;
        $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
        $entry['num_dropped'] = $numToDrop;
        $entry['dropped'] = array_slice($gps, 0, $numToDrop);
        $entry['counted'] = array_slice($gps, $numToDrop);

        // Attendance bonus
        $attWeight = $rules['attendance_weight'] ?? 1.0;
        $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
        $weeklyTracker = [];
        $attBonus = 0;
        foreach ($gps as $g) {
            $wk = date('Y-W', strtotime($g['race_date']));
            if (!isset($weeklyTracker[$wk])) $weeklyTracker[$wk] = 0;
            if ($weeklyTracker[$wk] < $weeklyCap) {
                $attBonus += $attWeight;
                $weeklyTracker[$wk] += $attWeight;
            }
        }
        $countedPoints = array_column($entry['counted'], 'gp_points');
        $avg = count($countedPoints) > 0 ? array_sum($countedPoints) / count($countedPoints) : 0;
        $entry['average'] = round($avg, 2);
        $entry['attendance_bonus'] = round($attBonus, 2);
    } elseif ($scoringSystem === 'preseason') {
        $dropRate = $rules['drop_rate'] ?? 10;
        $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
        $entry['num_dropped'] = $numToDrop;
        $entry['dropped'] = array_slice($gps, 0, $numToDrop);
        $entry['counted'] = array_slice($gps, $numToDrop);
        $countedPoints = array_column($entry['counted'], 'gp_points');
        $avg = count($countedPoints) > 0 ? array_sum($countedPoints) / count($countedPoints) : 0;
        $entry['average'] = round($avg, 2);
    } elseif ($scoringSystem === 'top_12_unique') {
        // Best score per cup, top 12
        $allCups = getMK8DCups();
        $cupBests = [];
        foreach ($allCups as $cupName) {
            $cStmt = $pdo->prepare("SELECT MAX(gp_points) as best FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' AND cup_name = ?");
            $cStmt->execute([$rid, $seasonId . '%', $cupName]);
            $best = $cStmt->fetchColumn();
            if ($best) $cupBests[$cupName] = (int)$best;
        }
        arsort($cupBests);
        $entry['cup_bests'] = $cupBests;
        $top12 = array_slice($cupBests, 0, 12, true);
        $dropped = array_slice($cupBests, 12, null, true);
        $entry['top12'] = $top12;
        $entry['dropped_cups'] = $dropped;
        $entry['top12_total'] = array_sum($top12);
        $entry['bubble_score'] = count($cupBests) >= 12 ? min($top12) : null;
        $entry['perfects'] = count(array_filter($top12, fn($p) => $p === 60));
    }

    $racerBreakdowns[] = $entry;
}

// Sort by score descending
usort($racerBreakdowns, fn($a, $b) => $b['score'] <=> $a['score']);

$minThreshold = (int)($rules['min_races_threshold'] ?? 3);
?>

<div class="stats-container">
    <div class="racer-card scr-header-card">
        <div class="scr-top-row">
            <div>
                <h1 class="scr-title"><?= $scoringInfo['icon'] ?> Scoring Explained</h1>
                <p class="scr-subtitle"><?= htmlspecialchars(strtoupper($seasonId)) ?> &mdash; <?= htmlspecialchars($scoringInfo['name']) ?></p>
            </div>
            <form method="GET" action="/scoring" class="season-selector-form">
                <select name="season" onchange="this.form.submit()" class="season-selector-select">
                    <?php foreach ($availableSeasons as $s): ?>
                        <option value="<?= htmlspecialchars($s['season_id']) ?>" <?= ($s['season_id'] === $seasonId) ? 'selected' : '' ?>>
                            <?= 'Season ' . strtoupper($s['season_id']) . ($s['status'] === 'archived' ? ' (Archived)' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="scr-formula">
            <?php if ($scoringSystem === 'average_attendance'): ?>
                <h2>How GPScore is calculated</h2>
                <div class="scr-formula-box">
                    <code>GPScore = Average(counted GPs) + Attendance Bonus</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">
                        <strong>Drop rate:</strong> 1 worst GP dropped per <?= (int)($rules['drop_rate'] ?? 10) ?> played
                    </div>
                    <div class="scr-rule">
                        <strong>Attendance bonus:</strong> +<?= $rules['attendance_weight'] ?? 1.0 ?> per GP, max <?= $rules['weekly_bonus_cap'] ?? 2 ?> per week
                    </div>
                    <div class="scr-rule">
                        <strong>Minimum races:</strong> <?= $minThreshold ?> GPs required to appear on leaderboard
                    </div>
                </div>

            <?php elseif ($scoringSystem === 'preseason'): ?>
                <h2>How the Pre-Season score is calculated</h2>
                <div class="scr-formula-box">
                    <code>Score = Average(counted GPs)</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">
                        <strong>Drop rate:</strong> 1 worst GP dropped per <?= (int)($rules['drop_rate'] ?? 10) ?> played
                    </div>
                </div>

            <?php elseif ($scoringSystem === 'top_12_unique'): ?>
                <h2>How Top 12 Unique scoring works</h2>
                <div class="scr-formula-box">
                    <code>Score = Sum of best score from each of your top 12 cups</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">
                        <strong>Per cup:</strong> Only your best GP in each cup counts
                    </div>
                    <div class="scr-rule">
                        <strong>Top 12:</strong> Your 12 highest-scoring cups are summed; the rest are dropped
                    </div>
                    <div class="scr-rule">
                        <strong>Minimum races:</strong> <?= $minThreshold ?> GPs required to appear on leaderboard
                    </div>
                </div>

            <?php elseif ($scoringSystem === 'black_box'): ?>
                <h2>Black Box</h2>
                <div class="scr-formula-box">
                    <code>???</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">The Black Box scoring formula is classified.</div>
                </div>

            <?php else: ?>
                <h2><?= htmlspecialchars($scoringInfo['name']) ?></h2>
                <p><?= htmlspecialchars($scoringInfo['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($racerBreakdowns as $rb): ?>
    <div class="racer-card scr-racer-card">
        <div class="scr-racer-header">
            <h3 class="scr-racer-name"><?= htmlspecialchars($rb['name']) ?></h3>
            <div class="scr-racer-score">
                <?php if ($rb['total'] < $minThreshold): ?>
                    <span class="scr-unranked">Unranked (<?= $rb['total'] ?>/<?= $minThreshold ?> GPs)</span>
                <?php else: ?>
                    <span class="scr-gpscore"><?= round($rb['score'], 2) ?></span>
                    <span class="scr-gpscore-label">GPScore</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($scoringSystem === 'top_12_unique' && !empty($rb['cup_bests'])): ?>
            <!-- Top 12 Unique view -->
            <div class="scr-summary">
                Top 12 Total: <strong><?= $rb['top12_total'] ?></strong> / 720 &middot;
                <?= count($rb['cup_bests']) ?> cups played &middot;
                <?= $rb['perfects'] ?> perfect<?= $rb['perfects'] !== 1 ? 's' : '' ?> (tiebreaker)
                <?php if ($rb['bubble_score'] !== null): ?>
                    &middot; bubble line: <?= $rb['bubble_score'] ?>
                <?php endif; ?>
            </div>
            <div class="scr-gp-grid">
                <?php $cupRank = 1; foreach ($rb['top12'] as $cup => $pts): ?>
                    <div class="scr-gp-chip scr-counted" title="#<?= $cupRank ?> — <?= htmlspecialchars($cup) ?> Cup — +<?= 60 - $pts ?> improvement possible">
                        <span class="scr-gp-rank">#<?= $cupRank ?></span>
                        <span class="scr-gp-cup"><?= htmlspecialchars($cup) ?></span>
                        <span class="scr-gp-pts"><?= $pts ?></span>
                    </div>
                <?php $cupRank++; endforeach; ?>
                <?php if (!empty($rb['dropped_cups'])): ?>
                <div class="scr-cut-divider">— cut line —</div>
                <?php endif; ?>
                <?php foreach ($rb['dropped_cups'] as $cup => $pts): ?>
                    <div class="scr-gp-chip scr-dropped" title="<?= htmlspecialchars($cup) ?> Cup — need <?= ($rb['bubble_score'] ?? 0) + 1 ?>+ to count">
                        <span class="scr-gp-cup"><?= htmlspecialchars($cup) ?></span>
                        <span class="scr-gp-pts"><?= $pts ?></span>
                        <span class="scr-gp-label">dropped</span>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($scoringSystem !== 'black_box'): ?>
            <!-- Average/drop view -->
            <div class="scr-summary">
                <?= $rb['total'] ?> GPs played &middot;
                <?= $rb['num_dropped'] ?? 0 ?> dropped &middot;
                <?= $rb['total'] - ($rb['num_dropped'] ?? 0) ?> counted
                <?php if (isset($rb['average'])): ?>
                    &middot; avg <?= $rb['average'] ?>
                <?php endif; ?>
                <?php if (isset($rb['attendance_bonus']) && $rb['attendance_bonus'] > 0): ?>
                    &middot; +<?= $rb['attendance_bonus'] ?> attendance
                <?php endif; ?>
            </div>
            <div class="scr-gp-grid">
                <?php
                // Show dropped first (they're at the start since sorted ASC by points)
                foreach ($rb['dropped'] as $gp): ?>
                    <div class="scr-gp-chip scr-dropped" title="<?= htmlspecialchars($gp['cup_name']) ?> Cup &middot; #<?= $gp['rank'] ?> &middot; <?= date('M j', strtotime($gp['race_date'])) ?>">
                        <span class="scr-gp-pts"><?= $gp['gp_points'] ?></span>
                        <span class="scr-gp-label">dropped</span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($rb['counted'] as $gp): ?>
                    <div class="scr-gp-chip scr-counted" title="<?= htmlspecialchars($gp['cup_name']) ?> Cup &middot; #<?= $gp['rank'] ?> &middot; <?= date('M j', strtotime($gp['race_date'])) ?>">
                        <span class="scr-gp-pts"><?= $gp['gp_points'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="scr-summary"><?= $rb['total'] ?> GPs played</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
