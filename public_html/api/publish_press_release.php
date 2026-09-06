<?php
/**
 * OMK Press Office — direct publish endpoint (no AI).
 *
 * Takes a headline + key_quote + body from the admin form on archive.php
 * and inserts straight into recap_archive with program_key = 'press_office'.
 * No Gemini, no Director's Notes, no rewriting — what you type is what gets
 * published.
 *
 * Path: /cdnmk/public_html/api/publish_press_release.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

require_admin();
verify_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /archive');
    exit;
}

$headline    = trim($_POST['headline']     ?? '');
$keyQuote    = trim($_POST['key_quote']    ?? '');
$body        = trim($_POST['body']         ?? '');
$linkedRaw   = trim($_POST['linked_gpids'] ?? '');

if ($headline === '' || $body === '') {
    header('Location: /archive?msg=press_validation');
    exit;
}

// Length guards — match the column expectations and keep card layouts sane.
if (strlen($headline) > 200)   $headline = substr($headline, 0, 200);
if (strlen($keyQuote) > 300)   $keyQuote = substr($keyQuote, 0, 300);

// Sanitise linked_gpids — keep only gpid-shaped tokens.
$linkedGpids = '';
if ($linkedRaw !== '') {
    $tokens = preg_split('/[\s,]+/', $linkedRaw);
    $clean  = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok !== '' && preg_match('/^[a-z0-9_\-]+$/i', $tok)) {
            $clean[] = $tok;
        }
    }
    $linkedGpids = implode(',', $clean);
}

$seasonId = getCurrentSeasonNumber();

$asDraft = !empty($_POST['draft']);
$stmt = $pdo->prepare("
    INSERT INTO recap_archive
        (season_id, recap_text, headline, key_quote, program_key, linked_gpids, status)
    VALUES (?, ?, ?, ?, 'press_office', ?, ?)
");
$stmt->execute([$seasonId, $body, $headline, $keyQuote, $linkedGpids, $asDraft ? 'draft' : 'published']);
$id = (int)$pdo->lastInsertId();

// A draft lands on the News desk; a published item on its own page.
header($asDraft ? "Location: /admin/news?open={$id}" : "Location: /view-recap/{$id}");
exit;
