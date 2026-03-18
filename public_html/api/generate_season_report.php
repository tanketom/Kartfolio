<?php
/**
 * Season Report Generator & Archiver
 * Path: /cdnmk/public_html/api/generate_season_report.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_admin();

$config = require __DIR__ . '/../../private/config/config.php';
$apiKey = $config['gemini_api_key'] ?? '';
$model  = $config['model_name'] ?? 'gemini-1.5-flash';

$seasonId = $_GET['season'] ?? null;
if (!$seasonId) {
    header("Location: ../admin/seasons.php");
    exit;
}

// 1. Fetch Season Rules
$metaStmt = $pdo->prepare("SELECT * FROM season_meta WHERE season_id = ?");
$metaStmt->execute([$seasonId]);
$rules = $metaStmt->fetch(PDO::FETCH_ASSOC);

// 2. Identify the Champion (Ranking logic)
$racerStmt = $pdo->prepare("SELECT DISTINCT r.id, r.name FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
$racerStmt->execute([$seasonId . "%"]);
$racers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

$standings = [];
foreach ($racers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);
    if ($score > 0) {
        $standings[] = ['id' => $r['id'], 'name' => $r['name'], 'score' => $score];
    }
}
$currentScoringSystem = $rules['scoring_system'] ?? 'average_attendance';
if ($currentScoringSystem === 'top_12_unique') {
    foreach ($standings as &$s) {
        $s['tiebreaker'] = getTop12UniqueTiebreaker($pdo, $s['id'], $seasonId);
    }
    unset($s);
    usort($standings, function($a, $b) {
        if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
} else {
    usort($standings, fn($a, $b) => $b['score'] <=> $a['score']);
}

$winner = $standings[0] ?? null;
$winnerName = $winner['name'] ?? 'The Ghost';
$winnerChar = 'Mii';

if ($winner) {
    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$winner['id'], $seasonId . "%"]);
    $winnerChar = $charStmt->fetchColumn() ?: 'Mii';
}

// 3. Prepare AI Prompt
$standingsText = "";
foreach ($standings as $i => $s) {
    $rank = $i + 1;
    $standingsText .= "#$rank: {$s['name']} ({$s['score']} pts)\n";
}

$prompt = "Act as a Mushroom Kingdom sports historian. Write an archival report for Season $seasonId.
The champion is $winnerName.
Final Standings:
$standingsText
Tone: Professional, nostalgic, slightly eccentric.";

// 4. Gemini API Call
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;
$data = ["contents" => [["parts" => [["text" => $prompt]]]]];

$jsonBody = json_encode($data);
if ($jsonBody === false) {
    $jsonBody = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonBody === false) {
        die("Error: Failed to encode API payload as JSON — " . json_last_error_msg());
    }
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$aiReport = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Historical report pending archive retrieval.";

// 5. Update Metadata with Final Snapshot
$update = $pdo->prepare("
    UPDATE season_meta 
    SET ecology_report = ?, 
        status = 'archived', 
        closed_at = CURRENT_TIMESTAMP,
        champion_name = ?,
        champion_char = ?
    WHERE season_id = ?
");
$update->execute([$aiReport, $winnerName, $winnerChar, $seasonId]);

$_SESSION['success'] = "Season archived! Long live Champion $winnerName!";
header("Location: ../season_archives.php");
exit;