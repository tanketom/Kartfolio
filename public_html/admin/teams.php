<?php
/**
 * Team management — create teams for a season and assign racers (manual
 * rosters). Standings are computed live on /teams via getTeamStandings().
 *
 * Path: /cdnmk/public_html/admin/teams.php
 * Route: /admin/teams
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/team_season.php';
require_admin();

$currentSeason  = getCurrentSeasonNumber();
$selectedSeason = $_GET['season'] ?? $currentSeason;
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $season = $_POST['season_id'] ?? $selectedSeason;

    if ($action === 'create_team') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#e60012';
        if ($name !== '') {
            $st = $pdo->prepare("INSERT INTO teams (season_id, name, color) VALUES (?, ?, ?)");
            $st->execute([$season, $name, $color]);
            $message = "Created team \"$name\".";
        } else {
            $message = "Team name is required.";
        }
    } elseif ($action === 'edit_team') {
        // The teams choose their own names and colours; this is where they land.
        $tid   = (int)($_POST['team_id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : null;
        if ($name !== '' && $color !== null) {
            $pdo->prepare("UPDATE teams SET name = ?, color = ? WHERE id = ? AND season_id = ?")->execute([$name, $color, $tid, $season]);
            $message = "Saved \"$name\".";
        } else {
            $message = "A team needs a name and a colour.";
        }
    } elseif ($action === 'draw_teams') {
        $ref = strtolower((string)($_POST['ref_season'] ?? ''));
        if (!preg_match('/^s[0-9]{2}$/', $ref)) {
            $message = "Pick a season to draw from.";
        } else {
            try {
                $drawn = teamSeasonDraw($pdo, $season, $ref, max(2, min(6, (int)($_POST['team_count'] ?? 3))), max(1, min(10, (int)($_POST['per_team'] ?? 3))));
                $message = "Drawn from " . strtoupper($ref) . ". " . implode(' · ', array_map(fn($n, $m) => "$n: " . implode(', ', $m), array_keys($drawn), $drawn));
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }
    } elseif ($action === 'sync_teams') {
        try {
            $r = teamSeasonSync($pdo, $season);
            $message = ($r['joined'] ? 'Joined: ' . implode(', ', $r['joined']) . '. ' : '')
                     . ($r['swapped'] ? 'Swapped: ' . implode(' and ', $r['swapped']) . '. ' : '')
                     ?: 'Already up to date.';
        } catch (Throwable $e) {
            $message = 'Update failed: ' . $e->getMessage();
        }
    } elseif ($action === 'delete_team') {
        $tid = (int)($_POST['team_id'] ?? 0);
        // Removing the team also frees its members (cascade on team_members).
        $pdo->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM team_stints WHERE team_id = ? AND season_id = ?")->execute([$tid, $season]);
        $pdo->prepare("DELETE FROM teams WHERE id = ? AND season_id = ?")->execute([$tid, $season]);
        $message = "Team deleted.";
    } elseif ($action === 'save_rosters') {
        // One select per racer: assignments[racer_id] = team_id ('' = unassigned).
        $assignments = $_POST['assignments'] ?? [];
        $pdo->beginTransaction();
        try {
            // Valid team ids for this season (guard against cross-season ids).
            $vt = $pdo->prepare("SELECT id FROM teams WHERE season_id = ?");
            $vt->execute([$season]);
            $validTeams = array_flip(array_map('intval', $vt->fetchAll(PDO::FETCH_COLUMN)));

            // Through the history, so a move mid-season leaves what the racer
            // earned on their old team where it is.
            foreach ($assignments as $rid => $tid) {
                $target = ($tid !== '' && isset($validTeams[(int)$tid])) ? (int)$tid : null;
                teamSeasonAdminMove($pdo, $season, (int)$rid, $target);
            }
            $pdo->commit();
            $message = "Rosters saved.";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = "Save failed: " . $e->getMessage();
        }
    }
    // Redirect to clear POST (preserve season).
    header('Location: /admin/teams?season=' . urlencode($season) . '&saved=' . urlencode($message));
    exit;
}

if (isset($_GET['saved'])) $message = (string)$_GET['saved'];

// Load data for the selected season.
$seasons = $pdo->query("SELECT DISTINCT SUBSTR(gpid,1,3) AS s FROM results WHERE gpid LIKE 's%' ORDER BY s DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($selectedSeason, $seasons, true)) $seasons[] = $selectedSeason;

$teams = getTeamConfig($pdo, $selectedSeason);

$teamSeason = isTeamSeason($pdo, $selectedSeason);
$assignStmt = $pdo->prepare("SELECT racer_id, team_id FROM team_members WHERE season_id = ?");
$assignStmt->execute([$selectedSeason]);
$assignedTeamOf = [];
foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $assignedTeamOf[(int)$row['racer_id']] = (int)$row['team_id'];

// The assignable pool: who raced this season and who is already on a team. On
// a team season the whole active roster, because the teams are made before
// anyone has raced.
$pool = [];
foreach (getActiveRacers($pdo, $selectedSeason) as $r) $pool[(int)$r['id']] = $r['name'];
$allRacers = $pdo->query("SELECT id, name, COALESCE(is_retired, 0) AS is_retired FROM racers")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allRacers as $r) {
    if (isset($assignedTeamOf[(int)$r['id']]) || ($teamSeason && !$r['is_retired'])) $pool[(int)$r['id']] = $r['name'];
}
asort($pool, SORT_NATURAL | SORT_FLAG_CASE);
$racers = [];
foreach ($pool as $id => $name) $racers[] = ['id' => $id, 'name' => $name];

// The draw: what the pots would be, shown before anyone presses the button.
$drawOpen = $teamSeason && teamSeasonRacedMembers($pdo, $selectedSeason) === 0;
$refSeason = strtolower((string)($_GET['ref'] ?? '')) ?: teamSeasonDefaultReference($pdo, $selectedSeason);
$teamCount = $teams ? count($teams) : max(2, min(6, (int)($_GET['n'] ?? 3)));
$perTeam   = max(1, min(10, (int)($_GET['per'] ?? 3)));
$drawPots  = ($drawOpen && $refSeason) ? teamSeasonPots($pdo, $refSeason, $teamCount, $perTeam) : [];
$swapDate  = $teamSeason ? rollCallSwapDate(getSeasonRules($pdo, $selectedSeason)) : null;
$swapMoves = $teamSeason ? rollCallSwapMoves($pdo, $selectedSeason) : [];

$pageTitle = "Team Management - Admin";
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a><span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Team Management</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🤝 Team Management</h1>
        <p class="page-subtitle"><?= htmlspecialchars(mb_strtoupper(teamScoringLine($pdo, $selectedSeason))) ?> · PUBLIC STANDINGS AT <a href="/teams?season=<?= htmlspecialchars($selectedSeason) ?>" style="color:#FFD700;">/teams</a></p>
    </header>

    <?php if ($message): ?><div class="tm-flash"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <form method="GET" class="tm-filter">
        <label>Season
            <select name="season" onchange="this.form.submit()">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $selectedSeason ? 'selected' : '' ?>>
                        <?= strtoupper(htmlspecialchars($s)) ?><?= $s === $currentSeason ? ' (current)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <?php if ($teamSeason): ?>
    <section class="tm-section">
        <h2 class="tm-h">🎲 The draw</h2>
        <?php if (!$drawOpen): ?>
            <p class="tm-note">Racers on these teams have already raced, so the draw is closed. Anyone new joins a team on their first race. To move someone, use the rosters below.</p>
        <?php elseif (!$refSeason): ?>
            <p class="tm-note">There is no earlier season to draw from yet. Create the teams and assign racers by hand below, or let them join on their first race.</p>
        <?php else: ?>
            <p class="tm-note">One racer from each pot goes to each team, at random. Everyone else joins on their first race. You can draw again until a drawn racer has raced.</p>
            <form method="GET" class="tm-draw-opts">
                <input type="hidden" name="season" value="<?= htmlspecialchars($selectedSeason) ?>">
                <label>Most active in
                    <select name="ref" onchange="this.form.submit()">
                        <?php foreach ($seasons as $s): if ($s >= $selectedSeason) continue; ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $s === $refSeason ? 'selected' : '' ?>><?= strtoupper(htmlspecialchars($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!$teams): ?>
                <label>Teams <input type="number" name="n" min="2" max="6" value="<?= $teamCount ?>" onchange="this.form.submit()"></label>
                <?php endif; ?>
                <label>Racers per team <input type="number" name="per" min="1" max="10" value="<?= $perTeam ?>" onchange="this.form.submit()"></label>
            </form>
            <div class="tm-pots">
                <?php foreach ($drawPots as $i => $pot): ?>
                <div class="tm-pot">
                    <div class="tm-pot-h">Pot <?= $i + 1 ?></div>
                    <?php foreach ($pot as $r): ?><div class="tm-pot-r"><span><?= htmlspecialchars($r['name']) ?></span><small><?= (int)$r['weeks'] ?> weeks · <?= (int)$r['gps'] ?> GPs</small></div><?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <form method="POST" onsubmit="return confirm('Draw the teams? This replaces the current rosters.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="draw_teams">
                <input type="hidden" name="season_id" value="<?= htmlspecialchars($selectedSeason) ?>">
                <input type="hidden" name="ref_season" value="<?= htmlspecialchars($refSeason) ?>">
                <input type="hidden" name="team_count" value="<?= $teamCount ?>">
                <input type="hidden" name="per_team" value="<?= $perTeam ?>">
                <button type="submit" class="btn btn-primary">🎲 Draw the teams</button>
            </form>
        <?php endif; ?>
        <p class="tm-note" style="margin-top:12px;">
            <?php if ($swapMoves): ?>
                Halfway swap done: <?= htmlspecialchars(implode(' · ', array_map(fn($m) => $m['racer'] . ' → ' . $m['team'], $swapMoves))) ?>.
            <?php elseif ($swapDate): ?>
                Halfway swap: <?= date('l j F', strtotime($swapDate)) ?>, applied by the first GP saved on or after it.
            <?php else: ?>
                No halfway swap: it is off, or the season has no start and end dates.
            <?php endif; ?>
        </p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_teams">
            <input type="hidden" name="season_id" value="<?= htmlspecialchars($selectedSeason) ?>">
            <button type="submit" class="btn btn-secondary btn-sm" title="Runs after every saved GP anyway. Useful after fixing results by hand.">Check for joiners and the swap now</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="tm-section">
        <h2 class="tm-h">Teams in <?= strtoupper(htmlspecialchars($selectedSeason)) ?></h2>
        <?php if (empty($teams)): ?>
            <p class="tm-empty">No teams yet. Create the first one below.</p>
        <?php else: ?>
            <div class="tm-team-list">
                <?php foreach ($teams as $t): ?>
                    <div class="tm-team" style="border-left-color: <?= htmlspecialchars($t['color']) ?>;">
                        <span class="tm-swatch" style="background: <?= htmlspecialchars($t['color']) ?>;"></span>
                        <form method="POST" class="tm-team-edit">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="edit_team">
                            <input type="hidden" name="season_id" value="<?= htmlspecialchars($selectedSeason) ?>">
                            <input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                            <input type="text" name="name" value="<?= htmlspecialchars($t['name']) ?>" aria-label="Team name" required>
                            <input type="color" name="color" value="<?= htmlspecialchars($t['color']) ?>" aria-label="Team colour">
                            <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                        </form>
                        <span class="tm-team-count"><?= count($t['members']) ?> member<?= count($t['members']) === 1 ? '' : 's' ?></span>
                        <form method="POST" onsubmit="return confirm('Delete this team? Its members become unassigned.');" style="margin-left:auto;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="season_id" value="<?= htmlspecialchars($selectedSeason) ?>">
                            <input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:#5a1a1a;color:#fff;">Delete</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="tm-create">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_team">
            <input type="hidden" name="season_id" value="<?= htmlspecialchars($selectedSeason) ?>">
            <input type="text" name="name" placeholder="New team name (e.g. Team Petey)" required>
            <input type="color" name="color" value="#e60012" title="Team colour">
            <button type="submit" class="btn btn-secondary">+ Add team</button>
        </form>
    </section>

    <?php if (!empty($teams)): ?>
    <section class="tm-section">
        <h2 class="tm-h">Assign racers</h2>
        <?php if ($teamSeason): ?><p class="tm-note">A move during the season applies from today. What a racer already earned stays with their old team.</p><?php endif; ?>
        <p class="tm-note">Each racer who raced in <?= strtoupper(htmlspecialchars($selectedSeason)) ?> can join one team. Leave “— Unassigned —” to keep them out.</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_rosters">
            <input type="hidden" name="season_id" value="<?= htmlspecialchars($selectedSeason) ?>">
            <div class="tm-assign-grid">
                <?php foreach ($racers as $r):
                    $cur = $assignedTeamOf[(int)$r['id']] ?? ''; ?>
                    <label class="tm-assign-row">
                        <span class="tm-racer-name"><?= htmlspecialchars($r['name']) ?></span>
                        <select name="assignments[<?= (int)$r['id'] ?>]">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $cur === (int)$t['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:16px;">💾 Save rosters</button>
        </form>
    </section>
    <?php endif; ?>
</div>

<style>
.tm-flash { background:var(--gray-50); border:1px solid var(--gray-200); border-left:3px solid #FFD700; color:var(--gray-800); padding:10px 16px; border-radius:6px; margin-bottom:16px; }
.tm-filter { margin-bottom:16px; }
.tm-filter select { background:var(--gray-50); color:var(--gray-900); border:1px solid #333; padding:6px 10px; border-radius:4px; margin-left:8px; }
.tm-section { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:10px; padding:18px 22px; margin-bottom:18px; }
.tm-h { color:#FFD700; font-size:1.2rem; margin:0 0 12px; }
.tm-empty, .tm-note { color:#999; font-size:0.9rem; }
.tm-team-list { display:flex; flex-direction:column; gap:8px; margin-bottom:14px; }
.tm-team { display:flex; align-items:center; gap:12px; background:var(--gray-200); border:1px solid #222; border-left:4px solid var(--nintendo-red); border-radius:8px; padding:10px 14px; color:#fff; }
.tm-swatch { width:18px; height:18px; border-radius:50%; }
.tm-team-name { font-weight:800; }
.tm-team-count { color:#999; font-size:0.85rem; }
.tm-create { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.tm-create input[type=text] { flex:1; min-width:200px; background:var(--gray-50); color:var(--gray-900); border:1px solid #333; padding:8px 10px; border-radius:4px; }
.tm-create input[type=color] { width:44px; height:38px; border:1px solid #333; background:var(--gray-200); border-radius:4px; }
.btn-sm { padding:5px 12px; font-size:0.85rem; }
.tm-team-edit { display:flex; align-items:center; gap:8px; flex:1; min-width:0; flex-wrap:wrap; }
.tm-team-edit input[type=text] { flex:1; min-width:140px; background:var(--gray-50); color:var(--gray-900); border:1px solid #333; padding:5px 8px; border-radius:4px; font-weight:800; }
.tm-team-edit input[type=color] { width:36px; height:30px; border:1px solid #333; background:transparent; border-radius:4px; padding:0; }
.tm-draw-opts { display:flex; gap:16px; flex-wrap:wrap; align-items:center; margin:12px 0; }
.tm-draw-opts select, .tm-draw-opts input { background:var(--gray-50); color:var(--gray-900); border:1px solid #333; padding:5px 8px; border-radius:4px; margin-left:6px; width:auto; max-width:90px; }
.tm-pots { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; margin-bottom:14px; }
.tm-pot { background:var(--gray-200); border:1px solid #222; border-radius:8px; padding:10px 14px; }
.tm-pot-h { font-weight:800; margin-bottom:6px; }
.tm-pot-r { display:flex; justify-content:space-between; gap:8px; padding:2px 0; }
.tm-pot-r small { color:#999; white-space:nowrap; }
.tm-assign-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; }
.tm-assign-row { display:flex; align-items:center; gap:10px; background:var(--gray-200); border:1px solid #222; border-radius:8px; padding:8px 12px; }
.tm-racer-name { flex:1; color:var(--gray-900); font-weight:600; }
.tm-assign-row select { background:var(--gray-50); color:var(--gray-900); border:1px solid #333; padding:5px 8px; border-radius:4px; }
</style>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
