<?php
/**
 * Track preference vote handler.
 *
 * POST { winner, loser } — records a single head-to-head preference,
 * then returns JSON with the next pair plus the latest rankings so the
 * UI can refresh in place without a page reload.
 *
 * Voter identity is cookie-based (see trackPrefVoterId()). No login.
 *
 * Path: /cdnmk/public_html/api/vote_track_preference.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/csrf.php';
require_once __DIR__ . '/../../private/includes/track_ranking.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
verify_csrf();

$voterId = trackPrefVoterId();
require_once __DIR__ . '/../../private/includes/throttle.php';
if (!throttleAllow($pdo, 'track_vote', 120, 10)) { http_response_code(429); echo json_encode(['error' => 'Too many votes from this connection. Take a breather.']); exit; }
$winner  = trim($_POST['winner'] ?? '');
$loser   = trim($_POST['loser']  ?? '');

$validTracks = getMKAllTracks();
if (!in_array($winner, $validTracks, true) || !in_array($loser, $validTracks, true) || $winner === $loser) {
    echo json_encode(['success' => false, 'error' => 'Invalid track pair']);
    exit;
}

// Rate limit: refuse if this voter just voted within the last 2 seconds.
$rl = $pdo->prepare("SELECT voted_at FROM track_preferences WHERE voter_id = ? ORDER BY voted_at DESC LIMIT 1");
$rl->execute([$voterId]);
$lastVote = $rl->fetchColumn();
if ($lastVote && (time() - strtotime($lastVote)) < 2) {
    echo json_encode(['success' => false, 'error' => 'Slow down a sec — try again.']);
    exit;
}

$ins = $pdo->prepare("INSERT INTO track_preferences (voter_id, winner_track, loser_track) VALUES (?, ?, ?)");
$ins->execute([$voterId, $winner, $loser]);

// Next pair + fresh rankings.
$nextPair = pickTrackPair($pdo, $voterId);
$rankings = trackRankings($pdo);
uasort($rankings, fn($a, $b) => $b['elo'] <=> $a['elo']);

$ordered = [];
foreach ($rankings as $track => $r) {
    $ordered[] = [
        'track' => $track,
        'emoji' => getMKTrackEmoji($track),
        'slug'  => getMKTrackImageSlug($track),
    ] + $r;
}

$nextPairWithImages = array_map(fn($t) => [
    'name'  => $t,
    'emoji' => getMKTrackEmoji($t),
    'slug'  => getMKTrackImageSlug($t),
    'cup'   => getMKTrackCup($t),
], $nextPair);

echo json_encode([
    'success'      => true,
    'next_pair'    => $nextPairWithImages,
    'rankings'     => $ordered,
    'voter_votes'  => trackPrefVoterVotes($pdo, $voterId),
    'global_votes' => trackPrefTotalVotes($pdo),
]);
