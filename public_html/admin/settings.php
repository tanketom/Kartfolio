<?php
/**
 * Site Settings Management
 * Path: /cdnmk/public_html/admin/settings.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_admin();

// Initialize settings table
initializeSettings($pdo);

$message = "";
$error = "";

// Check for import success
if (isset($_GET['import']) && $_GET['import'] === 'success') {
    $message = "✓ Database restored successfully! A backup of your previous database was saved.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    verify_csrf();
    $updated = 0;
    $failed = 0;

    foreach ($_POST as $key => $value) {
        // Skip non-setting fields
        if (in_array($key, ['save_settings', 'csrf_token'])) {
            continue;
        }

        // Handle checkboxes (boolean settings)
        if (strpos($key, 'enable_') === 0) {
            $value = isset($_POST[$key]) ? '1' : '0';
        }

        if (updateSetting($pdo, $key, $value)) {
            $updated++;
        } else {
            $failed++;
        }
    }

    // Handle unchecked boolean settings
    $allSettings = getAllSettings($pdo);
    foreach ($allSettings as $setting) {
        if ($setting['setting_type'] === 'boolean' && !isset($_POST[$setting['setting_key']])) {
            updateSetting($pdo, $setting['setting_key'], '0');
        }
    }

    if ($updated > 0) {
        $message = "✓ Settings updated successfully! ($updated settings changed)";
    }
    if ($failed > 0) {
        $error = "⚠ Some settings failed to update ($failed errors)";
    }
}

// Get all settings grouped by category
$settingsByCategory = getSettingsByCategory($pdo);

// Category labels
$categoryLabels = [
    'league_identity' => ['label' => 'League Identity', 'icon' => '🏁'],
    'features' => ['label' => 'Features', 'icon' => '⚙️'],
    'display' => ['label' => 'Display', 'icon' => '🎨']
];

$pageTitle = "Settings - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin">Admin</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Settings</span>
    </nav>

    <header class="section-header admin-section-header">
        <h1>⚙️ Site Settings</h1>
        <p>Configure your league's branding, features, and display options.</p>
    </header>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Database Import/Export Section -->
    <div class="settings-section">
        <h2 class="settings-section-title">
            <span class="settings-icon">💾</span>
            Database Backup & Restore
        </h2>

        <div class="backup-grid">
            <div class="backup-card">
                <div class="backup-icon">📤</div>
                <h3>Export Database</h3>
                <p>Download a complete backup of your database as a SQL file.</p>
                <a href="/admin/export-database" class="btn btn-export" download>
                    📥 Download Backup
                </a>
                <p class="backup-note">Includes all racers, results, seasons, tournaments, and settings.</p>
            </div>

            <div class="backup-card">
                <div class="backup-icon">📥</div>
                <h3>Import Database</h3>
                <p>Restore your database from a backup file.</p>
                <form action="/admin/import-database" method="POST" enctype="multipart/form-data" id="import-form">
                    <?= csrf_field() ?>
                    <input type="file" name="database_file" accept=".sql,.db,.sqlite" id="db-file-input" required class="admin-file-input-hidden">
                    <button type="button" class="btn btn-import" onclick="document.getElementById('db-file-input').click()">
                        📁 Choose Backup File
                    </button>
                    <div id="file-name" class="admin-file-name-display"></div>
                    <button type="submit" class="btn btn-danger admin-import-btn-hidden" id="import-btn">
                        ⚠️ Restore Database
                    </button>
                </form>
                <p class="backup-note backup-warning">⚠️ Warning: This will replace all current data!</p>
            </div>
        </div>
    </div>

    <form method="POST" class="settings-form">
        <?= csrf_field() ?>
        <?php foreach ($settingsByCategory as $category => $settings): ?>
            <?php
            $categoryInfo = $categoryLabels[$category] ?? ['label' => ucfirst($category), 'icon' => '📝'];
            ?>
            <div class="settings-section">
                <h2 class="settings-section-title">
                    <span class="settings-icon"><?= $categoryInfo['icon'] ?></span>
                    <?= htmlspecialchars($categoryInfo['label']) ?>
                </h2>

                <div class="settings-grid">
                    <?php foreach ($settings as $setting): ?>
                        <div class="setting-item">
                            <label for="<?= htmlspecialchars($setting['setting_key']) ?>" class="setting-label">
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $setting['setting_key']))) ?>
                            </label>

                            <?php if ($setting['description']): ?>
                                <p class="setting-description"><?= htmlspecialchars($setting['description']) ?></p>
                            <?php endif; ?>

                            <?php if ($setting['setting_type'] === 'textarea'): ?>
                                <textarea
                                    id="<?= htmlspecialchars($setting['setting_key']) ?>"
                                    name="<?= htmlspecialchars($setting['setting_key']) ?>"
                                    class="setting-input setting-textarea"
                                    rows="4"
                                ><?= htmlspecialchars($setting['setting_value']) ?></textarea>

                            <?php elseif ($setting['setting_type'] === 'boolean'): ?>
                                <label class="setting-toggle">
                                    <input
                                        type="checkbox"
                                        id="<?= htmlspecialchars($setting['setting_key']) ?>"
                                        name="<?= htmlspecialchars($setting['setting_key']) ?>"
                                        value="1"
                                        <?= $setting['setting_value'] ? 'checked' : '' ?>
                                    >
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-label"><?= $setting['setting_value'] ? 'Enabled' : 'Disabled' ?></span>
                                </label>

                            <?php elseif ($setting['setting_type'] === 'color'): ?>
                                <div class="color-input-wrapper">
                                    <input
                                        type="color"
                                        id="<?= htmlspecialchars($setting['setting_key']) ?>"
                                        name="<?= htmlspecialchars($setting['setting_key']) ?>"
                                        value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                        class="setting-color-picker"
                                    >
                                    <input
                                        type="text"
                                        value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                        class="setting-input setting-color-text"
                                        readonly
                                        onclick="document.getElementById('<?= htmlspecialchars($setting['setting_key']) ?>').click()"
                                    >
                                    <div class="color-preview" style="background-color: <?= htmlspecialchars($setting['setting_value']) ?>"></div>
                                </div>

                            <?php else: ?>
                                <input
                                    type="<?= $setting['setting_type'] === 'number' ? 'number' : 'text' ?>"
                                    id="<?= htmlspecialchars($setting['setting_key']) ?>"
                                    name="<?= htmlspecialchars($setting['setting_key']) ?>"
                                    value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                    class="setting-input"
                                >
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="settings-actions">
            <button type="submit" name="save_settings" class="btn btn-primary btn-save">
                💾 Save Settings
            </button>
            <a href="/admin" class="btn btn-secondary">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
// Update color text and preview in real-time
document.querySelectorAll('.setting-color-picker').forEach(picker => {
    picker.addEventListener('input', function() {
        const textInput = this.nextElementSibling;
        const preview = textInput.nextElementSibling;

        textInput.value = this.value.toUpperCase();
        preview.style.backgroundColor = this.value;
    });
});

// Update toggle labels in real-time
document.querySelectorAll('.setting-toggle input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const label = this.parentElement.querySelector('.toggle-label');
        label.textContent = this.checked ? 'Enabled' : 'Disabled';
    });
});

// Database import file picker
const fileInput = document.getElementById('db-file-input');
const fileNameDisplay = document.getElementById('file-name');
const importBtn = document.getElementById('import-btn');
const importForm = document.getElementById('import-form');

if (fileInput) {
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const fileName = this.files[0].name;
            fileNameDisplay.textContent = `Selected: ${fileName}`;
            importBtn.style.display = 'block';
        } else {
            fileNameDisplay.textContent = '';
            importBtn.style.display = 'none';
        }
    });
}

// Confirm before database restore
if (importForm) {
    importForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const confirmed = await showConfirm({
            icon: '⚠️',
            title: 'Restore Database?',
            message: 'This will REPLACE ALL current data with the backup file. This action cannot be undone. Are you absolutely sure?'
        });

        if (confirmed) {
            this.submit();
        }
    });
}
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
