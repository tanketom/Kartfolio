<?php
/**
 * Tournament Bracket View & Management - Multi-Player Edition
 * Path: /cdnmk/public_html/admin/tournament_bracket.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/mk8d_characters.php';
require_tournament_host($pdo);

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
    verify_csrf();
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
        } elseif ($tournament['format'] === 'team_scramble') {
            // Winner = a representative of the team with the highest combined points.
            require_once __DIR__ . '/../../private/includes/scramble_tournament.php';
            $finalWinner = scrambleWinnerId($pdo, (int)$tournamentId);
        } elseif ($tournament['format'] === 'world_cup') {
            // Winner of the knockout final.
            require_once __DIR__ . '/../../private/includes/worldcup_tournament.php';
            $finalWinner = worldCupWinnerId($pdo, (int)$tournamentId);
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
} elseif ($tournament['format'] === 'survivor') {
    // Survivor is complete when the tournament row itself is closed
    // (advanceSurvivor() sets status='completed' once one racer remains).
    $tournamentComplete = ($tournament['status'] === 'completed');
} elseif ($tournament['format'] === 'snakes_ladders') {
    // S&L closes when advanceSnl() sees a token land home.
    $tournamentComplete = ($tournament['status'] === 'completed');
} elseif ($tournament['format'] === 'team_scramble') {
    // One mega-match — done as soon as it's recorded.
    $tournamentComplete = true;
    foreach ($allMatches as $match) {
        if ($match['status'] !== 'completed') { $tournamentComplete = false; break; }
    }
} elseif ($tournament['format'] === 'world_cup') {
    // Done when the knockout final has a recorded winner.
    require_once __DIR__ . '/../../private/includes/worldcup_tournament.php';
    $tournamentComplete = (worldCupWinnerId($pdo, (int)$tournamentId) !== null);
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
    if ($bracket === 'wc_group') {
        return '🌍 Group Matchday ' . substr($round, 1);
    }

    $names = [
        'R1' => 'Round 1',
        'R2' => 'Round 2',
        'R3' => 'Round 3',
        'R16' => 'Round of 16',
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

    if ($format === 'team_scramble') {
        return; // Single mega-match — no rounds to advance.
    }

    if ($format === 'world_cup') {
        require_once __DIR__ . '/../../private/includes/worldcup_tournament.php';
        advanceWorldCup($pdo, (int)$tournamentId);
        return;
    }

    if ($format === 'survivor') {
        require_once __DIR__ . '/../../private/includes/survivor_tournament.php';
        advanceSurvivor($pdo, (int)$tournamentId);
        return;
    }

    if ($format === 'snakes_ladders') {
        require_once __DIR__ . '/../../private/includes/snl_tournament.php';
        advanceSnl($pdo, (int)$tournamentId);
        return;
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
                                'single_elim'   => 'Single Elimination',
                                'double_elim'   => 'Double Elimination',
                                'gauntlet'      => 'Gauntlet',
                                'team_relay'    => 'Team Relay',
                                'survivor'      => 'Survivor',
                                'team_scramble' => 'Team Scramble',
                                'world_cup'     => 'World Cup',
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
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="start_tournament">
                        <button type="submit" class="btn btn-primary">Start Tournament</button>
                    </form>
                <?php elseif ($tournament['status'] === 'in_progress'): ?>
                    <?php if ($currentRoundComplete && !$tournamentComplete): ?>
                        <form method="POST" class="bracket-form-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="advance_round">
                            <button type="submit" class="btn btn-primary">Next Round! →</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($tournamentComplete): ?>
                        <form method="POST" class="bracket-form-inline">
                            <?= csrf_field() ?>
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

    <?php if ($tournament['format'] === 'survivor'):
        require_once __DIR__ . '/../../private/includes/survivor_tournament.php';
        $roster      = survivorRoster($pdo, (int)$tournamentId);
        $aliveCount  = count(array_filter($roster, fn($r) => $r['status'] === 'alive'));
        $elimPerRnd  = (int)($tournament['eliminations_per_round'] ?? 1);
    ?>
    <div class="racer-card survivor-deathboard">
        <header class="survivor-header">
            <h2>💀 Deathboard</h2>
            <div class="survivor-counters">
                <span><strong><?= $aliveCount ?></strong> alive</span>
                <span>·</span>
                <span><strong><?= count($roster) - $aliveCount ?></strong> eliminated</span>
                <?php if ($elimPerRnd > 1): ?>
                <span>·</span>
                <span><strong><?= $elimPerRnd ?></strong> out per round</span>
                <?php endif; ?>
            </div>
        </header>

        <div class="survivor-grid">
            <div class="survivor-col">
                <h3 class="survivor-col-title">⚔️ Alive</h3>
                <?php $aliveOnly = array_filter($roster, fn($r) => $r['status'] === 'alive'); ?>
                <?php foreach ($aliveOnly as $r): ?>
                <div class="survivor-card survivor-card--alive">
                    <span class="survivor-seed">#<?= $r['seed'] ?></span>
                    <span class="survivor-name"><?= htmlspecialchars($r['name']) ?></span>
                    <?php if (!empty($r['elo_at_registration'])): ?>
                        <span class="survivor-elo"><?= (int)$r['elo_at_registration'] ?> ELO</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($aliveCount === 1): ?>
                    <div class="survivor-champion-note">🏆 Last one standing wins!</div>
                <?php endif; ?>
            </div>
            <div class="survivor-col">
                <h3 class="survivor-col-title">🪦 Eliminated</h3>
                <?php
                    // Order eliminated by elimination round (most recent first).
                    $elimOnly = array_filter($roster, fn($r) => $r['status'] === 'eliminated');
                    usort($elimOnly, fn($a, $b) => ($b['final_placement'] ?? 999) <=> ($a['final_placement'] ?? 999));
                ?>
                <?php if (empty($elimOnly)): ?>
                    <div class="survivor-empty">Nobody out yet.</div>
                <?php else: ?>
                    <?php foreach ($elimOnly as $r): ?>
                    <div class="survivor-card survivor-card--out">
                        <span class="survivor-seed">#<?= $r['seed'] ?></span>
                        <span class="survivor-name"><?= htmlspecialchars($r['name']) ?></span>
                        <span class="survivor-placement">Out in <?= htmlspecialchars($r['elimination_round'] ?? '?') ?> · finished <?= (int)$r['final_placement'] ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <style>
    .survivor-deathboard { padding: 20px 24px; }
    .survivor-header { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
    .survivor-header h2 { margin:0; font-size:1.4rem; }
    .survivor-counters { color:var(--gray-600); font-size:0.95rem; display:flex; gap:6px; align-items:baseline; }
    .survivor-counters strong { color:var(--gray-900); }
    .survivor-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .survivor-col-title { font-size:0.95rem; text-transform:uppercase; letter-spacing:1px; margin:0 0 8px; color:var(--gray-700); }
    .survivor-card { display:flex; align-items:center; gap:10px; padding:8px 12px; border-radius:6px; margin-bottom:6px; font-size:0.92rem; }
    .survivor-card--alive { background:#e6f6ec; border-left:4px solid #2EBD59; }
    .survivor-card--out   { background:#f4eeee; border-left:4px solid #999; color:var(--gray-700); text-decoration:line-through; text-decoration-thickness:1px; }
    .survivor-seed { font-weight:700; color:#999; min-width:32px; }
    .survivor-name { flex:1; font-weight:700; }
    .survivor-elo, .survivor-placement { font-size:0.78rem; color:#777; }
    .survivor-empty { color:#999; font-style:italic; padding:8px 0; }
    .survivor-champion-note { margin-top:8px; padding:10px; background:#FFD700; border-radius:6px; font-weight:800; text-align:center; color:#5a3a00; }
    @media (max-width:600px) { .survivor-grid { grid-template-columns:1fr; } }
    </style>
    <?php endif; ?>

    <?php if ($tournament['format'] === 'team_scramble'):
        require_once __DIR__ . '/../../private/includes/scramble_tournament.php';
        $scramble = scrambleStandings($pdo, (int)$tournamentId);
        $recorded = !empty($scramble) && $scramble[0]['total'] > 0;
    ?>
    <div class="racer-card scramble-board">
        <header class="scramble-header">
            <h2>🤝 Team Scramble</h2>
            <span class="scramble-sub"><?= count($scramble) ?> teams · highest combined points wins<?= $recorded ? '' : ' · race the GP and record it below' ?></span>
        </header>
        <div class="scramble-grid">
            <?php foreach ($scramble as $rank => $t):
                $medal = $recorded ? (['🥇','🥈','🥉'][$rank] ?? ('#' . ($rank + 1))) : '';
            ?>
            <div class="scramble-team" style="border-top-color: <?= htmlspecialchars($t['color']) ?>;">
                <div class="scramble-team-head">
                    <span class="scramble-dot" style="background: <?= htmlspecialchars($t['color']) ?>;"></span>
                    <span class="scramble-team-name"><?= htmlspecialchars($t['name']) ?></span>
                    <span class="scramble-team-total"><?= $recorded ? (int)$t['total'] . ' pts ' . $medal : '—' ?></span>
                </div>
                <ul class="scramble-members">
                    <?php foreach ($t['members'] as $m): ?>
                        <li><span><?= htmlspecialchars($m['name']) ?></span><span class="scramble-mpts"><?= $recorded ? (int)$m['points'] : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <style>
    .scramble-board { padding: 20px 24px; }
    .scramble-header { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
    .scramble-header h2 { margin:0; font-size:1.4rem; }
    .scramble-sub { color:var(--gray-600); font-size:0.9rem; }
    .scramble-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; }
    .scramble-team { background:var(--gray-100); border:1px solid var(--gray-200); border-top:4px solid #888; border-radius:8px; padding:12px 14px; }
    .scramble-team-head { display:flex; align-items:center; gap:8px; }
    .scramble-dot { width:14px; height:14px; border-radius:50%; }
    .scramble-team-name { font-weight:800; text-transform:uppercase; flex:1; }
    .scramble-team-total { font-weight:900; color:var(--gray-900); }
    .scramble-members { list-style:none; margin:10px 0 0; padding:0; }
    .scramble-members li { display:flex; justify-content:space-between; padding:3px 0; border-bottom:1px solid var(--gray-200); font-size:0.9rem; }
    .scramble-mpts { color:#888; font-weight:700; }
    </style>
    <?php endif; ?>

    <?php if ($tournament['format'] === 'snakes_ladders'):
        require_once __DIR__ . '/../../private/includes/snl_tournament.php';
        echo snlBoardHtml($pdo, (int)$tournamentId, "Record each heat's finishes below; tokens advance once the whole round is in.");
    endif; ?>

    <?php if ($tournament['format'] === 'world_cup'):
        require_once __DIR__ . '/../../private/includes/worldcup_tournament.php';
        $wcTables = worldCupGroupTables($pdo, (int)$tournamentId);
        $wcDeath  = worldCupGroupOfDeath($pdo, (int)$tournamentId);
        $wcLetters = range('A', 'Z');
    ?>
    <div class="racer-card wc-board">
        <header class="wc-header">
            <img src="/assets/img/kartificial.png" class="wc-mascot" alt="Kartificial"
                 onerror="this.style.display='none'">
            <div>
                <h2>🌍 World Cup — Group Stage</h2>
                <div class="wc-sub">
                    Top 2 per group + best third-placers advance to the knockout.
                    Hosted by <strong>Kartificial</strong>, the official mascot.
                    · <a href="/wc-pickem/<?= (int)$tournamentId ?>" target="_blank">Bracket Pick'em →</a>
                </div>
            </div>
        </header>
        <div class="wc-groups">
            <?php foreach ($wcTables as $gNum => $rows):
                $letter = $wcLetters[$gNum - 1] ?? $gNum;
                $isDeath = $wcDeath && $wcDeath[0] === $gNum;
            ?>
            <div class="wc-group <?= $isDeath ? 'wc-group--death' : '' ?>">
                <h3>Group <?= $letter ?><?= $isDeath ? ' 💀' : '' ?>
                    <?php if ($isDeath): ?><span class="wc-death-tag">Group of Death · avg Elo <?= $wcDeath[1] ?></span><?php endif; ?>
                </h3>
                <table class="wc-table">
                    <thead><tr><th>#</th><th>Racer</th><th>P</th><th>Pts</th><th>GP pts</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr class="<?= $row['rank'] <= 2 ? 'wc-qualify' : ($row['rank'] === 3 ? 'wc-bubble' : '') ?>">
                            <td><?= $row['rank'] ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= $row['played'] ?></td>
                            <td><strong><?= $row['table_pts'] ?></strong></td>
                            <td><?= $row['gp_pts'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="wc-legend">Green rows qualify · amber third-placers fight for the best-thirds spots · Pts = matchday placement points (3/2/1/0).</p>
    </div>
    <style>
    .wc-board { padding: 20px 24px; }
    .wc-header { display:flex; align-items:center; gap:16px; margin-bottom:14px; }
    .wc-mascot { width:84px; height:84px; object-fit:contain; }
    .wc-header h2 { margin:0; font-size:1.4rem; }
    .wc-sub { color:var(--gray-600); font-size:0.9rem; margin-top:4px; }
    .wc-groups { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px; }
    .wc-group { background:var(--gray-100); border:1px solid var(--gray-200); border-radius:8px; padding:12px 14px; }
    .wc-group--death { border-color:#c0392b; background:#fdecec; }
    .wc-group h3 { margin:0 0 8px; font-size:1rem; }
    .wc-death-tag { font-size:0.7rem; color:#c0392b; font-weight:700; margin-left:6px; }
    .wc-table { width:100%; border-collapse:collapse; font-size:0.88rem; }
    .wc-table th { text-align:left; color:#999; font-size:0.72rem; text-transform:uppercase; padding:2px 6px; }
    .wc-table td { padding:4px 6px; border-top:1px solid var(--gray-200); }
    .wc-qualify td { background:#e6f6ec; }
    .wc-bubble  td { background:#fdf8ec; }
    .wc-legend { color:#888; font-size:0.78rem; margin:12px 0 0; }
    </style>
    <?php endif; ?>

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
                                            <div style="padding: 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; <?= $participant['is_winner'] ? 'background: #d4edda; border: 2px solid #2EBD59;' : 'background: var(--gray-100);' ?>">
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
                            <?php
                                require_once __DIR__ . '/../../private/includes/programs.php';
                                foreach (getAIProgramsCatalog() as $key => $info):
                            ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($info['label']) ?></option>
                            <?php endforeach; ?>
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
