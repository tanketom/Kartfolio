<?php
/**
 * View Season Report - Archival Newspaper & Sidebar Standings
 * Path: /cdnmk/public_html/view_season_report.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/csrf.php';

// 1. Validate Input
if (!isset($_GET['season'])) {
    header("Location: season-archives.php");
    exit;
}

$sid = $_GET['season'];

// 2. Fetch Report & Season Metadata
$stmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ? AND status = 'archived'");
$stmt->execute([$sid]);
$seasonMeta = $stmt->fetch();

if (!$seasonMeta) {
    die("<h3>Report Unavailable</h3><p>This season has not been archived or no history report exists.</p><a href='/season-archives'>Back to Hall of Fame</a>");
}

// 3. Fetch Historical Standings for the Sidebar
// We pull the final scores for this specific season
$racerStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
");
$racerStmt->execute([$sid . "%"]);
$racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

$finalStandings = [];
foreach ($racers as $r) {
    // This uses the calculateGPScore logic which respects season_meta rules
    $score = calculateGPScore($pdo, $r['id'], $sid);

    // Get the character they used most in this season
    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$r['id'], $sid . "%"]);
    $char = $charStmt->fetchColumn() ?: 'Mii';

    if ($score > 0) {
        $finalStandings[] = ['name' => $r['name'], 'score' => $score, 'char' => $char];
    }
}
usort($finalStandings, fn($a, $b) => $b['score'] <=> $a['score']);

// Mikkoliiga standings for this archived season. Returns empty if no
// members or no Mikkoliiga races happened this season.
$mikkoliigaStandings = getMikkoliigaStandings($pdo, $sid);
foreach ($mikkoliigaStandings as &$_m) {
    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$_m['id'], $sid . "%"]);
    $_m['char'] = $charStmt->fetchColumn() ?: 'Mii';
}
unset($_m);
// Drop members who never raced this season.
$mikkoliigaStandings = array_values(array_filter($mikkoliigaStandings, fn($m) => $m['total_gps'] > 0));

// 3.5 Fetch Season Progression Data for Timeline
$progressionData = [];
$gpStmt = $pdo->prepare("
    SELECT DISTINCT gpid, race_date
    FROM results
    WHERE gpid LIKE ?
    ORDER BY race_date ASC, gpid ASC
");
$gpStmt->execute([$sid . "%"]);
$allGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($allGPs as $gp) {
    $gpResults = $pdo->prepare("SELECT racer_id, gp_points, rank FROM results WHERE gpid = ? ORDER BY rank ASC");
    $gpResults->execute([$gp['gpid']]);
    $results = $gpResults->fetchAll(PDO::FETCH_ASSOC);

    // Calculate cumulative scores up to this GP
    $cumulativeScores = [];
    foreach ($racers as $r) {
        $scoreUpToNow = calculateGPScoreUpTo($pdo, $r['id'], $sid, $gp['race_date']);
        if ($scoreUpToNow > 0) {
            $cumulativeScores[] = [
                'name' => $r['name'],
                'score' => $scoreUpToNow
            ];
        }
    }
    usort($cumulativeScores, fn($a, $b) => $b['score'] <=> $a['score']);

    $progressionData[] = [
        'gpid' => $gp['gpid'],
        'date' => $gp['race_date'],
        'standings' => $cumulativeScores,
        'winner' => $results[0] ?? null
    ];
}

// Helper function to calculate GPScore up to a specific date
// Note: For cup-based and other complex scoring systems, progressive scoring may not be accurate
function calculateGPScoreUpTo($pdo, $racer_id, $season_id, $date) {
    $rules = getSeasonRules($pdo, $season_id);

    $scoringSystem = $rules['scoring_system'] ?? 'average_attendance';

    $stmt = $pdo->prepare("SELECT gp_points, race_date, rank, id FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' AND race_date <= ? ORDER BY gp_points ASC, id ASC");
    $stmt->execute([$racer_id, $season_id . "%", $date]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalRaces = count($results);
    if (!racerQualifies($totalRaces, $rules)) return 0;

    // Systems that replay exactly from the racer's own rows (gp_logic).
    $exact = progressiveScoreFromRows($scoringSystem, $results, $rules);
    if ($exact !== null) return $exact;

    if ($scoringSystem === 'average_attendance') {
        $attWeight = $rules['attendance_weight'] ?? 1.0;
        $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
        $dropRate = $rules['drop_rate'] ?? 10;

        $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
        $pointsOnly = array_column($results, 'gp_points');
        $filteredPoints = array_slice($pointsOnly, $numToDrop);
        $average = (count($filteredPoints) > 0) ? array_sum($filteredPoints) / count($filteredPoints) : 0;

        $attendanceBonus = 0;
        $weeklyTracker = [];
        foreach ($results as $res) {
            $weekKey = date('Y-W', strtotime($res['race_date']));
            if (!isset($weeklyTracker[$weekKey])) $weeklyTracker[$weekKey] = 0;
            if ($weeklyTracker[$weekKey] < $weeklyCap) {
                $attendanceBonus += $attWeight;
                $weeklyTracker[$weekKey] += $attWeight;
            }
        }

        return round($average + $attendanceBonus, 2);
    } elseif ($scoringSystem === 'preseason') {
        $numToDrop = floor($totalRaces * 0.1);
        $pointsOnly = array_column($results, 'gp_points');
        $filteredPoints = array_slice($pointsOnly, $numToDrop);
        return round(array_sum($filteredPoints) / count($filteredPoints), 2);
    } else {
        // Cup-, Elo- and field-dependent systems can't be replayed from one
        // racer's rows: show a plain points average and SAY SO on the page.
        $GLOBALS['progressionApprox'] = true;
        $pointsOnly = array_column($results, 'gp_points');
        return round(array_sum($pointsOnly) / count($pointsOnly), 2);
    }
}

// 4. Formatter (same as view_recap.php)
function formatTranscript($text) {
    // Bold **Name**
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong class="highlight-name">$1</strong>', $text);
    // Paragraphs
    $paragraphs = preg_split('/\n\s*\n/', $text);
    $formatted = "";
    foreach ($paragraphs as $p) {
        $cleanP = trim($p);
        if (!empty($cleanP)) {
            $cleanP = nl2br($cleanP);
            $formatted .= "<p>$cleanP</p>";
        }
    }
    return $formatted;
}

$pageTitle = "History of Season " . strtoupper($sid);
$scoringInfo = getScoringSystemInfo($pdo, $sid);
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="container report-container">

    <div class="report-layout">

        <main class="report-paper">
            <div class="report-inner">
                <div class="report-header">
                    <div class="omk-branding">
                        <div class="omk-logo">OMK</div>
                        <div class="omk-full">Organisation Mondiale du Karting</div>
                    </div>
                    <h1 class="report-title">SEASON <?= strtoupper($sid) ?></h1>
                    <div class="scoring-badge">
                        <span class="scoring-badge-icon"><?= $scoringInfo['icon'] ?></span>
                        <span class="scoring-badge-name">
                            <?= htmlspecialchars($scoringInfo['name']) ?>
                        </span>
                    </div>
                    <div class="report-date">
                        OFFICIAL REPORT RELEASED: <?= date('F j, Y', strtotime($seasonMeta['closed_at'])) ?>
                    </div>
                </div>

                <!-- Interactive Season Replay Timeline -->
                <div class="timeline-container">
                    <h2 class="timeline-heading">📊 Season Replay</h2>
                    <p class="timeline-desc">Scrub through the season timeline to see standings evolution<?php if (!empty($GLOBALS['progressionApprox'])): ?>
                        &middot; <?= htmlspecialchars((getScoringSystemDef(getSeasonRules($pdo, $sid)['scoring_system'] ?? 'average_attendance')['name'] ?? 'This system')) ?> can't be replayed GP by GP, so these snapshots rank a plain points average — the final standings above are the real ones<?php endif; ?></p>

                    <div class="timeline-slider-wrap">
                        <input type="range" id="timelineSlider" min="0" max="<?= count($progressionData) - 1 ?>" value="<?= count($progressionData) - 1 ?>" class="timeline-slider">
                    </div>

                    <div id="timelineInfo" class="timeline-info">
                        <div class="timeline-current-gp" id="currentGP"></div>
                        <div class="timeline-current-date" id="currentDate"></div>
                    </div>

                    <div id="standingsSnapshot" class="timeline-snapshot"></div>
                </div>

                <div class="report-body">
                    <?php
                        $cleanText = preg_replace('/^1\.?\s*HEADLINE:/i', '', $seasonMeta['ecology_report']);
                        $cleanText = trim($cleanText);
                        echo formatTranscript($cleanText);
                    ?>
                </div>

                <?php if (isset($_SESSION['is_admin'])): ?>
                <div class="edit-controls">
                    <h3 class="edit-controls-title">Admin Controls</h3>
                    <button onclick="toggleEditMode()" class="btn-secondary edit-toggle-btn">✏️ Edit Season Report</button>
                    <div id="editForm" class="edit-form">
                        <form method="POST" action="/api/update_season_report.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="season_id" value="<?= htmlspecialchars($sid) ?>">
                            <textarea name="ecology_report" id="reportEditor" class="report-editor"><?= htmlspecialchars($seasonMeta['ecology_report']) ?></textarea>
                            <div class="edit-form-actions">
                                <button type="submit" class="btn-success">💾 Save Changes</button>
                                <button type="button" onclick="toggleEditMode()" class="btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="report-footer">
                    <div class="signature">
                        <span class="signature-label">Official Documentation by</span><br>
                        <strong>Organisation Mondiale du Karting</strong><br>
                        <span class="signature-location">Geneva, Switzerland</span>
                    </div>
                    <a href="/season-archives" class="btn-primary btn-back">&larr; Hall of Fame</a>
                </div>
            </div>
        </main>

        <aside class="standings-sidebar">
            <h3 class="sidebar-title">Final Standings</h3>
            <div class="sidebar-list">
                <?php foreach ($finalStandings as $index => $row):
                    $rank = $index + 1;
                    $rankClass = ($rank <= 3) ? ['gold', 'silver', 'bronze'][$rank-1] : "";
                ?>
                <div class="sidebar-card <?= $rankClass ?>">
                    <div class="s-rank">#<?= $rank ?></div>
                    <div class="s-portrait">
                        <img src="/assets/img/<?= htmlspecialchars($row['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'">
                    </div>
                    <div class="s-info">
                        <div class="s-name"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="s-score"><?= number_format($row['score'], 2) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($mikkoliigaStandings)): ?>
            <h3 class="sidebar-title sidebar-title--mikko">
                🌟 Mikkoliiga Standings
            </h3>
            <p class="sidebar-mikko-caption">Casual sub-league · best <?= MIKKOLIIGA_BEST_X ?> of <?= count($mikkoliigaStandings) ?> members</p>
            <div class="sidebar-list">
                <?php foreach ($mikkoliigaStandings as $index => $row):
                    $rank = $index + 1;
                    $rankClass = ($rank <= 3) ? ['gold', 'silver', 'bronze'][$rank-1] : "";
                ?>
                <div class="sidebar-card <?= $rankClass ?>">
                    <div class="s-rank">#<?= $rank ?></div>
                    <div class="s-portrait">
                        <img src="/assets/img/<?= htmlspecialchars($row['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'">
                    </div>
                    <div class="s-info">
                        <div class="s-name"><?= htmlspecialchars($row['name']) ?></div>
                        <div class="s-score"><?= $row['score'] ?> <span style="font-size:0.7em; color:#999;">pts</span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </aside>

    </div>
</div>

<script>
const progressionData = <?= json_encode($progressionData) ?>;
const slider = document.getElementById('timelineSlider');
const currentGP = document.getElementById('currentGP');
const currentDate = document.getElementById('currentDate');
const standingsSnapshot = document.getElementById('standingsSnapshot');

function updateTimeline(index) {
    const data = progressionData[index];
    currentGP.textContent = data.gpid.toUpperCase();
    currentDate.textContent = new Date(data.date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

    standingsSnapshot.innerHTML = '';
    data.standings.slice(0, 8).forEach((racer, idx) => {
        const rank = idx + 1;
        const isLeader = rank === 1;
        const isPodium = rank <= 3;

        const card = document.createElement('div');
        card.className = `timeline-card ${isLeader ? 'leader' : (isPodium ? 'podium' : '')}`;
        card.innerHTML = `
            <div class="timeline-card-inner">
                <div class="timeline-card-rank" style="color: ${isLeader ? '#FFD700' : (isPodium ? '#2EBD59' : '#888')};">#${rank}</div>
                <div class="timeline-card-body">
                    <div class="timeline-card-name">${racer.name}</div>
                    <div class="timeline-card-score">${racer.score.toFixed(2)}</div>
                </div>
            </div>
        `;
        standingsSnapshot.appendChild(card);
    });
}

slider.addEventListener('input', (e) => {
    updateTimeline(parseInt(e.target.value));
});

// Initialize with final standings
updateTimeline(progressionData.length - 1);

// Edit Mode Toggle
function toggleEditMode() {
    const editForm = document.getElementById('editForm');
    if (editForm.style.display === 'none' || editForm.style.display === '') {
        editForm.style.display = 'block';
    } else {
        editForm.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
