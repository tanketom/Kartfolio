<?php
/**
 * Interactive Timeline - Chronological GP History with Activity Feed
 * Path: /cdnmk/public_html/timeline.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$currentSeason = $_GET['season'] ?? getCurrentSeasonNumber();
$isAllTime = ($currentSeason === 'all');

$pageTitle = "Timeline - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// Fetch all GPs with results
if ($isAllTime) {
    $stmt = $pdo->query("
        SELECT
            gpid,
            cup_name,
            race_date,
            COUNT(DISTINCT racer_id) as participants,
            MIN(rank) as winner_rank
        FROM results
        GROUP BY gpid
        ORDER BY race_date DESC, id DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT
            gpid,
            cup_name,
            race_date,
            COUNT(DISTINCT racer_id) as participants,
            MIN(rank) as winner_rank
        FROM results
        WHERE gpid LIKE ?
        GROUP BY gpid
        ORDER BY race_date DESC, id DESC
    ");
    $stmt->execute([$currentSeason . "%"]);
}
$gps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available seasons
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Pre-compute historical data for activity feed events ──

// Get ALL results ordered chronologically (oldest first) for historical context
$allResultsStmt = $pdo->query("
    SELECT res.gpid, res.racer_id, r.name, res.gp_points, res.rank, res.character_used, res.cup_name, res.race_date
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    ORDER BY res.race_date ASC, res.id ASC
");
$allResults = $allResultsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build lookup structures
$racerBestScore = [];      // racer_id => best gp_points before this GP
$racerGPCount = [];        // racer_id => number of GPs played before this GP
$racerWinCount = [];       // racer_id => number of #1 finishes before this GP
$cupBestScore = [];        // cup_name => best gp_points ever before this GP
$racerLastRank = [];       // racer_id => rank in previous GP
$racerWinStreak = [];      // racer_id => current consecutive wins
$gpEventCache = [];        // gpid => array of events

// Group all results by gpid (chronological order)
$resultsByGP = [];
foreach ($allResults as $row) {
    $resultsByGP[$row['gpid']][] = $row;
}

// Process GPs chronologically to build events
foreach ($resultsByGP as $gpid => $gpResults) {
    $events = [];
    $cupName = $gpResults[0]['cup_name'] ?? '';

    foreach ($gpResults as $result) {
        $rid = $result['racer_id'];
        $name = $result['name'];
        $pts = (int)$result['gp_points'];
        $rank = (int)$result['rank'];

        // Debut detection
        if (!isset($racerGPCount[$rid]) || $racerGPCount[$rid] === 0) {
            $events[] = ['type' => 'debut', 'icon' => '🌟', 'label' => $name . ' makes their debut!'];
        }

        // Personal best detection
        $prevBest = $racerBestScore[$rid] ?? 0;
        if ($pts > $prevBest && ($racerGPCount[$rid] ?? 0) > 0) {
            $events[] = ['type' => 'pb', 'icon' => '🔥', 'label' => $name . ' new PB: ' . $pts . ' pts'];
        }

        // First ever win
        if ($rank === 1 && ($racerWinCount[$rid] ?? 0) === 0 && ($racerGPCount[$rid] ?? 0) > 0) {
            $events[] = ['type' => 'milestone', 'icon' => '🏆', 'label' => $name . ' claims first ever win!'];
        }

        // Win streak (3+)
        if ($rank === 1) {
            $racerWinStreak[$rid] = ($racerWinStreak[$rid] ?? 0) + 1;
            if ($racerWinStreak[$rid] >= 3) {
                $events[] = ['type' => 'streak', 'icon' => '🔥', 'label' => $name . ' on a ' . $racerWinStreak[$rid] . '-win streak!'];
            }
        } else {
            $racerWinStreak[$rid] = 0;
        }

        // Comeback: jumped 3+ ranks from previous GP
        if (isset($racerLastRank[$rid])) {
            $rankJump = $racerLastRank[$rid] - $rank;
            if ($rankJump >= 3) {
                $events[] = ['type' => 'comeback', 'icon' => '📈', 'label' => $name . ' surges +' . $rankJump . ' places'];
            }
        }

        // Cup record
        $prevCupBest = $cupBestScore[$cupName] ?? 0;
        if ($pts > $prevCupBest && $prevCupBest > 0) {
            $events[] = ['type' => 'record', 'icon' => '👑', 'label' => $name . ' sets new ' . $cupName . ' Cup record: ' . $pts . ' pts'];
        }

        // Update tracking for next GP
        $racerBestScore[$rid] = max($prevBest, $pts);
        $racerGPCount[$rid] = ($racerGPCount[$rid] ?? 0) + 1;
        if ($rank === 1) {
            $racerWinCount[$rid] = ($racerWinCount[$rid] ?? 0) + 1;
        }
        $racerLastRank[$rid] = $rank;
        $cupBestScore[$cupName] = max($prevCupBest, $pts);
    }

    // GP-level events
    $points = array_map('intval', array_column($gpResults, 'gp_points'));
    $ranks = array_map('intval', array_column($gpResults, 'rank'));

    // Perfect 60s
    $perfectNames = [];
    foreach ($gpResults as $r) {
        if ((int)$r['gp_points'] === 60) $perfectNames[] = $r['name'];
    }
    if (count($perfectNames) > 0) {
        $events[] = ['type' => 'perfect', 'icon' => '💯', 'label' => implode(' & ', $perfectNames) . ' — Perfect 60!'];
    }

    // Photo finish: top 2 separated by 2 points or less
    if (count($gpResults) >= 2) {
        $sorted = $gpResults;
        usort($sorted, fn($a, $b) => (int)$b['gp_points'] - (int)$a['gp_points']);
        $gap = (int)$sorted[0]['gp_points'] - (int)$sorted[1]['gp_points'];
        if ($gap <= 2 && $gap > 0) {
            $events[] = ['type' => 'drama', 'icon' => '📸', 'label' => 'Photo finish! ' . $sorted[0]['name'] . ' wins by ' . $gap . ' pts'];
        } elseif ($gap === 0) {
            $events[] = ['type' => 'drama', 'icon' => '📸', 'label' => 'Dead heat! ' . $sorted[0]['name'] . ' & ' . $sorted[1]['name'] . ' tied'];
        }
    }

    // Upset: lowest-ranked racer (by previous GP) wins this one
    // (only if we have enough history)

    // Chaos GP (high score variance)
    if (count($points) >= 3) {
        $mean = array_sum($points) / count($points);
        $variance = array_sum(array_map(fn($p) => pow($p - $mean, 2), $points)) / count($points);
        if (sqrt($variance) > 15) {
            $events[] = ['type' => 'chaos', 'icon' => '🌪️', 'label' => 'Chaos GP'];
        }
    }

    // Everyone close: all scores within 10 points
    if (count($points) >= 3) {
        $spread = max($points) - min($points);
        if ($spread <= 10) {
            $events[] = ['type' => 'tight', 'icon' => '🤝', 'label' => 'Tight race! Only ' . $spread . ' pts spread'];
        }
    }

    // Full lobby (5+ racers)
    if (count($gpResults) >= 5) {
        $events[] = ['type' => 'lobby', 'icon' => '🎉', 'label' => 'Full lobby: ' . count($gpResults) . ' racers'];
    }

    $gpEventCache[$gpid] = $events;
}

// ── Build display data ──
$gpDetails = [];
foreach ($gps as $gp) {
    $resultsStmt = $pdo->prepare("
        SELECT r.name, res.gp_points, res.rank, res.character_used
        FROM results res
        JOIN racers r ON res.racer_id = r.id
        WHERE res.gpid = ?
        ORDER BY res.rank ASC
    ");
    $resultsStmt->execute([$gp['gpid']]);
    $results = $resultsStmt->fetchAll(PDO::FETCH_ASSOC);

    $gpDetails[$gp['gpid']] = [
        'results' => $results,
        'events' => $gpEventCache[$gp['gpid']] ?? []
    ];
}
?>


<div class="timeline-container">
    <div class="timeline-header">
        <h1 class="timeline-page-title">🕐 Timeline</h1>
        <form method="GET" class="timeline-filter-form">
            <label class="timeline-filter-label">Filter:</label>
            <select name="season" onchange="this.form.submit()" class="timeline-filter-select">
                <?php foreach ($availableSeasons as $season): ?>
                    <option value="<?= htmlspecialchars($season['season_id']) ?>" <?= ($season['season_id'] === $currentSeason) ? 'selected' : '' ?>>
                        Season <?= strtoupper($season['season_id']) ?>
                    </option>
                <?php endforeach; ?>
                <option value="all" <?= $isAllTime ? 'selected' : '' ?>>All Time</option>
            </select>
        </form>
    </div>

    <div class="timeline">
        <?php
        $lastSeason = null;
        foreach ($gps as $gp):
            $currentGpSeason = substr($gp['gpid'], 0, 3);

            // Show season divider
            if ($isAllTime && $currentGpSeason !== $lastSeason && $lastSeason !== null):
        ?>
            <div class="season-divider">
                <span class="season-divider-text">Season <?= strtoupper($currentGpSeason) ?></span>
            </div>
        <?php
            endif;
            $lastSeason = $currentGpSeason;

            $details = $gpDetails[$gp['gpid']];
            $events = $details['events'];
        ?>

        <div class="timeline-item">
            <div class="timeline-date"><?= date('l, F j, Y', strtotime($gp['race_date'])) ?></div>
            <div class="timeline-title"><?= htmlspecialchars($gp['cup_name']) ?> Cup</div>

            <?php if (!empty($events)): ?>
            <div class="activity-feed">
                <?php foreach ($events as $event):
                    $badgeClass = 'feed-event feed-' . ($event['type'] ?? 'default');
                ?>
                <div class="<?= $badgeClass ?>">
                    <span class="feed-icon"><?= $event['icon'] ?></span>
                    <span class="feed-text"><?= htmlspecialchars($event['label']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="timeline-meta">
                <span class="meta-badge">
                    👥 <?= $gp['participants'] ?> racer<?= $gp['participants'] > 1 ? 's' : '' ?>
                </span>
                <span class="meta-badge">
                    📅 <?= htmlspecialchars($gp['gpid']) ?>
                </span>
            </div>

            <!-- All Results -->
            <div class="results-list">
                <?php foreach ($details['results'] as $result):
                    $rankClass = '';
                    if ($result['rank'] == 1) {
                        $rankClass = 'winner';
                    } elseif ($result['rank'] <= 3) {
                        $rankClass = 'podium';
                    }
                ?>
                <div class="result-row <?= $rankClass ?>">
                    <div class="result-rank">#<?= $result['rank'] ?></div>
                    <div class="result-name"><?= htmlspecialchars($result['name']) ?></div>
                    <div class="result-points"><?= $result['gp_points'] ?> pts</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php endforeach; ?>

        <?php if (empty($gps)): ?>
        <div class="timeline-empty">
            <div class="timeline-empty-icon">🏁</div>
            <h2 class="timeline-empty-heading">No races yet</h2>
            <p>The timeline will appear once you start racing!</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
