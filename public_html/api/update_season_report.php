<?php
require_once __DIR__ . '/../../private/includes/session.php';
/**
 * Update Season Report
 * Path: /cdnmk/public_html/api/update_season_report.php
 */
kartfolioSessionStart();
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/csrf.php';

// Check admin authentication
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    die('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}
verify_csrf();

$seasonId = $_POST['season_id'] ?? '';
$ecologyReport = $_POST['ecology_report'] ?? '';

if (empty($seasonId) || empty($ecologyReport)) {
    http_response_code(400);
    die('Missing required fields');
}

try {
    $stmt = $pdo->prepare("UPDATE season_meta SET ecology_report = ? WHERE season_id = ?");
    $stmt->execute([$ecologyReport, $seasonId]);

    // Redirect back to the season report
    header("Location: /view-season-report?season=" . urlencode($seasonId) . "&updated=1");
    exit;
} catch (Exception $e) {
    http_response_code(500);
    die('Error updating report: ' . $e->getMessage());
}
