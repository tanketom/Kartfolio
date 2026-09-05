<?php
/**
 * Season Management - Enhanced Rules & Multi-System Scoring
 * Path: /cdnmk/public_html/admin/seasons.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

require_admin();

// 1. Ensure Table and Columns exist (Enhanced Rules Engine)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS season_meta (
        season_id TEXT PRIMARY KEY,
        status TEXT DEFAULT 'active',
        ecology_report TEXT DEFAULT NULL,
        closed_at DATETIME DEFAULT NULL,

        -- Legacy scoring (average_attendance)
        attendance_weight FLOAT DEFAULT 1.0,
        weekly_bonus_cap INTEGER DEFAULT 2,
        min_races_threshold INTEGER DEFAULT 3,
        drop_rate INTEGER DEFAULT 10,

        -- New scoring system fields
        scoring_system TEXT DEFAULT 'average_attendance',
        academic_term TEXT,
        academic_year INTEGER,
        start_week INTEGER,
        end_week INTEGER,
        start_date DATE,
        end_date DATE,
        grace_period_end DATE,
        finals_week INTEGER,
        cups_required INTEGER DEFAULT 12,
        allow_retries BOOLEAN DEFAULT 1,
        best_n_count INTEGER DEFAULT 15,
        drop_worst_count INTEGER DEFAULT 2,
        perfect_multiplier FLOAT DEFAULT 2.0,
        random_cups_assigned TEXT,
        season_name TEXT,
        season_description TEXT
    )");
} catch (PDOException $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// Add MONSTER HUNT columns for existing databases
$mhColDefaults = [
    'mh_slay_xp'          => 100,
    'mh_survive_xp'       => 20,
    'mh_party_bonus_xp'   => 50,
    'mh_monster_win_xp'   => 80,
    'mh_monster_partial_xp' => 30,
    'mh_monster_loss_xp'  => -40,
    'mh_min_gps'          => 6,
    'mh_best_x'           => 20,
];
foreach ($mhColDefaults as $col => $default) {
    try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN $col INTEGER DEFAULT $default"); }
    catch (PDOException $e) {} // Column already exists
}

// Teams: how many members count toward each GP (constructor scoring).
try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN team_best_n INTEGER DEFAULT 2"); }
catch (PDOException $e) {}

// Bounty Hunter columns
$bhColDefaults = [
    'bh_multiplier'    => "FLOAT DEFAULT 1.0",     // bounty point multiplier
    'bh_carrying_cost' => "INTEGER DEFAULT 0",     // 0 = off, 1 = on (your bounty subtracts from your score)
    'bh_weekly_reset'  => "INTEGER DEFAULT 0",     // 0 = season-long, 1 = recompute median weekly
];
foreach ($bhColDefaults as $col => $type) {
    try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN $col $type"); }
    catch (PDOException $e) {}
}

// Pari-Mutuel columns
$pmColDefaults = [
    'pm_ante'           => "INTEGER DEFAULT 100",
    'pm_payout_preset'  => "TEXT DEFAULT 'steep'", // 'steep' | 'medium' | 'flat'
];
foreach ($pmColDefaults as $col => $type) {
    try { $pdo->exec("ALTER TABLE season_meta ADD COLUMN $col $type"); }
    catch (PDOException $e) {}
}

$message = "";
$error = "";

// 2. Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_season') {
        // Create new season
        $seasonId = $_POST['new_season_id'];
        $scoringSystem = $_POST['scoring_system'];
        $academicYear = (int)$_POST['academic_year'];
        $seasonName = $_POST['season_name'];
        $seasonDesc = $_POST['season_description'];

        try {
            // Insert new season
            $stmt = $pdo->prepare("
                INSERT INTO season_meta (
                    season_id, status, scoring_system, academic_year,
                    season_name, season_description,
                    cups_required, best_n_count, drop_worst_count, perfect_multiplier,
                    attendance_weight, weekly_bonus_cap, min_races_threshold, drop_rate,
                    mh_slay_xp, mh_survive_xp, mh_party_bonus_xp,
                    mh_monster_win_xp, mh_monster_partial_xp, mh_monster_loss_xp, mh_min_gps, mh_best_x
                ) VALUES (?, 'upcoming', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // Defaults per system come from newSeasonDefaults(); a knob is taken
            // from the form only when it belongs to the chosen system.
            $d = newSeasonDefaults($scoringSystem);
            $cupsRequired      = ($scoringSystem === 'cup_based')    ? (int)$_POST['cups_required']       : $d['cups_required'];
            $bestNCount        = ($scoringSystem === 'best_n_gps')   ? (int)$_POST['best_n_count']        : $d['best_n_count'];
            $dropWorstCount    = ($scoringSystem === 'drop_worst')   ? (int)$_POST['drop_worst_count']    : $d['drop_worst_count'];
            $perfectMultiplier = ($scoringSystem === 'perfect_hunt') ? (float)$_POST['perfect_multiplier'] : $d['perfect_multiplier'];
            $attWeight    = $d['attendance_weight'];
            $weeklyCap    = $d['weekly_bonus_cap'];
            $minThreshold = $d['min_races_threshold'];
            $dropRate     = $d['drop_rate'];

            // MONSTER HUNT fields
            $mh = fn(string $k) => $scoringSystem === 'monster_hunt' ? (int)($_POST[$k] ?? $d[$k]) : $d[$k];
            $mhSlayXP    = $mh('mh_slay_xp');
            $mhSurviveXP = $mh('mh_survive_xp');
            $mhPartyXP   = $mh('mh_party_bonus_xp');
            $mhMonWin    = $mh('mh_monster_win_xp');
            $mhMonPart   = $mh('mh_monster_partial_xp');
            $mhMonLoss   = $mh('mh_monster_loss_xp');
            $mhMinGPs    = $mh('mh_min_gps');
            $mhBestX     = max(1, $mh('mh_best_x'));

            $stmt->execute([
                $seasonId, $scoringSystem, $academicYear,
                $seasonName, $seasonDesc,
                $cupsRequired, $bestNCount, $dropWorstCount, $perfectMultiplier,
                $attWeight, $weeklyCap, $minThreshold, $dropRate,
                $mhSlayXP, $mhSurviveXP, $mhPartyXP,
                $mhMonWin, $mhMonPart, $mhMonLoss, $mhMinGPs, $mhBestX
            ]);

            $message = "Season " . strtoupper($seasonId) . " created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating season: " . $e->getMessage();
        }
    }

    if ($action === 'save_rules') {
        $sid = $_POST['season_id'];
        $scoringSystem = $_POST['scoring_system'];

        try {
            // Build dynamic UPDATE query based on scoring system
            $updateFields = [
                'scoring_system' => $scoringSystem,
                'season_name' => $_POST['season_name'] ?? '',
                'season_description' => $_POST['season_description'] ?? '',
                'academic_year' => $_POST['academic_year'] ?? null,
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null,
            ];

            // Add system-specific fields
            if ($scoringSystem === 'average_attendance') {
                $updateFields['attendance_weight'] = $_POST['att_w'];
                $updateFields['weekly_bonus_cap'] = $_POST['cap'];
                $updateFields['min_races_threshold'] = $_POST['thresh'];
                $updateFields['drop_rate'] = $_POST['drop'];
            } elseif ($scoringSystem === 'cup_based') {
                $updateFields['cups_required'] = $_POST['cups_required'];
                $updateFields['allow_retries'] = isset($_POST['allow_retries']) ? 1 : 0;
            } elseif ($scoringSystem === 'best_n_gps') {
                $updateFields['best_n_count'] = $_POST['best_n_count'];
            } elseif ($scoringSystem === 'drop_worst') {
                $updateFields['cups_required'] = $_POST['cups_required'];
                $updateFields['drop_worst_count'] = $_POST['drop_worst_count'];
            } elseif ($scoringSystem === 'perfect_hunt') {
                $updateFields['cups_required'] = $_POST['cups_required'];
                $updateFields['perfect_multiplier'] = $_POST['perfect_multiplier'];
            } elseif ($scoringSystem === 'monster_hunt') {
                $updateFields['mh_slay_xp']          = (int)($_POST['mh_slay_xp']          ?? 100);
                $updateFields['mh_survive_xp']        = (int)($_POST['mh_survive_xp']        ?? 20);
                $updateFields['mh_party_bonus_xp']    = (int)($_POST['mh_party_bonus_xp']    ?? 50);
                $updateFields['mh_monster_win_xp']    = (int)($_POST['mh_monster_win_xp']    ?? 80);
                $updateFields['mh_monster_partial_xp'] = (int)($_POST['mh_monster_partial_xp'] ?? 30);
                $updateFields['mh_monster_loss_xp']   = (int)($_POST['mh_monster_loss_xp']   ?? -40);
                $updateFields['mh_min_gps']            = (int)($_POST['mh_min_gps']            ?? 6);
                $updateFields['mh_best_x']             = max(1, (int)($_POST['mh_best_x']      ?? 20));
            } elseif ($scoringSystem === 'bounty_hunter') {
                $updateFields['bh_multiplier']    = max(0.1, (float)($_POST['bh_multiplier'] ?? 1.0));
                $updateFields['bh_carrying_cost'] = !empty($_POST['bh_carrying_cost']) ? 1 : 0;
                $updateFields['bh_weekly_reset']  = !empty($_POST['bh_weekly_reset'])  ? 1 : 0;
            } elseif ($scoringSystem === 'pari_mutuel') {
                $updateFields['pm_ante']          = max(1, (int)($_POST['pm_ante'] ?? 100));
                $preset = $_POST['pm_payout_preset'] ?? 'steep';
                $updateFields['pm_payout_preset'] = in_array($preset, ['steep', 'medium', 'flat'], true) ? $preset : 'steep';
            } elseif ($scoringSystem === 'positional_points') {
                $mode = $_POST['pos_mode'] ?? 'best_n';
                $updateFields['pos_mode']            = in_array($mode, ['best_n', 'average', 'sum'], true) ? $mode : 'best_n';
                $updateFields['best_n_count']        = max(1, (int)($_POST['pos_best_n'] ?? 15));
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['pos_thresh'] ?? 3));
            } elseif ($scoringSystem === 'head_to_head') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['h2h_thresh'] ?? 3));
                $updateFields['h2h_npc_weight']      = max(0.0, min(1.0, (float)($_POST['h2h_npc_weight'] ?? 0.25)));
            } elseif ($scoringSystem === 'blue_shell') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['bs_thresh'] ?? 3));
                $updateFields['bs_rate'] = max(0.0, min(1.0, (float)($_POST['bs_rate'] ?? 0.10)));
                $updateFields['bs_cap']  = max(1.0, min(5.0, (float)($_POST['bs_cap']  ?? 2.0)));
            } elseif ($scoringSystem === 'territory') {
                $updateFields['tt_decay_gps'] = max(0, min(20, (int)($_POST['tt_decay_gps'] ?? 4)));
            } elseif ($scoringSystem === 'median') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['md_thresh'] ?? 3));
            } elseif ($scoringSystem === 'hard_mode') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['hm_thresh'] ?? 3));
                $updateFields['hm_cap'] = max(1.0, min(5.0, (float)($_POST['hm_cap'] ?? 2.0)));
            } elseif ($scoringSystem === 'kart_bingo') {
                $updateFields['bg_line_pts'] = max(1, min(1000, (int)($_POST['bg_line_pts'] ?? 100)));
                $updateFields['bg_card_pts'] = max(0, min(5000, (int)($_POST['bg_card_pts'] ?? 500)));
            } elseif ($scoringSystem === 'price_is_right') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['pir_thresh'] ?? 3));
                $updateFields['pir_target'] = in_array($_POST['pir_target'] ?? 'median', ['median', 'mean'], true) ? $_POST['pir_target'] : 'median';
                $updateFields['pir_best_n'] = max(1, min(60, (int)($_POST['pir_best_n'] ?? 15)));
            } elseif ($scoringSystem === 'equaliser') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['eq_thresh'] ?? 3));
                $updateFields['eq_mode'] = in_array($_POST['eq_mode'] ?? 'season', ['season', 'per_gp'], true) ? $_POST['eq_mode'] : 'season';
            } elseif ($scoringSystem === 'form') {
                $updateFields['min_races_threshold'] = max(0, (int)($_POST['form_thresh'] ?? 3));
                $updateFields['form_window'] = max(1, min(50, (int)($_POST['form_window'] ?? 8)));
            }

            // Build SQL
            $setClauses = [];
            $values = [];
            foreach ($updateFields as $field => $value) {
                $setClauses[] = "$field = ?";
                $values[] = $value;
            }
            $values[] = $sid;

            $sql = "UPDATE season_meta SET " . implode(', ', $setClauses) . " WHERE season_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            $message = "Scoring rules for " . strtoupper($sid) . " have been updated!";
        } catch (PDOException $e) {
            $error = "Error updating rules: " . $e->getMessage();
        }
    }

    if ($action === 'archive') {
        $sid = $_POST['season_id'];
        $stmt = $pdo->prepare("UPDATE season_meta SET status='archived', closed_at=CURRENT_TIMESTAMP WHERE season_id = ?");
        $stmt->execute([$sid]);
        // Freeze Mikkoliiga roster and final placements at archive time.
        snapshotMikkoliigaMembership($pdo, $sid);
        snapshotSeasonPlacements($pdo, $sid);
        snapshotSeasonMap($pdo, $sid);   // Territory seasons: freeze the final map
        // Report generation is a POST+CSRF endpoint — a plain redirect can't
        // reach it, so hand off through the auto-submitting token bridge.
        csrf_bridge_post('/api/season-report', ['season' => $sid], 'Generate season report');
    }

    if ($action === 'generate_report') {
        // (Re)generate an archived season's report — same POST bridge.
        csrf_bridge_post('/api/season-report', ['season' => $_POST['season_id']], 'Generate season report');
    }

    if ($action === 'snapshot_mikkoliiga') {
        $sid = $_POST['season_id'];
        $count = snapshotMikkoliigaMembership($pdo, $sid);
        $message = "Mikkoliiga snapshot for " . strtoupper($sid) . " refreshed — $count member(s) frozen.";
    }

    if ($action === 'activate') {
        $sid = $_POST['season_id'];
        $stmt = $pdo->prepare("UPDATE season_meta SET status='active' WHERE season_id = ?");
        $stmt->execute([$sid]);
        $message = "Season " . strtoupper($sid) . " is now ACTIVE.";
    }

    if ($action === 'delete_season') {
        $sid = $_POST['season_id'];
        // Guard: refuse if the season has any race results on record.
        $check = $pdo->prepare("SELECT COUNT(*) FROM results WHERE gpid LIKE ?");
        $check->execute([$sid . '%']);
        if ((int)$check->fetchColumn() > 0) {
            $error = "Cannot delete " . strtoupper($sid) . ": race results exist for this season. Archive it instead.";
        } else {
            $del = $pdo->prepare("DELETE FROM season_meta WHERE season_id = ?");
            $del->execute([$sid]);
            $message = "Season " . strtoupper($sid) . " deleted.";
        }
    }

    // ── Teams (constructor season layer, managed per season) ──────────────
    if ($action === 'create_team') {
        $sid    = $_POST['season_id'];
        $tname  = trim((string)($_POST['team_name'] ?? ''));
        $tcolor = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['team_color'] ?? '') ? $_POST['team_color'] : '#e60012';
        if ($tname !== '') {
            $pdo->prepare("INSERT INTO teams (season_id, name, color) VALUES (?, ?, ?)")->execute([$sid, $tname, $tcolor]);
            $message = "Team \"$tname\" created for " . strtoupper($sid) . ".";
        } else {
            $error = "Team name is required.";
        }
    }

    if ($action === 'delete_team') {
        $sid = $_POST['season_id'];
        $tid = (int)($_POST['team_id'] ?? 0);
        $pdo->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$tid]);
        $pdo->prepare("DELETE FROM teams WHERE id = ? AND season_id = ?")->execute([$tid, $sid]);
        $message = "Team deleted.";
    }

    if ($action === 'add_team_member') {
        $sid = $_POST['season_id'];
        $tid = (int)($_POST['team_id'] ?? 0);
        $rid = (int)($_POST['racer_id'] ?? 0);
        if ($tid && $rid) {
            $valid = $pdo->prepare("SELECT 1 FROM teams WHERE id = ? AND season_id = ?");
            $valid->execute([$tid, $sid]);
            if ($valid->fetchColumn()) {
                // One team per racer per season — replace any existing assignment.
                $pdo->prepare("DELETE FROM team_members WHERE season_id = ? AND racer_id = ?")->execute([$sid, $rid]);
                $pdo->prepare("INSERT INTO team_members (season_id, team_id, racer_id) VALUES (?, ?, ?)")->execute([$sid, $tid, $rid]);
                $message = "Roster updated.";
            }
        }
    }

    if ($action === 'remove_team_member') {
        $sid = $_POST['season_id'];
        $rid = (int)($_POST['racer_id'] ?? 0);
        $pdo->prepare("DELETE FROM team_members WHERE season_id = ? AND racer_id = ?")->execute([$sid, $rid]);
        $message = "Member removed.";
    }

    if ($action === 'set_team_best_n') {
        $sid = $_POST['season_id'];
        $n   = max(1, min(12, (int)($_POST['team_best_n'] ?? 2)));
        $pdo->prepare("UPDATE season_meta SET team_best_n = ? WHERE season_id = ?")->execute([$n, $sid]);
        $message = "Teams now score best $n members per GP for " . strtoupper($sid) . ".";
    }
}

// 3. Fetch Data
// Seasons with results
$stmt = $pdo->query("SELECT DISTINCT SUBSTR(gpid, 1, 3) as id FROM results ORDER BY id DESC");
$resultSeasons = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Seasons from metadata (includes newly created seasons with no results yet)
$metaStmt = $pdo->query("SELECT * FROM season_meta ORDER BY season_id DESC");
$metaData = [];
while ($row = $metaStmt->fetch(PDO::FETCH_ASSOC)) {
    $metaData[$row['season_id']] = $row;
}

// Merge both sources: seasons with results + seasons in meta (no duplicates)
$allSeasons = array_values(array_unique(array_merge($resultSeasons, array_keys($metaData))));
usort($allSeasons, fn($a, $b) => strcmp($b, $a)); // DESC order

// Get next season ID (handles both s## and ps## formats)
$lastSeasonNum = 0;
$lastPreSeasonNum = 0;
foreach (array_keys($metaData) as $sid) {
    if (preg_match('/^s(\d+)$/', $sid, $matches)) {
        $lastSeasonNum = max($lastSeasonNum, (int)$matches[1]);
    }
    if (preg_match('/^ps(\d+)$/', $sid, $matches)) {
        $lastPreSeasonNum = max($lastPreSeasonNum, (int)$matches[1]);
    }
}
$nextSeasonId = 's' . str_pad($lastSeasonNum + 1, 2, '0', STR_PAD_LEFT);
$nextPreSeasonId = 'ps' . str_pad($lastPreSeasonNum + 1, 2, '0', STR_PAD_LEFT);

// Scoring system definitions — derived from the canonical registry in
// gp_logic.php. This keeps the admin dropdowns and the public /scoring +
// /index labels using the *same* names and descriptions. Previously
// each location maintained its own copy and they drifted. One source of
// truth, no sync needed.
$scoringSystems = [];
foreach (getScoringSystemRegistry() as $key => $def) {
    // Resolve dynamic name/description (some use closures keyed on $rules
    // to inject the configured value — we pass an empty rules array to
    // surface the default copy).
    $scoringSystems[$key] = [
        'name'             => is_callable($def['name'])        ? ($def['name'])([])        : $def['name'],
        'description'      => is_callable($def['description']) ? ($def['description'])([]) : $def['description'],
        'long_description' => $def['long_description'] ?? '',
        'icon'             => $def['icon'],
    ];
}

$pageTitle = "Manage Seasons";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Season Management</span>
    </nav>

    <header class="admin-page-header">
        <div>
            <h1 class="admin-page-title">SEASON RULES & ARCHIVES</h1>
            <p class="admin-page-subtitle">Configure scoring systems and manage season lifecycle.</p>
        </div>
        <a href="/season-archives" class="btn-primary admin-btn-dark">VIEW HALL OF FAME</a>
    </header>

    <?php if($message): ?>
        <div class="alert-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="alert-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Scoring Simulator -->
    <div class="season-card simulator-card">
        <div class="season-header sim-header-toggle" onclick="document.getElementById('simulator-body').style.display = document.getElementById('simulator-body').style.display === 'none' ? 'block' : 'none';">
            <div class="season-title-row">
                <div>
                    <h2 class="season-title">🧪 Scoring Simulator</h2>
                    <p class="admin-new-season-subtitle">Preview how different scoring systems affect standings. Click to expand.</p>
                </div>
                <span class="badge badge--tool">TOOL</span>
            </div>
        </div>

        <div id="simulator-body" style="display: none;">
            <div class="season-config">
                <div class="config-section">
                    <div class="form-grid">
                        <div class="form-field">
                            <label>Scoring System</label>
                            <select id="sim-system" onchange="onSimSelectionChange()" class="scoring-system-select">
                                <?php foreach($scoringSystems as $key => $info): ?>
                                    <?php if ($key === 'random_cup_draw') continue; ?>
                                    <option value="<?= $key ?>"><?= $info['icon'] ?> <?= $info['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Source Season</label>
                            <select id="sim-season" onchange="onSimSelectionChange()">
                                <option value="">-- Use sample data --</option>
                                <?php foreach ($allSeasons as $sid): ?>
                                    <option value="<?= htmlspecialchars($sid) ?>">Season <?= strtoupper($sid) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field" id="sim-param-n" style="display:none;">
                            <label>Best N Count</label>
                            <input type="number" id="sim-best-n" value="15" min="1" max="100" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-drop" style="display:none;">
                            <label>Drop Worst N</label>
                            <input type="number" id="sim-drop-n" value="2" min="0" max="10" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-mult" style="display:none;">
                            <label>Perfect Multiplier</label>
                            <input type="number" id="sim-multiplier" value="2.0" step="0.1" min="1" max="5" onchange="updateSimulator()">
                        </div>

                        <?php // Positional Points ?>
                        <div class="form-field" id="sim-param-pos-mode" style="display:none;">
                            <label>Aggregation</label>
                            <select id="sim-pos-mode" onchange="updateSimulator()">
                                <option value="best_n">Best N nights</option>
                                <option value="average">Per-GP average</option>
                                <option value="sum">Season sum</option>
                            </select>
                        </div>
                        <div class="form-field" id="sim-param-pos-n" style="display:none;">
                            <label>Positional Best N</label>
                            <input type="number" id="sim-pos-best-n" value="15" min="1" max="100" onchange="updateSimulator()">
                        </div>

                        <?php // Bounty Hunter ?>
                        <div class="form-field" id="sim-param-bh-mult" style="display:none;">
                            <label>Bounty Multiplier</label>
                            <input type="number" id="sim-bh-multiplier" value="1.0" step="0.1" min="0.1" max="5.0" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-bh-cost" style="display:none;">
                            <label>Carrying Cost</label>
                            <select id="sim-bh-carrying-cost" onchange="updateSimulator()">
                                <option value="0">Off (just collect)</option>
                                <option value="1">On (your bounty subtracts)</option>
                            </select>
                        </div>

                        <?php // Pari-Mutuel ?>
                        <div class="form-field" id="sim-param-pm-ante" style="display:none;">
                            <label>Ante per GP</label>
                            <input type="number" id="sim-pm-ante" value="100" min="1" max="1000" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-pm-preset" style="display:none;">
                            <label>Payout Curve</label>
                            <select id="sim-pm-payout-preset" onchange="updateSimulator()">
                                <option value="steep">Steep</option>
                                <option value="medium">Medium</option>
                                <option value="flat">Flat</option>
                            </select>
                        </div>

                        <?php // Head-to-Head ?>
                        <div class="form-field" id="sim-param-h2h-w" style="display:none;">
                            <label>CPU Kart Weight</label>
                            <input type="number" id="sim-h2h-npc-weight" value="0.25" min="0" max="1" step="0.05" onchange="updateSimulator()">
                        </div>

                        <?php // Blue Shell / Hard Mode / Form ?>
                        <div class="form-field" id="sim-param-bs-rate" style="display:none;">
                            <label>Catch-up Rate</label>
                            <input type="number" id="sim-bs-rate" value="0.10" min="0" max="1" step="0.01" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-bs-cap" style="display:none;">
                            <label>Multiplier Cap</label>
                            <input type="number" id="sim-bs-cap" value="2.0" min="1" max="5" step="0.1" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-tt-decay" style="display:none;">
                            <label>Undefended GPs before decay</label>
                            <input type="number" id="sim-tt-decay" value="4" min="0" max="20" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-bg-line" style="display:none;"><label>Points per line</label><input type="number" id="sim-bg-line" value="100" min="1" max="1000" onchange="updateSimulator()"></div>
                        <div class="form-field" id="sim-param-bg-card" style="display:none;"><label>Full-card bonus</label><input type="number" id="sim-bg-card" value="500" min="0" max="5000" onchange="updateSimulator()"></div>
                        <div class="form-field" id="sim-param-pir-target" style="display:none;"><label>Target</label><select id="sim-pir-target" onchange="updateSimulator()"><option value="median">Median</option><option value="mean">Mean</option></select></div>
                        <div class="form-field" id="sim-param-pir-n" style="display:none;"><label>Best N GPs</label><input type="number" id="sim-pir-n" value="15" min="1" max="60" onchange="updateSimulator()"></div>
                        <div class="form-field" id="sim-param-eq-mode" style="display:none;"><label>Judged</label><select id="sim-eq-mode" onchange="updateSimulator()"><option value="season">Season average</option><option value="per_gp">Every GP</option></select></div>
                        <div class="form-field" id="sim-param-hm-cap" style="display:none;">
                            <label>Difficulty Cap</label>
                            <input type="number" id="sim-hm-cap" value="2.0" min="1" max="5" step="0.1" onchange="updateSimulator()">
                        </div>
                        <div class="form-field" id="sim-param-form-w" style="display:none;">
                            <label>Form Window (GPs)</label>
                            <input type="number" id="sim-form-window" value="8" min="1" max="50" onchange="updateSimulator()">
                        </div>
                    </div>
                </div>

                <!-- Simulator Results -->
                <div class="config-section" id="sim-results" style="display: none;">
                    <h3 class="config-label">Simulated Standings</h3>
                    <div id="sim-standings-table"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Existing Seasons -->
    <?php
    $seasonsWithResults = array_flip($resultSeasons);
    foreach ($allSeasons as $sid):
        $isEmpty = !isset($seasonsWithResults[$sid]);
        $meta = $metaData[$sid] ?? (newSeasonDefaults('average_attendance') + [
            'status' => 'active',
            'scoring_system' => 'average_attendance',
            'ecology_report' => null,
            'season_name' => strtoupper($sid),
            'season_description' => '',
            'academic_year' => null,
            'start_date' => null,
            'end_date' => null
        ]);
        $hasReport = !empty($meta['ecology_report']);
        $systemInfo = $scoringSystems[$meta['scoring_system']] ?? $scoringSystems['average_attendance'];
    ?>
    <div class="season-card <?= $meta['status'] ?>">
        <form method="POST" class="season-form">
            <?= csrf_field() ?>
            <input type="hidden" name="season_id" value="<?= $sid ?>">

            <!-- Season Header -->
            <div class="season-header">
                <div class="season-title-row">
                    <div>
                        <h2 class="season-title">
                            <?= htmlspecialchars($meta['season_name'] ?: strtoupper($sid)) ?>
                        </h2>
                        <div class="season-meta-line">
                            <span class="scoring-badge">
                                <?= $systemInfo['icon'] ?> <?= htmlspecialchars($systemInfo['name']) ?>
                            </span>
                            <?php if($meta['academic_year']): ?>
                            <span class="academic-info">
                                📅 <?= $meta['academic_year'] ?>
                            </span>
                            <?php endif; ?>
                            <?php if($meta['start_date'] && $meta['end_date']): ?>
                            <span class="date-range">
                                <?= date('M j', strtotime($meta['start_date'])) ?> → <?= date('M j', strtotime($meta['end_date'])) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="status-badges">
                        <span class="badge status-<?= $meta['status'] ?>">
                            <?= strtoupper($meta['status']) ?>
                        </span>
                        <?php if($hasReport): ?>
                        <span class="badge report-ready">REPORT READY</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Season Settings -->
            <div class="season-config">
                <!-- Basic Info -->
                <div class="config-section">
                    <h3 class="config-label">Season Information</h3>
                    <div class="form-grid">
                        <div class="form-field">
                            <label>Season Name</label>
                            <input type="text" name="season_name" value="<?= htmlspecialchars($meta['season_name'] ?: '') ?>" placeholder="e.g., Spring 2026">
                        </div>
                        <div class="form-field">
                            <label>Description</label>
                            <input type="text" name="season_description" value="<?= htmlspecialchars($meta['season_description'] ?: '') ?>" placeholder="Brief description">
                        </div>
                        <div class="form-field">
                            <label>Academic Year</label>
                            <input type="number" name="academic_year" value="<?= $meta['academic_year'] ?: '' ?>" placeholder="2026">
                        </div>
                        <div class="form-field">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?= $meta['start_date'] ?: '' ?>">
                        </div>
                        <div class="form-field">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?= $meta['end_date'] ?: '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Scoring System -->
                <div class="config-section">
                    <h3 class="config-label">Scoring System</h3>
                    <div class="form-field">
                        <label>System Type</label>
                        <select name="scoring_system" class="scoring-system-select" onchange="toggleScoringFields(this, '<?= $sid ?>')">
                            <?php foreach($scoringSystems as $key => $info): ?>
                            <option value="<?= $key ?>" <?= $meta['scoring_system'] === $key ? 'selected' : '' ?>>
                                <?= $info['icon'] ?> <?= $info['name'] ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- System-Specific Fields -->
                    <div id="fields-<?= $sid ?>-preseason" class="scoring-fields" style="<?= $meta['scoring_system'] === 'preseason' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Pre-Season Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['preseason']['long_description']) ?></p>
                    </div>

                    <div id="fields-<?= $sid ?>-average_attendance" class="scoring-fields" style="<?= $meta['scoring_system'] === 'average_attendance' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Average + Attendance Settings</h4>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Attendance Weight</label>
                                <input type="text" name="att_w" value="<?= htmlspecialchars($meta['attendance_weight']) ?>">
                            </div>
                            <div class="form-field">
                                <label>Weekly Cap</label>
                                <input type="text" name="cap" value="<?= htmlspecialchars($meta['weekly_bonus_cap']) ?>">
                            </div>
                            <div class="form-field">
                                <label>Min Races</label>
                                <input type="number" name="thresh" value="<?= $meta['min_races_threshold'] ?>">
                            </div>
                            <div class="form-field">
                                <label>Drop 1 Per X</label>
                                <input type="number" name="drop" value="<?= $meta['drop_rate'] ?>">
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-cup_based" class="scoring-fields" style="<?= $meta['scoring_system'] === 'cup_based' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Cup-Based Scoring Settings</h4>
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Cups Required</label>
                                <select name="cups_required">
                                    <option value="12" <?= $meta['cups_required'] == 12 ? 'selected' : '' ?>>12 (Base Cups Only)</option>
                                    <option value="24" <?= $meta['cups_required'] == 24 ? 'selected' : '' ?>>24 (Base + DLC)</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Allow Retries</label>
                                <input type="checkbox" name="allow_retries" <?= $meta['allow_retries'] ? 'checked' : '' ?>>
                                <span class="checkbox-label">Players can replay cups to improve scores</span>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-best_n_gps" class="scoring-fields" style="<?= $meta['scoring_system'] === 'best_n_gps' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Best N GPs Settings</h4>
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Count Best N GPs</label>
                                <input type="number" name="best_n_count" value="<?= $meta['best_n_count'] ?>" min="1" max="100">
                                <small>Sum of best N GP scores (all others dropped)</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-drop_worst" class="scoring-fields" style="<?= $meta['scoring_system'] === 'drop_worst' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Drop Worst Cups Settings</h4>
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Cups Required</label>
                                <select name="cups_required">
                                    <option value="12" <?= $meta['cups_required'] == 12 ? 'selected' : '' ?>>12 (Base Cups)</option>
                                    <option value="24" <?= $meta['cups_required'] == 24 ? 'selected' : '' ?>>24 (Base + DLC)</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Drop Worst N</label>
                                <input type="number" name="drop_worst_count" value="<?= $meta['drop_worst_count'] ?>" min="0" max="5">
                                <small>Drop N worst cup scores</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-perfect_hunt" class="scoring-fields" style="<?= $meta['scoring_system'] === 'perfect_hunt' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Perfect Hunt Settings</h4>
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Cups Required</label>
                                <select name="cups_required">
                                    <option value="12" <?= $meta['cups_required'] == 12 ? 'selected' : '' ?>>12 (Base Cups)</option>
                                    <option value="24" <?= $meta['cups_required'] == 24 ? 'selected' : '' ?>>24 (Base + DLC)</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Perfect Score Multiplier</label>
                                <input type="number" step="0.1" name="perfect_multiplier" value="<?= $meta['perfect_multiplier'] ?>" min="1.0" max="5.0">
                                <small>60 pts × multiplier (e.g., 2.0 = 120 pts)</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-top_12_unique" class="scoring-fields" style="<?= $meta['scoring_system'] === 'top_12_unique' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Top 12 Unique Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['top_12_unique']['long_description']) ?></p>
                    </div>

                    <div id="fields-<?= $sid ?>-random_cup_draw" class="scoring-fields" style="<?= $meta['scoring_system'] === 'random_cup_draw' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Random Cup Draw Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['random_cup_draw']['long_description']) ?></p>
                    </div>

                    <div id="fields-<?= $sid ?>-black_box" class="scoring-fields" style="<?= $meta['scoring_system'] === 'black_box' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">⬛ Black Box Settings</h4>
                        <p class="info-text info-text--warning">ADMIN EYES ONLY. Players see "Black Box Score" — no formula, no breakdown, no explanation.</p>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['black_box']['long_description']) ?></p>
                    </div>

                    <div id="fields-<?= $sid ?>-monster_hunt" class="scoring-fields" style="<?= $meta['scoring_system'] === 'monster_hunt' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">👹 MONSTER HUNT Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['monster_hunt']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Slay XP (base)</label>
                                <input type="number" name="mh_slay_xp" value="<?= $meta['mh_slay_xp'] ?? 100 ?>" min="0" max="500">
                                <small>Adventurer beats Monster (×CR mult)</small>
                            </div>
                            <div class="form-field">
                                <label>Survive XP</label>
                                <input type="number" name="mh_survive_xp" value="<?= $meta['mh_survive_xp'] ?? 20 ?>" min="0" max="200">
                                <small>Adventurer loses to Monster</small>
                            </div>
                            <div class="form-field">
                                <label>Party Bonus XP</label>
                                <input type="number" name="mh_party_bonus_xp" value="<?= $meta['mh_party_bonus_xp'] ?? 50 ?>" min="0" max="200">
                                <small>All adventurers beat Monster</small>
                            </div>
                            <div class="form-field">
                                <label>Best X Hunts</label>
                                <input type="number" name="mh_best_x" value="<?= $meta['mh_best_x'] ?? 20 ?>" min="1" max="200">
                                <small>Sum of top X GP XP results</small>
                            </div>
                        </div>
                        <h4 class="subsection-title" style="margin-top:1rem;">Monster Payouts</h4>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Win XP (beat all)</label>
                                <input type="number" name="mh_monster_win_xp" value="<?= $meta['mh_monster_win_xp'] ?? 80 ?>" min="0" max="300">
                            </div>
                            <div class="form-field">
                                <label>Partial XP (beat some)</label>
                                <input type="number" name="mh_monster_partial_xp" value="<?= $meta['mh_monster_partial_xp'] ?? 30 ?>" min="0" max="200">
                            </div>
                            <div class="form-field">
                                <label>Loss XP (Full Slay — monster wiped)</label>
                                <input type="number" name="mh_monster_loss_xp" value="<?= $meta['mh_monster_loss_xp'] ?? -40 ?>" min="-200" max="0">
                                <small>Can be negative</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-bounty_hunter" class="scoring-fields" style="<?= $meta['scoring_system'] === 'bounty_hunter' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🎯 Bounty Hunter Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['bounty_hunter']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Bounty Multiplier</label>
                                <input type="number" step="0.1" name="bh_multiplier" value="<?= $meta['bh_multiplier'] ?? 1.0 ?>" min="0.1" max="5.0">
                                <small>Scales raw Elo-gap → points</small>
                            </div>
                            <div class="form-field">
                                <label>Carrying Cost</label>
                                <select name="bh_carrying_cost">
                                    <option value="0" <?= empty($meta['bh_carrying_cost']) ? 'selected' : '' ?>>Off (just collect)</option>
                                    <option value="1" <?= !empty($meta['bh_carrying_cost']) ? 'selected' : '' ?>>On (your bounty subtracts)</option>
                                </select>
                                <small>Hurts strong racers who farm the weak</small>
                            </div>
                            <div class="form-field">
                                <label>Reset Cadence</label>
                                <select name="bh_weekly_reset">
                                    <option value="0" <?= empty($meta['bh_weekly_reset']) ? 'selected' : '' ?>>Season-long</option>
                                    <option value="1" <?= !empty($meta['bh_weekly_reset']) ? 'selected' : '' ?>>Weekly (reserved)</option>
                                </select>
                                <small>Weekly reset is reserved for future use</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-pari_mutuel" class="scoring-fields" style="<?= $meta['scoring_system'] === 'pari_mutuel' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🐎 Pari-Mutuel Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['pari_mutuel']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Ante (pts per GP)</label>
                                <input type="number" name="pm_ante" value="<?= $meta['pm_ante'] ?? 100 ?>" min="1" max="1000">
                                <small>Each racer pays this into the pot</small>
                            </div>
                            <div class="form-field">
                                <label>Payout Curve</label>
                                <select name="pm_payout_preset">
                                    <?php $pmPreset = $meta['pm_payout_preset'] ?? 'steep'; ?>
                                    <option value="steep"  <?= $pmPreset === 'steep'  ? 'selected' : '' ?>>Steep (50/30/15/5)</option>
                                    <option value="medium" <?= $pmPreset === 'medium' ? 'selected' : '' ?>>Medium (top 7 paid)</option>
                                    <option value="flat"   <?= $pmPreset === 'flat'   ? 'selected' : '' ?>>Flat (top 9 paid)</option>
                                </select>
                                <small>Steep = winner-takes-most. Flat = everyone breaks even.</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-positional_points" class="scoring-fields" style="<?= $meta['scoring_system'] === 'positional_points' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🏁 Positional Points Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['positional_points']['long_description']) ?></p>
                        <p class="info-text">Ladder: 1st=15 · 2nd=12 · 3rd=10 · 4th=9 · 5th=8 … 12th=1.</p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Aggregation</label>
                                <?php $posMode = $meta['pos_mode'] ?? 'best_n'; ?>
                                <select name="pos_mode">
                                    <option value="best_n"  <?= $posMode === 'best_n'  ? 'selected' : '' ?>>Best N nights</option>
                                    <option value="average" <?= $posMode === 'average' ? 'selected' : '' ?>>Per-GP average</option>
                                    <option value="sum"     <?= $posMode === 'sum'     ? 'selected' : '' ?>>Season sum</option>
                                </select>
                                <small>Average + a min-GP gate is the fairest across uneven attendance</small>
                            </div>
                            <div class="form-field">
                                <label>Best N (for Best-N mode)</label>
                                <input type="number" name="pos_best_n" value="<?= $meta['best_n_count'] ?? 15 ?>" min="1" max="100">
                                <small>How many of your best nights count</small>
                            </div>
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="pos_thresh" value="<?= $meta['min_races_threshold'] ?? 3 ?>" min="0" max="50">
                                <small>Below this, a racer is shown but ineligible</small>
                            </div>
                        </div>
                    </div>

                    <div id="fields-<?= $sid ?>-head_to_head" class="scoring-fields" style="<?= $meta['scoring_system'] === 'head_to_head' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🤺 Head-to-Head Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['head_to_head']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="h2h_thresh" value="<?= $meta['min_races_threshold'] ?? 3 ?>" min="0" max="50">
                                <small>Filters one-GP flukes from the win-rate board</small>
                            </div>
                            <div class="form-field">
                                <label>CPU kart weight</label>
                                <input type="number" name="h2h_npc_weight" value="<?= htmlspecialchars((string)($meta['h2h_npc_weight'] ?? 0.25)) ?>" min="0" max="1" step="0.05">
                                <small>How much beating (or losing to) a CPU kart counts vs a human. 0 = pure duels, 1 = every kart is an opponent</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-blue_shell" class="scoring-fields" style="<?= $meta['scoring_system'] === 'blue_shell' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🐢 Blue Shell Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['blue_shell']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="bs_thresh" value="<?= htmlspecialchars((string)($meta['min_races_threshold'] ?? 3)) ?>" min="0" max="50">
                                <small>Below this a racer is shown but ineligible</small>
                            </div>
                            <div class="form-field">
                                <label>Catch-up rate</label>
                                <input type="number" name="bs_rate" value="<?= htmlspecialchars((string)($meta['bs_rate'] ?? 0.10)) ?>" min="0" max="1" step="0.01">
                                <small>Multiplier added per place behind the leader (0.10 = +10% per place)</small>
                            </div>
                            <div class="form-field">
                                <label>Multiplier cap</label>
                                <input type="number" name="bs_cap" value="<?= htmlspecialchars((string)($meta['bs_cap'] ?? 2.0)) ?>" min="1" max="5" step="0.1">
                                <small>Highest multiplier any GP can get</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-territory" class="scoring-fields" style="<?= $meta['scoring_system'] === 'territory' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🏰 Territory Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['territory']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Undefended GPs before a cup changes hands</label>
                                <input type="number" name="tt_decay_gps" value="<?= htmlspecialchars((string)($meta['tt_decay_gps'] ?? 4)) ?>" min="0" max="20">
                                <small>Race the cup yourself to reset the count. 0 = a holding never decays. Equal scores always go to the challenger.</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-median" class="scoring-fields" style="<?= $meta['scoring_system'] === 'median' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">⚖️ Median Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['median']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="md_thresh" value="<?= htmlspecialchars((string)($meta['min_races_threshold'] ?? 3)) ?>" min="0" max="50">
                                <small>A median of two GPs is a coin flip — gate it</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-hard_mode" class="scoring-fields" style="<?= $meta['scoring_system'] === 'hard_mode' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🔥 Hard Mode Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['hard_mode']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="hm_thresh" value="<?= htmlspecialchars((string)($meta['min_races_threshold'] ?? 3)) ?>" min="0" max="50">
                                <small>Below this a racer is shown but ineligible</small>
                            </div>
                            <div class="form-field">
                                <label>Multiplier cap</label>
                                <input type="number" name="hm_cap" value="<?= htmlspecialchars((string)($meta['hm_cap'] ?? 2.0)) ?>" min="1" max="5" step="0.1">
                                <small>Ceiling for the hardest cups (floor is fixed at ×0.5)</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-kart_bingo" class="scoring-fields" style="<?= $meta['scoring_system'] === 'kart_bingo' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🎱 Kart Bingo Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['kart_bingo']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Points per line</label>
                                <input type="number" name="bg_line_pts" value="<?= htmlspecialchars((string)($meta['bg_line_pts'] ?? 100)) ?>" min="1" max="1000">
                                <small>Rows, columns and diagonals each</small>
                            </div>
                            <div class="form-field">
                                <label>Full-card bonus</label>
                                <input type="number" name="bg_card_pts" value="<?= htmlspecialchars((string)($meta['bg_card_pts'] ?? 500)) ?>" min="0" max="5000">
                                <small>On top of the eight lines</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-price_is_right" class="scoring-fields" style="<?= $meta['scoring_system'] === 'price_is_right' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">🏷️ The Price Is Right Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['price_is_right']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="pir_thresh" value="<?= htmlspecialchars((string)($meta['min_races_threshold'] ?? 3)) ?>" min="0" max="50">
                                <small>Keeps one-night wonders off the board</small>
                            </div>
                            <div class="form-field">
                                <label>Target</label>
                                <select name="pir_target"><option value="median" <?= ($meta['pir_target'] ?? 'median') === 'median' ? 'selected' : '' ?>>Median of the GP</option><option value="mean" <?= ($meta['pir_target'] ?? 'median') === 'mean' ? 'selected' : '' ?>>Mean of the GP</option></select>
                                <small>The hidden number to get under</small>
                            </div>
                            <div class="form-field">
                                <label>Best N GPs count</label>
                                <input type="number" name="pir_best_n" value="<?= htmlspecialchars((string)($meta['pir_best_n'] ?? 15)) ?>" min="1" max="60">
                                <small>Season = sum of your best N ladder scores</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-equaliser" class="scoring-fields" style="<?= $meta['scoring_system'] === 'equaliser' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">⚖️ The Great Equaliser Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['equaliser']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="eq_thresh" value="<?= htmlspecialchars((string)($meta['min_races_threshold'] ?? 3)) ?>" min="0" max="50">
                                <small>Keeps one-night wonders off the board</small>
                            </div>
                            <div class="form-field">
                                <label>Judged</label>
                                <select name="eq_mode"><option value="season" <?= ($meta['eq_mode'] ?? 'season') === 'season' ? 'selected' : '' ?>>Season average vs league average</option><option value="per_gp" <?= ($meta['eq_mode'] ?? 'season') === 'per_gp' ? 'selected' : '' ?>>Every GP vs that night\'s average</option></select>
                                <small>Per-GP punishes wild nights; season mode forgives them</small>
                            </div>
                        </div>
                    </div>
                    <div id="fields-<?= $sid ?>-form" class="scoring-fields" style="<?= $meta['scoring_system'] === 'form' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">📈 Form Settings</h4>
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['form']['long_description']) ?></p>
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Min GPs to qualify</label>
                                <input type="number" name="form_thresh" value="<?= htmlspecialchars((string)($meta['min_races_threshold'] ?? 3)) ?>" min="0" max="50">
                                <small>Keeps one-night wonders off the board</small>
                            </div>
                            <div class="form-field">
                                <label>Window (GPs)</label>
                                <input type="number" name="form_window" value="<?= htmlspecialchars((string)($meta['form_window'] ?? 8)) ?>" min="1" max="50">
                                <small>How many recent GPs count; older ones fall off</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="season-actions">
                <button type="submit" name="action" value="save_rules" class="btn btn-primary">
                    💾 Save Configuration
                </button>

                <?php if($meta['status'] === 'active'): ?>
                    <button type="submit" name="action" value="archive" class="btn btn-archive">
                        📦 Finalize & Archive
                    </button>
                <?php elseif($meta['status'] === 'archived'): ?>
                    <button type="submit" name="action" value="generate_report" class="btn btn-report">
                        <?= $hasReport ? "🔄 Regenerate Report" : "📝 Generate Report" ?>
                    </button>
                    <button type="submit" name="action" value="activate" class="btn btn-secondary">
                        🔓 Re-Open Season
                    </button>
                    <button type="submit" name="action" value="snapshot_mikkoliiga" class="btn btn-secondary"
                            onclick="return confirm('Re-freeze Mikkoliiga roster for <?= htmlspecialchars(strtoupper($sid)) ?> using the CURRENT member flags? This overwrites any existing snapshot for this season.');">
                        🌟 Re-snapshot Mikkoliiga
                    </button>
                <?php else: ?>
                    <?php // 'upcoming' — a season created here starts in this state and used to
                          // have NO status controls at all, so it could never be started or
                          // closed from the UI. Both actions belong here. ?>
                    <button type="submit" name="action" value="activate" class="btn btn-primary">
                        ▶️ Start Season
                    </button>
                    <button type="submit" name="action" value="archive" class="btn btn-archive"
                            onclick="return confirm('Close <?= htmlspecialchars(strtoupper($sid)) ?> and archive it now?');">
                        📦 Finalize &amp; Archive
                    </button>
                <?php endif; ?>

                <?php if($isEmpty): ?>
                    <button type="submit" name="action" value="delete_season" class="btn btn-danger"
                            onclick="return confirm('Delete season <?= htmlspecialchars(strtoupper($sid)) ?>? This removes the rules row; no race results exist for it.');">
                        🗑️ Delete Empty Season
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <?php
        // ── Teams block (constructor season layer) — outside the config form
        //    because team CRUD uses its own small forms. ──
        $seasonTeams   = getTeamConfig($pdo, $sid);
        $seasonRacers  = getActiveRacers($pdo, $sid);
        usort($seasonRacers, fn($a, $b) => strcmp($a['name'], $b['name']));
        $assignedRids  = [];
        foreach ($seasonTeams as $stm) foreach ($stm['members'] as $rid => $n) $assignedRids[$rid] = true;
        $unassigned    = array_filter($seasonRacers, fn($r) => !isset($assignedRids[(int)$r['id']]));
        ?>
        <?php $seasonBestN = (int)($meta['team_best_n'] ?? TEAM_BEST_N); ?>
        <details class="season-teams" <?= !empty($seasonTeams) ? 'open' : '' ?>>
            <summary>🤝 Teams <span class="season-teams-count"><?= count($seasonTeams) ?> team<?= count($seasonTeams) === 1 ? '' : 's' ?></span></summary>
            <p class="info-text">Constructor scoring: each GP a team banks its best <strong><?= $seasonBestN ?></strong> members' points. Public standings at <a href="/teams?season=<?= htmlspecialchars($sid) ?>" target="_blank">/teams</a>. Configuring teams here makes <?= htmlspecialchars(strtoupper($sid)) ?> a teams season (cards show on the homepage when it's active).</p>

            <form method="POST" class="season-team-bestn">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_team_best_n">
                <input type="hidden" name="season_id" value="<?= htmlspecialchars($sid) ?>">
                <label>Best <input type="number" name="team_best_n" value="<?= $seasonBestN ?>" min="1" max="12"> members count per GP</label>
                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
            </form>

            <?php foreach ($seasonTeams as $team): ?>
                <div class="season-team" style="border-left-color: <?= htmlspecialchars($team['color']) ?>;">
                    <div class="season-team-head">
                        <span class="season-team-dot" style="background: <?= htmlspecialchars($team['color']) ?>;"></span>
                        <strong><?= htmlspecialchars($team['name']) ?></strong>
                        <span class="season-team-n"><?= count($team['members']) ?></span>
                        <form method="POST" class="season-team-del" onsubmit="return confirm('Delete team <?= htmlspecialchars($team['name']) ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="season_id" value="<?= htmlspecialchars($sid) ?>">
                            <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                            <button type="submit" title="Delete team">✕</button>
                        </form>
                    </div>
                    <div class="season-team-members">
                        <?php foreach ($team['members'] as $rid => $mname): ?>
                            <span class="season-member-chip">
                                <?= htmlspecialchars($mname) ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove_team_member">
                                    <input type="hidden" name="season_id" value="<?= htmlspecialchars($sid) ?>">
                                    <input type="hidden" name="racer_id" value="<?= (int)$rid ?>">
                                    <button type="submit" title="Remove">×</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                        <?php if (empty($team['members'])): ?><span class="season-member-empty">no members yet</span><?php endif; ?>
                    </div>
                    <?php if (!empty($unassigned)): ?>
                        <form method="POST" class="season-team-add">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_team_member">
                            <input type="hidden" name="season_id" value="<?= htmlspecialchars($sid) ?>">
                            <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                            <select name="racer_id" onchange="this.form.submit()">
                                <option value="">+ add racer…</option>
                                <?php foreach ($unassigned as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <form method="POST" class="season-team-create">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_team">
                <input type="hidden" name="season_id" value="<?= htmlspecialchars($sid) ?>">
                <input type="text" name="team_name" placeholder="New team name" required>
                <input type="color" name="team_color" value="var(--nintendo-red)" title="Team colour">
                <button type="submit" class="btn btn-secondary btn-sm">+ Add team</button>
            </form>
        </details>
    </div>
    <?php endforeach; ?>

    <!-- Create New Season -->
    <div class="season-card new-season">
        <div class="season-header">
            <h2 class="season-title">➕ Create New Season</h2>
            <p class="admin-new-season-subtitle">
                Next: <?= $nextSeasonId ?> (Official) or <?= $nextPreSeasonId ?> (Pre-Season)
            </p>
        </div>

        <form method="POST" class="season-form" id="new-season-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_season">
            <input type="hidden" id="season-type" name="season_type" value="official">

            <div class="season-config">
                <div class="config-section">
                    <h3 class="config-label">New Season Configuration</h3>

                    <!-- Season Type Selector -->
                    <div class="season-type-selector admin-type-selector-wrapper">
                        <button type="button" class="season-type-btn active" onclick="selectSeasonType('official')">
                            🏆 Official Season (<?= $nextSeasonId ?>)
                        </button>
                        <button type="button" class="season-type-btn" onclick="selectSeasonType('preseason')">
                            🌟 Pre-Season (<?= $nextPreSeasonId ?>)
                        </button>
                    </div>

                    <div class="form-grid">
                        <div class="form-field">
                            <label>Season ID</label>
                            <input type="text" id="season-id-input" name="new_season_id" value="<?= $nextSeasonId ?>" required>
                            <small id="season-id-hint">Format: s02, s03, etc.</small>
                        </div>
                        <div class="form-field">
                            <label>Season Name</label>
                            <input type="text" id="season-name-input" name="season_name" placeholder="e.g., Autumn 2026" required>
                        </div>
                        <div class="form-field">
                            <label>Academic Year</label>
                            <input type="number" name="academic_year" value="2026" required>
                        </div>
                        <div class="form-field full-width">
                            <label>Scoring System</label>
                            <select name="scoring_system" id="new-scoring-system" onchange="toggleNewSeasonFields(this)" required>
                                <?php foreach($scoringSystems as $key => $info): ?>
                                <option value="<?= $key ?>">
                                    <?= $info['icon'] ?> <?= $info['name'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field full-width">
                            <label>Description</label>
                            <textarea name="season_description" rows="2" placeholder="Brief description of this season's format"></textarea>
                        </div>
                    </div>

                    <!-- System-specific fields for new season -->
                    <div id="new-fields-cup_based" class="scoring-fields scoring-fields-hidden">
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Cups Required</label>
                                <select name="cups_required">
                                    <option value="12">12 (Base Cups)</option>
                                    <option value="24">24 (Base + DLC)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="new-fields-best_n_gps" class="scoring-fields scoring-fields-hidden">
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Count Best N GPs</label>
                                <input type="number" name="best_n_count" value="15" min="1" max="100">
                            </div>
                        </div>
                    </div>

                    <div id="new-fields-drop_worst" class="scoring-fields scoring-fields-hidden">
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Cups Required</label>
                                <select name="cups_required">
                                    <option value="12">12 (Base)</option>
                                    <option value="24">24 (Base + DLC)</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Drop Worst N</label>
                                <input type="number" name="drop_worst_count" value="2" min="0" max="5">
                            </div>
                        </div>
                    </div>

                    <div id="new-fields-perfect_hunt" class="scoring-fields scoring-fields-hidden">
                        <div class="form-grid grid-2">
                            <div class="form-field">
                                <label>Perfect Score Multiplier</label>
                                <input type="number" step="0.1" name="perfect_multiplier" value="2.0" min="1.0" max="5.0">
                            </div>
                        </div>
                    </div>

                    <div id="new-fields-top_12_unique" class="scoring-fields scoring-fields-hidden">
                        <p class="info-text"><?= htmlspecialchars($scoringSystems['top_12_unique']['long_description']) ?></p>
                    </div>

                    <div id="new-fields-black_box" class="scoring-fields scoring-fields-hidden">
                        <p class="info-text info-text--warning">Players will only see "Black Box Score" — the formula is hidden.</p>
                    </div>

                    <div id="new-fields-monster_hunt" class="scoring-fields scoring-fields-hidden">
                        <div class="form-grid grid-4">
                            <div class="form-field">
                                <label>Slay XP (base)</label>
                                <input type="number" name="mh_slay_xp" value="100" min="0" max="500">
                                <small>Adventurer beats Monster (×CR)</small>
                            </div>
                            <div class="form-field">
                                <label>Survive XP</label>
                                <input type="number" name="mh_survive_xp" value="20" min="0" max="200">
                            </div>
                            <div class="form-field">
                                <label>Party Bonus XP</label>
                                <input type="number" name="mh_party_bonus_xp" value="50" min="0" max="200">
                            </div>
                            <div class="form-field">
                                <label>Monster Win XP</label>
                                <input type="number" name="mh_monster_win_xp" value="80" min="0" max="300">
                            </div>
                            <div class="form-field">
                                <label>Monster Partial XP</label>
                                <input type="number" name="mh_monster_partial_xp" value="30" min="0" max="200">
                            </div>
                            <div class="form-field">
                                <label>Monster Loss XP</label>
                                <input type="number" name="mh_monster_loss_xp" value="-40" min="-200" max="0">
                            </div>
                            <div class="form-field">
                                <label>Best X Hunts</label>
                                <input type="number" name="mh_best_x" value="20" min="1" max="200">
                                <small>Sum of top X GP XP results</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="season-actions">
                <button type="submit" class="btn btn-success">
                    ✨ Create Season
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleScoringFields(select, seasonId) {
    const selectedSystem = select.value;
    const allFields = document.querySelectorAll(`[id^="fields-${seasonId}-"]`);

    allFields.forEach(field => {
        field.style.display = 'none';
    });

    const targetField = document.getElementById(`fields-${seasonId}-${selectedSystem}`);
    if (targetField) {
        targetField.style.display = 'block';
    }
}

function toggleNewSeasonFields(select) {
    const selectedSystem = select.value;
    const allFields = document.querySelectorAll('[id^="new-fields-"]');

    allFields.forEach(field => {
        field.style.display = 'none';
    });

    const targetField = document.getElementById(`new-fields-${selectedSystem}`);
    if (targetField) {
        targetField.style.display = 'block';
    }
}

// ── Scoring Simulator ──
let simData = null;

// Each season's saved knob values, so the simulator opens on the season's REAL
// configuration. Without this the fields would sit at hardcoded defaults and a
// season configured for, say, best-10 Positional would be simulated as best-15
// — quietly disagreeing with its own live standings.
const SIM_SEASON_RULES = <?= json_encode(array_map(fn($m) => [
    'pos_mode'          => $m['pos_mode']          ?? 'best_n',
    'best_n_count'      => (int)($m['best_n_count'] ?? 15),
    'drop_worst_count'  => (int)($m['drop_worst_count'] ?? 2),
    'perfect_multiplier'=> (float)($m['perfect_multiplier'] ?? 2.0),
    'bh_multiplier'     => (float)($m['bh_multiplier'] ?? 1.0),
    'bh_carrying_cost'  => (int)($m['bh_carrying_cost'] ?? 0),
    'pm_ante'           => (int)($m['pm_ante'] ?? 100),
    'pm_payout_preset'  => $m['pm_payout_preset']  ?? 'steep',
    'h2h_npc_weight'    => (float)($m['h2h_npc_weight'] ?? 0.25),
    'bs_rate'           => (float)($m['bs_rate'] ?? 0.10),
    'bs_cap'            => (float)($m['bs_cap'] ?? 2.0),
    'hm_cap'            => (float)($m['hm_cap'] ?? 2.0),
    'tt_decay_gps'      => (int)($m['tt_decay_gps'] ?? 4),
    'bg_line_pts'       => (int)($m['bg_line_pts'] ?? 100),
    'bg_card_pts'       => (int)($m['bg_card_pts'] ?? 500),
    'pir_target'        => $m['pir_target'] ?? 'median',
    'pir_best_n'        => (int)($m['pir_best_n'] ?? 15),
    'eq_mode'           => $m['eq_mode'] ?? 'season',
    'form_window'       => (int)($m['form_window'] ?? 8),
], $metaData), JSON_UNESCAPED_SLASHES) ?>;

// Reseed the knob fields from the selected season, then re-run. Called when the
// season or the system changes — editing a knob itself must NOT reseed, or the
// admin's input would be reverted as they typed.
function onSimSelectionChange() {
    const season = document.getElementById('sim-season').value;
    const r = SIM_SEASON_RULES[season];
    if (r) {
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        set('sim-best-n', r.best_n_count);
        set('sim-drop-n', r.drop_worst_count);
        set('sim-multiplier', r.perfect_multiplier);
        set('sim-pos-mode', r.pos_mode);
        set('sim-pos-best-n', r.best_n_count);
        set('sim-bh-multiplier', r.bh_multiplier);
        set('sim-bh-carrying-cost', r.bh_carrying_cost);
        set('sim-pm-ante', r.pm_ante);
        set('sim-pm-payout-preset', r.pm_payout_preset);
        set('sim-h2h-npc-weight', r.h2h_npc_weight);
        set('sim-bs-rate', r.bs_rate);
        set('sim-bs-cap', r.bs_cap);
        set('sim-hm-cap', r.hm_cap);
        set('sim-tt-decay', r.tt_decay_gps);
        set('sim-bg-line', r.bg_line_pts); set('sim-bg-card', r.bg_card_pts);
        set('sim-pir-target', r.pir_target); set('sim-pir-n', r.pir_best_n);
        set('sim-eq-mode', r.eq_mode);
        set('sim-form-window', r.form_window);
    }
    loadSimData();
}

function loadSimData() {
    const season = document.getElementById('sim-season').value;
    if (!season) {
        document.getElementById('sim-results').style.display = 'none';
        return;
    }
    updateSimulator();
}

function updateSimulator() {
    const system = document.getElementById('sim-system').value;
    const season = document.getElementById('sim-season').value;

    // Toggle param fields — each system shows only the knobs it actually uses.
    const show = {
        'sim-param-n':        system === 'best_n_gps',
        'sim-param-drop':     system === 'drop_worst',
        'sim-param-mult':     system === 'perfect_hunt',
        'sim-param-pos-mode': system === 'positional_points',
        'sim-param-pos-n':    system === 'positional_points' && document.getElementById('sim-pos-mode').value === 'best_n',
        'sim-param-bh-mult':  system === 'bounty_hunter',
        'sim-param-bh-cost':  system === 'bounty_hunter',
        'sim-param-pm-ante':  system === 'pari_mutuel',
        'sim-param-tt-decay': system === 'territory',
        'sim-param-bg-line':  system === 'kart_bingo',
        'sim-param-bg-card':  system === 'kart_bingo',
        'sim-param-pir-target': system === 'price_is_right',
        'sim-param-pir-n':    system === 'price_is_right',
        'sim-param-eq-mode':  system === 'equaliser',
        'sim-param-pm-preset': system === 'pari_mutuel',
        'sim-param-h2h-w':    system === 'head_to_head',
        'sim-param-bs-rate':  system === 'blue_shell',
        'sim-param-bs-cap':   system === 'blue_shell',
        'sim-param-hm-cap':   system === 'hard_mode',
        'sim-param-form-w':   system === 'form',
    };
    for (const [id, visible] of Object.entries(show)) {
        document.getElementById(id).style.display = visible ? '' : 'none';
    }

    if (!season) return;

    const val = id => encodeURIComponent(document.getElementById(id).value);

    // Send only the knobs belonging to the chosen system. The endpoint keys
    // each override to its own system, so nothing can bleed across (best_n and
    // pos_best_n both land in best_n_count, but only for their own system).
    let url = `/api/simulate_scoring.php?season=${encodeURIComponent(season)}&system=${encodeURIComponent(system)}`;
    if (system === 'best_n_gps')        url += `&best_n=${val('sim-best-n')}`;
    if (system === 'drop_worst')        url += `&drop_worst=${val('sim-drop-n')}`;
    if (system === 'perfect_hunt')      url += `&perfect_mult=${val('sim-multiplier')}`;
    if (system === 'positional_points') url += `&pos_mode=${val('sim-pos-mode')}&pos_best_n=${val('sim-pos-best-n')}`;
    if (system === 'bounty_hunter')     url += `&bh_multiplier=${val('sim-bh-multiplier')}&bh_carrying_cost=${val('sim-bh-carrying-cost')}`;
    if (system === 'pari_mutuel')       url += `&pm_ante=${val('sim-pm-ante')}&pm_payout_preset=${val('sim-pm-payout-preset')}`;
    if (system === 'head_to_head')      url += `&h2h_npc_weight=${val('sim-h2h-npc-weight')}`;
    if (system === 'blue_shell')        url += `&bs_rate=${val('sim-bs-rate')}&bs_cap=${val('sim-bs-cap')}`;
    if (system === 'hard_mode')         url += `&hm_cap=${val('sim-hm-cap')}`;
    if (system === 'territory')         url += `&tt_decay_gps=${val('sim-tt-decay')}`;
    if (system === 'kart_bingo')        url += `&bg_line_pts=${val('sim-bg-line')}&bg_card_pts=${val('sim-bg-card')}`;
    if (system === 'price_is_right')    url += `&pir_target=${val('sim-pir-target')}&pir_best_n=${val('sim-pir-n')}`;
    if (system === 'equaliser')         url += `&eq_mode=${val('sim-eq-mode')}`;
    if (system === 'form')              url += `&form_window=${val('sim-form-window')}`;

    document.getElementById('sim-standings-table').innerHTML = '<p class="sim-status">Computing...</p>';
    document.getElementById('sim-results').style.display = 'block';

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                document.getElementById('sim-standings-table').innerHTML = `<p class="sim-error">${data.error}</p>`;
                return;
            }
            renderSimStandings(data.standings, system);
        })
        .catch(err => {
            document.getElementById('sim-standings-table').innerHTML = `<p class="sim-error">Error: ${err.message}</p>`;
        });
}

function renderSimStandings(standings, system) {
    if (!standings || standings.length === 0) {
        document.getElementById('sim-standings-table').innerHTML = '<p class="sim-status">No data.</p>';
        return;
    }

    const isMonsterHunt = system === 'monster_hunt';

    let html = `<table class="admin-table sim-table">
        <thead><tr>
            <th>#</th><th>Racer</th><th>Score</th>
            ${isMonsterHunt ? '<th>Title</th><th>Lv</th><th>Total XP</th>' : '<th>GPs</th><th>Avg</th><th>Best</th><th>Worst</th>'}
            <th>Delta</th>
        </tr></thead><tbody>`;

    const topScore = standings[0].score;
    standings.forEach((s, i) => {
        const delta = i === 0 ? '—' : '-' + (topScore - s.score).toFixed(1);
        const rowClass = i === 0 ? 'class="sim-row-leader"' : (i <= 2 ? 'class="sim-row-podium"' : '');
        const extraCols = isMonsterHunt
            ? `<td>${s.mh_title ?? '—'}</td><td>${s.mh_level ?? '—'}</td><td>${s.mh_total_xp ?? '—'}</td>`
            : `<td>${s.gps}</td><td>${s.avg}</td><td>${s.best}</td><td>${s.worst}</td>`;
        html += `<tr ${rowClass}>
            <td>${s.rank}</td>
            <td><strong>${s.name}</strong></td>
            <td class="sim-score">${s.score}</td>
            ${extraCols}
            <td class="sim-delta">${delta}</td>
        </tr>`;
    });

    html += '</tbody></table>';
    document.getElementById('sim-standings-table').innerHTML = html;
}

function selectSeasonType(type) {
    // Update buttons
    document.querySelectorAll('.season-type-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');

    // Update hidden field
    document.getElementById('season-type').value = type;

    if (type === 'preseason') {
        // Pre-season mode
        document.getElementById('season-id-input').value = '<?= $nextPreSeasonId ?>';
        document.getElementById('season-id-hint').textContent = 'Format: ps01, ps02, etc.';
        document.getElementById('season-name-input').placeholder = 'e.g., Summer Break 2026';

        // Auto-select pre-season scoring
        const scoringSelect = document.getElementById('new-scoring-system');
        scoringSelect.value = 'preseason';
        toggleNewSeasonFields(scoringSelect);
    } else {
        // Official season mode
        document.getElementById('season-id-input').value = '<?= $nextSeasonId ?>';
        document.getElementById('season-id-hint').textContent = 'Format: s02, s03, etc.';
        document.getElementById('season-name-input').placeholder = 'e.g., Autumn 2026';

        // Reset scoring to default
        const scoringSelect = document.getElementById('new-scoring-system');
        scoringSelect.value = 'average_attendance';
        toggleNewSeasonFields(scoringSelect);
    }
}
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
