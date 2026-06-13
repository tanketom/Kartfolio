<?php
/**
 * Cup preference vote handler.
 *
 * POST { winner, loser } — records a single head-to-head preference,
 * then returns JSON with the next pair to vote on plus the latest
 * rankings (so the UI can refresh in place without a page reload).
 *
 * Voter identity is cookie-based (see cupPrefVoterId()). No login.
 *
 * Path: /cdnmk/public_html/api/vote_cup_preference.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/csrf.php';
require_once __DIR__ . '/../../private/includes/cup_ranking.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
verify_csrf();

$voterId = cupPrefVoterId();
$winner  = trim($_POST['winner'] ?? '');
$loser   = trim($_POST['loser']  ?? '');

$validCups = getMKAllCups();
if (!in_array($winner, $validCups, true) || !in_array($loser, $validCups, true) || $winner === $loser) {
    echo json_encode(['success' => false, 'error' => 'Invalid cup pair']);
    exit;
}

// Rate limit: refuse if this voter just voted within the last 2 seconds.
// Stops double-clicks but doesn't slow legitimate fast voting.
$rl = $pdo->prepare("SELECT voted_at FROM cup_preferences WHERE voter_id = ? ORDER BY voted_at DESC LIMIT 1");
$rl->execute([$voterId]);
$lastVote = $rl->fetchColumn();
if ($lastVote && (time() - strtotime($lastVote)) < 2) {
    echo json_encode(['success' => false, 'error' => 'Slow down a sec — try again.']);
    exit;
}

$ins = $pdo->prepare("INSERT INTO cup_preferences (voter_id, winner_cup, loser_cup) VALUES (?, ?, ?)");
$ins->execute([$voterId, $winner, $loser]);

// Pick the next pair + compute fresh rankings for the client to render.
$nextPair = pickCupPair($pdo, $voterId);
$rankings = cupRankings($pdo);

// Sort cups by Elo desc for the leaderboard payload.
uasort($rankings, fn($a, $b) => $b['elo'] <=> $a['elo']);
$ordered = [];
foreach ($rankings as $cup => $r) {
    $ordered[] = ['cup' => $cup, 'emoji' => getMKCupEmoji($cup)] + $r;
}

echo json_encode([
    'success'          => true,
    'next_pair'        => $nextPair,
    'rankings'         => $ordered,
    'voter_votes'      => cupPrefVoterVotes($pdo, $voterId),
    'global_votes'     => cupPrefTotalVotes($pdo),
]);
