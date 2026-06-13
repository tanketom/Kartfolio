<?php
/**
 * Edit AI Recap - Full Metadata Control
 * Path: /cdnmk/public_html/admin/edit_recap.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) { header("Location: ../archive.php"); exit; }
$id = (int)$_GET['id'];
$message = "";

// 0. Program Definitions for the dropdown — emoji-prefixed labels, kept
// in sync with the shared catalog by reading from it.
require_once __DIR__ . '/../../private/includes/programs.php';
$programEmojis = [
    'press_office'       => '📰',
    'core_team'          => '🎙️',
    'reef_dispatch'      => '🚬',
    'meta_report'        => '📊',
    'the_rant'           => '🤬',
    'ghost_racer'        => '👻',
    'situated_spectator' => '🎓',
    'viberacing'         => '✨',
    'random'             => '🎲',
];
$programs = [];
foreach (getProgramsCatalog() as $key => $info) {
    $emoji = $programEmojis[$key] ?? '🎙️';
    $programs[$key] = $emoji . ' ' . $info['label'];
}

// 1. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Clean up GPIDs (remove spaces, ensure comma separation)
    $cleanGPIDs = trim($_POST['linked_gpids']);
    $cleanGPIDs = preg_replace('/\s+/', '', $cleanGPIDs); // Remove whitespace

    $stmt = $pdo->prepare("
        UPDATE recap_archive 
        SET headline = ?, 
            key_quote = ?, 
            recap_text = ?, 
            season_id = ?, 
            linked_gpids = ?,
            program_key = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $_POST['headline'], 
        $_POST['key_quote'], 
        $_POST['recap_text'], 
        $_POST['season_id'],
        $cleanGPIDs,
        $_POST['program_key'],
        $id
    ]);
    
    $message = "Changes saved successfully!";
}

// 2. Fetch Data
$stmt = $pdo->prepare("SELECT * FROM recap_archive WHERE id = ?");
$stmt->execute([$id]);
$recap = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recap) { die("Recap not found."); }

$pageTitle = "Edit: " . ($recap['headline'] ?: "Broadcast #" . $id);
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="edit-container admin-edit-container">

    <header class="admin-edit-header">
        <h1>Edit Broadcast Data</h1>
        <a href="/view-recap/<?= $id ?>" class="admin-edit-back-link">&larr; Back to View</a>
    </header>

    <?php if($message): ?>
        <div class="admin-alert-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="admin-form-card">
        <?= csrf_field() ?>

        <div class="admin-grid-3col">
            <div>
                <label class="admin-label">Broadcast Headline</label>
                <input type="text" name="headline" value="<?= htmlspecialchars($recap['headline']) ?>" class="admin-input admin-input-bold">
            </div>
            
            <div>
                <label class="admin-label">Program Source</label>
                <select name="program_key" class="admin-input">
                    <?php foreach($programs as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($recap['program_key'] ?? 'core_team') === $key ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="admin-label">Season Tag</label>
                <input type="text" name="season_id" value="<?= htmlspecialchars($recap['season_id']) ?>" class="admin-input">
            </div>
        </div>

        <div class="admin-grid-2col">
            <div>
                <label class="admin-label">Key Quote (Ticker Display)</label>
                <input type="text" name="key_quote" value="<?= htmlspecialchars($recap['key_quote']) ?>" class="admin-input admin-input-italic">
            </div>
            <div>
                <label class="admin-label">Linked GP IDs</label>
                <input type="text" name="linked_gpids" value="<?= htmlspecialchars($recap['linked_gpids'] ?? '') ?>" class="admin-input" placeholder="e.g. s01g04, s01g05">
                <div class="admin-field-hint">Comma separated. Populates sidebar.</div>
            </div>
        </div>

        <div class="admin-field-group">
            <label class="admin-label">Full Script Transcript</label>
            <textarea name="recap_text" rows="20" class="admin-input admin-textarea-mono"><?= htmlspecialchars($recap['recap_text']) ?></textarea>
            <div class="admin-field-tip">
                Tip: Use **asterisks** to bold names. Double newlines create paragraphs.
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn-primary admin-btn-submit-full">Save All Changes</button>
            <a href="/view-recap/<?= $id ?>" class="admin-btn-cancel">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>