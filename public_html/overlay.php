<?php
/**
 * OBS Stream Overlay — multi-view router
 * Path: /cdnmk/public_html/overlay.php
 * URL:  /display/overlay  (or /overlay)
 *
 * URL params:
 *   ?view=N            — pick a specific view (1=standings, 2=last GP, 3=rivalry,
 *                        4=fantasy, 5=Elo movers, 6=cup-of-night, 0=hide)
 *                        Default: 1.
 *   ?rotate=15         — auto-rotate through views every N seconds.
 *                        Combine with ?views=1,2,5 to limit which views cycle.
 *                        Default rotation set: 1,2,3,5.
 *   ?cup=Mushroom      — pin "NOW RACING · MUSHROOM CUP" banner + drives view 6.
 *   ?refresh=30        — auto-refresh interval (seconds, default 60).
 *                        Ignored when rotate is on (rotation handles updates).
 *   ?maxrows=8         — cap the number of standings rows shown.
 *
 * Hotkeys (when the overlay window has keyboard focus):
 *   0–6                — switch views directly (also pushes ?view= to URL).
 *
 * OBS browser sources don't always receive keyboard events, so the URL params
 * are the reliable mechanism — set up one Browser Source per scene with the
 * view baked into the URL.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';

$seasonId     = getCurrentSeasonNumber();
$leagueName   = getSetting($pdo, 'league_name', 'Kartfolio League');
$primaryColor = getSetting($pdo, 'primary_color', '#E60012');
$seasonTag    = strtoupper($seasonId);

// URL params
$currentCup    = trim(strip_tags($_GET['cup'] ?? ''));
$refreshSecs   = max(10, (int)($_GET['refresh'] ?? 60));
$maxRows       = max(3, (int)($_GET['maxrows'] ?? 99));
$initialView   = isset($_GET['view']) ? (int)$_GET['view'] : 1;
if ($initialView < 0 || $initialView > 6) $initialView = 1;
$rotateSecs    = isset($_GET['rotate']) ? max(5, (int)$_GET['rotate']) : 0;
$rotateViewSet = !empty($_GET['views']) ? array_filter(array_map('intval', explode(',', $_GET['views'])), fn($v) => $v >= 1 && $v <= 6) : [1, 2, 3, 5];
$rotateViewSet = array_values(array_unique($rotateViewSet));

// Season rules
$rules         = getSeasonRules($pdo, $seasonId);
$scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
$isIntScore    = in_array($scoringSystem, ['top_12_unique', 'monster_hunt', 'bounty_hunter', 'pari_mutuel']);

// Only attendance/averaging systems gate visibility on min_races_threshold.
$visibilityFloor = in_array($scoringSystem, ['average_attendance', 'preseason', 'drop_worst'])
    ? (int)($rules['min_races_threshold'] ?? 1)
    : 1;

// ─── VIEW 1: STANDINGS ───────────────────────────────────────────────────
$racersStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name, COUNT(res.id) as gp_count
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
    GROUP BY r.id
    HAVING gp_count >= ?
    ORDER BY r.name
");
$racersStmt->execute([$seasonId . '%', $visibilityFloor]);
$activeRacers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

$standings = [];
foreach ($activeRacers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);
    $standings[] = [
        'id'    => $r['id'],
        'name'  => $r['name'],
        'score' => $score,
        'gps'   => (int)$r['gp_count'],
    ];
}
// Sort through the registry: this used a score-only usort (ties in whatever
// order the racers query returned them) and then computed its own Top-12
// tiebreaker WITHOUT re-sorting by it. sortStandingsByScoring() attaches
// 'tiebreaker' for Top-12 seasons, which the standings view still prints.
sortStandingsByScoring($standings, $scoringSystem, $pdo, $seasonId);
$standings = array_slice($standings, 0, $maxRows);

// ─── VIEW 2: LAST GP ─────────────────────────────────────────────────────
$lastGPStmt = $pdo->prepare("
    SELECT DISTINCT gpid, cup_name, race_date FROM results
    WHERE gpid LIKE ? ORDER BY race_date DESC, gpid DESC LIMIT 1
");
$lastGPStmt->execute([$seasonId . '%']);
$lastGPRow = $lastGPStmt->fetch(PDO::FETCH_ASSOC);

$lastGPResults = [];
if ($lastGPRow) {
    $stmt = $pdo->prepare("
        SELECT r.name, res.gp_points, res.rank FROM results res
        JOIN racers r ON res.racer_id = r.id
        WHERE res.gpid = ? ORDER BY res.rank ASC LIMIT 4
    ");
    $stmt->execute([$lastGPRow['gpid']]);
    $lastGPResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ─── VIEW 3: RIVALRY SPOTLIGHT ───────────────────────────────────────────
$rivalryStmt = $pdo->prepare("
    SELECT r1.id AS id1, r1.name AS name1, r2.id AS id2, r2.name AS name2,
           COUNT(*) AS meetings,
           SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) AS r1_wins,
           SUM(CASE WHEN res2.rank < res1.rank THEN 1 ELSE 0 END) AS r2_wins
    FROM results res1
    JOIN results res2 ON res1.gpid = res2.gpid AND res1.racer_id < res2.racer_id
    JOIN racers r1 ON r1.id = res1.racer_id
    JOIN racers r2 ON r2.id = res2.racer_id
    WHERE res1.gpid LIKE ?
    GROUP BY r1.id, r2.id
    HAVING meetings >= 3
    ORDER BY ABS(r1_wins - r2_wins) ASC, meetings DESC
    LIMIT 1
");
$rivalryStmt->execute([$seasonId . '%']);
$rivalry = $rivalryStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ─── VIEW 4: FANTASY LEADERBOARD ─────────────────────────────────────────
$fantasyTop = [];
try {
    $ftStmt = $pdo->query("
        SELECT fp.id AS predictor_id, fp.racer_id, fp.guest_name,
               COALESCE(SUM(fb.points_earned), 0) AS total_points,
               COUNT(DISTINCT fb.week_key) AS weeks
        FROM fantasy_predictors fp
        JOIN fantasy_bets fb ON fp.id = fb.predictor_id
        WHERE fb.points_earned IS NOT NULL
        GROUP BY fp.id
        ORDER BY total_points DESC
        LIMIT 5
    ");
    $fantasyTop = $ftStmt->fetchAll(PDO::FETCH_ASSOC);
    // Resolve display names.
    $nm = [];
    foreach ($pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nm[$r['id']] = $r['name'];
    }
    foreach ($fantasyTop as &$f) {
        $f['display_name'] = $f['racer_id'] && isset($nm[$f['racer_id']]) ? $nm[$f['racer_id']] : ($f['guest_name'] ?: 'Unknown');
    }
    unset($f);
} catch (Throwable $e) {
    // Fantasy tables might not exist — silent fallback.
}

// ─── VIEW 5: ELO MOVERS (last 7 days) ────────────────────────────────────
// Reuse the MH Elo changelog (it has per-GP pre/post Elo for everyone).
$eloMovers = ['up' => [], 'down' => []];
try {
    $changelog = getMonsterHuntEloChangelog($pdo);
    $deltas    = []; // name => sum delta this season, last 7 days
    $cutoff    = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
    $dateStmt  = $pdo->prepare("SELECT race_date FROM results WHERE gpid = ? LIMIT 1");

    foreach ($changelog as $gpid => $racers) {
        if (strpos($gpid, $seasonId) !== 0) continue;
        $dateStmt->execute([$gpid]);
        $gpDate = $dateStmt->fetchColumn();
        if (!$gpDate || $gpDate < $cutoff) continue;

        foreach ($racers as $name => $d) {
            if (!isset($d['old_elo']) || !isset($d['new_elo'])) continue;
            $deltas[$name] = ($deltas[$name] ?? 0) + ($d['new_elo'] - $d['old_elo']);
        }
    }
    if (!empty($deltas)) {
        arsort($deltas);
        $eloMovers['up']   = array_slice(array_map(
            fn($name, $d) => ['name' => $name, 'delta' => (int)round($d)],
            array_keys($deltas), $deltas
        ), 0, 3);
        asort($deltas);
        $eloMovers['down'] = array_slice(array_map(
            fn($name, $d) => ['name' => $name, 'delta' => (int)round($d)],
            array_keys($deltas), $deltas
        ), 0, 3);
    }
} catch (Throwable $e) {
    // Elo engine not configured / no data — fall through.
}

// ─── VIEW 6: CUP-OF-THE-NIGHT ────────────────────────────────────────────
$cupStats = null;
if ($currentCup !== '') {
    $cs = $pdo->prepare("
        SELECT COUNT(DISTINCT res.gpid) AS plays,
               MAX(res.gp_points) AS best_pts,
               (SELECT r.name FROM results r2 JOIN racers r ON r.id = r2.racer_id WHERE r2.cup_name = ? AND r2.gp_points = (SELECT MAX(gp_points) FROM results WHERE cup_name = ?) LIMIT 1) AS best_holder
        FROM results res WHERE res.cup_name = ?
    ");
    $cs->execute([$currentCup, $currentCup, $currentCup]);
    $cupStats = $cs->fetch(PDO::FETCH_ASSOC);

    // Last 3 winners on this cup
    $recent = $pdo->prepare("
        SELECT r.name, res.race_date, res.gp_points FROM results res
        JOIN racers r ON r.id = res.racer_id
        WHERE res.cup_name = ? AND res.rank = 1
        ORDER BY res.race_date DESC LIMIT 3
    ");
    $recent->execute([$currentCup]);
    $cupStats['recent_winners'] = $recent->fetchAll(PDO::FETCH_ASSOC);
}

$medals = ['🥇', '🥈', '🥉', '4'];
$primaryRGB = implode(',', sscanf($primaryColor, '#%02x%02x%02x'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($rotateSecs === 0): ?>
    <meta http-equiv="refresh" content="<?= $refreshSecs ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($leagueName) ?> Overlay</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: transparent; font-family: 'Segoe UI', 'Arial Black', Arial, sans-serif; color: #fff; width: fit-content; padding: 0; }
        .overlay-card {
            background: rgba(8, 8, 8, 0.88);
            border: 2px solid <?= htmlspecialchars($primaryColor) ?>;
            border-radius: 10px;
            overflow: hidden;
            min-width: 260px;
            max-width: 340px;
            backdrop-filter: blur(6px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.7);
        }
        .overlay-card[data-view="0"] { background: transparent; border: none; box-shadow: none; }
        .overlay-card[data-view="0"] .overlay-view { display: none !important; }
        .overlay-card[data-view="0"]::after { content: "● LIVE"; display: block; padding: 4px 10px; font-size: 0.6rem; font-weight: 900; color: <?= htmlspecialchars($primaryColor) ?>; letter-spacing: 1px; }

        .overlay-view { display: none; }
        .overlay-view.active { display: block; }

        /* NOW RACING banner */
        .overlay-cup-banner {
            background: <?= htmlspecialchars($primaryColor) ?>;
            padding: 7px 16px;
            font-size: 0.72rem; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; text-align: center;
            color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,0.5);
        }

        /* League header */
        .overlay-header { display: flex; justify-content: space-between; align-items: baseline; padding: 10px 14px 6px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .overlay-league-name { font-size: 0.78rem; font-weight: 900; font-style: italic; text-transform: uppercase; letter-spacing: 1px; color: #fff; }
        .overlay-season-tag  { font-size: 0.65rem; font-weight: 700; color: <?= htmlspecialchars($primaryColor) ?>; letter-spacing: 1px; }

        /* Section label */
        .overlay-section-label { font-size: 0.55rem; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; color: #666; padding: 8px 14px 4px; }
        .overlay-section-label--accent { color: <?= htmlspecialchars($primaryColor) ?>; }

        /* Standings */
        .overlay-standings { padding: 0 0 6px; }
        .overlay-row { display: flex; align-items: center; gap: 8px; padding: 5px 14px; }
        .overlay-row--leader { background: rgba(<?= $primaryRGB ?>, 0.12); }
        .overlay-pos { width: 18px; font-size: 0.7rem; font-weight: 900; color: #555; text-align: right; flex-shrink: 0; }
        .overlay-row--leader .overlay-pos { color: <?= htmlspecialchars($primaryColor) ?>; }
        .overlay-name { flex: 1; font-size: 0.82rem; font-weight: 700; color: #ddd; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .overlay-score { font-size: 0.82rem; font-weight: 900; color: #fff; font-variant-numeric: tabular-nums; letter-spacing: -0.5px; }
        .overlay-gps { font-size: 0.6rem; color: #444; width: 28px; text-align: right; flex-shrink: 0; }
        .overlay-tiebreak { font-size: 0.58rem; color: #d4a017; flex-shrink: 0; }

        .overlay-divider { height: 1px; background: rgba(255,255,255,0.07); margin: 4px 0; }

        /* Last GP */
        .overlay-last-gp { padding: 6px 14px 10px; }
        .overlay-last-cup { display: inline; font-size: 0.68rem; font-weight: 700; color: #aaa; }
        .overlay-last-date { display: inline; font-size: 0.6rem; color: #444; margin-left: 6px; }
        .overlay-gp-result-row { display: flex; align-items: center; gap: 7px; padding: 2px 0; }
        .overlay-gp-medal { font-size: 0.72rem; width: 16px; }
        .overlay-gp-name  { flex: 1; font-size: 0.72rem; font-weight: 700; color: #ccc; }
        .overlay-gp-pts   { font-size: 0.7rem; font-weight: 900; color: #aaa; font-variant-numeric: tabular-nums; }

        /* Rivalry */
        .rivalry-body { padding: 12px 14px 14px; text-align: center; }
        .rivalry-pair { display: flex; align-items: center; justify-content: center; gap: 10px; margin: 6px 0; }
        .rivalry-name { font-size: 0.95rem; font-weight: 900; color: #fff; }
        .rivalry-vs { font-size: 0.7rem; font-weight: 700; color: <?= htmlspecialchars($primaryColor) ?>; padding: 2px 8px; border: 1px solid <?= htmlspecialchars($primaryColor) ?>; border-radius: 4px; }
        .rivalry-stats { display: flex; justify-content: center; gap: 18px; font-size: 0.75rem; color: #aaa; margin-top: 8px; }
        .rivalry-stats strong { color: #fff; font-size: 1.1rem; }
        .rivalry-meetings { font-size: 0.6rem; color: #666; margin-top: 6px; letter-spacing: 1px; text-transform: uppercase; }

        /* Fantasy */
        .fantasy-row { display: flex; align-items: center; gap: 8px; padding: 4px 14px; }
        .fantasy-medal { font-size: 0.72rem; width: 18px; text-align: center; }
        .fantasy-name { flex: 1; font-size: 0.78rem; font-weight: 700; color: #ddd; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fantasy-points { font-size: 0.82rem; font-weight: 900; color: <?= htmlspecialchars($primaryColor) ?>; font-variant-numeric: tabular-nums; }
        .fantasy-weeks { font-size: 0.6rem; color: #444; width: 36px; text-align: right; }

        /* Elo movers */
        .elo-side { padding: 4px 14px 10px; }
        .elo-side-label { font-size: 0.55rem; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px; }
        .elo-up   { color: #2EBD59; }
        .elo-down { color: #e60012; }
        .elo-mover-row { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
        .elo-mover-name { flex: 1; font-size: 0.78rem; font-weight: 700; color: #ddd; }
        .elo-mover-delta { font-size: 0.78rem; font-weight: 900; font-variant-numeric: tabular-nums; }

        /* Cup of the night */
        .cup-body { padding: 12px 14px 14px; }
        .cup-title { font-size: 1.1rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #fff; text-align: center; margin-bottom: 8px; }
        .cup-stat-row { display: flex; justify-content: space-between; font-size: 0.72rem; color: #aaa; padding: 3px 0; }
        .cup-stat-row strong { color: #fff; }
        .cup-recent-header { font-size: 0.55rem; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; color: #666; margin: 8px 0 4px; }

        .overlay-footer { text-align: right; padding: 0 14px 7px; font-size: 0.5rem; color: #333; letter-spacing: 0.5px; }

        /* Empty state */
        .empty-state { padding: 16px 14px; text-align: center; color: #666; font-size: 0.78rem; font-style: italic; }
    </style>
</head>
<body>
<div class="overlay-card" id="overlay-card" data-view="<?= $initialView ?>">

    <?php if ($currentCup !== '' && $initialView !== 0): ?>
    <div class="overlay-cup-banner">▶ NOW RACING &middot; <?= htmlspecialchars(strtoupper($currentCup)) ?> CUP</div>
    <?php endif; ?>

    <?php if ($initialView !== 0): ?>
    <div class="overlay-header">
        <span class="overlay-league-name"><?= htmlspecialchars($leagueName) ?></span>
        <span class="overlay-season-tag"><?= $seasonTag ?></span>
    </div>
    <?php endif; ?>

    <!-- ── View 1: Standings ─────────────────────────────────────── -->
    <div class="overlay-view" data-view="1">
        <?php if (!empty($standings)): ?>
        <div class="overlay-standings">
            <div class="overlay-section-label">Standings</div>
            <?php foreach ($standings as $i => $row): ?>
            <div class="overlay-row <?= $i === 0 ? 'overlay-row--leader' : '' ?>">
                <span class="overlay-pos"><?= $i + 1 ?></span>
                <span class="overlay-name"><?= htmlspecialchars($row['name']) ?></span>
                <span class="overlay-score"><?= $isIntScore ? number_format($row['score'], 0) : number_format($row['score'], 2) ?></span>
                <?php if ($scoringSystem === 'top_12_unique' && ($row['tiebreaker'] ?? 0) > 0): ?>
                    <span class="overlay-tiebreak">🌟<?= $row['tiebreaker'] ?></span>
                <?php endif; ?>
                <span class="overlay-gps"><?= $row['gps'] ?>gp</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">No standings yet</div>
        <?php endif; ?>
    </div>

    <!-- ── View 2: Last GP ───────────────────────────────────────── -->
    <div class="overlay-view" data-view="2">
        <?php if (!empty($lastGPResults)): ?>
        <div class="overlay-last-gp">
            <div class="overlay-section-label overlay-section-label--accent">Last GP</div>
            <div style="margin-bottom:6px;">
                <span class="overlay-last-cup"><?= htmlspecialchars($lastGPRow['cup_name']) ?> Cup</span>
                <span class="overlay-last-date">&middot; <?= date('M j', strtotime($lastGPRow['race_date'])) ?></span>
            </div>
            <?php foreach ($lastGPResults as $i => $res): ?>
            <div class="overlay-gp-result-row">
                <span class="overlay-gp-medal"><?= $medals[$i] ?? ($i + 1) ?></span>
                <span class="overlay-gp-name"><?= htmlspecialchars($res['name']) ?></span>
                <span class="overlay-gp-pts"><?= $res['gp_points'] ?>pts</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">No GPs yet</div>
        <?php endif; ?>
    </div>

    <!-- ── View 3: Rivalry Spotlight ─────────────────────────────── -->
    <div class="overlay-view" data-view="3">
        <?php if ($rivalry): ?>
        <div class="overlay-section-label overlay-section-label--accent">Rivalry Watch</div>
        <div class="rivalry-body">
            <div class="rivalry-pair">
                <span class="rivalry-name"><?= htmlspecialchars($rivalry['name1']) ?></span>
                <span class="rivalry-vs">VS</span>
                <span class="rivalry-name"><?= htmlspecialchars($rivalry['name2']) ?></span>
            </div>
            <div class="rivalry-stats">
                <div><strong><?= (int)$rivalry['r1_wins'] ?></strong> – <strong><?= (int)$rivalry['r2_wins'] ?></strong></div>
            </div>
            <div class="rivalry-meetings"><?= (int)$rivalry['meetings'] ?> meetings this season</div>
        </div>
        <?php else: ?>
        <div class="empty-state">No rivalries (yet)</div>
        <?php endif; ?>
    </div>

    <!-- ── View 4: Fantasy Leaderboard ───────────────────────────── -->
    <div class="overlay-view" data-view="4">
        <?php if (!empty($fantasyTop)): ?>
        <div class="overlay-section-label overlay-section-label--accent">Fantasy Standings</div>
        <?php foreach ($fantasyTop as $i => $f): ?>
        <div class="fantasy-row">
            <span class="fantasy-medal"><?= $medals[$i] ?? ($i + 1) ?></span>
            <span class="fantasy-name"><?= htmlspecialchars($f['display_name']) ?></span>
            <span class="fantasy-points"><?= (int)$f['total_points'] ?></span>
            <span class="fantasy-weeks"><?= (int)$f['weeks'] ?>wk</span>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">No fantasy scores yet</div>
        <?php endif; ?>
    </div>

    <!-- ── View 5: Elo Movers ────────────────────────────────────── -->
    <div class="overlay-view" data-view="5">
        <?php if (!empty($eloMovers['up']) || !empty($eloMovers['down'])): ?>
        <div class="overlay-section-label overlay-section-label--accent">Elo Movers · 7 Days</div>
        <div class="elo-side">
            <div class="elo-side-label elo-up">▲ Top Gainers</div>
            <?php foreach ($eloMovers['up'] as $m): ?>
            <div class="elo-mover-row">
                <span class="elo-mover-name"><?= htmlspecialchars($m['name']) ?></span>
                <span class="elo-mover-delta elo-up">+<?= $m['delta'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="overlay-divider"></div>
        <div class="elo-side">
            <div class="elo-side-label elo-down">▼ Top Losers</div>
            <?php foreach ($eloMovers['down'] as $m): ?>
            <div class="elo-mover-row">
                <span class="elo-mover-name"><?= htmlspecialchars($m['name']) ?></span>
                <span class="elo-mover-delta elo-down"><?= $m['delta'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">No Elo activity in last 7 days</div>
        <?php endif; ?>
    </div>

    <!-- ── View 6: Cup of the Night ──────────────────────────────── -->
    <div class="overlay-view" data-view="6">
        <?php if ($currentCup !== '' && $cupStats): ?>
        <div class="cup-body">
            <div class="cup-title"><?= htmlspecialchars($currentCup) ?> Cup</div>
            <div class="cup-stat-row"><span>All-time plays</span><strong><?= (int)$cupStats['plays'] ?></strong></div>
            <div class="cup-stat-row"><span>Best score</span><strong><?= (int)$cupStats['best_pts'] ?> by <?= htmlspecialchars($cupStats['best_holder'] ?? '—') ?></strong></div>
            <?php if (!empty($cupStats['recent_winners'])): ?>
            <div class="cup-recent-header">Recent winners</div>
            <?php foreach ($cupStats['recent_winners'] as $w): ?>
            <div class="cup-stat-row">
                <span><?= htmlspecialchars($w['name']) ?></span>
                <strong><?= (int)$w['gp_points'] ?>pts · <?= date('M j', strtotime($w['race_date'])) ?></strong>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">Set <code>?cup=Name</code> to enable</div>
        <?php endif; ?>
    </div>

    <?php if ($initialView !== 0): ?>
    <div class="overlay-footer" id="overlay-footer">↻ <?= date('H:i') ?> &middot; v<span id="overlay-view-num"><?= $initialView ?></span><?= $rotateSecs ? ' &middot; ↺' . $rotateSecs . 's' : '' ?></div>
    <?php endif; ?>

</div>

<script>
(function () {
    const card = document.getElementById('overlay-card');
    const viewNumLabel = document.getElementById('overlay-view-num');
    const views = Array.from(document.querySelectorAll('.overlay-view'));

    // Activate a view by number. Updates URL so OBS scene refreshes pick it up.
    function setView(n, pushUrl = true) {
        n = Math.max(0, Math.min(6, parseInt(n, 10) || 0));
        card.dataset.view = String(n);
        views.forEach(v => {
            v.classList.toggle('active', parseInt(v.dataset.view, 10) === n);
        });
        if (viewNumLabel) viewNumLabel.textContent = String(n);

        if (pushUrl && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', String(n));
            window.history.replaceState({}, '', url.toString());
        }
    }

    // Initial render — make the server-rendered initial view actually visible.
    const initialView = <?= json_encode($initialView) ?>;
    setView(initialView, false);

    // Hotkeys (0–6). OBS browser sources often won't get keyboard focus;
    // URL params are the reliable mechanism.
    document.addEventListener('keydown', (e) => {
        if (e.key >= '0' && e.key <= '6') {
            setView(parseInt(e.key, 10));
        }
    });

    // Auto-rotate through a configured set every ?rotate=N seconds.
    const rotateSecs    = <?= json_encode($rotateSecs) ?>;
    const rotateViewSet = <?= json_encode($rotateViewSet) ?>;
    if (rotateSecs > 0 && rotateViewSet.length > 1) {
        let idx = Math.max(0, rotateViewSet.indexOf(initialView));
        setInterval(() => {
            idx = (idx + 1) % rotateViewSet.length;
            setView(rotateViewSet[idx], false);
        }, rotateSecs * 1000);
    }
})();
</script>
</body>
</html>
