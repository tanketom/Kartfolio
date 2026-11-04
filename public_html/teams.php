<?php
/**
 * Teams — public constructor-style team standings for a season.
 *
 * A constructor season: a team's GP score = its best TEAM_BEST_N member finishes
 * that night; season total = sum over GPs. Mirrors the Mikkoliiga page shape.
 * A team season (Roll Call): the table leads, with a week-by-week grid and each
 * racer's number. Which one comes from the registry via isTeamSeason().
 *
 * Path: /cdnmk/public_html/teams.php
 * Route: /teams  (?season=sNN)
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';
requireModule($pdo, 'teams');   // Admin → Modules
require_once __DIR__ . '/../private/includes/gp_logic.php';

$seasonId  = $_GET['season'] ?? getCurrentSeasonNumber();
$standings = getTeamStandings($pdo, $seasonId);
$bestN     = teamBestN($pdo, $seasonId);
$teamSeason = isTeamSeason($pdo, $seasonId);
if ($teamSeason) {
    $rules    = getSeasonRules($pdo, $seasonId);
    $rc       = rollCallSeason($pdo, $seasonId, $rules);
    $career   = hauntedGhostBase($pdo, $seasonId)['per'];
    $weeks    = rollCallSeasonWeeks($rules, $rc['weeks']);
    $thisWeek = rollCallWeekOf(date('Y-m-d'));
    $swapDate = rollCallSwapDate($rules);
    $swapMoves = rollCallSwapMoves($pdo, $seasonId);
    $names    = racerNamesMap($pdo);
}

$seasons = $pdo->query("SELECT DISTINCT SUBSTR(gpid,1,3) AS s FROM results WHERE gpid LIKE 's%' ORDER BY s DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($seasonId, $seasons, true)) $seasons[] = $seasonId;

$pageTitle = $teamSeason ? "Teams" : "Teams — Constructor Standings";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a><span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Teams</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🤝 Teams</h1>
        <p class="page-subtitle">SEASON <?= strtoupper(htmlspecialchars($seasonId)) ?> · <?= $teamSeason ? 'THE TEAM TABLE' : 'CONSTRUCTOR STANDINGS' ?></p>
    </header>

    <form method="GET" class="tm-season-filter">
        <label>Season
            <select name="season" onchange="this.form.submit()">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $seasonId ? 'selected' : '' ?>><?= strtoupper(htmlspecialchars($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if ($teamSeason): ?>
    <div class="teams-intro">
        <p>
            Each week you race, your team gets <strong><?= (int)$rc['knobs']['show'] ?></strong>. If any of your GPs that week beats
            <strong>your own number</strong>, it gets <strong><?= (int)$rc['knobs']['beat'] ?></strong> instead. Your number is your career
            average from before the season. Racing more often in a week earns no more, and staying home costs nothing.
        </p>
        <?php if ($swapMoves): ?>
            <p class="teams-swap">🔀 The halfway swap moved <?= htmlspecialchars(implode(' and ', array_map(fn($m) => $m['racer'] . ' to ' . $m['team'], $swapMoves))) ?>. Points earned before it stayed with the team that earned them.</p>
        <?php elseif ($swapDate): ?>
            <p class="teams-swap">🔀 Halfway swap on <?= date('l j F', strtotime($swapDate)) ?>: the most-present team's second most-present racer swaps with the least-present team's least-present racer.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="teams-intro">
        <p>
            Teams score like an <strong>F1 constructor</strong>: in each Grand Prix, a team banks the
            points of its <strong>best <?= $bestN ?> finishers</strong> that night. The season total is
            the sum across every GP — so roster size and patchy attendance don't decide it, depth does.
        </p>
    </div>
    <?php endif; ?>

    <?php if (empty($standings)): ?>
        <div class="teams-empty">
            <div style="font-size:3rem;">🤝</div>
            <h3>No teams set up for <?= strtoupper(htmlspecialchars($seasonId)) ?> yet.</h3>
            <p>An admin can create teams and assign racers on the <a href="/admin/teams?season=<?= rawurlencode($seasonId) ?>">Team Management</a> page.</p>
        </div>
    <?php else: ?>
        <div class="teams-standings">
            <?php foreach ($standings as $idx => $t):
                $rank = $t['rank'];
                $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : ''));
            ?>
                <div class="team-row" style="border-left-color: <?= htmlspecialchars($t['color']) ?>;">
                    <div class="team-rank"><?= $medal ?: ('#' . $rank) ?></div>
                    <div class="team-info">
                        <div class="team-name">
                            <span class="team-dot" style="background: <?= htmlspecialchars($t['color']) ?>;"></span>
                            <?= htmlspecialchars($t['name']) ?>
                        </div>
                        <?php if ($teamSeason):
                            // Current roster, then anyone who earned points here and has since moved on.
                            $rowsHere = [];
                            foreach ($t['members'] as $rid => $mname) $rowsHere[$rid] = $mname;
                            foreach (array_keys($t['racers']) as $rid) $rowsHere[$rid] ??= $names[$rid] ?? '?';
                            uksort($rowsHere, fn($a, $b) => (($t['racers'][$b]['pts'] ?? 0) <=> ($t['racers'][$a]['pts'] ?? 0)) ?: strcmp($rowsHere[$a], $rowsHere[$b]));
                        ?>
                        <table class="team-roster">
                            <thead><tr><th>Racer</th><th title="What they have to beat">Number</th><th>This week</th><th title="Points earned for this team">Points</th></tr></thead>
                            <tbody>
                            <?php foreach ($rowsHere as $rid => $mname):
                                $x = $rc['racers'][$rid] ?? null;
                                $num = $x['number'] ?? $career[$rid] ?? null;
                                $w = $x['by_week'][$thisWeek] ?? null;
                                $left = !isset($t['members'][$rid]); ?>
                                <tr class="<?= $left ? 'is-left' : '' ?>">
                                    <td><a href="/racer/<?= (int)$rid ?>"><?= htmlspecialchars($mname) ?></a><?= $left ? ' <small>moved on</small>' : '' ?></td>
                                    <td class="num"><?= $num === null ? 'first GP' : number_format((float)$num, 1) ?></td>
                                    <td class="num"><?= ($w && !$left) ? ($w['beat'] ? '⭐' : '✓') : '–' ?></td>
                                    <td class="num"><?= (int)($t['racers'][$rid]['pts'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rowsHere): ?><tr><td colspan="4">No racers yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="team-members">
                            <?php foreach ($t['members'] as $rid => $mname): ?>
                                <a href="/racer/<?= (int)$rid ?>" class="team-member-chip"><?= htmlspecialchars($mname) ?></a>
                            <?php endforeach; ?>
                            <?php if (empty($t['members'])): ?><span class="team-member-chip team-empty-chip">no members</span><?php endif; ?>
                        </div>
                        <div class="team-meta"><?= $t['member_count'] ?> member<?= $t['member_count'] === 1 ? '' : 's' ?> · scored in <?= $t['gps_scored'] ?> GP<?= $t['gps_scored'] === 1 ? '' : 's' ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-score"><?= (int)$t['score'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($teamSeason && $weeks): ?>
        <h2 class="team-weeks-title">Week by week</h2>
        <div class="team-weeks-wrap">
            <table class="team-weeks">
                <thead><tr><th>Week of</th><?php foreach ($standings as $t): ?><th><span class="team-dot" style="background: <?= htmlspecialchars($t['color']) ?>;"></span> <?= htmlspecialchars($t['name']) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($weeks as $wk): ?>
                    <tr class="<?= $wk === $thisWeek ? 'is-now' : '' ?>">
                        <td><?= date('j M', strtotime($wk)) ?><?= $wk === $swapDate ? ' 🔀' : '' ?></td>
                        <?php foreach ($standings as $t): ?><td class="num"><?= $wk > $thisWeek ? '' : (int)($t['by_week'][$wk] ?? 0) ?></td><?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.tm-season-filter { margin-bottom:16px; }
.tm-season-filter select { background:var(--gray-50); color:var(--gray-900); border:1px solid #333; padding:6px 10px; border-radius:4px; margin-left:8px; }
.teams-intro { background:var(--gray-50); border:1px solid var(--gray-200); border-left:4px solid var(--boost); border-radius:8px; padding:16px 22px; margin-bottom:22px; color:var(--gray-600); font-size:0.95rem; line-height:1.5; }
.teams-intro strong { color: var(--boost); }
.teams-standings { display:flex; flex-direction:column; gap:10px; }
.team-row { display:flex; align-items:center; gap:18px; background:var(--gray-50); color:var(--gray-900); padding:16px 22px; border:2.5px solid var(--dark-bg); border-radius:12px; box-shadow:4px 4px 0 var(--dark-bg); }
.team-rank { font-size:1.8rem; font-weight:900; font-style:italic; min-width:54px; text-align:center; }
.team-info { flex:1; min-width:0; }
.team-name { font-size:1.35rem; font-weight:900; text-transform:uppercase; display:flex; align-items:center; gap:8px; }
.team-dot { width:14px; height:14px; border-radius:50%; display:inline-block; }
.team-members { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.team-member-chip { background:var(--gray-100); color:var(--gray-800); border-radius:999px; padding:3px 10px; font-size:0.8rem; text-decoration:none; font-weight:600; }
.team-member-chip:hover { background:#FFD700; color:#3a2c00; }
.team-empty-chip { background:#fdecec; color:#a33; }
.team-meta { font-size:0.8rem; color:var(--gray-500); margin-top:6px; }
.team-score { font-size:2rem; font-weight:900; color:var(--nintendo-red); min-width:80px; text-align:right; }
.teams-swap { margin:10px 0 0; }
.team-roster { width:100%; border-collapse:collapse; margin-top:10px; font-size:0.92rem; }
.team-roster th { text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--gray-500); padding:2px 8px 4px 0; }
.team-roster td { padding:4px 8px 4px 0; border-top:1px solid var(--gray-200); }
.team-roster .num, .team-roster th:not(:first-child) { text-align:right; font-variant-numeric:tabular-nums; }
.team-roster a { color:inherit; font-weight:700; text-decoration:none; }
.team-roster a:hover { text-decoration:underline; }
.team-roster .is-left { opacity:0.65; }
.team-roster small { color:var(--gray-500); font-weight:500; }
.team-weeks-title { margin:28px 0 10px; font-size:1.2rem; }
.team-weeks-wrap { overflow-x:auto; }
.team-weeks { width:100%; border-collapse:collapse; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:8px; font-size:0.92rem; }
.team-weeks th, .team-weeks td { padding:8px 12px; border-top:1px solid var(--gray-200); text-align:left; white-space:nowrap; }
.team-weeks .num, .team-weeks th:not(:first-child) { text-align:right; font-variant-numeric:tabular-nums; }
.team-weeks .is-now td { background:var(--gray-100); font-weight:700; }
.teams-empty { text-align:center; padding:60px 20px; color:var(--gray-500); }
.teams-empty h3 { color:var(--gray-600); margin:12px 0; }
.teams-empty a { color:#FFD700; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
