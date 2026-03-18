<?php
/**
 * Tournament Bracket View & Management - Multi-Player Edition
 * Path: /cdnmk/public_html/admin/tournament_bracket.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/mk8d_characters.php';
require_admin();

$tournamentId = $_GET['id'] ?? null;
if (!$tournamentId) {
    header("Location: /admin/tournaments");
    exit;
}

// Fetch tournament details
$stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    die("Tournament not found");
}

$message = "";
$successMessage = isset($_GET['success']) && $_GET['success'] === 'match_recorded';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'advance_round') {
        // Generate next round matches
        advanceToNextRound($pdo, $tournamentId, $tournament['format']);
        $message = "Advanced to next round!";
    } elseif ($_POST['action'] === 'start_tournament') {
        // Mark tournament as in progress
        $stmt = $pdo->prepare("UPDATE tournaments SET status = 'in_progress' WHERE id = ?");
        $stmt->execute([$tournamentId]);
        $tournament['status'] = 'in_progress';
        $message = "Tournament started!";
    } elseif ($_POST['action'] === 'complete_tournament') {
        $finalWinner = null;

        if ($tournament['format'] === 'gauntlet') {
            // Winner is the last Boss standing
            $stmt = $pdo->prepare("
                SELECT winner_id FROM tournament_matches
                WHERE tournament_id = ? AND status = 'completed'
                ORDER BY match_number DESC LIMIT 1
            ");
            $stmt->execute([$tournamentId]);
            $finalWinner = $stmt->fetchColumn();
        } elseif ($tournament['format'] === 'team_relay') {
            // Calculate team scores from all relay matches
            $stmt = $pdo->prepare("
                SELECT player1_id, player2_id, winner_id FROM tournament_matches
                WHERE tournament_id = ? AND status = 'completed'
            ");
            $stmt->execute([$tournamentId]);
            $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get team assignments
            $stmt = $pdo->prepare("
                SELECT racer_id, final_placement as team_num FROM tournament_participants
                WHERE tournament_id = ?
            ");
            $stmt->execute([$tournamentId]);
            $teamAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $racerToTeam = [];
            foreach ($teamAssignments as $assignment) {
                $racerToTeam[$assignment['racer_id']] = $assignment['team_num'];
            }

            // Count wins per team
            $teamWins = [];
            foreach ($matches as $match) {
                $winnerId = $match['winner_id'];
                if ($winnerId && isset($racerToTeam[$winnerId])) {
                    $team = $racerToTeam[$winnerId];
                    $teamWins[$team] = ($teamWins[$team] ?? 0) + 1;
                }
            }

            // Find winning team
            arsort($teamWins);
            $winningTeam = array_key_first($teamWins);

            // Pick team captain (highest seed from winning team) as winner
            $stmt = $pdo->prepare("
                SELECT racer_id FROM tournament_participants
                WHERE tournament_id = ? AND final_placement = ?
                ORDER BY seed ASC LIMIT 1
            ");
            $stmt->execute([$tournamentId, $winningTeam]);
            $finalWinner = $stmt->fetchColumn();
        } else {
            // Standard brackets - get winner with is_winner=1 from last completed match
            $stmt = $pdo->prepare("
                SELECT tmp.racer_id
                FROM tournament_match_participants tmp
                JOIN tournament_matches m ON tmp.match_id = m.id
                WHERE m.tournament_id = ? AND m.status = 'completed' AND tmp.is_winner = 1
                ORDER BY m.round DESC, m.completed_at DESC
                LIMIT 1
            ");
            $stmt->execute([$tournamentId]);
            $finalWinner = $stmt->fetchColumn();
        }

        if ($finalWinner) {
            $stmt = $pdo->prepare("
                UPDATE tournaments
                SET status = 'completed', winner_id = ?, end_date = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$finalWinner, $tournamentId]);

            // Award trophy
            $stmt = $pdo->prepare("
                INSERT INTO tournament_trophies (tournament_id, racer_id, placement, trophy_type)
                VALUES (?, ?, 1, 'champion')
            ");
            $stmt->execute([$tournamentId, $finalWinner]);

            $tournament['status'] = 'completed';
            $tournament['winner_id'] = $finalWinner;
            $message = "Tournament completed! Champion crowned!";
        }
    }
}

// Fetch all matches grouped by round
$stmt = $pdo->prepare("
    SELECT m.*,
           p1.name as player1_name,
           p2.name as player2_name,
           w.name as winner_name
    FROM tournament_matches m
    LEFT JOIN racers p1 ON m.player1_id = p1.id
    LEFT JOIN racers p2 ON m.player2_id = p2.id
    LEFT JOIN racers w ON m.winner_id = w.id
    WHERE m.tournament_id = ?
    ORDER BY m.bracket DESC, m.round, m.match_number
");
$stmt->execute([$tournamentId]);
$allMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load match participants from junction table
foreach ($allMatches as &$match) {
    $stmt = $pdo->prepare("
        SELECT tmp.*, r.name, r.id as racer_id
        FROM tournament_match_participants tmp
        JOIN racers r ON tmp.racer_id = r.id
        WHERE tmp.match_id = ?
        ORDER BY tmp.id
    ");
    $stmt->execute([$match['id']]);
    $match['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-populate with most recent character and kart setup for each participant
    foreach ($match['participants'] as &$participant) {
        $stmt = $pdo->prepare("
            SELECT character_used, kart_setup
            FROM results
            WHERE racer_id = ? AND character_used IS NOT NULL AND character_used != ''
            ORDER BY race_date DESC
            LIMIT 1
        ");
        $stmt->execute([$participant['racer_id']]);
        $recentData = $stmt->fetch(PDO::FETCH_ASSOC);

        $participant['recent_character'] = $recentData['character_used'] ?? '';
        $participant['recent_kart'] = $recentData['kart_setup'] ?? '';
    }
    unset($participant);
}
unset($match);

// Group matches by round
$rounds = [];
foreach ($allMatches as $match) {
    $roundKey = $match['bracket'] . '_' . $match['round'];
    if (!isset($rounds[$roundKey])) {
        $rounds[$roundKey] = [
            'name' => formatRoundName($match['round'], $match['bracket']),
            'matches' => []
        ];
    }
    $rounds[$roundKey]['matches'][] = $match;
}

// Fetch participants
$stmt = $pdo->prepare("
    SELECT tp.*, r.name
    FROM tournament_participants tp
    JOIN racers r ON tp.racer_id = r.id
    WHERE tp.tournament_id = ?
    ORDER BY tp.seed ASC
");
$stmt->execute([$tournamentId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if current round is complete
$currentRoundComplete = true;
$hasActiveMatches = false;
foreach ($allMatches as $match) {
    if ($match['status'] !== 'completed' && $match['status'] !== 'bye') {
        $hasActiveMatches = true;
        if (count($match['participants']) >= 2) {
            $currentRoundComplete = false;
        }
    }
}

// Check if tournament is complete
$tournamentComplete = false;

if ($tournament['format'] === 'gauntlet') {
    // Gauntlet is complete when all matches are done
    $allGauntletComplete = true;
    foreach ($allMatches as $match) {
        if ($match['status'] !== 'completed') {
            $allGauntletComplete = false;
            break;
        }
    }
    $tournamentComplete = $allGauntletComplete;
} elseif ($tournament['format'] === 'team_relay') {
    // Team Relay is complete when all relay matches are done
    $allRelayComplete = true;
    foreach ($allMatches as $match) {
        if ($match['status'] !== 'completed') {
            $allRelayComplete = false;
            break;
        }
    }
    $tournamentComplete = $allRelayComplete;
} else {
    // Standard brackets - tournament is complete when all matches are done AND exactly 1 champion remains

    // First, check if there are any incomplete matches
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as incomplete_count
        FROM tournament_matches
        WHERE tournament_id = ? AND status NOT IN ('completed', 'bye')
    ");
    $stmt->execute([$tournamentId]);
    $incompleteCount = $stmt->fetchColumn();

    // If all matches are complete, check if we have a single champion
    if ($incompleteCount === 0) {
        // Get the most recent round
        $stmt = $pdo->prepare("
            SELECT m.round
            FROM tournament_matches m
            WHERE m.tournament_id = ?
            ORDER BY
                CASE m.round
                    WHEN 'F' THEN 99
                    WHEN 'GF' THEN 100
                    WHEN 'SF' THEN 98
                    WHEN 'QF' THEN 97
                    ELSE CAST(SUBSTR(m.round, 2) AS INTEGER)
                END DESC
            LIMIT 1
        ");
        $stmt->execute([$tournamentId]);
        $finalRound = $stmt->fetchColumn();

        // Count winners from the final round
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT tmp.racer_id) as final_winners
            FROM tournament_match_participants tmp
            JOIN tournament_matches m ON tmp.match_id = m.id
            WHERE m.tournament_id = ? AND m.round = ? AND tmp.is_winner = 1
        ");
        $stmt->execute([$tournamentId, $finalRound]);
        $finalWinners = $stmt->fetchColumn();

        // Tournament is complete if final round has exactly 1 winner
        if ($finalWinners === 1) {
            $tournamentComplete = true;
        }
    }
}

function formatRoundName($round, $bracket) {
    if ($bracket === 'gauntlet') {
        return 'Gauntlet Challenge';
    }
    if ($bracket === 'team_relay') {
        return 'Team Relay Matches';
    }

    $names = [
        'R1' => 'Round 1',
        'R2' => 'Round 2',
        'R3' => 'Round 3',
        'QF' => 'Quarter Finals',
        'SF' => 'Semi Finals',
        'F' => 'Finals',
        'GF' => 'Grand Finals'
    ];
    $prefix = $bracket === 'losers' ? 'Losers ' : '';
    return $prefix . ($names[$round] ?? $round);
}

function advanceToNextRound($pdo, $tournamentId, $format) {
    if ($format === 'gauntlet') {
        advanceGauntlet($pdo, $tournamentId);
        return;
    }

    if ($format === 'team_relay') {
        return; // No advancement
    }

    // Get all winners from completed matches in the most recent round
    $stmt = $pdo->prepare("
        SELECT m.round, m.bracket
        FROM tournament_matches m
        WHERE m.tournament_id = ? AND m.status = 'completed'
        ORDER BY m.round DESC
        LIMIT 1
    ");
    $stmt->execute([$tournamentId]);
    $lastCompleted = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lastCompleted) return;

    $latestRound = $lastCompleted['round'];
    $bracket = $lastCompleted['bracket'];

    // Get all winners from current round (those with is_winner=1)
    $stmt = $pdo->prepare("
        SELECT tmp.racer_id, tmp.placement, tmp.points
        FROM tournament_match_participants tmp
        JOIN tournament_matches m ON tmp.match_id = m.id
        WHERE m.tournament_id = ? AND m.round = ? AND m.bracket = ? AND tmp.is_winner = 1 AND m.status = 'completed'
        ORDER BY tmp.points DESC, tmp.placement ASC
    ");
    $stmt->execute([$tournamentId, $latestRound, $bracket]);
    $advancers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($advancers)) return;

    // Determine next round
    $nextRound = getNextRound($latestRound);
    if (!$nextRound) return;

    // Create next round matches (prefer 4 players per match, then use byes)
    $participantsPerMatch = 4;
    $matchNum = 1;

    for ($i = 0; $i < count($advancers); $i += $participantsPerMatch) {
        $matchParticipants = array_slice($advancers, $i, $participantsPerMatch);

        if (count($matchParticipants) >= 2) {
            // Create match (2-4 players)
            // Set num_advance based on match size: 4 players = top 2 advance, 2-3 players = top 1 advances
            $numAdvanceNext = (count($matchParticipants) >= 4) ? 2 : 1;

            $stmt = $pdo->prepare("
                INSERT INTO tournament_matches (tournament_id, round, match_number, bracket, num_participants, num_advance, status, player1_id, player2_id)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
            ");
            $player1 = $matchParticipants[0]['racer_id'];
            $player2 = $matchParticipants[1]['racer_id'] ?? null;
            $stmt->execute([$tournamentId, $nextRound, $matchNum, $bracket, count($matchParticipants), $numAdvanceNext, $player1, $player2]);
            $newMatchId = $pdo->lastInsertId();

            // Add participants to junction table
            foreach ($matchParticipants as $participant) {
                $stmt = $pdo->prepare("
                    INSERT INTO tournament_match_participants (match_id, racer_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$newMatchId, $participant['racer_id']]);
            }

            $matchNum++;
        } elseif (count($matchParticipants) === 1) {
            // Bye - auto-advance to next round
            $stmt = $pdo->prepare("
                INSERT INTO tournament_matches (tournament_id, round, match_number, bracket, num_participants, num_advance, status, player1_id, winner_id)
                VALUES (?, ?, ?, ?, 1, 1, 'bye', ?, ?)
            ");
            $racerId = $matchParticipants[0]['racer_id'];
            $stmt->execute([$tournamentId, $nextRound, $matchNum, $bracket, $racerId, $racerId]);
            $newMatchId = $pdo->lastInsertId();

            // Add to junction table and mark as winner
            $stmt = $pdo->prepare("
                INSERT INTO tournament_match_participants (match_id, racer_id, is_winner)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$newMatchId, $racerId]);
        }
    }
}

function getNextRound($currentRound) {
    // Extract round number if it's R1, R2, R3...
    if (preg_match('/R(\d+)/', $currentRound, $matches)) {
        $nextNum = (int)$matches[1] + 1;
        // After R3, use named rounds
        if ($nextNum === 4) return 'QF';
        return 'R' . $nextNum;
    }

    // Use fixed progression for named rounds
    $progression = [
        'QF' => 'SF',
        'SF' => 'F',
        'F' => null  // Finals is last
    ];

    return $progression[$currentRound] ?? null;
}

function advanceGauntlet($pdo, $tournamentId) {
    // Get last completed match
    $stmt = $pdo->prepare("
        SELECT * FROM tournament_matches
        WHERE tournament_id = ? AND status = 'completed'
        ORDER BY match_number DESC
        LIMIT 1
    ");
    $stmt->execute([$tournamentId]);
    $lastMatch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lastMatch) return;

    $currentBoss = $lastMatch['player1_id'];
    $challenger = $lastMatch['player2_id'];
    $winner = $lastMatch['winner_id'];

    // If challenger won, they become the new Boss
    $newBoss = ($winner === $challenger) ? $challenger : $currentBoss;

    // Find next pending match
    $stmt = $pdo->prepare("
        SELECT * FROM tournament_matches
        WHERE tournament_id = ? AND status = 'pending'
        ORDER BY match_number ASC
        LIMIT 1
    ");
    $stmt->execute([$tournamentId]);
    $nextMatch = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($nextMatch) {
        // Update next match to have new Boss as player1
        $stmt = $pdo->prepare("
            UPDATE tournament_matches
            SET player1_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$newBoss, $nextMatch['id']]);

        // Update junction table
        $stmt = $pdo->prepare("
            UPDATE tournament_match_participants
            SET racer_id = ?
            WHERE match_id = ? AND racer_id = ?
        ");
        $stmt->execute([$newBoss, $nextMatch['id'], $currentBoss]);
    }
}

// Load character list for dropdowns
$characters = getMK8DCharacters();

$pageTitle = $tournament['name'] . " - Bracket";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin/tournaments">Tournaments</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current"><?= htmlspecialchars($tournament['name']) ?></span>
    </nav>

    <?php if($message): ?>
        <div class="alert alert-success">
            ✓ <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if($successMessage): ?>
        <div class="alert alert-success">
            ✓ Match results recorded successfully!
        </div>
    <?php endif; ?>

    <!-- Winner Podium -->
    <?php if ($tournament['status'] === 'completed' && $tournament['winner_id']): ?>
        <?php
        // Get winner details
        $stmt = $pdo->prepare("SELECT name FROM racers WHERE id = ?");
        $stmt->execute([$tournament['winner_id']]);
        $winnerName = $stmt->fetchColumn();

        // Get runner-ups (top 2-3 finalists who didn't win)
        $stmt = $pdo->prepare("
            SELECT r.name, tmp.points, tmp.placement
            FROM tournament_match_participants tmp
            JOIN racers r ON tmp.racer_id = r.id
            JOIN tournament_matches m ON tmp.match_id = m.id
            WHERE m.tournament_id = ? AND tmp.is_winner = 0 AND m.status = 'completed'
            AND m.round = (
                SELECT MAX(round) FROM tournament_matches WHERE tournament_id = ? AND status = 'completed'
            )
            ORDER BY tmp.points DESC, tmp.placement ASC
            LIMIT 2
        ");
        $stmt->execute([$tournamentId, $tournamentId]);
        $runnerUps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="winner-podium">
            <div class="podium-particles"></div>
            <div class="podium-container">
                <?php if (count($runnerUps) >= 1): ?>
                    <div class="podium-place second">
                        <div class="podium-medal">🥈</div>
                        <div class="podium-name"><?= htmlspecialchars($runnerUps[0]['name']) ?></div>
                        <div class="podium-rank">2nd Place</div>
                    </div>
                <?php endif; ?>

                <div class="podium-place first">
                    <div class="podium-crown">👑</div>
                    <div class="podium-trophy">🏆</div>
                    <div class="podium-name champion"><?= htmlspecialchars($winnerName) ?></div>
                    <div class="podium-rank">Champion!</div>
                </div>

                <?php if (count($runnerUps) >= 2): ?>
                    <div class="podium-place third">
                        <div class="podium-medal">🥉</div>
                        <div class="podium-name"><?= htmlspecialchars($runnerUps[1]['name']) ?></div>
                        <div class="podium-rank">3rd Place</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="racer-card bracket-header-card">
        <div class="bracket-header-inner">
            <div>
                <h1 class="bracket-title">
                    🏆 <?= htmlspecialchars($tournament['name']) ?>
                </h1>
                <div class="bracket-meta-row">
                    <div>
                        <span class="bracket-meta-label">Format:</span>
                        <strong class="bracket-meta-value">
                            <?php
                            $formatLabels = [
                                'single_elim' => 'Single Elimination',
                                'double_elim' => 'Double Elimination',
                                'gauntlet' => 'Gauntlet',
                                'team_relay' => 'Team Relay'
                            ];
                            echo $formatLabels[$tournament['format']] ?? $tournament['format'];
                            ?>
                        </strong>
                    </div>
                    <div>
                        <span class="bracket-meta-label">Participants:</span>
                        <strong class="bracket-meta-value"><?= count($participants) ?></strong>
                    </div>
                    <div>
                        <span class="bracket-meta-label">Status:</span>
                        <strong class="bracket-status-value">
                            <?= htmlspecialchars($tournament['status']) ?>
                        </strong>
                    </div>
                </div>
            </div>
            <div class="bracket-actions">
                <?php if ($tournament['status'] === 'setup'): ?>
                    <form method="POST" class="bracket-form-inline">
                        <input type="hidden" name="action" value="start_tournament">
                        <button type="submit" class="btn btn-primary">Start Tournament</button>
                    </form>
                <?php elseif ($tournament['status'] === 'in_progress'): ?>
                    <?php if ($currentRoundComplete && !$tournamentComplete): ?>
                        <form method="POST" class="bracket-form-inline">
                            <input type="hidden" name="action" value="advance_round">
                            <button type="submit" class="btn btn-primary">Next Round! →</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($tournamentComplete): ?>
                        <form method="POST" class="bracket-form-inline">
                            <input type="hidden" name="action" value="complete_tournament">
                            <button type="submit" class="btn btn-success">🏆 Complete Tournament</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($tournament['status'] === 'completed'): ?>
                    <button onclick="document.getElementById('broadcastModal').style.display='flex'" class="btn btn-broadcast">
                        📡 Generate Broadcast
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bracket Display -->
    <?php if (empty($allMatches)): ?>
        <div class="racer-card bracket-empty-card">
            <div class="bracket-empty-icon">📋</div>
            <h2 class="bracket-empty-title">No bracket generated yet</h2>
            <p class="bracket-empty-text">Click "Start Tournament" to generate the bracket.</p>
        </div>
    <?php else: ?>
        <div class="bracket-tree-container">
            <?php foreach ($rounds as $roundKey => $roundData): ?>
                <div class="bracket-round">
                    <h2 class="bracket-round-title">
                        <?= htmlspecialchars($roundData['name']) ?>
                    </h2>

                    <div class="bracket-matches">
                        <?php foreach ($roundData['matches'] as $match): ?>
                            <div class="match-card">
                                <div class="match-number">
                                    Match #<?= $match['match_number'] ?>
                                </div>

                                <?php if ($match['status'] === 'bye'): ?>
                                    <div class="bracket-bye-display">
                                        <strong><?= htmlspecialchars($match['participants'][0]['name'] ?? 'TBD') ?></strong><br>
                                        <span class="bracket-bye-label">Bye (Advances Automatically)</span>
                                    </div>
                                <?php elseif ($match['status'] === 'completed'): ?>
                                    <!-- Completed Match Display -->
                                    <?php if (!empty($match['cup_name'])): ?>
                                        <div class="bracket-cup-display">
                                            🏁 <?= htmlspecialchars($match['cup_name']) ?> Cup
                                        </div>
                                    <?php endif; ?>
                                    <div class="bracket-participant-list">
                                        <?php foreach ($match['participants'] as $participant): ?>
                                            <div style="padding: 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; <?= $participant['is_winner'] ? 'background: #d4edda; border: 2px solid #2EBD59;' : 'background: #f8f9fa;' ?>">
                                                <div class="bracket-participant-info">
                                                    <strong><?= htmlspecialchars($participant['name']) ?></strong>
                                                    <?php if ($match['bracket'] === 'gauntlet' && $match['player1_id'] == $participant['racer_id']): ?>
                                                        <span class="bracket-boss-badge">👑 BOSS</span>
                                                    <?php endif; ?>
                                                    <?php if ($participant['placement']): ?>
                                                        <div class="bracket-placement-text">
                                                            Placement: <?= $participant['placement'] ?> | Points: <?= $participant['points'] ?>
                                                            <?php if ($participant['character_used']): ?>
                                                                | <?= htmlspecialchars($participant['character_used']) ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($participant['is_winner']): ?>
                                                    <span class="bracket-winner-check">✓</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($tournament['status'] === 'in_progress' && count($match['participants']) >= 2): ?>
                                    <!-- Match Entry Form -->
                                    <form method="POST" action="/api/record_tournament_match.php" class="bracket-match-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                        <input type="hidden" name="match_id" value="<?= $match['id'] ?>">

                                        <div class="bracket-cup-select-box">
                                            <label class="bracket-form-label-cup">🏁 Cup Name</label>
                                            <select name="cup_name" required class="bracket-form-select">
                                                <option value="">Select cup...</option>
                                                <option value="Mushroom">Mushroom Cup</option>
                                                <option value="Flower">Flower Cup</option>
                                                <option value="Star">Star Cup</option>
                                                <option value="Special">Special Cup</option>
                                                <option value="Egg">Egg Cup</option>
                                                <option value="Triforce">Triforce Cup</option>
                                                <option value="Crossing">Crossing Cup</option>
                                                <option value="Bell">Bell Cup</option>
                                                <option value="Golden Dash">Golden Dash Cup</option>
                                                <option value="Lucky Cat">Lucky Cat Cup</option>
                                                <option value="Turnip">Turnip Cup</option>
                                                <option value="Propeller">Propeller Cup</option>
                                                <option value="Rock">Rock Cup</option>
                                                <option value="Moon">Moon Cup</option>
                                                <option value="Fruit">Fruit Cup</option>
                                                <option value="Boomerang">Boomerang Cup</option>
                                                <option value="Feather">Feather Cup</option>
                                                <option value="Cherry">Cherry Cup</option>
                                                <option value="Acorn">Acorn Cup</option>
                                                <option value="Spiny">Spiny Cup</option>
                                            </select>
                                        </div>

                                        <?php foreach ($match['participants'] as $index => $participant): ?>
                                            <div class="bracket-form-participant">
                                                <div class="bracket-form-participant-name">
                                                    <?= htmlspecialchars($participant['name']) ?>
                                                    <?php if ($match['bracket'] === 'gauntlet' && $match['player1_id'] == $participant['racer_id']): ?>
                                                        <span class="bracket-boss-badge-form">👑 BOSS</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="bracket-form-grid-2col">
                                                    <div>
                                                        <label class="bracket-form-label">Placement</label>
                                                        <input type="number" name="placement_<?= $index + 1 ?>" min="1" max="12" placeholder="1-12" required
                                                               class="bracket-form-input">
                                                    </div>
                                                    <div>
                                                        <label class="bracket-form-label">Points</label>
                                                        <input type="number" name="points_<?= $index + 1 ?>" min="0" max="60" placeholder="0-60" required
                                                               class="bracket-form-input">
                                                    </div>
                                                </div>
                                                <div class="bracket-form-field-mb">
                                                    <label class="bracket-form-label">Character</label>
                                                    <select name="character_<?= $index + 1 ?>" class="bracket-form-input">
                                                        <option value="">Select character...</option>
                                                        <?php foreach ($characters as $char): ?>
                                                            <option value="<?= htmlspecialchars($char) ?>" <?= ($participant['recent_character'] === $char) ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($char) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="bracket-form-label">Kart Setup</label>
                                                    <input type="text" name="kart_<?= $index + 1 ?>" placeholder="e.g. Standard Kart / Roller / Cloud Glider"
                                                           value="<?= htmlspecialchars($participant['recent_kart']) ?>"
                                                           class="bracket-form-input">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <button type="submit" class="btn btn-primary bracket-form-submit">
                                            📊 Record Match Results
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Pending Match (TBD) -->
                                    <div class="bracket-pending-list">
                                        <?php if (empty($match['participants'])): ?>
                                            <div class="bracket-participant-tbd">
                                                <strong>TBD</strong>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($match['participants'] as $participant): ?>
                                                <div class="bracket-participant-pending">
                                                    <strong><?= htmlspecialchars($participant['name']) ?></strong>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Participants List -->
    <div class="racer-card bracket-participants-card">
        <h2 class="bracket-participants-title">Tournament Participants</h2>
        <div class="bracket-participants-grid">
            <?php foreach ($participants as $p): ?>
                <div class="bracket-participant-card">
                    <div>
                        <div class="bracket-participant-name"><?= htmlspecialchars($p['name']) ?></div>
                        <div class="bracket-participant-meta">
                            Seed #<?= $p['seed'] ?> • ELO: <?= $p['elo_at_registration'] ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Broadcast Generation Modal -->
    <?php if ($tournament['status'] === 'completed'): ?>
        <div id="broadcastModal" class="broadcast-modal">
            <div class="broadcast-modal-content">
                <span class="broadcast-modal-close" onclick="document.getElementById('broadcastModal').style.display='none'">&times;</span>

                <div class="broadcast-modal-header">
                    <div class="broadcast-live-indicator"></div>
                    <h2>Generate Tournament Broadcast</h2>
                </div>

                <p class="bracket-broadcast-desc">AI will analyze the tournament results and generate a broadcast recap in the selected program's style.</p>

                <form action="/api/gemini_tournament_recap.php" method="POST" class="broadcast-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">

                    <div class="broadcast-input-group">
                        <label>Select Program</label>
                        <select name="program" class="broadcast-select" required>
                            <option value="core_team">Kart Core Team</option>
                            <option value="reef_dispatch">Reef's Dispatch</option>
                            <option value="meta_report">The Meta Report</option>
                            <option value="the_rant">The Rant</option>
                            <option value="ghost_racer">The Ghost Racer's Ascent</option>
                            <option value="situated_spectator">The Situated Spectator</option>
                            <option value="viberacing">Viberacing</option>
                            <option value="random">🎲 Surprise Me</option>
                        </select>
                    </div>

                    <div class="broadcast-input-group">
                        <label>Director Notes (Optional)</label>
                        <textarea name="notes" placeholder="Specify focus or length (e.g. 'Focus on the finals', 'short', 'detailed')..." class="broadcast-textarea"></textarea>
                    </div>

                    <button type="submit" class="btn-broadcast-generate">📡 Broadcast Now</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>


<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
