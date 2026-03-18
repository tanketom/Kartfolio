<?php
/**
 * Head-to-Head Comparison Page
 * URL: /compare/5/6 or /compare?a=5&b=6
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';

// Get racer IDs
$racerA = $_GET['a'] ?? null;
$racerB = $_GET['b'] ?? null;

// Fetch all racers for the picker
$allRacers = $pdo->query("SELECT id, name FROM racers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Head-to-Head";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

$currentSeason = getCurrentSeasonNumber();
?>

<div class="stats-container">
    <header class="cup-stats-page-header">
        <h1 class="cup-stats-page-title">HEAD-TO-HEAD</h1>
        <p class="cup-stats-page-subtitle">Compare two racers side by side.</p>
    </header>

    <!-- Racer Picker -->
    <div class="compare-picker">
        <form method="GET" action="/compare" class="compare-picker-form">
            <div class="compare-picker-slot">
                <label>Racer A</label>
                <select name="a" required>
                    <option value="">Select racer...</option>
                    <?php foreach ($allRacers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $racerA == $r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="compare-picker-vs">VS</div>
            <div class="compare-picker-slot">
                <label>Racer B</label>
                <select name="b" required>
                    <option value="">Select racer...</option>
                    <?php foreach ($allRacers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $racerB == $r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Compare</button>
        </form>
    </div>

<?php if ($racerA && $racerB && $racerA != $racerB):
    // Fetch racer info
    $stmtA = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
    $stmtA->execute([$racerA]);
    $a = $stmtA->fetch(PDO::FETCH_ASSOC);

    $stmtB = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
    $stmtB->execute([$racerB]);
    $b = $stmtB->fetch(PDO::FETCH_ASSOC);

    if ($a && $b):

    // --- Head-to-head record ---
    $h2hStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN ra.rank < rb.rank THEN 1 ELSE 0 END) as a_wins,
            SUM(CASE WHEN rb.rank < ra.rank THEN 1 ELSE 0 END) as b_wins,
            SUM(CASE WHEN ra.rank = rb.rank THEN 1 ELSE 0 END) as draws,
            COUNT(*) as meetings,
            AVG(ra.gp_points) as a_avg_when_meeting,
            AVG(rb.gp_points) as b_avg_when_meeting,
            AVG(ra.rank - rb.rank) as avg_rank_gap
        FROM results ra
        JOIN results rb ON ra.gpid = rb.gpid
        WHERE ra.racer_id = ? AND rb.racer_id = ?
    ");
    $h2hStmt->execute([$racerA, $racerB]);
    $h2h = $h2hStmt->fetch(PDO::FETCH_ASSOC);
    $meetings = (int)$h2h['meetings'];

    // --- Career stats for each ---
    $careerQuery = "
        SELECT
            COUNT(*) as total_gps,
            SUM(gp_points) as total_points,
            AVG(gp_points) as avg_score,
            MAX(gp_points) as personal_best,
            SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN gp_points = 60 THEN 1 ELSE 0 END) as perfect_60s
        FROM results WHERE racer_id = ? AND gpid LIKE 's%'
    ";
    $stmtCA = $pdo->prepare($careerQuery);
    $stmtCA->execute([$racerA]);
    $careerA = $stmtCA->fetch(PDO::FETCH_ASSOC);

    $stmtCB = $pdo->prepare($careerQuery);
    $stmtCB->execute([$racerB]);
    $careerB = $stmtCB->fetch(PDO::FETCH_ASSOC);

    // --- Most used characters ---
    $charQuery = "SELECT character_used, COUNT(*) as uses FROM results WHERE racer_id = ? AND gpid LIKE 's%' GROUP BY character_used ORDER BY uses DESC LIMIT 1";
    $charA = $pdo->prepare($charQuery); $charA->execute([$racerA]); $mainCharA = $charA->fetchColumn() ?: 'Mii';
    $charB = $pdo->prepare($charQuery); $charB->execute([$racerB]); $mainCharB = $charB->fetchColumn() ?: 'Mii';

    // --- Current season GPScore ---
    $scoreA = calculateGPScore($pdo, $racerA, $currentSeason);
    $scoreB = calculateGPScore($pdo, $racerB, $currentSeason);

    // --- Cup-by-cup comparison ---
    $allCups = getMK8DCups();
    $cupComparison = [];
    foreach ($allCups as $cupName) {
        $cupQ = $pdo->prepare("SELECT MAX(gp_points) as best FROM results WHERE racer_id = ? AND gpid LIKE 's%' AND cup_name = ?");
        $cupQ->execute([$racerA, $cupName]);
        $bestA = (int)($cupQ->fetch(PDO::FETCH_ASSOC)['best'] ?? 0);

        $cupQ->execute([$racerB, $cupName]);
        $bestB = (int)($cupQ->fetch(PDO::FETCH_ASSOC)['best'] ?? 0);

        if ($bestA > 0 || $bestB > 0) {
            $cupComparison[$cupName] = ['a' => $bestA, 'b' => $bestB];
        }
    }

    // --- Score distribution (buckets of 5) ---
    $distQuery = "SELECT gp_points FROM results WHERE racer_id = ? AND gpid LIKE 's%'";
    $distA = $pdo->prepare($distQuery); $distA->execute([$racerA]); $scoresA = $distA->fetchAll(PDO::FETCH_COLUMN);
    $distB = $pdo->prepare($distQuery); $distB->execute([$racerB]); $scoresB = $distB->fetchAll(PDO::FETCH_COLUMN);

    $buckets = [];
    for ($i = 0; $i <= 60; $i += 5) {
        $label = $i . '-' . min($i + 4, 60);
        $countA = count(array_filter($scoresA, fn($s) => $s >= $i && $s <= min($i + 4, 60)));
        $countB = count(array_filter($scoresB, fn($s) => $s >= $i && $s <= min($i + 4, 60)));
        $buckets[] = ['label' => $label, 'a' => $countA, 'b' => $countB];
    }

    // --- Recent meetings (last 10 shared GPs) ---
    $recentStmt = $pdo->prepare("
        SELECT ra.gpid, ra.gp_points as a_pts, rb.gp_points as b_pts,
               ra.rank as a_rank, rb.rank as b_rank,
               ra.race_date, ra.cup_name
        FROM results ra
        JOIN results rb ON ra.gpid = rb.gpid
        WHERE ra.racer_id = ? AND rb.racer_id = ?
        ORDER BY ra.race_date DESC, ra.gpid DESC
        LIMIT 10
    ");
    $recentStmt->execute([$racerA, $racerB]);
    $recentMeetings = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Win rate
    $aWinPct = $meetings > 0 ? round(($h2h['a_wins'] / $meetings) * 100, 1) : 0;
    $bWinPct = $meetings > 0 ? round(($h2h['b_wins'] / $meetings) * 100, 1) : 0;
?>

    <!-- Showdown Header -->
    <div class="compare-showdown">
        <div class="compare-fighter compare-fighter--left">
            <img src="/assets/img/<?= htmlspecialchars($mainCharA) ?>.png" onerror="this.src='/assets/img/Mii.png'" class="compare-fighter-img">
            <div class="compare-fighter-name"><?= htmlspecialchars($a['name']) ?></div>
            <?php if (!empty($a['nickname'])): ?>
                <div class="compare-fighter-nick">"<?= htmlspecialchars($a['nickname']) ?>"</div>
            <?php endif; ?>
        </div>
        <div class="compare-vs-block">
            <div class="compare-vs-text">VS</div>
            <div class="compare-meetings"><?= $meetings ?> meetings</div>
        </div>
        <div class="compare-fighter compare-fighter--right">
            <img src="/assets/img/<?= htmlspecialchars($mainCharB) ?>.png" onerror="this.src='/assets/img/Mii.png'" class="compare-fighter-img">
            <div class="compare-fighter-name"><?= htmlspecialchars($b['name']) ?></div>
            <?php if (!empty($b['nickname'])): ?>
                <div class="compare-fighter-nick">"<?= htmlspecialchars($b['nickname']) ?>"</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Head-to-Head Record -->
    <?php if ($meetings > 0): ?>
    <div class="card">
        <h2 class="card-header">Head-to-Head Record</h2>
        <div class="compare-record">
            <div class="compare-record-bar">
                <div class="compare-record-bar-a" style="width: <?= $aWinPct ?>%;">
                    <?= (int)$h2h['a_wins'] ?>W
                </div>
                <?php if ((int)$h2h['draws'] > 0): ?>
                <div class="compare-record-bar-draw" style="width: <?= round(((int)$h2h['draws'] / $meetings) * 100, 1) ?>%;">
                    <?= (int)$h2h['draws'] ?>D
                </div>
                <?php endif; ?>
                <div class="compare-record-bar-b" style="width: <?= $bWinPct ?>%;">
                    <?= (int)$h2h['b_wins'] ?>W
                </div>
            </div>
            <div class="compare-record-labels">
                <span><?= htmlspecialchars($a['name']) ?> <?= $aWinPct ?>%</span>
                <span><?= htmlspecialchars($b['name']) ?> <?= $bWinPct ?>%</span>
            </div>
        </div>

        <div class="compare-record-stats">
            <div class="compare-record-stat">
                <div class="compare-record-stat-label">Avg Score When Meeting</div>
                <div class="compare-record-stat-values">
                    <span class="<?= $h2h['a_avg_when_meeting'] >= $h2h['b_avg_when_meeting'] ? 'compare-winner' : '' ?>">
                        <?= number_format($h2h['a_avg_when_meeting'], 1) ?>
                    </span>
                    <span class="compare-record-stat-vs">vs</span>
                    <span class="<?= $h2h['b_avg_when_meeting'] >= $h2h['a_avg_when_meeting'] ? 'compare-winner' : '' ?>">
                        <?= number_format($h2h['b_avg_when_meeting'], 1) ?>
                    </span>
                </div>
            </div>
            <div class="compare-record-stat">
                <div class="compare-record-stat-label">Avg Rank Gap</div>
                <div class="compare-record-stat-values">
                    <span><?= number_format(abs($h2h['avg_rank_gap']), 1) ?> positions</span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Career Comparison -->
    <div class="card">
        <h2 class="card-header">Career Comparison</h2>
        <table class="clean-table compare-table">
            <thead>
                <tr>
                    <th>Stat</th>
                    <th class="compare-col-a"><?= htmlspecialchars($a['name']) ?></th>
                    <th class="compare-col-b"><?= htmlspecialchars($b['name']) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stats = [
                    ['GPScore (' . strtoupper($currentSeason) . ')', $scoreA, $scoreB, 'high'],
                    ['Total GPs', $careerA['total_gps'], $careerB['total_gps'], 'high'],
                    ['Career Avg', number_format($careerA['avg_score'], 1), number_format($careerB['avg_score'], 1), 'high'],
                    ['Personal Best', $careerA['personal_best'], $careerB['personal_best'], 'high'],
                    ['Total Wins', $careerA['wins'], $careerB['wins'], 'high'],
                    ['Perfect 60s', $careerA['perfect_60s'], $careerB['perfect_60s'], 'high'],
                    ['Total Points', $careerA['total_points'], $careerB['total_points'], 'high'],
                ];
                foreach ($stats as $stat):
                    $aVal = is_numeric($stat[1]) ? (float)$stat[1] : 0;
                    $bVal = is_numeric($stat[2]) ? (float)$stat[2] : 0;
                    $aWins = ($stat[3] === 'high') ? $aVal > $bVal : $aVal < $bVal;
                    $bWins = ($stat[3] === 'high') ? $bVal > $aVal : $aVal > $bVal;
                ?>
                <tr>
                    <td class="compare-stat-name"><?= $stat[0] ?></td>
                    <td class="compare-col-a <?= $aWins ? 'compare-winner' : '' ?>"><?= $stat[1] ?></td>
                    <td class="compare-col-b <?= $bWins ? 'compare-winner' : '' ?>"><?= $stat[2] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Cup-by-Cup Best Scores -->
    <?php if (!empty($cupComparison)): ?>
    <div class="card">
        <h2 class="card-header">Cup Best Scores</h2>
        <div class="compare-cups-grid">
            <?php foreach ($cupComparison as $cupName => $scores):
                $aLeads = $scores['a'] > $scores['b'];
                $bLeads = $scores['b'] > $scores['a'];
                $tied = $scores['a'] === $scores['b'] && $scores['a'] > 0;
            ?>
            <div class="compare-cup-row">
                <span class="compare-cup-score <?= $aLeads ? 'compare-winner' : '' ?><?= $scores['a'] == 0 ? ' compare-empty' : '' ?>">
                    <?= $scores['a'] ?: '—' ?>
                </span>
                <span class="compare-cup-name <?= $tied ? 'compare-tied' : '' ?>">
                    <?= htmlspecialchars($cupName) ?>
                </span>
                <span class="compare-cup-score <?= $bLeads ? 'compare-winner' : '' ?><?= $scores['b'] == 0 ? ' compare-empty' : '' ?>">
                    <?= $scores['b'] ?: '—' ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
            $aCupWins = count(array_filter($cupComparison, fn($s) => $s['a'] > $s['b']));
            $bCupWins = count(array_filter($cupComparison, fn($s) => $s['b'] > $s['a']));
            $cupTies = count(array_filter($cupComparison, fn($s) => $s['a'] === $s['b'] && $s['a'] > 0));
        ?>
        <div class="compare-cups-summary">
            <span class="<?= $aCupWins > $bCupWins ? 'compare-winner' : '' ?>"><?= htmlspecialchars($a['name']) ?>: <?= $aCupWins ?> cups</span>
            <span>Tied: <?= $cupTies ?></span>
            <span class="<?= $bCupWins > $aCupWins ? 'compare-winner' : '' ?>"><?= htmlspecialchars($b['name']) ?>: <?= $bCupWins ?> cups</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Meetings -->
    <?php if (!empty($recentMeetings)): ?>
    <div class="card">
        <h2 class="card-header">Recent Meetings</h2>
        <table class="clean-table compare-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cup</th>
                    <th class="compare-col-a"><?= htmlspecialchars($a['name']) ?></th>
                    <th class="compare-col-b"><?= htmlspecialchars($b['name']) ?></th>
                    <th>Winner</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentMeetings as $m):
                    $aWon = $m['a_rank'] < $m['b_rank'];
                    $bWon = $m['b_rank'] < $m['a_rank'];
                ?>
                <tr>
                    <td><?= date('M j', strtotime($m['race_date'])) ?></td>
                    <td><?= htmlspecialchars($m['cup_name']) ?></td>
                    <td class="compare-col-a <?= $aWon ? 'compare-winner' : '' ?>"><?= $m['a_pts'] ?> pts (#<?= $m['a_rank'] ?>)</td>
                    <td class="compare-col-b <?= $bWon ? 'compare-winner' : '' ?>"><?= $m['b_pts'] ?> pts (#<?= $m['b_rank'] ?>)</td>
                    <td>
                        <?php if ($aWon): ?>
                            <?= htmlspecialchars($a['name']) ?>
                        <?php elseif ($bWon): ?>
                            <?= htmlspecialchars($b['name']) ?>
                        <?php else: ?>
                            Draw
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Score Distribution -->
    <div class="card">
        <h2 class="card-header">Score Distribution</h2>
        <div class="compare-dist">
            <?php
            $maxCount = max(1, max(array_column($buckets, 'a')), max(array_column($buckets, 'b')));
            foreach ($buckets as $bucket):
                if ($bucket['a'] == 0 && $bucket['b'] == 0) continue;
                $aPct = ($bucket['a'] / $maxCount) * 100;
                $bPct = ($bucket['b'] / $maxCount) * 100;
            ?>
            <div class="compare-dist-row">
                <div class="compare-dist-bar-left">
                    <div class="compare-dist-fill compare-dist-fill--a" style="width: <?= $aPct ?>%;">
                        <?php if ($bucket['a'] > 0): ?><span><?= $bucket['a'] ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="compare-dist-label"><?= $bucket['label'] ?></div>
                <div class="compare-dist-bar-right">
                    <div class="compare-dist-fill compare-dist-fill--b" style="width: <?= $bPct ?>%;">
                        <?php if ($bucket['b'] > 0): ?><span><?= $bucket['b'] ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="compare-dist-legend">
                <span class="compare-dist-legend-a"><?= htmlspecialchars($a['name']) ?></span>
                <span class="compare-dist-legend-b"><?= htmlspecialchars($b['name']) ?></span>
            </div>
        </div>
    </div>

<?php
    endif; // $a && $b
endif; // racerA && racerB
?>

</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
