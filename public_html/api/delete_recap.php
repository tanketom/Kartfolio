<?php
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM recap_archive WHERE id = ?");
    $stmt->execute([$_GET['id']]);
}

header("Location: /archive?msg=deleted");
exit;