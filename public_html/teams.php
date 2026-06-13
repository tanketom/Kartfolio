<?php
/**
 * Teams — public constructor-style team standings for a season.
 *
 * A team's GP score = its best TEAM_BEST_N member finishes that night;
 * season total = sum over GPs. Mirrors the Mikkoliiga page shape.
 *
 * Path: /cdnmk/public_html/teams.php
 * Route: /teams  (?season=sNN)
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$seasonId  = $_GET['season'] ?? getCurrentSeasonNumber();
$standings = getTeamStandings($pdo, $seasonId);
$bestN     = teamBestN($pdo, $seasonId);

$seasons = $pdo->query("SELECT DISTINCT SUBSTR(gpid,1,3) AS s FROM results WHERE gpid LIKE 's%' ORDER BY s DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($seasonId, $seasons, true)) $seasons[] = $seasonId;

$pageTitle = "Teams — Constructor Standings";
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
        <p class="page-subtitle">SEASON <?= strtoupper(htmlspecialchars($seasonId)) ?> · CONSTRUCTOR STANDINGS</p>
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

    <div class="teams-intro">
        <p>
            Teams score like an <strong>F1 constructor</strong>: in each Grand Prix, a team banks the
            points of its <strong>best <?= $bestN ?> finishers</strong> that night. The season total is
            the sum across every GP — so roster size and patchy attendance don't decide it, depth does.
        </p>
    </div>

    <?php if (empty($standings)): ?>
        <div class="teams-empty">
            <div style="font-size:3rem;">🤝</div>
            <h3>No teams set up for <?= strtoupper(htmlspecialchars($seasonId)) ?> yet.</h3>
            <p>An admin can create teams and assign racers on the <a href="/admin/teams?season=<?= htmlspecialchars($seasonId) ?>">Team Management</a> page.</p>
        </div>
    <?php else: ?>
        <div class="teams-standings">
            <?php foreach ($standings as $idx => $t):
                $rank = $idx + 1;
                $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : ''));
            ?>
                <div class="team-row" style="border-left-color: <?= htmlspecialchars($t['color']) ?>;">
                    <div class="team-rank"><?= $medal ?: ('#' . $rank) ?></div>
                    <div class="team-info">
                        <div class="team-name">
                            <span class="team-dot" style="background: <?= htmlspecialchars($t['color']) ?>;"></span>
                            <?= htmlspecialchars($t['name']) ?>
                        </div>
                        <div class="team-members">
                            <?php foreach ($t['members'] as $rid => $mname): ?>
                                <a href="/racer/<?= (int)$rid ?>" class="team-member-chip"><?= htmlspecialchars($mname) ?></a>
                            <?php endforeach; ?>
                            <?php if (empty($t['members'])): ?><span class="team-member-chip team-empty-chip">no members</span><?php endif; ?>
                        </div>
                        <div class="team-meta"><?= $t['member_count'] ?> member<?= $t['member_count'] === 1 ? '' : 's' ?> · scored in <?= $t['gps_scored'] ?> GP<?= $t['gps_scored'] === 1 ? '' : 's' ?></div>
                    </div>
                    <div class="team-score"><?= (int)$t['score'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.tm-season-filter { margin-bottom:16px; }
.tm-season-filter select { background:var(--gray-200); color:#fff; border:1px solid #333; padding:6px 10px; border-radius:4px; margin-left:8px; }
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
.teams-empty { text-align:center; padding:60px 20px; color:var(--gray-500); }
.teams-empty h3 { color:var(--gray-600); margin:12px 0; }
.teams-empty a { color:#FFD700; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
