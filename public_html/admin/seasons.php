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
                    attendance_weight, weekly_bonus_cap, min_races_threshold, drop_rate
                ) VALUES (?, 'upcoming', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            // Set defaults based on scoring system
            $cupsRequired = ($scoringSystem === 'cup_based') ? (int)$_POST['cups_required'] : 12;
            $bestNCount = ($scoringSystem === 'best_n_gps') ? (int)$_POST['best_n_count'] : 15;
            $dropWorstCount = ($scoringSystem === 'drop_worst') ? (int)$_POST['drop_worst_count'] : 2;
            $perfectMultiplier = ($scoringSystem === 'perfect_hunt') ? (float)$_POST['perfect_multiplier'] : 2.0;

            // Legacy fields for backward compatibility
            $attWeight = ($scoringSystem === 'average_attendance') ? 1.0 : 0.0;
            $weeklyCap = 2;
            $minThreshold = 3;
            $dropRate = ($scoringSystem === 'average_attendance') ? 10 : 0;

            $stmt->execute([
                $seasonId, $scoringSystem, $academicYear,
                $seasonName, $seasonDesc,
                $cupsRequired, $bestNCount, $dropWorstCount, $perfectMultiplier,
                $attWeight, $weeklyCap, $minThreshold, $dropRate
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
        header("Location: ../api/generate_season_report.php?season=$sid");
        exit;
    }

    if ($action === 'activate') {
        $sid = $_POST['season_id'];
        $stmt = $pdo->prepare("UPDATE season_meta SET status='active' WHERE season_id = ?");
        $stmt->execute([$sid]);
        $message = "Season " . strtoupper($sid) . " is now ACTIVE.";
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

// Scoring system definitions
$scoringSystems = [
    'preseason' => [
        'name' => 'Pre-Season (Casual)',
        'description' => 'Simple average with 10% drop - for off-season play',
        'icon' => '🌟'
    ],
    'average_attendance' => [
        'name' => 'Average + Attendance (Legacy)',
        'description' => 'Average GP score with attendance bonuses and drop mechanics',
        'icon' => '📊'
    ],
    'cup_based' => [
        'name' => 'Best Score on All Cups',
        'description' => 'Sum of best scores across all required cups (12 or 24)',
        'icon' => '🏆'
    ],
    'best_n_gps' => [
        'name' => 'Best N GPs (Accumulated)',
        'description' => 'Sum of your best N GP scores, drop all others',
        'icon' => '⭐'
    ],
    'drop_worst' => [
        'name' => 'Drop Worst Cups',
        'description' => 'Play all cups, drop X worst scores',
        'icon' => '🗑️'
    ],
    'perfect_hunt' => [
        'name' => 'Perfect Hunt (Multipliers)',
        'description' => 'Bonus multipliers for perfect 60 scores',
        'icon' => '💎'
    ],
    'top_12_unique' => [
        'name' => 'Top 12 Unique',
        'description' => 'Best 12 GPs from 12 separate cups. Tiebreaker: most 60s in unique cups',
        'icon' => '🎯'
    ],
    'random_cup_draw' => [
        'name' => 'Random Cup Draw',
        'description' => 'Each player assigned random cups to complete',
        'icon' => '🎲'
    ],
    'black_box' => [
        'name' => 'Black Box',
        'description' => 'Opaque scoring. Players cannot see the formula. Equalizer mechanics active.',
        'icon' => '⬛'
    ]
];

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
                            <select id="sim-system" onchange="updateSimulator()" class="scoring-system-select">
                                <?php foreach($scoringSystems as $key => $info): ?>
                                    <?php if ($key === 'random_cup_draw') continue; ?>
                                    <option value="<?= $key ?>"><?= $info['icon'] ?> <?= $info['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Source Season</label>
                            <select id="sim-season" onchange="loadSimData()">
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
    <?php foreach ($allSeasons as $sid):
        $meta = $metaData[$sid] ?? [
            'status' => 'active',
            'scoring_system' => 'average_attendance',
            'attendance_weight' => 1.0,
            'weekly_bonus_cap' => 2,
            'min_races_threshold' => 3,
            'drop_rate' => 10,
            'ecology_report' => null,
            'season_name' => strtoupper($sid),
            'season_description' => '',
            'academic_year' => null,
            'cups_required' => 12,
            'best_n_count' => 15,
            'drop_worst_count' => 2,
            'perfect_multiplier' => 2.0,
            'start_date' => null,
            'end_date' => null
        ];
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
                        <p class="info-text">Pre-season uses simple average with 10% worst scores dropped. No configuration needed.</p>
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
                        <p class="info-text">Cumulative score from the best 12 GPs, each from a different cup. Tiebreaker: most perfect 60 scores in unique cups.</p>
                    </div>

                    <div id="fields-<?= $sid ?>-random_cup_draw" class="scoring-fields" style="<?= $meta['scoring_system'] === 'random_cup_draw' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">Random Cup Draw Settings</h4>
                        <p class="info-text">Each player will be assigned a random set of cups at season start.</p>
                    </div>

                    <div id="fields-<?= $sid ?>-black_box" class="scoring-fields" style="<?= $meta['scoring_system'] === 'black_box' ? '' : 'display:none;' ?>">
                        <h4 class="subsection-title">⬛ Black Box Settings</h4>
                        <p class="info-text info-text--warning">ADMIN EYES ONLY. Players see "Black Box Score" — no formula, no breakdown, no explanation.</p>
                        <p class="info-text">The formula applies diminishing returns to high scorers, momentum bonuses for improvement streaks, "chaos points" seeded from race dates, and a comeback multiplier that scales inversely with historical average. The net effect: the leaderboard will feel plausible but unpredictable, and lower-ranked players will punch above their weight.</p>
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
                    <a href="/api/season-report?season=<?= $sid ?>" class="btn btn-report">
                        <?= $hasReport ? "🔄 Regenerate Report" : "📝 Generate Report" ?>
                    </a>
                    <button type="submit" name="action" value="activate" class="btn btn-secondary">
                        🔓 Re-Open Season
                    </button>
                <?php endif; ?>
            </div>
        </form>
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
                        <p class="info-text">Cumulative score from the best 12 GPs, each from a different cup. Tiebreaker: most perfect 60 scores in unique cups.</p>
                    </div>

                    <div id="new-fields-black_box" class="scoring-fields scoring-fields-hidden">
                        <p class="info-text info-text--warning">Players will only see "Black Box Score" — the formula is hidden.</p>
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

    // Toggle param fields
    document.getElementById('sim-param-n').style.display = system === 'best_n_gps' ? '' : 'none';
    document.getElementById('sim-param-drop').style.display = system === 'drop_worst' ? '' : 'none';
    document.getElementById('sim-param-mult').style.display = system === 'perfect_hunt' ? '' : 'none';

    if (!season) return;

    const bestN = document.getElementById('sim-best-n').value;
    const dropN = document.getElementById('sim-drop-n').value;
    const mult = document.getElementById('sim-multiplier').value;

    const url = `/api/simulate_scoring.php?season=${encodeURIComponent(season)}&system=${encodeURIComponent(system)}&best_n=${bestN}&drop_worst=${dropN}&perfect_mult=${mult}`;

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

    let html = `<table class="admin-table sim-table">
        <thead><tr>
            <th>#</th><th>Racer</th><th>Score</th><th>GPs</th><th>Avg</th><th>Best</th><th>Worst</th><th>Delta</th>
        </tr></thead><tbody>`;

    const topScore = standings[0].score;
    standings.forEach((s, i) => {
        const delta = i === 0 ? '—' : '-' + (topScore - s.score).toFixed(1);
        const rowClass = i === 0 ? 'class="sim-row-leader"' : (i <= 2 ? 'class="sim-row-podium"' : '');
        html += `<tr ${rowClass}>
            <td>${s.rank}</td>
            <td><strong>${s.name}</strong></td>
            <td class="sim-score">${s.score}</td>
            <td>${s.gps}</td>
            <td>${s.avg}</td>
            <td>${s.best}</td>
            <td>${s.worst}</td>
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
