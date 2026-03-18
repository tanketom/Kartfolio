<?php
/**
 * Admin Result Management
 * Path: /cdnmk/public_html/admin/results_manage.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

$message = "";
$cups = getMK8DCups();

// 1. HANDLE UPDATES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $stmt = $pdo->prepare("UPDATE results SET 
        gp_points = ?, rank = ?, character_used = ?, 
        kart_setup = ?, cup_name = ?, is_lol = ? 
        WHERE id = ?");
    
    $is_lol = isset($_POST['is_lol']) ? 1 : 0;
    $stmt->execute([
        $_POST['gp_points'], $_POST['rank'], $_POST['character_used'], 
        $_POST['kart_setup'], $_POST['cup_name'], $is_lol, $_POST['id']
    ]);
    $message = "Result updated successfully!";
}

// 2. HANDLE DELETION
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM results WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    $message = "Entry deleted.";
}

// 2B. HANDLE BULK DELETION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $placeholders = str_repeat('?,', count($_POST['selected_ids']) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM results WHERE id IN ($placeholders)");
        $stmt->execute($_POST['selected_ids']);
        $message = count($_POST['selected_ids']) . " result(s) deleted successfully!";
    }
}

// 2C. HANDLE BULK EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_edit') {
    if (!empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
        $ids = $_POST['selected_ids'];
        $updates = [];
        $params = [];

        // Only update fields that were explicitly set (non-empty)
        if (!empty($_POST['bulk_cup_name'])) {
            $updates[] = "cup_name = ?";
            $params[] = $_POST['bulk_cup_name'];
        }
        if ($_POST['bulk_character'] ?? '' !== '') {
            $updates[] = "character_used = ?";
            $params[] = $_POST['bulk_character'];
        }
        if ($_POST['bulk_kart'] ?? '' !== '') {
            $updates[] = "kart_setup = ?";
            $params[] = $_POST['bulk_kart'];
        }
        if (isset($_POST['bulk_lol']) && $_POST['bulk_lol'] !== '') {
            $updates[] = "is_lol = ?";
            $params[] = (int)$_POST['bulk_lol'];
        }

        // Points offset (add/subtract from current)
        $pointsOffset = (int)($_POST['bulk_points_offset'] ?? 0);

        if (!empty($updates) || $pointsOffset !== 0) {
            $count = 0;
            if ($pointsOffset !== 0) {
                $updates[] = "gp_points = MAX(0, MIN(60, gp_points + ?))";
                $params[] = $pointsOffset;
            }

            if (!empty($updates)) {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $sql = "UPDATE results SET " . implode(', ', $updates) . " WHERE id IN ($placeholders)";
                $allParams = array_merge($params, $ids);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($allParams);
                $count = $stmt->rowCount();
            }
            $message = "$count result(s) updated successfully!";
        }
    }
}

// 3. FETCH DATA
$search = $_GET['search'] ?? '';
$query = "SELECT res.*, r.name FROM results res JOIN racers r ON res.racer_id = r.id";
if ($search) {
    $query .= " WHERE res.gpid LIKE :search OR r.name LIKE :search OR res.cup_name LIKE :search";
}
$query .= " ORDER BY res.race_date DESC, res.gpid DESC LIMIT 100";

$stmt = $pdo->prepare($query);
if ($search) $stmt->bindValue(':search', "%$search%");
$stmt->execute();
$results = $stmt->fetchAll();

$pageTitle = "Manage Results - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Results Management</span>
    </nav>

    <header class="section-header admin-results-header">
        <h1>Manage Race Results</h1>
        <?php if($message): ?>
            <div class="badge admin-message-badge">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
    </header>

    <form method="GET" class="admin-search-form">
        <input type="text" name="search" placeholder="Search GPID, Name, or Cup..." value="<?= htmlspecialchars($search) ?>" class="admin-select admin-search-input">
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div id="bulk-actions-bar" class="admin-bulk-bar">
        <div>
            <span id="selected-count" class="admin-bulk-count">0</span> result(s) selected
        </div>
        <div class="admin-bulk-actions">
            <button type="button" onclick="toggleBulkEdit()" class="btn btn-primary btn-sm" id="bulk-edit-toggle">Edit Selected</button>
            <button type="button" onclick="clearSelection()" class="btn btn-secondary btn-sm">Clear</button>
            <button type="button" onclick="bulkDelete()" class="btn btn-danger btn-sm">Delete Selected</button>
        </div>
    </div>

    <!-- Bulk Edit Panel -->
    <div id="bulk-edit-panel" class="admin-bulk-edit-panel" style="display: none;">
        <form method="POST" id="bulk-edit-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_edit">
            <div id="bulk-edit-ids"></div>
            <div class="bulk-edit-fields">
                <div class="bulk-edit-field">
                    <label>Cup</label>
                    <select name="bulk_cup_name" class="admin-select">
                        <option value="">— Don't change —</option>
                        <?php foreach($cups as $cup): ?>
                            <option value="<?= $cup ?>"><?= $cup ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bulk-edit-field">
                    <label>Character</label>
                    <input type="text" name="bulk_character" placeholder="Leave blank to skip" class="admin-select">
                </div>
                <div class="bulk-edit-field">
                    <label>Kart Setup</label>
                    <input type="text" name="bulk_kart" placeholder="Leave blank to skip" class="admin-select">
                </div>
                <div class="bulk-edit-field">
                    <label>Points +/-</label>
                    <input type="number" name="bulk_points_offset" value="0" min="-60" max="60" class="admin-select bulk-points-input" title="Add or subtract from current points">
                </div>
                <div class="bulk-edit-field">
                    <label>LOL?</label>
                    <select name="bulk_lol" class="admin-select">
                        <option value="">— Don't change —</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="bulk-edit-field bulk-edit-submit">
                    <button type="button" onclick="submitBulkEdit()" class="btn-primary">Apply to Selected</button>
                </div>
            </div>
        </form>
    </div>

    <form method="POST" id="bulk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_delete">
        <div id="bulk-ids-container"></div>
    </form>

    <div class="admin-table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th class="admin-th-checkbox">
                        <input type="checkbox" id="select-all" onclick="toggleSelectAll(this)" class="admin-checkbox-scaled">
                    </th>
                    <th>GPID / Date</th>
                    <th>Cup</th>
                    <th>Racer</th>
                    <th>Pts</th>
                    <th>Rank</th>
                    <th>Character</th>
                    <th>Setup</th>
                    <th>LOL?</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $res): ?>
                <tr>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $res['id'] ?>">

                        <td class="admin-td-center">
                            <input type="checkbox" class="row-checkbox admin-checkbox-scaled" value="<?= $res['id'] ?>" onchange="updateBulkActions()">
                        </td>
                        <td>
                            <strong class="admin-gpid-text"><?= $res['gpid'] ?></strong><br>
                            <span class="admin-date-text"><?= date('Y-m-d', strtotime($res['race_date'])) ?></span>
                        </td>
                        <td>
                            <select name="cup_name" class="admin-select admin-select-cup">
                                <option value="">-Cup-</option>
                                <?php foreach($cups as $cup): ?>
                                    <option value="<?= $cup ?>" <?= $res['cup_name'] == $cup ? 'selected' : '' ?>><?= $cup ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><strong class="admin-racer-name"><?= htmlspecialchars($res['name']) ?></strong></td>
                        <td><input type="number" name="gp_points" value="<?= $res['gp_points'] ?>" class="admin-select admin-input-pts"></td>
                        <td><input type="number" name="rank" value="<?= $res['rank'] ?>" class="admin-select admin-input-rank"></td>
                        <td><input type="text" name="character_used" value="<?= htmlspecialchars($res['character_used']) ?>" class="admin-select admin-input-char"></td>
                        <td><input type="text" name="kart_setup" value="<?= htmlspecialchars($res['kart_setup']) ?>" class="admin-select admin-input-setup" placeholder="Setup"></td>
                        <td class="admin-td-center"><input type="checkbox" name="is_lol" <?= $res['is_lol'] ? 'checked' : '' ?>></td>
                        <td>
                            <div class="admin-row-actions">
                                <button type="submit" class="btn-primary admin-btn-save-sm">SAVE</button>
                                <a href="?delete_id=<?= $res['id'] ?>" class="btn-danger" onclick="event.preventDefault(); showConfirm({icon: '🗑️', title: 'Delete Result?', message: 'This will permanently delete this race result. This action cannot be undone.'}).then(ok => { if(ok) window.location.href = this.href; });">×</a>
                            </div>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;
    const bulkBar = document.getElementById('bulk-actions-bar');
    const selectAllCheckbox = document.getElementById('select-all');

    document.getElementById('selected-count').textContent = count;

    if (count > 0) {
        bulkBar.style.display = 'flex';
    } else {
        bulkBar.style.display = 'none';
    }

    // Update "select all" checkbox state
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    selectAllCheckbox.checked = count === allCheckboxes.length && count > 0;
    selectAllCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
}

function toggleSelectAll(checkbox) {
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    rowCheckboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateBulkActions();
}

function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

async function bulkDelete() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;

    if (count === 0) return;

    const confirmed = await showConfirm({
        icon: '🗑️',
        title: 'Delete Multiple Results?',
        message: `Are you sure you want to permanently delete ${count} race result(s)? This action cannot be undone.`
    });

    if (!confirmed) return;

    // Build form with selected IDs
    const container = document.getElementById('bulk-ids-container');
    container.innerHTML = '';

    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });

    // Submit form
    document.getElementById('bulk-form').submit();
}

function toggleBulkEdit() {
    const panel = document.getElementById('bulk-edit-panel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

async function submitBulkEdit() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;

    if (count === 0) return;

    const confirmed = await showConfirm({
        icon: '✏️',
        title: 'Bulk Edit Results?',
        message: `Apply changes to ${count} selected result(s)? Only non-empty fields will be updated.`
    });

    if (!confirmed) return;

    const container = document.getElementById('bulk-edit-ids');
    container.innerHTML = '';

    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });

    document.getElementById('bulk-edit-form').submit();
}
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>