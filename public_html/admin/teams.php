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
    } elseif ($action === 'delete_team') {
        $tid = (int)($_POST['team_id'] ?? 0);
        // Removing the team also frees its members (cascade on team_members).
        $pdo->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$tid]);
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

            $del = $pdo->prepare("DELETE FROM team_members WHERE season_id = ? AND racer_id = ?");
            $ins = $pdo->prepare("INSERT INTO team_members (season_id, team_id, racer_id) VALUES (?, ?, ?)");
            foreach ($assignments as $rid => $tid) {
                $rid = (int)$rid;
                $del->execute([$season, $rid]);
                if ($tid !== '' && isset($validTeams[(int)$tid])) {
                    $ins->execute([$season, (int)$tid, $rid]);
                }
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

// Racers who raced this season (the assignable pool) + current assignment.
$racers = getActiveRacers($pdo, $selectedSeason);
usort($racers, fn($a, $b) => strcmp($a['name'], $b['name']));
$assignStmt = $pdo->prepare("SELECT racer_id, team_id FROM team_members WHERE season_id = ?");
$assignStmt->execute([$selectedSeason]);
$assignedTeamOf = [];
foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $assignedTeamOf[(int)$row['racer_id']] = (int)$row['team_id'];

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
        <p class="page-subtitle">CONSTRUCTOR SCORING · BEST <?= TEAM_BEST_N ?> MEMBERS PER GP · PUBLIC STANDINGS AT <a href="/teams?season=<?= htmlspecialchars($selectedSeason) ?>" style="color:#FFD700;">/teams</a></p>
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

    <section class="tm-section">
        <h2 class="tm-h">Teams in <?= strtoupper(htmlspecialchars($selectedSeason)) ?></h2>
        <?php if (empty($teams)): ?>
            <p class="tm-empty">No teams yet. Create the first one below.</p>
        <?php else: ?>
            <div class="tm-team-list">
                <?php foreach ($teams as $t): ?>
                    <div class="tm-team" style="border-left-color: <?= htmlspecialchars($t['color']) ?>;">
                        <span class="tm-swatch" style="background: <?= htmlspecialchars($t['color']) ?>;"></span>
                        <span class="tm-team-name"><?= htmlspecialchars($t['name']) ?></span>
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
            <input type="color" name="color" value="var(--nintendo-red)" title="Team colour">
            <button type="submit" class="btn btn-secondary">+ Add team</button>
        </form>
    </section>

    <?php if (!empty($teams)): ?>
    <section class="tm-section">
        <h2 class="tm-h">Assign racers</h2>
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
.tm-flash { background:#14140a; border-left:3px solid #FFD700; color:var(--gray-600); padding:10px 16px; border-radius:6px; margin-bottom:16px; }
.tm-filter { margin-bottom:16px; }
.tm-filter select { background:var(--gray-200); color:#fff; border:1px solid #333; padding:6px 10px; border-radius:4px; margin-left:8px; }
.tm-section { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:10px; padding:18px 22px; margin-bottom:18px; }
.tm-h { color:#FFD700; font-size:1.2rem; margin:0 0 12px; }
.tm-empty, .tm-note { color:#999; font-size:0.9rem; }
.tm-team-list { display:flex; flex-direction:column; gap:8px; margin-bottom:14px; }
.tm-team { display:flex; align-items:center; gap:12px; background:var(--gray-200); border:1px solid #222; border-left:4px solid var(--nintendo-red); border-radius:8px; padding:10px 14px; color:#fff; }
.tm-swatch { width:18px; height:18px; border-radius:50%; }
.tm-team-name { font-weight:800; }
.tm-team-count { color:#999; font-size:0.85rem; }
.tm-create { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.tm-create input[type=text] { flex:1; min-width:200px; background:var(--gray-200); color:#fff; border:1px solid #333; padding:8px 10px; border-radius:4px; }
.tm-create input[type=color] { width:44px; height:38px; border:1px solid #333; background:var(--gray-200); border-radius:4px; }
.btn-sm { padding:5px 12px; font-size:0.85rem; }
.tm-assign-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; }
.tm-assign-row { display:flex; align-items:center; gap:10px; background:var(--gray-200); border:1px solid #222; border-radius:8px; padding:8px 12px; }
.tm-racer-name { flex:1; color:#fff; font-weight:600; }
.tm-assign-row select { background:var(--gray-200); color:#fff; border:1px solid #333; padding:5px 8px; border-radius:4px; }
</style>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
