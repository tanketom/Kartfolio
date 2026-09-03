<?php
/**
 * Season Transition Wizard
 * Step-by-step guided flow for closing an active season.
 * Path: /cdnmk/public_html/admin/close_season.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

require_admin();

// ── Get target season ─────────────────────────────────────────────────────
$seasonId = $_GET['season'] ?? $_POST['season_id'] ?? null;

if (!$seasonId) {
    // Show picker if no season specified.
    // Any season that isn't already archived is closable — NOT just status='active'.
    // Seasons created on /admin/seasons start as 'upcoming', so keying on 'active'
    // alone hid real, raced seasons from this wizard and reported "all seasons are
    // already archived" when they plainly weren't.
    $activeStmt = $pdo->query("
        SELECT sm.season_id, sm.season_name, sm.status,
               (SELECT COUNT(DISTINCT gpid) FROM results WHERE gpid LIKE sm.season_id || '%') AS gp_count
        FROM season_meta sm
        WHERE sm.status != 'archived'
        ORDER BY sm.season_id DESC
    ");
    $activeSeasons = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

    $pageTitle = "Season Transition Wizard";
    $extraCss  = '<link rel="stylesheet" href="/assets/css/forms.css"><link rel="stylesheet" href="/assets/css/admin.css">';
    include __DIR__ . '/../../private/templates/header.php';
    ?>
    <div class="stats-container">
        <nav class="breadcrumb"><a href="/admin/seasons">← Seasons</a><span class="breadcrumb-separator">/</span><span class="breadcrumb-current">Transition Wizard</span></nav>
        <h1 class="admin-page-title" style="margin-top:20px;">SEASON TRANSITION WIZARD</h1>
        <?php if (empty($activeSeasons)): ?>
            <div class="alert-error">Every season is already archived — there's nothing left to close.</div>
        <?php else: ?>
            <p style="color:var(--gray-500);margin-bottom:24px;">Select the season to close:</p>
            <div style="display:flex;flex-direction:column;gap:12px;max-width:400px;">
                <?php foreach ($activeSeasons as $s): ?>
                    <a href="/admin/close-season?season=<?= htmlspecialchars($s['season_id']) ?>" class="btn btn-primary" style="text-align:center;padding:16px;font-size:1.1rem;">
                        🏁 Close <?= htmlspecialchars($s['season_name'] ?: strtoupper($s['season_id'])) ?>
                        <small style="display:block;font-weight:600;opacity:0.85;font-size:0.75rem;margin-top:4px;">
                            <?= htmlspecialchars(strtoupper($s['season_id'])) ?> ·
                            <?= htmlspecialchars($s['status']) ?> ·
                            <?= (int)$s['gp_count'] ?> GP<?= (int)$s['gp_count'] === 1 ? '' : 's' ?> raced
                        </small>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    include __DIR__ . '/../../private/templates/footer.php';
    exit;
}

// ── Load season ───────────────────────────────────────────────────────────
$season = getSeasonRules($pdo, $seasonId);

if (!$season) {
    die("Season not found: " . htmlspecialchars($seasonId));
}

$isMH = ($season['scoring_system'] === 'monster_hunt');

// ── Handle POST actions ───────────────────────────────────────────────────
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_champion') {
        $championName = trim($_POST['champion_name'] ?? '');
        $championChar = trim($_POST['champion_char'] ?? 'Mii');
        $stmt = $pdo->prepare("UPDATE season_meta SET champion_name=?, champion_char=? WHERE season_id=?");
        $stmt->execute([$championName, $championChar, $seasonId]);
        $season['champion_name'] = $championName;
        $season['champion_char'] = $championChar;
        $message = 'Champion saved.';
    }

    if ($action === 'save_report') {
        $ecologyReport = trim($_POST['ecology_report'] ?? '');
        $stmt = $pdo->prepare("UPDATE season_meta SET ecology_report=? WHERE season_id=?");
        $stmt->execute([$ecologyReport, $seasonId]);
        $season['ecology_report'] = $ecologyReport;
        $message = 'Season narrative saved.';
    }

    if ($action === 'finalize') {
        verify_csrf();
        $championName  = trim($_POST['champion_name']  ?? $season['champion_name']  ?? '');
        $championChar  = trim($_POST['champion_char']  ?? $season['champion_char']  ?? 'Mii');
        $ecologyReport = trim($_POST['ecology_report'] ?? $season['ecology_report'] ?? '');
        $stmt = $pdo->prepare("
            UPDATE season_meta
            SET status='archived', closed_at=CURRENT_TIMESTAMP,
                champion_name=?, champion_char=?, ecology_report=?
            WHERE season_id=?
        ");
        $stmt->execute([$championName, $championChar, $ecologyReport, $seasonId]);

        // Freeze the Mikkoliiga roster for this season so historical standings
        // don't shift retroactively if a member toggles their flag later.
        snapshotMikkoliigaMembership($pdo, $seasonId);
        snapshotSeasonPlacements($pdo, $seasonId);
        snapshotSeasonMap($pdo, $seasonId);   // Territory seasons: freeze the final map

        // Report generation is a POST+CSRF endpoint — a plain redirect can't
        // reach it, so hand off through the auto-submitting token bridge.
        csrf_bridge_post('/api/season-report', ['season' => $seasonId], 'Generate season report');
    }
}

// ── Compute standings for this season ─────────────────────────────────────
// Fetch all GPs and scores in season
$gpStmt = $pdo->prepare("
    SELECT r.id, r.name,
           COUNT(DISTINCT res.gpid) AS gp_count,
           AVG(res.gp_points) AS avg_score,
           MAX(res.gp_points) AS best_score,
           SUM(CASE WHEN res.gp_points = " . MK_MAX_GP_POINTS . " THEN 1 ELSE 0 END) AS perfects
    FROM racers r
    JOIN results res ON res.racer_id = r.id
    WHERE res.gpid LIKE ?
    GROUP BY r.id
    ORDER BY avg_score DESC
");
$gpStmt->execute([$seasonId . '%']);
$standings = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

$totalGPs = 0;
$totalGPsStmt = $pdo->prepare("SELECT COUNT(DISTINCT gpid) FROM results WHERE gpid LIKE ?");
$totalGPsStmt->execute([$seasonId . '%']);
$totalGPs = (int)$totalGPsStmt->fetchColumn();

$totalRacers = count($standings);
$leader      = $standings[0] ?? null;

// MONSTER HUNT XP standings if applicable
$mhStandings = [];
if ($isMH) {
    require_once __DIR__ . '/../../private/includes/elo_engine.php';
    $changelog = getMonsterHuntEloChangelog($pdo);
    $slay_xp        = (int)($season['mh_slay_xp']           ?? 100);
    $survive_xp     = (int)($season['mh_survive_xp']         ?? 20);
    $party_bonus_xp = (int)($season['mh_party_bonus_xp']     ?? 50);
    $monster_win_xp = (int)($season['mh_monster_win_xp']     ?? 80);
    $monster_part   = (int)($season['mh_monster_partial_xp'] ?? 30);
    $monster_loss   = (int)($season['mh_monster_loss_xp']    ?? -40);
    $bestX          = (int)($season['mh_best_x']             ?? 20);

    $racerXpLog = [];
    foreach (array_keys($changelog) as $gpid) {
        if (strpos($gpid, $seasonId) !== 0) continue;
        $gpData = $changelog[$gpid];
        if (count($gpData) < 2) continue;

        [$monsterName, $monsterOldElo] = pickMonster($gpid, $gpData, $pdo);
        $advElos = [];
        foreach ($gpData as $name => $d) { if ($name !== $monsterName) $advElos[] = $d['old_elo']; }
        $avgAdv = count($advElos) > 0 ? array_sum($advElos) / count($advElos) : $monsterOldElo;
        $gap    = max(0, $monsterOldElo - $avgAdv);
        $crMult = $gap < 50 ? 1.0 : ($gap < 150 ? 1.25 : ($gap < 300 ? 1.5 : 2.0));

        $monsterRank = $gpData[$monsterName]['rank'];
        $slayers   = []; $survivors = [];
        foreach ($gpData as $name => $d) {
            if ($name === $monsterName) continue;
            if ($d['rank'] < $monsterRank) $slayers[] = $name; else $survivors[] = $name;
        }
        $isFullSlay = (count($slayers) > 0 && count($survivors) === 0); // all beat Monster
        $isTPK      = empty($slayers);                                   // Monster beat all

        foreach ($gpData as $name => $d) {
            if ($name === $monsterName) {
                $xp = $isTPK ? $monster_win_xp : ($isFullSlay ? $monster_loss : $monster_part);
            } else {
                $xp = in_array($name, $slayers) ? (int)round($slay_xp * $crMult) + ($isFullSlay ? $party_bonus_xp : 0) : $survive_xp;
            }
            $racerXpLog[$name][] = $xp;
        }
    }
    foreach ($racerXpLog as $name => $xpList) {
        rsort($xpList);
        $bestN = array_slice($xpList, 0, $bestX);
        $mhStandings[] = ['name' => $name, 'total_xp' => array_sum($bestN), 'gp_count' => count($xpList)];
    }
    usort($mhStandings, fn($a, $b) => $b['total_xp'] <=> $a['total_xp']);
    $leader = !empty($mhStandings) ? ['name' => $mhStandings[0]['name']] : $leader;
}

// All characters for dropdown
require_once __DIR__ . '/../../private/includes/mk_data.php';
$characters = getMKCharacters();

$seasonLabel = $season['season_name'] ?: strtoupper($seasonId);

// Champion pre-fill
$championName = $season['champion_name'] ?: ($leader ? $leader['name'] : '');
$championChar = $season['champion_char'] ?: 'Mii';

$pageTitle = "Close Season: $seasonLabel";
$extraCss  = '<link rel="stylesheet" href="/assets/css/forms.css"><link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">

    <nav class="breadcrumb">
        <a href="/admin/seasons">← Seasons</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin/close-season">Transition Wizard</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current"><?= htmlspecialchars($seasonLabel) ?></span>
    </nav>

    <?php if ($season['status'] === 'archived'): ?>
    <div class="alert-success" style="margin:20px 0;">
        This season is already archived.
        <form method="POST" action="/api/season-report" style="display:inline; margin-left:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="season" value="<?= htmlspecialchars($seasonId) ?>">
            <button type="submit" class="btn btn-primary">Regenerate Report</button>
        </form>
        <a href="/admin/seasons" class="btn btn-secondary" style="margin-left:8px;">Back to Seasons</a>
    </div>
    <?php else: ?>

    <?php if ($message): ?><div class="alert-success" style="margin:16px 0;"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert-error"   style="margin:16px 0;"><?= htmlspecialchars($error)   ?></div><?php endif; ?>

    <!-- Progress bar -->
    <div class="stw-progress" id="stw-progress">
        <div class="stw-step active" data-step="1" onclick="goStep(1)"><span>1</span> Overview</div>
        <div class="stw-step"        data-step="2" onclick="goStep(2)"><span>2</span> Champion</div>
        <div class="stw-step"        data-step="3" onclick="goStep(3)"><span>3</span> Narrative</div>
        <div class="stw-step"        data-step="4" onclick="goStep(4)"><span>4</span> Finalize</div>
    </div>

    <!-- ─────────────────────────────────── STEP 1: OVERVIEW ──────── -->
    <div class="stw-panel" id="step-1">
        <h2 class="stw-step-title">📊 Season Overview</h2>
        <p class="stw-step-sub">Review the season at a glance before closing it.</p>

        <div class="stw-overview-grid">
            <div class="stw-ov-card">
                <div class="stw-ov-num"><?= $totalGPs ?></div>
                <div class="stw-ov-label">Grand Prix played</div>
            </div>
            <div class="stw-ov-card">
                <div class="stw-ov-num"><?= $totalRacers ?></div>
                <div class="stw-ov-label">Racers participated</div>
            </div>
            <?php if ($leader): ?>
            <div class="stw-ov-card stw-ov-card--gold">
                <div class="stw-ov-num">🏆</div>
                <div class="stw-ov-label">Current leader: <strong><?= htmlspecialchars($leader['name']) ?></strong></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Season standings preview -->
        <?php if (!empty($isMH ? $mhStandings : $standings)): ?>
        <h3 style="color:var(--gray-500);font-size:0.85rem;text-transform:uppercase;letter-spacing:1px;margin:24px 0 12px;">
            <?= $isMH ? 'XP Standings' : 'Score Standings' ?>
        </h3>
        <table class="admin-table" style="max-width:500px;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Racer</th>
                    <th><?= $isMH ? 'Total XP' : 'Avg Score' ?></th>
                    <th>GPs</th>
                </tr>
            </thead>
            <tbody>
                <?php $rows = $isMH ? $mhStandings : $standings; ?>
                <?php foreach (array_slice($rows, 0, 8) as $i => $s): ?>
                <tr <?= $i === 0 ? 'style="font-weight:900;color:#ffd700;"' : '' ?>>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($s['name']) ?></td>
                    <td><?= $isMH ? number_format($s['total_xp']) : round($s['avg_score'], 1) ?></td>
                    <td><?= $s['gp_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="stw-nav">
            <button class="btn btn-primary" onclick="goStep(2)">Next: Set Champion →</button>
        </div>
    </div>

    <!-- ─────────────────────────────────── STEP 2: CHAMPION ──────── -->
    <div class="stw-panel hidden" id="step-2">
        <h2 class="stw-step-title">🏆 Champion Selection</h2>
        <p class="stw-step-sub">Confirm who won <?= htmlspecialchars($seasonLabel) ?> and what character they used.</p>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action"    value="save_champion">
            <input type="hidden" name="season_id" value="<?= htmlspecialchars($seasonId) ?>">

            <div class="form-grid" style="max-width:500px;">
                <div class="form-field">
                    <label>Champion Name</label>
                    <input type="text" name="champion_name"
                           value="<?= htmlspecialchars($championName) ?>"
                           placeholder="e.g., Knut"
                           list="racer-datalist" required>
                    <datalist id="racer-datalist">
                        <?php foreach ($standings as $s): ?>
                            <option value="<?= htmlspecialchars($s['name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <small>Pre-filled with season leader. Edit if needed.</small>
                </div>
                <div class="form-field">
                    <label>Champion's Character</label>
                    <select name="champion_char">
                        <?php foreach ($characters as $char): ?>
                            <option value="<?= htmlspecialchars($char) ?>" <?= $char === $championChar ? 'selected' : '' ?>>
                                <?= htmlspecialchars($char) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>The character they used most / are known for.</small>
                </div>
            </div>

            <div class="stw-nav">
                <button type="button" class="btn btn-secondary" onclick="goStep(1)">← Back</button>
                <button type="submit" class="btn btn-primary">💾 Save Champion</button>
                <button type="button" class="btn btn-primary" onclick="goStep(3)" style="margin-left:8px;">Next: Narrative →</button>
            </div>
        </form>
    </div>

    <!-- ─────────────────────────────────── STEP 3: NARRATIVE ─────── -->
    <div class="stw-panel hidden" id="step-3">
        <h2 class="stw-step-title">📜 Season Narrative</h2>
        <p class="stw-step-sub">Write a closing ecology report or summary for the record. This appears in the Hall of Fame and season report.</p>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action"    value="save_report">
            <input type="hidden" name="season_id" value="<?= htmlspecialchars($seasonId) ?>">

            <div class="form-field">
                <label>Season Summary / Ecology Report</label>
                <textarea name="ecology_report" rows="8" style="width:100%;max-width:700px;padding:12px;background:#111;color:#eee;border:1px solid #333;border-radius:8px;font-size:0.9rem;line-height:1.6;resize:vertical;"
                    placeholder="Write a brief narrative of this season — key moments, rivalries, upset victories, the state of the field...  No pressure on length."><?= htmlspecialchars($season['ecology_report'] ?? '') ?></textarea>
                <small>Optional. Leave blank if you prefer to generate it with AI from the Seasons admin page.</small>
            </div>

            <div class="stw-nav">
                <button type="button" class="btn btn-secondary" onclick="goStep(2)">← Back</button>
                <button type="submit" class="btn btn-primary">💾 Save Narrative</button>
                <button type="button" class="btn btn-primary" onclick="goStep(4)" style="margin-left:8px;">Next: Finalize →</button>
            </div>
        </form>
    </div>

    <!-- ─────────────────────────────────── STEP 4: FINALIZE ──────── -->
    <div class="stw-panel hidden" id="step-4">
        <h2 class="stw-step-title">🏁 Finalize &amp; Archive</h2>
        <p class="stw-step-sub">This will close the season permanently and generate the season report. <strong>This cannot be undone</strong> without an admin re-opening it.</p>

        <div class="stw-finalize-summary">
            <div class="stw-fs-row">
                <span>Season</span>
                <strong><?= htmlspecialchars($seasonLabel) ?></strong>
            </div>
            <div class="stw-fs-row">
                <span>GPs Played</span>
                <strong><?= $totalGPs ?></strong>
            </div>
            <div class="stw-fs-row">
                <span>Champion</span>
                <strong id="summary-champion"><?= htmlspecialchars($season['champion_name'] ?: '— not set') ?></strong>
            </div>
            <div class="stw-fs-row">
                <span>Character</span>
                <strong id="summary-char"><?= htmlspecialchars($season['champion_char'] ?: 'Mii') ?></strong>
            </div>
            <div class="stw-fs-row">
                <span>Narrative</span>
                <strong><?= empty($season['ecology_report']) ? '— none (can be AI-generated later)' : '✓ Written' ?></strong>
            </div>
        </div>

        <form method="POST" id="finalize-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action"         value="finalize">
            <input type="hidden" name="season_id"      value="<?= htmlspecialchars($seasonId) ?>">
            <input type="hidden" name="champion_name"  value="<?= htmlspecialchars($season['champion_name'] ?? '') ?>">
            <input type="hidden" name="champion_char"  value="<?= htmlspecialchars($season['champion_char'] ?? 'Mii') ?>">
            <input type="hidden" name="ecology_report" value="<?= htmlspecialchars($season['ecology_report'] ?? '') ?>">

            <div class="stw-finalize-warning">
                ⚠️ The season status will be set to <strong>ARCHIVED</strong> and the AI season report will be generated immediately.
            </div>

            <div class="stw-nav">
                <button type="button" class="btn btn-secondary" onclick="goStep(3)">← Back</button>
                <button type="button" class="btn btn-danger stw-finalize-btn" onclick="confirmFinalize()">
                    🏁 Archive Season &amp; Generate Report
                </button>
            </div>
        </form>
    </div>

    <?php endif; // season not already archived ?>
</div>

<style>
/* ── Season Transition Wizard ────────────────────────────────────────── */
.stw-progress {
    display: flex;
    gap: 0;
    margin: 24px 0 32px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #2a2a2a;
}

.stw-step {
    flex: 1;
    padding: 12px 8px;
    text-align: center;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--gray-600);
    background: #111;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-right: 1px solid #2a2a2a;
    transition: background 0.2s, color 0.2s;
    user-select: none;
}
.stw-step:last-child { border-right: none; }
.stw-step span {
    width: 22px; height: 22px;
    background: #2a2a2a;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 900;
    flex-shrink: 0;
}
.stw-step.active {
    background: #1a0a0a;
    color: var(--nintendo-red);
    border-bottom: 3px solid var(--nintendo-red);
}
.stw-step.active span { background: var(--nintendo-red); color: #fff; }
.stw-step:hover:not(.active) { background: #181818; color: var(--gray-500); }

.stw-panel { margin-top: 8px; }
.stw-panel.hidden { display: none; }

.stw-step-title {
    font-size: 1.3rem;
    font-weight: 900;
    color: #eee;
    margin-bottom: 6px;
}
.stw-step-sub { color: #888; margin-bottom: 24px; }

.stw-overview-grid {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.stw-ov-card {
    background: #111;
    border: 1px solid #2a2a2a;
    border-radius: 12px;
    padding: 18px 24px;
    text-align: center;
    min-width: 120px;
}
.stw-ov-card--gold { border-color: #5a4a00; }
.stw-ov-num { font-size: 2rem; font-weight: 900; color: var(--nintendo-red); }
.stw-ov-card--gold .stw-ov-num { color: #ffd700; font-size: 1.4rem; }
.stw-ov-label { font-size: 0.75rem; color: #888; margin-top: 4px; }

.stw-nav {
    display: flex;
    gap: 10px;
    margin-top: 28px;
    flex-wrap: wrap;
    align-items: center;
}

/* Finalize summary */
.stw-finalize-summary {
    background: #111;
    border: 1px solid #2a2a2a;
    border-radius: 12px;
    padding: 20px 24px;
    max-width: 480px;
    margin-bottom: 24px;
}
.stw-fs-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #1e1e1e;
    font-size: 0.88rem;
}
.stw-fs-row:last-child { border-bottom: none; }
.stw-fs-row span { color: #888; }
.stw-fs-row strong { color: #eee; }

.stw-finalize-warning {
    background: #2a1a00;
    border: 1px solid #5a3a00;
    color: #ffcc77;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 0.85rem;
    margin-bottom: 20px;
    max-width: 520px;
}

.stw-finalize-btn {
    background: #b00000 !important;
    font-size: 1rem;
    padding: 14px 28px !important;
}
.stw-finalize-btn:hover { background: #cc0000 !important; }

.btn-danger {
    background: var(--nintendo-red);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 800;
    cursor: pointer;
    transition: background 0.2s;
}
</style>

<script>
function goStep(n) {
    document.querySelectorAll('.stw-panel').forEach(p => p.classList.add('hidden'));
    document.querySelectorAll('.stw-step').forEach(s => s.classList.remove('active'));
    document.getElementById('step-' + n).classList.remove('hidden');
    document.querySelector('[data-step="' + n + '"]').classList.add('active');
    window.scrollTo({ top: document.getElementById('stw-progress').offsetTop - 80, behavior: 'smooth' });
}

function confirmFinalize() {
    if (window.showConfirm) {
        window.showConfirm({
            icon: '🏁',
            title: 'Archive Season?',
            message: 'This will permanently close the season and generate the AI season report. Are you ready?'
        }).then(confirmed => {
            if (confirmed) document.getElementById('finalize-form').submit();
        });
    } else {
        if (confirm('Archive this season and generate the report?')) {
            document.getElementById('finalize-form').submit();
        }
    }
}
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
