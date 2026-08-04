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
$rules = getSeasonRules($pdo, $seasonId);

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

// Mikkoliiga top 3 — internal-points standings for the casual sub-league.
$mikkoliigaStandings = getMikkoliigaStandings($pdo, $seasonId);
$mikkoliigaTop3 = array_slice($mikkoliigaStandings, 0, 3);

// Per-racer Mikkoliiga lookup: id → ['score', 'rank']. Used to badge
// Mikkoliiga members on the main leaderboard.
$mikkoliigaByRacer = [];
foreach ($mikkoliigaStandings as $idx => $row) {
    $mikkoliigaByRacer[$row['id']] = [
        'rank'  => $idx + 1,
        'score' => $row['score'],
        'gps'   => $row['gps_counted'],
    ];
}
$mikkoliigaTotalMembers = count($mikkoliigaStandings);

// Teams — if this season has teams configured, it's a "teams season" and the
// homepage surfaces constructor cards. (Empty array = not a teams season.)
$teamStandings = getTeamStandings($pdo, $seasonId);

// 4. On This Day — GPs from this calendar date in past seasons
$onThisDayStmt = $pdo->prepare("
    SELECT res.gpid, res.race_date, res.cup_name,
           r.name AS winner_name, res.gp_points,
           SUBSTR(res.gpid, 1, 3) AS season_id
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE strftime('%m-%d', res.race_date) = strftime('%m-%d', 'now')
      AND res.rank = 1
      AND SUBSTR(res.gpid, 1, 3) != ?
    GROUP BY res.gpid
    ORDER BY res.race_date DESC
    LIMIT 5
");
$onThisDayStmt->execute([$seasonId]);
$onThisDay = $onThisDayStmt->fetchAll(PDO::FETCH_ASSOC);

// Build a gpid → recap_id map (linked_gpids is a comma-separated list)
$otdRecapMap = [];
if (!empty($onThisDay)) {
    $recapLinksStmt = $pdo->query("SELECT id, linked_gpids FROM recap_archive WHERE linked_gpids IS NOT NULL AND linked_gpids != ''");
    foreach ($recapLinksStmt->fetchAll(PDO::FETCH_ASSOC) as $rec) {
        foreach (explode(',', $rec['linked_gpids']) as $gid) {
            $gid = trim($gid);
            if ($gid !== '') $otdRecapMap[$gid] = $rec['id'];
        }
    }
}

// Prepare Ticker Data
$tickerLines = [];
if ($nemesisTicker) $tickerLines[] = ['headline' => 'RIVALRY', 'key_quote' => $nemesisTicker];
foreach ($latestNews as $item) $tickerLines[] = $item;

// Program Definitions — shared catalog (includes OMK Press Office)
require_once __DIR__ . '/../private/includes/programs.php';
$programs = getProgramsCatalog();

// 4. Fetch Latest Grand Prix Results
$latestDate = getLatestRaceDate($pdo, $seasonId);

$latestGPs = [];
if ($latestDate) {
    $gpStmt = $pdo->prepare("
        SELECT gpid, cup_name, race_date FROM results
        WHERE gpid LIKE ? AND race_date = ?
        GROUP BY gpid ORDER BY id DESC
    ");
    $gpStmt->execute([$seasonId . '%', $latestDate]);
    $latestGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($latestGPs) < 6) {
        $additionalStmt = $pdo->prepare("
            SELECT gpid, cup_name, race_date FROM results
            WHERE gpid LIKE ? AND race_date < ?
            GROUP BY gpid ORDER BY race_date DESC, id DESC LIMIT ?
        ");
        $additionalStmt->execute([$seasonId . '%', $latestDate, 6 - count($latestGPs)]);
        $latestGPs = array_merge($latestGPs, $additionalStmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

// 5. Previous standings for rank-change arrows
$previousStandings = calculatePreviousStandings($pdo, $seasonId, $latestDate, $rules);

// 6. Fetch Leaderboard
$standings = [];
foreach (getActiveRacers($pdo, $seasonId) as $r) {
    $raceCount = getRaceCount($pdo, $r['id'], $seasonId);
    $standings[] = [
        'id'        => $r['id'],
        'name'      => $r['name'],
        'score'     => calculateGPScore($pdo, $r['id'], $seasonId),
        'breakdown' => getScoringBreakdown($pdo, $r['id'], $seasonId),
        'char'      => getMostUsedCharacter($pdo, $r['id'], $seasonId),
        'badges'    => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $seasonId) : [],
        'raceCount' => $raceCount,
    ];
}
sortStandingsByScoring($standings, $scoringInfo['system'], $pdo, $seasonId);

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

// Live tournament(s) — any not-yet-completed tournament gets a banner at the
// very top linking to its public view. Newest first; extras roll into a count.
$liveTournaments = $pdo->query("
    SELECT id, name, format FROM tournaments
    WHERE status != 'completed' ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);
$liveTournament  = $liveTournaments[0] ?? null;
$liveFormatLabels = [
    'single_elim' => 'Single Elimination', 'double_elim' => 'Double Elimination',
    'gauntlet' => 'Gauntlet', 'team_relay' => 'Team Relay', 'survivor' => 'Survivor',
    'team_scramble' => 'Team Scramble', 'world_cup' => 'World Cup', 'snakes_ladders' => '🐍 Snakes & Ladders',
];
?>

<?php if ($liveTournament): ?>
<a class="live-tourney-banner" href="/view-tournament-report?id=<?= (int)$liveTournament['id'] ?>">
    <span class="ltb-live">🔴 LIVE</span>
    <span class="ltb-text">
        <strong><?= htmlspecialchars($liveTournament['name']) ?></strong> is underway — follow it live
        <?php if (count($liveTournaments) > 1): ?>
            <span class="ltb-more">+<?= count($liveTournaments) - 1 ?> more</span>
        <?php endif; ?>
    </span>
    <span class="ltb-fmt"><?= $liveFormatLabels[$liveTournament['format']] ?? htmlspecialchars($liveTournament['format']) ?></span>
    <span class="ltb-go">Watch →</span>
</a>
<style>
.live-tourney-banner { display:flex; align-items:center; gap:14px; flex-wrap:wrap; background:var(--nintendo-red); color:#fff; border-bottom:4px solid var(--ink); padding:11px 22px; text-decoration:none; font-weight:700; }
.live-tourney-banner:hover { background:var(--nintendo-red-dark); opacity:1; }
.ltb-live { background:#fff; color:var(--nintendo-red); border:2px solid var(--ink); border-radius:999px; padding:2px 12px; font-weight:900; font-size:0.8rem; letter-spacing:0.5px; }
.ltb-text { flex:1; min-width:200px; font-weight:600; }
.ltb-text strong { font-family:var(--font-display); font-weight:700; }
.ltb-more { background:rgba(255,255,255,0.22); border-radius:999px; padding:1px 9px; font-size:0.78rem; margin-left:6px; }
.ltb-fmt { font-size:0.82rem; opacity:0.9; }
.ltb-go { background:var(--ink); color:#fff; border-radius:999px; padding:4px 14px; font-weight:800; font-size:0.85rem; }
</style>
<?php endif; ?>

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
            $isQualifying = racerQualifies($row['raceCount'], $rules);
            $rankClass = ($isQualifying && $rank <= 3) ? ['gold', 'silver', 'bronze'][$rank-1] : "";
            $bd = $row['breakdown'];
            $c  = $bd['components'] ?? [];

            $bdSystem = $bd['system'] ?? 'average_attendance';
            // System-aware tooltip, dispatched through the scoring registry so
            // every system explains its own number (this used to be a hardcoded
            // if/else chain here, and any system missing a branch — Positional
            // Points, Head-to-Head, Bounty Hunter, Pari-Mutuel — silently fell
            // through to the GPScore™ wording and showed all zeros).
            $tooltip = scoringTooltipFromBreakdown($bd);
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
                    <?php if (isset($mikkoliigaByRacer[$row['id']])):
                        $mk = $mikkoliigaByRacer[$row['id']];
                        $mkTip = sprintf('Mikkoliiga member · #%d of %d · %d internal pts (best %d GPs)',
                            $mk['rank'], $mikkoliigaTotalMembers, $mk['score'], $mk['gps']);
                    ?>
                    <a href="/mikkoliiga" class="mikko-leaderboard-badge" data-tooltip="<?= htmlspecialchars($mkTip) ?>">
                        🌟 <span class="mikko-leaderboard-rank">#<?= $mk['rank'] ?></span>
                    </a>
                    <?php endif; ?>
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
                    <?php // Name the season's actual scoring system — this said
                          // "GPScore™ Active" on every system, including ones
                          // that don't compute a GPScore at all. The original
                          // average_attendance system keeps its GPScore™ brand.
                          $activeLabel = $scoringInfo['system'] === 'average_attendance'
                              ? 'GPScore™'
                              : $scoringInfo['name']; ?>
                    <?= !$isQualifying ? '• Ineligible' : '• ' . htmlspecialchars($activeLabel) . ' Active' ?>
                </div>
            </div>
            <div class="racer-score" data-tooltip="<?= htmlspecialchars($tooltip) ?>">
                <?php if ($bdSystem === 'top_12_unique'): ?>
                    <?= (int)$row['score'] ?>
                    <div class="cup-completion">
                        <?= $c['cups_counted'] ?? 0 ?> of 12 cups counted
                        <?php if (($c['unique_60s'] ?? 0) > 0): ?>
                            &middot; <?= $c['unique_60s'] ?> 🌟
                        <?php endif; ?>
                    </div>
                <?php elseif ($bdSystem === 'monster_hunt'): ?>
                    <?= number_format($row['score'], 0) ?>
                    <div class="cup-completion">
                        <?= htmlspecialchars($c['title'] ?? '') ?> &middot; <span style="opacity:0.6">lv.&nbsp;<?= $c['level'] ?? 0 ?></span>
                    </div>
                <?php elseif ($bdSystem === 'positional_points'): ?>
                    <?= (int)$row['score'] ?>
                    <div class="cup-completion">
                        <?php if (($c['mode'] ?? 'best_n') === 'best_n'): ?>
                            best <?= (int)($c['counted'] ?? 0) ?> of <?= (int)($c['gps_played'] ?? 0) ?> GPs
                        <?php elseif (($c['mode'] ?? '') === 'average'): ?>
                            avg over <?= (int)($c['gps_played'] ?? 0) ?> GPs
                        <?php else: ?>
                            all <?= (int)($c['gps_played'] ?? 0) ?> GPs
                        <?php endif; ?>
                        <?php if (($c['wins'] ?? 0) > 0): ?>
                            &middot; <?= (int)$c['wins'] ?> 🏆
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?= number_format($row['score'], 2) ?>
                <?php endif; ?>
                <?php if (in_array($bdSystem, ['cup_based', 'drop_worst', 'perfect_hunt'])): ?>
                    <?php $cupsCompleted = $c['cups_completed'] ?? 0; $cupsRequired = $c['cups_required'] ?? 0; ?>
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
                    SELECT r.id AS racer_id, r.name, res.gp_points, res.rank
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

    <?php if (!empty($teamStandings)): ?>
    <section class="team-home-section">
        <div class="section-header">
            <h3 class="section-title">🤝 Team Standings</h3>
            <span class="section-meta">Constructor scoring · best <?= teamBestN($pdo, $seasonId) ?> per GP · <a href="/teams" class="mikko-full-link">all teams →</a></span>
        </div>
        <div class="team-home-grid">
            <?php foreach ($teamStandings as $tIdx => $t):
                $tMedals = ['🥇', '🥈', '🥉'];
            ?>
            <a href="/teams?season=<?= htmlspecialchars($seasonId) ?>" class="team-home-card" style="--team-color: <?= htmlspecialchars($t['color']) ?>;">
                <div class="team-home-top">
                    <span class="team-home-medal"><?= $tMedals[$tIdx] ?? ('#' . ($tIdx + 1)) ?></span>
                    <span class="team-home-name"><?= htmlspecialchars($t['name']) ?></span>
                    <span class="team-home-score"><?= (int)$t['score'] ?></span>
                </div>
                <div class="team-home-members">
                    <?php foreach (array_values($t['members']) as $mi => $mname): if ($mi >= 6) { echo '<span class="team-home-more">+' . (count($t['members']) - 6) . '</span>'; break; } ?>
                        <span class="team-home-chip"><?= htmlspecialchars($mname) ?></span>
                    <?php endforeach; ?>
                    <?php if (empty($t['members'])): ?><span class="team-home-chip team-home-chip--empty">no members</span><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($mikkoliigaTop3)): ?>
    <section class="mikko-section">
        <div class="section-header">
            <h3 class="section-title">🌟 Mikkoliiga Top 3</h3>
            <span class="section-meta">Casual sub-league · <a href="/mikkoliiga" class="mikko-full-link">full standings →</a></span>
        </div>
        <div class="mikko-grid">
            <?php foreach ($mikkoliigaTop3 as $idx => $m):
                $mainChar = getMostUsedCharacter($pdo, $m['id'], $seasonId);
                $medals = ['🥇', '🥈', '🥉'];
            ?>
            <a href="/racer/<?= $m['id'] ?>" class="mikko-card">
                <div class="mikko-medal"><?= $medals[$idx] ?? '' ?></div>
                <img src="/assets/img/<?= htmlspecialchars($mainChar) ?>.png" class="mikko-portrait" onerror="this.src='/assets/img/Mii.png'">
                <div class="mikko-card-body">
                    <div class="mikko-name"><?= htmlspecialchars($m['name']) ?></div>
                    <div class="mikko-score"><?= $m['score'] ?> pts</div>
                    <div class="mikko-meta"><?= $m['gps_counted'] ?> of best <?= MIKKOLIIGA_BEST_X ?> counted</div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($onThisDay)): ?>
    <section class="otd-section">
        <div class="section-header">
            <h3 class="section-title">🗓️ On This Day</h3>
            <span class="section-meta"><?= date('F j') ?> in league history</span>
        </div>
        <div class="otd-grid">
            <?php foreach ($onThisDay as $otd):
                $yearDiff = (int)date('Y') - (int)date('Y', strtotime($otd['race_date']));
                $yearLabel = $yearDiff === 1 ? '1 year ago' : $yearDiff . ' years ago';
            ?>
            <div class="otd-card">
                <div class="otd-meta">
                    <span class="otd-season"><?= strtoupper($otd['season_id']) ?></span>
                    <span class="otd-age"><?= $yearLabel ?></span>
                </div>
                <div class="otd-cup"><?= htmlspecialchars($otd['cup_name']) ?> Cup</div>
                <div class="otd-winner">
                    🥇 <?= htmlspecialchars($otd['winner_name']) ?>
                    <span class="otd-score">&middot; <?= $otd['gp_points'] ?> pts</span>
                </div>
                <?php if (isset($otdRecapMap[$otd['gpid']])): ?>
                <a href="/view-recap/<?= $otdRecapMap[$otd['gpid']] ?>" class="otd-recap-link">Read recap →</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>

<style>
/* ── On This Day ──────────────────────────────────────────────────── */
.otd-section {
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}
.section-header {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin-bottom: 16px;
}
.section-meta {
    font-size: 0.75rem;
    color: var(--gray-700);
    font-style: italic;
}
.otd-grid {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.otd-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 10px;
    padding: 14px 18px;
    flex: 1;
    min-width: 150px;
    max-width: 220px;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.otd-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2px;
}
.otd-season {
    font-size: 0.65rem;
    font-weight: 900;
    color: var(--nintendo-red);
    letter-spacing: 1px;
}
.otd-age {
    font-size: 0.62rem;
    color: var(--gray-700);
}
.otd-cup {
    font-family: var(--font-display);
    font-size: 1rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1.2;
}
.otd-winner {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--gray-500);
}
.otd-score {
    color: var(--gray-600);
    font-weight: 400;
}
.otd-recap-link {
    display: inline-block;
    margin-top: 4px;
    font-size: 0.68rem;
    color: var(--nintendo-red);
    text-decoration: none;
    font-weight: 700;
}
.otd-recap-link:hover { text-decoration: underline; }

@media (max-width: 600px) {
    .otd-card { max-width: 100%; }
}

/* ── Mikkoliiga Top 3 ─────────────────────────────────────────────── */
.team-home-section {
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}
.team-home-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
    margin-top: 14px;
}
.team-home-card {
    display: block;
    background: color-mix(in srgb, var(--team-color) 14%, #ffffff);
    border: 2px solid var(--dark-bg);
    border-left: 6px solid var(--team-color, #e60012);
    border-radius: 14px;
    box-shadow: 3px 3px 0 var(--dark-bg);
    padding: 16px 18px;
    text-decoration: none;
    color: var(--gray-900);
    transition: transform .15s;
}
.team-home-card:hover { transform: translateY(-3px); }
.team-home-top { display: flex; align-items: center; gap: 10px; }
.team-home-medal { font-size: 1.3rem; min-width: 1.6em; }
.team-home-name { font-weight: 900; text-transform: uppercase; font-size: 1.15rem; flex: 1; min-width: 0; }
.team-home-score { font-size: 1.8rem; font-weight: 900; color: color-mix(in srgb, var(--team-color, #b3000e) 75%, #1a1a1a); }
.team-home-members { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
.team-home-chip { background: rgba(26,26,26,.07); color: var(--gray-700); border-radius: 999px; padding: 2px 9px; font-size: .78rem; }
.team-home-chip--empty { color: #b3261e; }
.team-home-more { color: var(--gray-500); font-size: .78rem; align-self: center; }

.mikko-section {
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid var(--gray-200);
}
.mikko-full-link {
    color: var(--nintendo-red);
    text-decoration: none;
}
.mikko-full-link:hover { text-decoration: underline; }
.mikko-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.mikko-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fff6dc;
    border: 2.5px solid var(--ink);
    border-radius: 14px;
    box-shadow: 4px 4px 0 var(--ink);
    padding: 14px 18px;
    text-decoration: none;
    color: var(--gray-900);
    transition: transform .22s var(--ease-pop), box-shadow .22s var(--ease-pop);
}
.mikko-card:hover {
    transform: translate(-2px, -2px);
    box-shadow: 6px 6px 0 var(--ink);
}
.mikko-medal {
    font-size: 1.8rem;
    line-height: 1;
}
.mikko-portrait {
    width: 56px;
    height: 56px;
    object-fit: contain;
}
.mikko-card-body { flex: 1; min-width: 0; }
.mikko-name {
    font-family: var(--font-display);
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: -0.01em;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mikko-score {
    font-family: var(--font-mono);
    font-size: 1.4rem;
    font-weight: 900;
    color: var(--gold-deep, #9a7b00);
    line-height: 1.1;
}
.mikko-meta {
    font-size: 0.7rem;
    color: var(--gray-600);
    font-style: italic;
}

/* ── Mikkoliiga marker on main leaderboard cards ──────────────────── */
.mikko-leaderboard-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #FFD700;
    color: #3a2c00;
    font-size: 0.7rem;
    font-weight: 900;
    padding: 2px 8px;
    border-radius: 10px;
    margin-left: 8px;
    text-decoration: none;
    letter-spacing: 0.5px;
    vertical-align: middle;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.mikko-leaderboard-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(255, 200, 0, 0.4);
}
.mikko-leaderboard-rank {
    font-weight: 700;
    color: #5a3a00;
    font-size: 0.68rem;
}
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>