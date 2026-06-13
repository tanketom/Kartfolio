<?php
/**
 * Racer Management - High Fidelity Admin
 * Path: /cdnmk/public_html/admin/racers.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

$message = "";
$status = "success";

// 1. Handle Deletion (With Safety Check)
if (isset($_GET['delete'])) {
    $check = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ?");
    $check->execute([$_GET['delete']]);
    if ($check->fetchColumn() > 0) {
        $message = "Cannot delete: Racer has existing GP results. Retire them instead?";
        $status = "error";
    } else {
        $stmt = $pdo->prepare("DELETE FROM racers WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $message = "Racer removed from the roster.";
    }
}

// 2. Handle Save/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id        = $_POST['racer_id'] ?? '';
    $name      = trim($_POST['name']);
    $nick      = trim($_POST['nickname']);
    $phrase    = trim($_POST['catchphrase']);
    $mikko     = isset($_POST['in_mikkoliiga']) ? 1 : 0;
    $retired   = isset($_POST['is_retired']) ? 1 : 0;

    if (!empty($id)) {
        $stmt = $pdo->prepare("UPDATE racers SET name = ?, nickname = ?, catchphrase = ?, in_mikkoliiga = ?, is_retired = ? WHERE id = ?");
        $stmt->execute([$name, $nick, $phrase, $mikko, $retired, $id]);
        $message = "Updated " . htmlspecialchars($name) . " successfully.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO racers (name, nickname, catchphrase, in_mikkoliiga, is_retired) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $nick, $phrase, $mikko, $retired]);
        $message = "Welcome to the league, $name!";
    }
}

// 3. Fetch Racers with Career Aggregates
$query = "
    SELECT r.*, 
    COUNT(res.id) as total_gps,
    MAX(res.gp_points) as pb,
    (SELECT character_used FROM results WHERE racer_id = r.id GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1) as main_char,
    (SELECT kart_setup FROM results WHERE racer_id = r.id GROUP BY kart_setup ORDER BY COUNT(*) DESC LIMIT 1) as main_kart
    FROM racers r
    LEFT JOIN results res ON r.id = res.racer_id
    GROUP BY r.id
    ORDER BY r.name ASC
";
$racers = $pdo->query($query)->fetchAll();

$pageTitle = "Racer Management";
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Racer Management</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">Roster Management</h1>
        <p class="page-subtitle">EDIT RACER PROFILES & CAREER ORIGINS</p>
    </header>

    <?php if($message): ?>
        <div class="alert <?= $status === 'success' ? 'alert-success' : 'alert-error' ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <section id="form-container" class="card">
        <h3 id="form-title" class="card-header">Add New Racer</h3>
        <form method="POST" id="racer-form">
            <?= csrf_field() ?>
            <input type="hidden" name="racer_id" id="f_id" value="">
            <div class="form-grid">
                <div class="input-group">
                    <label class="form-label">FULL NAME</label>
                    <input type="text" name="name" id="f_name" class="form-input" placeholder="e.g. Mario Segale" required>
                </div>
                <div class="input-group">
                    <label class="form-label">NICKNAME</label>
                    <input type="text" name="nickname" id="f_nickname" class="form-input" placeholder="e.g. The Red Menace">
                </div>
                <div class="input-group">
                    <label class="form-label">CATCHPHRASE</label>
                    <input type="text" name="catchphrase" id="f_catchphrase" class="form-input" placeholder="e.g. It's-a me!">
                </div>
                <div class="input-group">
                    <label class="form-label">MIKKOLIIGA</label>
                    <label style="display:flex; align-items:center; gap:0.5rem; padding:0.6rem 0;">
                        <input type="checkbox" name="in_mikkoliiga" id="f_mikkoliiga" value="1" class="mikko-admin-checkbox">
                        <span style="font-size:0.9rem; color:var(--gray-600);">Member?</span>
                    </label>
                </div>
                <div class="input-group">
                    <label class="form-label">RETIRED</label>
                    <label style="display:flex; align-items:center; gap:0.5rem; padding:0.6rem 0;">
                        <input type="checkbox" name="is_retired" id="f_retired" value="1" class="mikko-admin-checkbox">
                        <span style="font-size:0.9rem; color:var(--gray-600);">No longer racing?</span>
                    </label>
                </div>
            </div>
            <div class="flex gap-sm mt-md">
                <button type="submit" class="btn btn-primary" id="submit-btn">Save Racer Profile</button>
                <button type="button" onclick="resetForm()" id="cancel-btn" class="btn btn-secondary hidden">Cancel</button>
            </div>
        </form>
    </section>

    <div class="racer-roster-grid">
        <?php foreach ($racers as $r): ?>
        <div class="racer-card">
            <div class="racer-card-header">
                <div class="racer-avatar-section">
                    <div class="racer-avatar-frame">
                        <img src="/assets/img/<?= htmlspecialchars($r['main_char']) ?>.png"
                             class="racer-avatar-img"
                             onerror="this.src='/assets/img/Mii.png'"
                             alt="<?= htmlspecialchars($r['name']) ?>">
                    </div>
                    <div class="racer-stats-mini">
                        <div class="mini-stat">
                            <span class="mini-stat-value"><?= $r['total_gps'] ?></span>
                            <span class="mini-stat-label">GPs</span>
                        </div>
                        <div class="mini-stat">
                            <span class="mini-stat-value highlight-red"><?= $r['pb'] ?: 0 ?></span>
                            <span class="mini-stat-label">PB</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="racer-card-body">
                <h3 class="racer-card-name">
                    <?= htmlspecialchars($r['name']) ?>
                    <?php if (!empty($r['in_mikkoliiga'])): ?>
                        <span title="Mikkoliiga member" class="mikko-roster-tag">MIKKOLIIGA</span>
                    <?php endif; ?>
                    <?php if (!empty($r['is_retired'])): ?>
                        <span title="Retired racer" class="retired-roster-tag">RETIRED</span>
                    <?php endif; ?>
                </h3>

                <?php if (!empty($r['nickname'])): ?>
                    <div class="racer-card-nickname">
                        <?= htmlspecialchars($r['nickname']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($r['catchphrase'])): ?>
                    <div class="racer-card-catchphrase">
                        "<?= htmlspecialchars($r['catchphrase']) ?>"
                    </div>
                <?php endif; ?>

                <div class="racer-card-loadout">
                    <div class="loadout-label">Main Loadout</div>
                    <div class="loadout-details">
                        <span class="loadout-char"><?= htmlspecialchars($r['main_char'] ?: 'Undecided') ?></span>
                        <span class="loadout-separator">•</span>
                        <span class="loadout-kart"><?= htmlspecialchars($r['main_kart'] ?: 'Standard') ?></span>
                    </div>
                </div>
            </div>

            <div class="racer-card-actions">
                <a href="/racer/<?= $r['id'] ?>" class="btn-card btn-profile" title="View Profile">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    Profile
                </a>
                <button class="btn-card btn-edit" onclick='editRacer(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' title="Edit Racer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit
                </button>
                <a href="?delete=<?= $r['id'] ?>" class="btn-card btn-delete" onclick="event.preventDefault(); showConfirm({icon: '🗑️', title: 'Delete Racer?', message: 'Are you sure you want to delete <?= htmlspecialchars($r['name']) ?>? All their stats and race history will be permanently lost.'}).then(ok => { if(ok) window.location.href = this.href; });" title="Delete Racer">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                    Delete
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function editRacer(data) {
    const container = document.getElementById('form-container');
    container.classList.add('editing');

    document.getElementById('form-title').innerText = "Update Identity: " + data.name;
    document.getElementById('f_id').value = data.id;
    document.getElementById('f_name').value = data.name || '';
    document.getElementById('f_nickname').value = data.nickname || '';
    document.getElementById('f_catchphrase').value = data.catchphrase || '';
    document.getElementById('f_mikkoliiga').checked = !!(parseInt(data.in_mikkoliiga, 10) || 0);
    document.getElementById('f_retired').checked = !!(parseInt(data.is_retired, 10) || 0);

    document.getElementById('submit-btn').innerText = "Update Profile";
    document.getElementById('cancel-btn').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    const container = document.getElementById('form-container');
    container.classList.remove('editing');

    document.getElementById('form-title').innerText = "Add New Racer";
    document.getElementById('f_id').value = "";
    document.getElementById('racer-form').reset();
    document.getElementById('f_mikkoliiga').checked = false;
    document.getElementById('f_retired').checked = false;
    document.getElementById('submit-btn').innerText = "Save Racer Profile";
    document.getElementById('cancel-btn').classList.add('hidden');
}
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>