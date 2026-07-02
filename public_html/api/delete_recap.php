<?php
/**
 * Delete a broadcast from the recap archive.
 * POST-only + CSRF: state-changing GETs are forgeable by any page the admin
 * visits (a bare <img src> was enough to delete before this hardening).
 * Path: /cdnmk/public_html/api/delete_recap.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /archive");
    exit;
}
verify_csrf();

if (isset($_POST['id']) && is_numeric($_POST['id'])) {
    $stmt = $pdo->prepare("DELETE FROM recap_archive WHERE id = ?");
    $stmt->execute([$_POST['id']]);
}

header("Location: /archive?msg=deleted");
exit;
