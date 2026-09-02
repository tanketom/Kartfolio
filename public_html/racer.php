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
require_once __DIR__ . '/../private/includes/elo_engine.php';
// csrf.php starts the session so $_SESSION['is_admin'] is available for any
// admin-gated UI on this page.
require_once __DIR__ . '/../private/includes/csrf.php';

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

// Consecutive seasons participated
$seasonNums = [];
foreach ($seasons as $s) {
    if (preg_match('/\d+/', $s, $m)) $seasonNums[] = (int)$m[0];
}
sort($seasonNums);
$maxConsecSeasons = 0;
$currentConsecSeasons = 0;
if (!empty($seasonNums)) {
    $streak = 1; $maxStreak = 1;
    for ($i = 1; $i < count($seasonNums); $i++) {
        if ($seasonNums[$i] === $seasonNums[$i - 1] + 1) { $streak++; $maxStreak = max($maxStreak, $streak); }
        else $streak = 1;
    }
    $maxConsecSeasons = $maxStreak;
    $currentConsecSeasons = 1;
    for ($i = count($seasonNums) - 1; $i > 0; $i--) {
        if ($seasonNums[$i] === $seasonNums[$i - 1] + 1) $currentConsecSeasons++;
        else break;
    }
}

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

// Longest completed win drought (gap between wins, ended by a subsequent win)
$maxWinDrought = 0;
$winDroughtCurrent = 0;
foreach ($allResults as $r) {
    if ($r['rank'] == 1) {
        $maxWinDrought = max($maxWinDrought, $winDroughtCurrent);
        $winDroughtCurrent = 0;
    } else {
        $winDroughtCurrent++;
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
    $seasonRules = getSeasonRules($pdo, $season);

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

        if (racerQualifies($rCount, $seasonRules)) {
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

// Season-derived personal bests
$bestSeasonPlacement = PHP_INT_MAX;
$bestSeasonWins      = 0;
$bestPastSeasonWins  = 0;
$bestSeasonAvg       = 0.0;
$bestPastSeasonAvg   = 0.0;
foreach ($seasonBreakdown as $sb) {
    if ($sb['placement'] > 0) $bestSeasonPlacement = min($bestSeasonPlacement, $sb['placement']);
    $wins = (int)$sb['stats']['wins'];
    $gps  = (int)$sb['stats']['gps'];
    $avg  = $gps > 0 ? (float)$sb['stats']['points'] / $gps : 0.0;
    $bestSeasonWins = max($bestSeasonWins, $wins);
    $bestSeasonAvg  = max($bestSeasonAvg, $avg);
    if ($sb['season'] !== $currentSeason) {
        $bestPastSeasonWins = max($bestPastSeasonWins, $wins);
        $bestPastSeasonAvg  = max($bestPastSeasonAvg, $avg);
    }
}
if ($bestSeasonPlacement === PHP_INT_MAX) $bestSeasonPlacement = 0;

$isAdminViewer        = !empty($_SESSION['is_admin']);

// Mikkoliiga: compute current-season score and rank if this racer is a member.
$mikkoliigaInfo = null;
if (!empty($racer['in_mikkoliiga'])) {
    $mikkoStandings = getMikkoliigaStandings($pdo, $currentSeason);
    foreach ($mikkoStandings as $idx => $row) {
        // $racerId comes from $_GET (string); row id is cast to int — compare as ints.
        if ((int)$row['id'] === (int)$racerId) {
            $mikkoliigaInfo = [
                'rank'        => $idx + 1,
                'total'       => count($mikkoStandings),
                'score'       => $row['score'],
                'gps_counted' => $row['gps_counted'],
                'total_gps'   => $row['total_gps'],
            ];
            break;
        }
    }
}

// Consistency vs Ceiling (this season) + field medians for the archetype.
require_once __DIR__ . '/../private/includes/quests.php';
$ccStats = racerSeasonStats($pdo, $racerId, $currentSeason);
$ccArchetype = null; $ccMedianCeiling = 0; $ccMedianStddev = 0;
if ($ccStats['gps'] >= 3) {
    $ceilings = []; $stddevs = [];
    foreach (getActiveRacers($pdo, $currentSeason) as $ar) {
        $as = racerSeasonStats($pdo, (int)$ar['id'], $currentSeason);
        if ($as['gps'] >= 3) { $ceilings[] = $as['best']; $stddevs[] = $as['stddev']; }
    }
    sort($ceilings); sort($stddevs);
    $median = fn($a) => empty($a) ? 0 : (count($a) % 2 ? $a[intdiv(count($a), 2)] : ($a[count($a)/2 - 1] + $a[count($a)/2]) / 2);
    $ccMedianCeiling = $median($ceilings);
    $ccMedianStddev  = $median($stddevs);
    $ccArchetype = consistencyCeilingArchetype($ccStats['best'], $ccStats['stddev'], $ccMedianCeiling, $ccMedianStddev);
}

// Side quests (this season) — two per racer, assigned + frozen on first view.
$racerQuests = ($ccStats['gps'] >= 1) ? getRacerQuests($pdo, $racerId, $currentSeason) : [];

// Current season quick-stats (for self-record chases)
$currentSeasonEntry = null;
foreach ($seasonBreakdown as $sb) {
    if ($sb['season'] === $currentSeason) { $currentSeasonEntry = $sb; break; }
}
$currentSeasonWins = (int)($currentSeasonEntry['stats']['wins'] ?? 0);
$currentSeasonGPs  = (int)($currentSeasonEntry['stats']['gps']  ?? 0);
$currentSeasonAvg  = $currentSeasonGPs > 0
    ? (float)($currentSeasonEntry['stats']['points'] ?? 0) / $currentSeasonGPs
    : 0.0;

// Peak ELO
$eloData         = calculateAllELORatings($pdo);
$racerEloHistory = $eloData['history'][$racer['name']] ?? [];
$currentElo      = (int)round($eloData['ratings'][$racer['name']] ?? ELO_INITIAL_RATING);
$peakElo         = $currentElo;
foreach ($racerEloHistory as $entry) {
    $peakElo = max($peakElo, (int)round($entry['rating']));
}

// Score distribution — career and current season
$distRawStmt = $pdo->prepare("SELECT gp_points, COUNT(*) as cnt FROM results WHERE racer_id = ? GROUP BY gp_points");
$distRawStmt->execute([$racerId]);
$distRawCareer = $distRawStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$distRawSeasonStmt = $pdo->prepare("SELECT gp_points, COUNT(*) as cnt FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY gp_points");
$distRawSeasonStmt->execute([$racerId, $currentSeason . '%']);
$distRawSeason = $distRawSeasonStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$distBins = [
    '0–9'   => [0,  9,  '#5c1010', '#ff9999'],
    '10–19' => [10, 19, '#5c1010', '#ff9999'],
    '20–29' => [20, 29, '#5c1010', '#ff9999'],
    '30–34' => [30, 34, '#5c3b00', '#ffbb66'],
    '35–39' => [35, 39, '#5c3b00', '#ffbb66'],
    '40–44' => [40, 44, '#5c3b00', '#ffbb66'],
    '45–49' => [45, 49, '#1a4a1a', '#88ee88'],
    '50–54' => [50, 54, '#1a4a1a', '#88ee88'],
    '55–59' => [55, 59, '#0d3a5c', '#88ccff'],
    '60'    => [60, 60, '#4a3800', '#ffd700'],
];

$distCareer = [];
$distSeason = [];
foreach ($distBins as $label => [$lo, $hi, $bg, $fg]) {
    $c = 0; $s = 0;
    for ($v = $lo; $v <= $hi; $v++) { $c += $distRawCareer[$v] ?? 0; $s += $distRawSeason[$v] ?? 0; }
    $distCareer[$label] = $c;
    $distSeason[$label] = $s;
}
$distTotalCareer = max(1, array_sum($distCareer));
$distTotalSeason = max(1, array_sum($distSeason));
$distMaxCareer   = max(1, max($distCareer));
$distMaxSeason   = max(1, max($distSeason));

// ── Record Chaser ──────────────────────────────────────────────────────────
// Build a list of upcoming milestones and records this racer is close to.
$chaseMilestones = [];

$rcGPs      = (int)$careerStats['total_gps'];
$rcWins     = (int)$careerStats['wins'];
$rcPodiums  = (int)$careerStats['podiums'];
$rcPoints   = (int)$careerStats['total_points'];
$rcPerfects = $distCareer['60'] ?? 0; // reuse already-computed distribution

// Helper: return next threshold >= 85% of the way toward, or null
$nextMs = function(int $current, array $thresholds): ?int {
    foreach ($thresholds as $t) {
        if ($current < $t && ($current / $t) >= 0.82) return $t;
    }
    // Also catch: just below the first threshold
    foreach ($thresholds as $t) {
        if ($current < $t) return $t;
    }
    return null;
};

// Career GP count
if (($n = $nextMs($rcGPs, [25, 50, 75, 100, 150, 200, 250, 300])) !== null) {
    $gap = $n - $rcGPs;
    if ($gap <= 15) $chaseMilestones[] = [
        'icon' => '🎮', 'label' => number_format($n) . ' Career GPs',
        'gap' => $gap, 'unit' => 'GP', 'pct' => round($rcGPs / $n * 100), 'record' => false,
    ];
}

// Career wins
if ($rcWins > 0 && ($n = $nextMs($rcWins, [5, 10, 15, 20, 25, 50])) !== null) {
    $gap = $n - $rcWins;
    if ($gap <= 5) $chaseMilestones[] = [
        'icon' => '🏆', 'label' => $n . ' Career Wins',
        'gap' => $gap, 'unit' => 'win', 'pct' => round($rcWins / $n * 100), 'record' => false,
    ];
}

// Career podiums
if ($rcPodiums > 0 && ($n = $nextMs($rcPodiums, [10, 25, 50, 75, 100, 150])) !== null) {
    $gap = $n - $rcPodiums;
    if ($gap <= 10) $chaseMilestones[] = [
        'icon' => '🥇', 'label' => $n . ' Career Podiums',
        'gap' => $gap, 'unit' => 'podium', 'pct' => round($rcPodiums / $n * 100), 'record' => false,
    ];
}

// Career perfect 60s
if ($rcPerfects > 0 && ($n = $nextMs($rcPerfects, [3, 5, 10, 20])) !== null) {
    $gap = $n - $rcPerfects;
    if ($gap <= 3) $chaseMilestones[] = [
        'icon' => '💯', 'label' => $n . ' Perfect 60s',
        'gap' => $gap, 'unit' => 'perfect', 'pct' => round($rcPerfects / $n * 100), 'record' => false,
    ];
}

// Career points
if (($n = $nextMs($rcPoints, [1000, 2000, 3000, 5000, 7500, 10000])) !== null) {
    $gap = $n - $rcPoints;
    if ($gap <= 400) $chaseMilestones[] = [
        'icon' => '✨', 'label' => number_format($n) . ' Career Points',
        'gap' => $gap, 'unit' => 'pt', 'pct' => round($rcPoints / $n * 100), 'record' => false,
    ];
}

// All-time wins record chase
$rcWinsLeaderStmt = $pdo->query("
    SELECT r.name, COUNT(*) as cnt
    FROM results res JOIN racers r ON res.racer_id = r.id
    WHERE res.rank = 1 AND res.gpid LIKE 's%'
    GROUP BY res.racer_id ORDER BY cnt DESC LIMIT 1
");
$rcWinsLeader = $rcWinsLeaderStmt->fetch(PDO::FETCH_ASSOC);
if ($rcWinsLeader && $rcWinsLeader['name'] !== $racer['name'] && $rcWins > 0) {
    $gap = (int)$rcWinsLeader['cnt'] - $rcWins;
    if ($gap > 0 && $gap <= 5) $chaseMilestones[] = [
        'icon' => '👑', 'label' => 'Break ' . $rcWinsLeader['name'] . "'s wins record",
        'gap' => $gap, 'unit' => 'win', 'pct' => round($rcWins / $rcWinsLeader['cnt'] * 100), 'record' => true,
    ];
}

// All-time GPs played record chase
$rcGPsLeaderStmt = $pdo->query("
    SELECT r.name, COUNT(*) as cnt
    FROM results res JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.racer_id ORDER BY cnt DESC LIMIT 1
");
$rcGPsLeader = $rcGPsLeaderStmt->fetch(PDO::FETCH_ASSOC);
if ($rcGPsLeader && $rcGPsLeader['name'] !== $racer['name']) {
    $gap = (int)$rcGPsLeader['cnt'] - $rcGPs;
    if ($gap > 0 && $gap <= 10) $chaseMilestones[] = [
        'icon' => '📅', 'label' => 'Most GPs record (' . $rcGPsLeader['name'] . ': ' . $rcGPsLeader['cnt'] . ')',
        'gap' => $gap, 'unit' => 'GP', 'pct' => round($rcGPs / $rcGPsLeader['cnt'] * 100), 'record' => true,
    ];
}

// All-time perfect 60s record chase
if ($rcPerfects > 0) {
    $rcPerfLeaderStmt = $pdo->query("
        SELECT r.name, COUNT(*) as cnt
        FROM results res JOIN racers r ON res.racer_id = r.id
        WHERE res.gp_points = 60 AND res.gpid LIKE 's%'
        GROUP BY res.racer_id ORDER BY cnt DESC LIMIT 1
    ");
    $rcPerfLeader = $rcPerfLeaderStmt->fetch(PDO::FETCH_ASSOC);
    if ($rcPerfLeader && $rcPerfLeader['name'] !== $racer['name']) {
        $gap = (int)$rcPerfLeader['cnt'] - $rcPerfects;
        if ($gap > 0 && $gap <= 3) $chaseMilestones[] = [
            'icon' => '💎', 'label' => 'Most perfects record (' . $rcPerfLeader['name'] . ': ' . $rcPerfLeader['cnt'] . ')',
            'gap' => $gap, 'unit' => 'perfect', 'pct' => round($rcPerfects / $rcPerfLeader['cnt'] * 100), 'record' => true,
        ];
    }
}
// All-time podiums record chase
$rcPodiumsLeaderStmt = $pdo->query("
    SELECT r.name, COUNT(*) as cnt
    FROM results res JOIN racers r ON res.racer_id = r.id
    WHERE res.rank <= 3 AND res.gpid LIKE 's%'
    GROUP BY res.racer_id ORDER BY cnt DESC LIMIT 1
");
$rcPodiumsLeader = $rcPodiumsLeaderStmt->fetch(PDO::FETCH_ASSOC);
if ($rcPodiumsLeader && $rcPodiumsLeader['name'] !== $racer['name'] && $rcPodiums > 0) {
    $gap = (int)$rcPodiumsLeader['cnt'] - $rcPodiums;
    if ($gap > 0 && $gap <= 10) $chaseMilestones[] = [
        'icon' => '🥇', 'label' => 'Most podiums record (' . $rcPodiumsLeader['name'] . ': ' . $rcPodiumsLeader['cnt'] . ')',
        'gap' => $gap, 'unit' => 'podium', 'pct' => round($rcPodiums / $rcPodiumsLeader['cnt'] * 100), 'record' => true,
    ];
}

// All-time career points record chase
$rcPointsLeaderStmt = $pdo->query("
    SELECT r.name, SUM(res.gp_points) as total
    FROM results res JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.racer_id ORDER BY total DESC LIMIT 1
");
$rcPointsLeader = $rcPointsLeaderStmt->fetch(PDO::FETCH_ASSOC);
if ($rcPointsLeader && $rcPointsLeader['name'] !== $racer['name'] && $rcPoints > 0) {
    $gap = (int)$rcPointsLeader['total'] - $rcPoints;
    if ($gap > 0 && $gap <= 400) $chaseMilestones[] = [
        'icon' => '✨', 'label' => 'Most career points (' . $rcPointsLeader['name'] . ': ' . number_format($rcPointsLeader['total']) . ')',
        'gap' => $gap, 'unit' => 'pt', 'pct' => round($rcPoints / $rcPointsLeader['total'] * 100), 'record' => true,
    ];
}

// All-time win rate record (min 20 GPs qualifier)
if ($rcGPs >= 20 && $rcWins > 0) {
    $rcWinRateLeaderStmt = $pdo->query("
        SELECT r.name,
               COUNT(*) as total_gps,
               CAST(SUM(CASE WHEN res.rank = 1 THEN 1 ELSE 0 END) AS FLOAT) / COUNT(*) as win_rate
        FROM results res JOIN racers r ON res.racer_id = r.id
        WHERE res.gpid LIKE 's%'
        GROUP BY res.racer_id
        HAVING total_gps >= 20
        ORDER BY win_rate DESC LIMIT 1
    ");
    $rcWinRateLeader = $rcWinRateLeaderStmt->fetch(PDO::FETCH_ASSOC);
    $myWinRate = $rcWins / $rcGPs;
    if ($rcWinRateLeader && $rcWinRateLeader['name'] !== $racer['name']) {
        $leaderRate = (float)$rcWinRateLeader['win_rate'];
        $gapPct = ($leaderRate - $myWinRate) * 100;
        if ($gapPct > 0 && ($myWinRate / $leaderRate) >= 0.82) $chaseMilestones[] = [
            'icon' => '📊',
            'label' => 'Highest win rate (' . $rcWinRateLeader['name'] . ': ' . number_format($leaderRate * 100, 1) . '%)',
            'gap' => $gapPct, 'unit' => '% pt',
            'gap_label' => number_format($gapPct, 1) . '% pts away',
            'pct' => round(($myWinRate / $leaderRate) * 100), 'record' => true,
        ];
    }
}

// All-time best single-season average (min 5 GPs in the season)
$rcSeasonAvgLeaderStmt = $pdo->query("
    SELECT r.name, SUBSTR(res.gpid, 1, 3) as sid,
           AVG(res.gp_points) as avg_pts,
           COUNT(*) as gps_count
    FROM results res JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.racer_id, sid
    HAVING gps_count >= 5
    ORDER BY avg_pts DESC LIMIT 1
");
$rcSeasonAvgLeader = $rcSeasonAvgLeaderStmt->fetch(PDO::FETCH_ASSOC);
if ($rcSeasonAvgLeader && $rcSeasonAvgLeader['name'] !== $racer['name'] && $bestSeasonAvg > 0) {
    $leaderAvg = (float)$rcSeasonAvgLeader['avg_pts'];
    $gapAvg = $leaderAvg - $bestSeasonAvg;
    if ($gapAvg > 0 && ($bestSeasonAvg / $leaderAvg) >= 0.82) $chaseMilestones[] = [
        'icon' => '📈',
        'label' => 'Best season average (' . $rcSeasonAvgLeader['name'] . ': ' . number_format($leaderAvg, 1) . ')',
        'gap' => $gapAvg, 'unit' => 'pt avg',
        'gap_label' => number_format($gapAvg, 1) . ' pts avg away',
        'pct' => round(($bestSeasonAvg / $leaderAvg) * 100), 'record' => true,
    ];
}

// Self: approaching own season wins record in the current season
if ($currentSeasonWins > 0 && $bestPastSeasonWins > 0) {
    $target = $bestPastSeasonWins + 1;
    $gap    = $target - $currentSeasonWins;
    $pct    = round(($currentSeasonWins / $target) * 100);
    if ($gap > 0 && $gap <= 3 && $pct >= 82) $chaseMilestones[] = [
        'icon' => '🎯', 'label' => 'Break your season wins record (' . $bestPastSeasonWins . ')',
        'gap' => $gap, 'unit' => 'win', 'pct' => $pct, 'record' => false,
    ];
}

// Self: approaching own season average record in the current season
if ($currentSeasonGPs >= 3 && $bestPastSeasonAvg > 0 && $currentSeasonAvg < $bestPastSeasonAvg) {
    $gapAvg = $bestPastSeasonAvg - $currentSeasonAvg;
    $pct    = round(($currentSeasonAvg / $bestPastSeasonAvg) * 100);
    if ($pct >= 82) $chaseMilestones[] = [
        'icon' => '📈', 'label' => 'Beat your best season average (' . number_format($bestPastSeasonAvg, 1) . ')',
        'gap' => $gapAvg, 'unit' => 'pt avg',
        'gap_label' => number_format($gapAvg, 1) . ' pts avg away',
        'pct' => $pct, 'record' => false,
    ];
}

// Self: approaching next round-100 ELO milestone
foreach ([1600, 1700, 1800, 1900, 2000] as $eloTarget) {
    if ($currentElo < $eloTarget) {
        $eloGap = $eloTarget - $currentElo;
        $eloPct = round(($currentElo / $eloTarget) * 100);
        if ($eloPct >= 82) $chaseMilestones[] = [
            'icon' => '⚡', 'label' => number_format($eloTarget) . ' ELO Rating',
            'gap' => $eloGap, 'unit' => 'ELO', 'pct' => $eloPct, 'record' => false,
        ];
        break;
    }
}

// ── /Record Chaser ─────────────────────────────────────────────────────────

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
    <?php
    // Wrapped entry point — public in December, admins can preview any time.
    $wrappedShow = ((int)date('n') === 12) || $isAdminViewer;
    if ($wrappedShow):
        $wrappedYear = date('Y');
    ?>
        <a href="/wrapped/<?= (int)$racerId ?>?year=<?= htmlspecialchars($wrappedYear) ?>" class="racer-wrapped-cta">
            🎁 See <?= htmlspecialchars($racer['name']) ?>'s <?= htmlspecialchars($wrappedYear) ?> Wrapped
            <?php if ((int)date('n') !== 12): ?><span class="racer-wrapped-preview">admin preview</span><?php endif; ?>
            <span class="racer-wrapped-arrow">→</span>
        </a>
    <?php endif; ?>

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
            <?php
            // Sticker album chip — public from the stickers epoch; admins see
            // it early as an art-preview link.
            $stkEpoch = getSetting($pdo, 'stickers_epoch', '2026-06-21') ?: '2026-06-21';
            $stkLive  = date('Y-m-d') >= $stkEpoch;
            if ($stkLive || $isAdminViewer): ?>
                <a href="/stickers/<?= (int)$racerId ?>" class="stk-profile-chip">
                    🩹 Sticker album →
                    <?php if (!$stkLive): ?><span class="racer-wrapped-preview">admin preview</span><?php endif; ?>
                </a>
            <?php endif; ?>
            <?php if (!empty($racer['in_mikkoliiga'])): ?>
                <a href="/mikkoliiga" class="mikko-member-badge">
                    🌟 MIKKOLIIGA MEMBER
                    <?php if ($mikkoliigaInfo): ?>
                        <span class="mikko-member-rank">#<?= $mikkoliigaInfo['rank'] ?> of <?= $mikkoliigaInfo['total'] ?> · <?= $mikkoliigaInfo['score'] ?> pts</span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </div>
        <a href="/" class="btn btn-secondary">← Back to Leaderboard</a>
    </header>

    <?php if (!empty($racer['in_mikkoliiga']) && $mikkoliigaInfo): ?>
    <div class="mikko-profile-banner">
        <div class="mikko-profile-banner-icon">🌟</div>
        <div class="mikko-profile-banner-content">
            <div class="mikko-profile-banner-title">Mikkoliiga · Season <?= strtoupper($currentSeason) ?></div>
            <div class="mikko-profile-banner-stats">
                <span><strong>#<?= $mikkoliigaInfo['rank'] ?></strong> of <?= $mikkoliigaInfo['total'] ?> members</span>
                <span>·</span>
                <span><strong><?= $mikkoliigaInfo['score'] ?></strong> internal pts</span>
                <span>·</span>
                <span><?= $mikkoliigaInfo['gps_counted'] ?> of best <?= MIKKOLIIGA_BEST_X ?> GPs counted</span>
                <?php if ($mikkoliigaInfo['total_gps'] > $mikkoliigaInfo['gps_counted']): ?>
                <span>·</span>
                <span><?= $mikkoliigaInfo['total_gps'] ?> total GPs this season</span>
                <?php endif; ?>
            </div>
        </div>
        <a href="/mikkoliiga" class="mikko-profile-banner-cta">Full standings →</a>
    </div>
    <?php endif; ?>

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

    <!-- Season Form: Consistency vs Ceiling + Side Quests -->
    <?php if ($ccArchetype || !empty($racerQuests)): ?>
    <div class="card cc-quest-card">
        <?php if ($ccArchetype): ?>
        <div class="cc-block">
            <h2 class="card-header">📐 Consistency vs Ceiling <span class="cc-season">S<?= strtoupper(htmlspecialchars(substr($currentSeason, 1))) ?></span></h2>
            <div class="cc-archetype" style="--cc-accent: <?= $ccStats['best'] >= $ccMedianCeiling ? '#e60012' : '#0066cc' ?>;">
                <div class="cc-archetype-label"><?= htmlspecialchars($ccArchetype['label']) ?></div>
                <div class="cc-archetype-blurb"><?= htmlspecialchars($ccArchetype['blurb']) ?></div>
            </div>
            <div class="cc-metrics">
                <div class="cc-metric">
                    <div class="cc-metric-val"><?= (int)$ccStats['best'] ?></div>
                    <div class="cc-metric-lbl">Ceiling (best GP)</div>
                    <div class="cc-metric-ctx">field median <?= (int)round($ccMedianCeiling) ?></div>
                </div>
                <div class="cc-metric">
                    <div class="cc-metric-val">±<?= number_format($ccStats['stddev'], 1) ?></div>
                    <div class="cc-metric-lbl">Spread (std dev)</div>
                    <div class="cc-metric-ctx">field median ±<?= number_format($ccMedianStddev, 1) ?> · lower = steadier</div>
                </div>
                <div class="cc-metric">
                    <div class="cc-metric-val"><?= number_format($ccStats['avg'], 1) ?></div>
                    <div class="cc-metric-lbl">Season average</div>
                    <div class="cc-metric-ctx"><?= (int)$ccStats['gps'] ?> GPs raced</div>
                </div>
            </div>
            <p class="cc-foot">See the whole field plotted on <a href="/stats?season=<?= htmlspecialchars($currentSeason) ?>">Trends →</a></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($racerQuests)): ?>
        <div class="cc-block">
            <h2 class="card-header">🎯 Side Quests</h2>
            <p class="cc-quest-sub">Two season-long objectives, drawn for <?= htmlspecialchars($racer['name']) ?> this season.</p>
            <div class="quest-list">
                <?php foreach ($racerQuests as $q): ?>
                    <div class="quest <?= $q['completed'] ? 'quest--done' : '' ?>">
                        <span class="quest-icon"><?= $q['icon'] ?></span>
                        <div class="quest-body">
                            <div class="quest-title"><?= htmlspecialchars($q['title']) ?><?= $q['completed'] ? ' <span class="quest-check">✓ complete</span>' : '' ?></div>
                            <div class="quest-desc"><?= htmlspecialchars($q['desc']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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

            <!-- Best Season Finish -->
            <?php if ($bestSeasonPlacement > 0 && count($seasons) > 1): ?>
            <?php $placIcon = match($bestSeasonPlacement) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => '🏁' }; ?>
            <div class="milestone-card milestone-card--placement">
                <div class="milestone-icon"><?= $placIcon ?></div>
                <div class="milestone-label">Best Season Finish</div>
                <div class="milestone-value--lg">#<?= $bestSeasonPlacement ?></div>
            </div>
            <?php endif; ?>

            <!-- Best Single-Season Wins -->
            <?php if ($bestSeasonWins > 0 && count($seasons) > 1): ?>
            <div class="milestone-card milestone-card--season-wins">
                <div class="milestone-icon">🏅</div>
                <div class="milestone-label">Best Season Wins</div>
                <div class="milestone-value--lg"><?= $bestSeasonWins ?></div>
                <div class="milestone-sub">in a single season</div>
            </div>
            <?php endif; ?>

            <!-- Best Season Average -->
            <?php if ($bestSeasonAvg > 0 && count($seasons) > 1): ?>
            <div class="milestone-card milestone-card--season-avg">
                <div class="milestone-icon">📊</div>
                <div class="milestone-label">Best Season Avg</div>
                <div class="milestone-value--lg"><?= number_format($bestSeasonAvg, 1) ?></div>
                <div class="milestone-sub">pts per GP</div>
            </div>
            <?php endif; ?>

            <!-- Peak ELO -->
            <?php if ($peakElo > ELO_INITIAL_RATING && $rcGPs >= 5): ?>
            <div class="milestone-card milestone-card--elo">
                <div class="milestone-icon">⚡</div>
                <div class="milestone-label">Peak ELO</div>
                <div class="milestone-value--lg"><?= $peakElo ?></div>
                <div class="milestone-sub">Current: <?= $currentElo ?></div>
            </div>
            <?php endif; ?>

            <!-- Consecutive Seasons -->
            <?php if ($maxConsecSeasons >= 2): ?>
            <div class="milestone-card milestone-card--seasons">
                <div class="milestone-icon">📅</div>
                <div class="milestone-label">Consecutive Seasons</div>
                <div class="milestone-value--lg"><?= $maxConsecSeasons ?></div>
                <?php if ($currentConsecSeasons < $maxConsecSeasons): ?>
                    <div class="milestone-sub">current: <?= $currentConsecSeasons ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Longest Win Drought -->
            <?php if ($maxWinDrought > 0 && $rcWins > 0): ?>
            <div class="milestone-card milestone-card--drought">
                <div class="milestone-icon">🏜️</div>
                <div class="milestone-label">Longest Win Drought</div>
                <div class="milestone-value--lg"><?= $maxWinDrought ?> GP<?= $maxWinDrought > 1 ? 's' : '' ?></div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Record Chaser -->
    <?php if (!empty($chaseMilestones)): ?>
    <div class="card">
        <h2 class="card-header">🎯 Record Chaser</h2>
        <div class="chase-grid">
            <?php foreach ($chaseMilestones as $m): ?>
            <div class="chase-item <?= $m['record'] ? 'chase-item--record' : '' ?>">
                <div class="chase-icon"><?= $m['icon'] ?></div>
                <div class="chase-body">
                    <div class="chase-label"><?= htmlspecialchars($m['label']) ?></div>
                    <div class="chase-progress-wrap">
                        <div class="chase-bar" style="width:<?= min(100, $m['pct']) ?>%"></div>
                    </div>
                    <div class="chase-gap">
                        <?php if (!empty($m['gap_label'])): ?>
                            <strong><?= htmlspecialchars($m['gap_label']) ?></strong>
                        <?php else: ?>
                            <strong><?= number_format($m['gap']) ?></strong>
                            <?= htmlspecialchars($m['unit']) ?><?= $m['gap'] !== 1 ? 's' : '' ?> away
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

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

    if (count($formData) >= 2):
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

    <!-- Score Distribution -->
    <?php if ($distTotalCareer > 1): ?>
    <div class="card">
        <div class="dist-header">
            <h2 class="card-header" style="margin:0;">📊 Score Distribution</h2>
            <div class="dist-toggle">
                <button class="dist-toggle-btn active" onclick="setDistView('career', this)">Career</button>
                <button class="dist-toggle-btn" onclick="setDistView('season', this)"><?= strtoupper($currentSeason) ?></button>
            </div>
        </div>

        <div class="dist-chart">
            <?php foreach ($distBins as $label => [$lo, $hi, $bg, $fg]):
                $cCount  = $distCareer[$label];
                $sCount  = $distSeason[$label];
                $cPct    = round(($cCount / $distTotalCareer) * 100, 1);
                $sPct    = round(($sCount / $distTotalSeason) * 100, 1);
                $cHeight = round(($cCount / $distMaxCareer) * 100);
                $sHeight = round(($sCount / $distMaxSeason) * 100);
            ?>
            <div class="dist-col"
                 data-career-h="<?= $cHeight ?>"
                 data-season-h="<?= $sHeight ?>"
                 data-career-count="<?= $cCount ?>"
                 data-season-count="<?= $sCount ?>"
                 data-career-pct="<?= $cPct ?>"
                 data-season-pct="<?= $sPct ?>"
                 data-tooltip="<?= $label ?>: <?= $cCount ?> GP<?= $cCount !== 1 ? 's' : '' ?> (<?= $cPct ?>%)">
                <div class="dist-count" id="dc-<?= preg_replace('/[^a-z0-9]/i', '', $label) ?>">
                    <?= $cCount ?: '' ?>
                </div>
                <div class="dist-bar-wrap">
                    <div class="dist-bar"
                         style="height:<?= $cHeight ?>%;background:<?= $bg ?>;color:<?= $fg ?>">
                    </div>
                </div>
                <div class="dist-label" style="color:<?= $fg ?>"><?= $label ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="dist-footer">
            <span class="dist-footer-stat" id="dist-total-label">
                <?= $distTotalCareer ?> GPs career
            </span>
            <?php
            // Most common bin (career)
            $peakBin   = array_search(max($distCareer), $distCareer);
            $peakCount = max($distCareer);
            $peakPct   = round(($peakCount / $distTotalCareer) * 100);
            ?>
            <span class="dist-footer-stat">
                Most common: <strong><?= htmlspecialchars($peakBin) ?></strong> (<?= $peakPct ?>%)
            </span>
            <?php
            $perfects = $distCareer['60'];
            if ($perfects > 0):
            ?>
            <span class="dist-footer-stat" style="color:#ffd700;">
                ✦ <?= $perfects ?> perfect<?= $perfects !== 1 ? 's' : '' ?>
            </span>
            <?php endif; ?>
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
        <?php
        // Career arc — placement per season, chronological. Needs 2+ ranked seasons.
        $arc = [];
        foreach ($seasonBreakdown as $sb) {
            if (is_numeric($sb['placement']) && (int)$sb['placement'] >= 1) $arc[$sb['season']] = (int)$sb['placement'];
        }
        ksort($arc, SORT_NATURAL);
        if (count($arc) >= 2):
            $n = count($arc); $maxP = max(3, max($arc));
            $w = 64 * $n + 24; $h = 92; $top = 18; $bot = 66;
            $pts = []; $i = 0;
            foreach ($arc as $sid => $p) { $xx = 32 + $i * 64; $yy = $top + ($p - 1) / max(1, $maxP - 1) * ($bot - $top); $pts[] = [$xx, $yy, $sid, $p]; $i++; }
        ?>
        <div class="career-arc" title="Where this racer finished each season">
            <svg viewBox="0 0 <?= $w ?> <?= $h ?>" width="<?= $w ?>" height="<?= $h ?>" role="img" aria-label="Career arc">
                <polyline class="career-arc-line" fill="none" points="<?= implode(' ', array_map(fn($q) => round($q[0], 1) . ',' . round($q[1], 1), $pts)) ?>"/>
                <?php foreach ($pts as [$xx, $yy, $sid, $p]): ?>
                    <circle class="career-arc-dot<?= $p === 1 ? ' career-arc-dot--win' : '' ?>" cx="<?= round($xx, 1) ?>" cy="<?= round($yy, 1) ?>" r="6"/>
                    <text class="career-arc-place" x="<?= round($xx, 1) ?>" y="<?= round($yy, 1) - 11 ?>" text-anchor="middle">#<?= $p ?></text>
                    <text class="career-arc-season" x="<?= round($xx, 1) ?>" y="<?= $h - 6 ?>" text-anchor="middle"><?= strtoupper(htmlspecialchars($sid)) ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
        <?php endif; ?>
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

                    // System-aware tooltip via the scoring registry — same source
                    // as the standings hover, so a season's score is explained
                    // identically wherever it appears. (Was a hardcoded chain
                    // whose fallback reduced newer systems to a bare "Score: N".)
                    $tooltip = scoringTooltipFromBreakdown($bd);

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
// ── Score Distribution toggle ────────────────────────────────────────────
let distView = 'career';

function setDistView(view, btn) {
    distView = view;
    document.querySelectorAll('.dist-toggle-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const cols = document.querySelectorAll('.dist-col');
    const totalLabel = document.getElementById('dist-total-label');
    let maxH = 0;
    cols.forEach(col => {
        maxH = Math.max(maxH, parseInt(col.dataset[view + 'H'], 10) || 0);
    });
    // Rescale so the tallest bar always fills the chart
    cols.forEach(col => {
        const h    = parseInt(col.dataset[view + 'H'], 10) || 0;
        const cnt  = parseInt(col.dataset[view + 'Count'], 10) || 0;
        const pct  = parseFloat(col.dataset[view + 'Pct']) || 0;
        const bar  = col.querySelector('.dist-bar');
        const countEl = col.querySelector('.dist-count');
        const label = col.querySelector('.dist-label').textContent.trim();

        bar.style.height = (maxH > 0 ? Math.round((h / maxH) * 100) : 0) + '%';
        countEl.textContent = cnt > 0 ? cnt : '';
        col.dataset.tooltip = label + ': ' + cnt + ' GP' + (cnt !== 1 ? 's' : '') + ' (' + pct + '%)';
    });

    if (totalLabel) {
        const totals = { career: <?= $distTotalCareer ?>, season: <?= $distTotalSeason ?> };
        const labels = { career: 'GPs career', season: 'GPs this season' };
        totalLabel.textContent = totals[view] + ' ' + labels[view];
    }
}

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

<style>
/* ── Record Chaser ────────────────────────────────────────────────── */
.chase-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
    margin-top: 4px;
}
.chase-item {
    background: var(--gray-50);
    border: 1px solid #1e1e1e;
    border-radius: 8px;
    padding: 14px 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.chase-item--record {
    border-color: #6b4f00;
    background: #0c0900;
}
.chase-icon { font-size: 1.3rem; flex-shrink: 0; line-height: 1; margin-top: 2px; }
.chase-body { flex: 1; min-width: 0; }
.chase-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--gray-600);
    margin-bottom: 8px;
    line-height: 1.3;
}
.chase-progress-wrap {
    height: 4px;
    background: #1e1e1e;
    border-radius: 2px;
    overflow: hidden;
    margin-bottom: 6px;
}
.chase-bar {
    height: 100%;
    background: var(--nintendo-red);
    border-radius: 2px;
    transition: width 0.4s ease;
}
.chase-item--record .chase-bar { background: #c9901a; }
.chase-gap { font-size: 0.7rem; color: var(--gray-700); }
.chase-gap strong { color: #eee; }

/* ── Score Distribution Chart ─────────────────────────────────────── */
.dist-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}

.dist-toggle {
    display: flex;
    border: 1px solid #2a2a2a;
    border-radius: 8px;
    overflow: hidden;
}

.dist-toggle-btn {
    background: #111;
    border: none;
    color: #888;
    padding: 6px 16px;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: background 0.15s, color 0.15s;
}

.dist-toggle-btn.active {
    background: var(--nintendo-red);
    color: #fff;
}

.dist-toggle-btn:not(.active):hover {
    background: #1e1e1e;
    color: var(--gray-600);
}

.dist-chart {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    height: 160px;
    padding-bottom: 28px; /* space for labels */
    position: relative;
}

.dist-col {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    height: 100%;
    cursor: default;
    position: relative;
}

.dist-count {
    font-size: 0.7rem;
    font-weight: 900;
    color: #888;
    margin-bottom: 3px;
    min-height: 14px;
    text-align: center;
}

.dist-bar-wrap {
    width: 100%;
    flex: 1;
    display: flex;
    align-items: flex-end;
}

.dist-bar {
    width: 100%;
    border-radius: 3px 3px 0 0;
    min-height: 2px;
    transition: height 0.35s cubic-bezier(0.4, 0, 0.2, 1);
}

.dist-label {
    position: absolute;
    bottom: 0;
    font-size: 0.6rem;
    font-weight: 700;
    white-space: nowrap;
    transform: rotate(-45deg);
    transform-origin: top left;
    left: 50%;
    bottom: -4px;
}

.dist-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid #1e1e1e;
}

.dist-footer-stat {
    font-size: 0.78rem;
    color: #888;
}

.dist-footer-stat strong {
    color: var(--gray-600);
}
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
