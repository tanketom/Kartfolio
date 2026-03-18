<?php
/**
 * Discord Integration Example for add_result.php
 * Path: /cdnmk/private/includes/discord_integration_example.php
 *
 * This shows how to integrate Discord notifications into your existing result posting flow.
 * Add this code to add_result.php after a successful transaction commit.
 */

// EXAMPLE: How to modify add_result.php to send Discord notifications

/*
// After line 94 in add_result.php, BEFORE $pdo->commit():

// 1. Fetch the results we just posted
$resultsStmt = $pdo->prepare("
    SELECT r.name, res.gp_points, res.rank
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid = ? AND res.cup_name = ?
    ORDER BY res.gp_points DESC
");
$resultsStmt->execute([$_POST['gpid'], $_POST['cup_name']]);
$topResults = $resultsStmt->fetchAll();

// 2. Send Discord notification
require_once __DIR__ . '/../private/includes/discord.php';
$discord = new DiscordNotifier();

// Send the new GP notification
$discord->notifyNewGP(
    $_POST['gpid'],
    $_POST['cup_name'],
    $_POST['race_date'],
    $topResults
);

// Optionally, also send standings update
$seasonId = substr($_POST['gpid'], 0, 4); // Extract "2025" from "2025gp01"

// Fetch current standings
$standingsStmt = $pdo->prepare("
    SELECT
        r.name,
        calculateGPScore(r.id, ?) as score
    FROM racers r
    WHERE EXISTS (
        SELECT 1 FROM results res
        WHERE res.racer_id = r.id
        AND res.gpid LIKE ?
    )
    ORDER BY score DESC
");
$standingsStmt->execute([$seasonId, $seasonId . '%']);
$standings = $standingsStmt->fetchAll();

$discord->notifyStandings($seasonId, $standings);

// Then commit
$pdo->commit();
*/

// ALTERNATIVE: Create a webhook endpoint that can be called after posting results
// This allows you to send notifications even if the form submission is from mobile

/*
// Create a new file: /public_html/api/notify_discord.php

<?php
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/discord.php';

// Simple API key check (set this in your environment)
$apiKey = $_GET['key'] ?? '';
if ($apiKey !== getenv('DISCORD_NOTIFY_KEY')) {
    http_response_code(403);
    die('Invalid key');
}

$gpid = $_GET['gpid'] ?? '';
if (!$gpid) {
    http_response_code(400);
    die('Missing gpid');
}

// Fetch the GP results
$stmt = $pdo->prepare("
    SELECT r.name, res.gp_points, res.rank, res.cup_name, res.race_date
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid = ?
    ORDER BY res.gp_points DESC
");
$stmt->execute([$gpid]);
$results = $stmt->fetchAll();

if (empty($results)) {
    http_response_code(404);
    die('No results found');
}

// Send notification
$discord = new DiscordNotifier();
$success = $discord->notifyNewGP(
    $gpid,
    $results[0]['cup_name'],
    $results[0]['race_date'],
    $results
);

echo json_encode(['success' => $success]);
?>

// Then call this endpoint after posting results:
// https://yoursite.com/api/notify_discord?gpid=2025gp01&key=YOUR_SECRET_KEY
*/
