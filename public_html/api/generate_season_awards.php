<?php
/**
 * Season Awards Generation — AJAX endpoint.
 *
 * Called via fetch() from /admin/season_awards.php so the slow Gemini call
 * doesn't block a regular page load (shared-host proxies cut off long
 * synchronous POSTs from within an HTML response).
 *
 * Path: /cdnmk/public_html/api/generate_season_awards.php
 */

require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/season_awards_logic.php';

header('Content-Type: application/json');

require_admin();
verify_csrf();

// Give the chain room to finish. Worst case is 3 models × 3 retries with
// backoff; the helper's per-call cap is 90s so we want headroom above that.
@set_time_limit(300);
ignore_user_abort(true);

$seasonId = $_POST['season'] ?? $_GET['season'] ?? getCurrentSeasonNumber();
$config = kartfolioConfig();

try {
    $result = generateAndSaveSeasonAwards($pdo, $config, $seasonId);
    echo json_encode($result);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'fixed'   => [],
        'ai'      => [],
        'error'   => 'Fatal: ' . $e->getMessage(),
    ]);
}
