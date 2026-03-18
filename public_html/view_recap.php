<?php
/**
 * View AI Recap - Cinematic Header, Smart Formatting & Context Sidebar
 * Path: /cdnmk/public_html/view_recap.php
 */
require_once __DIR__ . '/../private/includes/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id === 0) { header("Location: /archive"); exit; }

$isAdmin = (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);

// Handle Delete
if ($isAdmin && isset($_GET['action']) && $_GET['action'] === 'delete') {
    $del = $pdo->prepare("DELETE FROM recap_archive WHERE id = ?");
    $del->execute([$id]);
    header("Location: /archive?msg=deleted");
    exit;
}

// 1. Fetch Recap Data
$stmt = $pdo->prepare("SELECT * FROM recap_archive WHERE id = ?");
$stmt->execute([$id]);
$recap = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recap) { die("Broadcast not found."); }

// 2. Fetch Contextual Race Results (The "Sidebar")
$recentGPs = [];
$linkedIDs = trim($recap['linked_gpids'] ?? '');

if (!empty($linkedIDs)) {
    // A. Explicitly Linked GPs (e.g., "s01g05,s01g06")
    // We convert the CSV string into an array for the SQL IN clause
    $idArray = array_map('trim', explode(',', $linkedIDs));
    $placeholders = str_repeat('?,', count($idArray) - 1) . '?';
    
    $contextStmt = $pdo->prepare("
        SELECT gpid, cup_name, race_date 
        FROM results 
        WHERE gpid IN ($placeholders)
        GROUP BY gpid 
        ORDER BY race_date DESC, id DESC
    ");
    $contextStmt->execute($idArray);
    $recentGPs = $contextStmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    // B. Fallback: Recent Races relative to recap date
    $seasonId = $recap['season_id'];
    $recapDate = $recap['created_at'];

    $contextStmt = $pdo->prepare("
        SELECT gpid, cup_name, race_date 
        FROM results 
        WHERE gpid LIKE ? AND race_date <= ? 
        GROUP BY gpid 
        ORDER BY race_date DESC, id DESC 
        LIMIT 4
    ");
    $contextStmt->execute([$seasonId . "%", $recapDate]);
    $recentGPs = $contextStmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. Formatter
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

// 4. Program Definitions
$programs = [
    "core_team" => ["label" => "Kart Core Team", "img" => "program_core_team.png", "color" => "#e60012"],
    "reef_dispatch" => ["label" => "Reef’s Dispatch", "img" => "program_reef_dispatch.png", "color" => "#2c3e50"],
    "meta_report" => ["label" => "The Meta Report", "img" => "program_meta_report.png", "color" => "#27ae60"],
    "the_rant" => ["label" => "The Rant", "img" => "program_the_rant.png", "color" => "#c0392b"],
    "ghost_racer" => ["label" => "The Ghost Racer’s Ascent", "img" => "program_ghost_racer.png", "color" => "#8e44ad"],
    "situated_spectator" => ["label" => "The Situated Spectator", "img" => "program_situated_spectator.png", "color" => "#f39c12"],
    "viberacing" => ["label" => "Viberacing", "img" => "program_viberacing.png", "color" => "#ff00ff"],
    "random" => ["label" => "Special Broadcast", "img" => "program_default.png", "color" => "#333"]
];

$pKey = $recap['program_key'] ?? 'core_team';
$pInfo = $programs[$pKey] ?? $programs['core_team'];

$h = htmlspecialchars($recap['headline'] ?? 'Untitled Broadcast');
$q = htmlspecialchars($recap['key_quote'] ?? '');
$formattedText = formatTranscript($recap['recap_text'] ?? '');
$s = strtoupper($recap['season_id'] ?? 'S01');
$date = date('F j, Y', strtotime($recap['created_at']));

$pageTitle = $h;
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="recap-viewer-root">
    
    <div class="program-hero-header" style="background-color: <?= $pInfo['color'] ?>;">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <img src="/assets/img/<?= $pInfo['img'] ?>" class="hero-logo" onerror="this.src='/assets/img/program_default.png'">
            <div class="hero-text">
                <span class="hero-subtitle">OFFICIAL BROADCAST SOURCE</span>
                <h1 class="hero-title"><?= htmlspecialchars($pInfo['label']) ?></h1>
            </div>
        </div>
    </div>

    <div class="recap-layout-grid">
        
        <article class="recap-paper">
            <div class="recap-body-padding">
                <header class="recap-top-meta">
                    <div>
                        <span class="recap-season-pill"><?= $s ?></span>
                        <span class="recap-date-label"><?= $date ?></span>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="admin-controls">
                        <a href="/edit-recap/<?= $id ?>" class="btn-edit-recap">EDIT</a>
                        <a href="?id=<?= $id ?>&action=delete" class="btn-delete-recap" onclick="event.preventDefault(); showConfirm({icon: '🗑️', title: 'Delete Broadcast?', message: 'Are you sure you want to delete this broadcast? This action cannot be undone.'}).then(ok => { if(ok) window.location.href = this.href; });">DELETE</a>
                    </div>
                    <?php endif; ?>
                </header>

                <h2 class="recap-full-headline"><?= $h ?></h2>

                <?php if (!empty($q)): ?>
                <aside class="recap-featured-quote"><p>"<?= $q ?>"</p></aside>
                <?php endif; ?>

                <section id="transcript-body" class="recap-transcript">
                    <?= $formattedText ?>
                </section>

                <footer class="recap-footer">
                    <button onclick="copyTranscript()" id="copy-btn" class="btn-copy-transcript">
                        <span id="copy-icon">📋</span> <span id="copy-text">Copy for Discord</span>
                    </button>
                    <a href="/archive" class="back-link">&larr; Archive</a>
                </footer>
            </div>
        </article>

        <aside class="recap-sidebar">
            <h3 class="sidebar-label">Relevant Telemetry</h3>
            <p class="sidebar-sub">Races analyzed in this report</p>

            <?php if (empty($recentGPs)): ?>
                <div class="sidebar-empty">No telemetry data linked.</div>
            <?php else: ?>
                <?php foreach ($recentGPs as $gp): 
                    // Fetch FULL Results for this GP (No Limit)
                    $resStmt = $pdo->prepare("
                        SELECT r.name, res.gp_points, res.rank 
                        FROM results res 
                        JOIN racers r ON res.racer_id = r.id 
                        WHERE res.gpid = ? 
                        ORDER BY res.rank ASC
                    ");
                    $resStmt->execute([$gp['gpid']]);
                    $results = $resStmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <div class="sidebar-gp-card">
                    <div class="sgp-header">
                        <span class="sgp-cup"><?= htmlspecialchars($gp['cup_name']) ?> Cup</span>
                        <span class="sgp-date"><?= date('M j', strtotime($gp['race_date'])) ?></span>
                    </div>
                    <div class="sgp-podium">
                        <?php foreach ($results as $p): 
                            $medal = ($p['rank'] == 1) ? '🥇' : (($p['rank'] == 2) ? '🥈' : (($p['rank'] == 3) ? '🥉' : $p['rank']));
                        ?>
                        <div class="sgp-row">
                            <span class="sgp-rank"><?= $medal ?></span>
                            <span class="sgp-name"><?= htmlspecialchars($p['name']) ?></span>
                            <span class="sgp-pts"><?= $p['gp_points'] ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

    </div>
</div>


<script>
function copyTranscript() {
    const text = document.getElementById('transcript-body').innerText;
    const btn = document.getElementById('copy-btn');
    const btnText = document.getElementById('copy-text');
    const formattedText = "### " + "<?= $h ?>\n> " + "<?= $q ?>\n\n" + text;
    navigator.clipboard.writeText(formattedText).then(() => {
        btn.classList.add('success');
        btnText.innerText = 'Copied!';
        setTimeout(() => { btn.classList.remove('success'); btnText.innerText = 'Copy for Discord'; }, 2000);
    });
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>