<?php
/**
 * Tournament Setup Handler - Generate Bracket
 * Path: /cdnmk/public_html/admin/tournament_setup.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

// Initialize tournament tables
$pdo->exec(file_get_contents(__DIR__ . '/../../private/data/tournament_schema.sql'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tournamentName = $_POST['tournament_name'] ?? '';
    $format = $_POST['format'] ?? 'single_elim';
    $seasonId = $_POST['season_id'] ?? null;
    $participantIds = $_POST['participants'] ?? [];
    $tiebreakerRule = $_POST['tiebreaker_rule'] ?? 'points';

    if (empty($tournamentName) || empty($participantIds) || count($participantIds) < 2) {
        die("Invalid tournament data. Please go back and try again.");
    }

    // Add tiebreaker_rule column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN tiebreaker_rule TEXT DEFAULT 'points'");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Create tournament
    $stmt = $pdo->prepare("
        INSERT INTO tournaments (name, format, status, season_id, tiebreaker_rule, start_date)
        VALUES (?, ?, 'setup', ?, ?, datetime('now'))
    ");
    $stmt->execute([$tournamentName, $format, $seasonId, $tiebreakerRule]);
    $tournamentId = $pdo->lastInsertId();

    // Get ELO ratings for selected participants
    $placeholders = str_repeat('?,', count($participantIds) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, name FROM racers WHERE id IN ($placeholders) ORDER BY id");
    $stmt->execute($participantIds);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate ELO for seeding (same as in tournament_create.php)
    require_once __DIR__ . '/../../private/includes/gp_logic.php';

    define('INITIAL_RATING', 1500);
    define('K_FACTOR_NEW', 40);
    define('K_FACTOR_MID', 30);
    define('K_FACTOR_VET', 20);

    function calculateExpectedScore($racerRating, $opponentRatings) {
        $expected = 0;
        foreach ($opponentRatings as $oppRating) {
            $expected += 1 / (1 + pow(10, ($oppRating - $racerRating) / 400));
        }
        return $expected;
    }

    function getKFactor($gamesPlayed) {
        if ($gamesPlayed < 10) return K_FACTOR_NEW;
        if ($gamesPlayed < 30) return K_FACTOR_MID;
        return K_FACTOR_VET;
    }

    $stmt = $pdo->query("
        SELECT res.gpid, res.race_date, res.racer_id, res.rank
        FROM results res
        ORDER BY res.race_date ASC, res.gpid ASC, res.rank ASC
    ");
    $all_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $gps = [];
    foreach ($all_results as $result) {
        $gpid = $result['gpid'];
        if (!isset($gps[$gpid])) $gps[$gpid] = ['results' => []];
        $gps[$gpid]['results'][] = $result;
    }

    $ratings = [];
    $games_played = [];

    foreach ($gps as $gp) {
        $results = $gp['results'];
        $numRacers = count($results);

        foreach ($results as $result) {
            $racerId = $result['racer_id'];
            if (!isset($ratings[$racerId])) {
                $ratings[$racerId] = INITIAL_RATING;
                $games_played[$racerId] = 0;
            }
        }

        $changes = [];
        foreach ($results as $result) {
            $racerId = $result['racer_id'];
            $currentRating = $ratings[$racerId];
            $k = getKFactor($games_played[$racerId]);
            $actualScore = $numRacers - $result['rank'];

            $opponentRatings = [];
            foreach ($results as $opp) {
                if ($opp['racer_id'] !== $racerId) {
                    $opponentRatings[] = $ratings[$opp['racer_id']];
                }
            }

            $expectedScore = calculateExpectedScore($currentRating, $opponentRatings);
            $ratingChange = $k * ($actualScore - $expectedScore);
            $changes[$racerId] = ['new' => max(100, $currentRating + $ratingChange)];
        }

        foreach ($changes as $racerId => $change) {
            $ratings[$racerId] = $change['new'];
            $games_played[$racerId]++;
        }
    }

    // Attach ELO and sort by rating
    foreach ($participants as &$p) {
        $p['elo'] = isset($ratings[$p['id']]) ? round($ratings[$p['id']]) : 1500;
    }
    unset($p);

    usort($participants, fn($a, $b) => $b['elo'] <=> $a['elo']);

    // Insert participants with seeding
    foreach ($participants as $seed => $participant) {
        $stmt = $pdo->prepare("
            INSERT INTO tournament_participants (tournament_id, racer_id, seed, elo_at_registration)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$tournamentId, $participant['id'], $seed + 1, $participant['elo']]);
    }

    // Generate bracket based on format
    if ($format === 'single_elim') {
        generateSingleElimBracket($pdo, $tournamentId, $participants);
    } elseif ($format === 'double_elim') {
        generateDoubleElimBracket($pdo, $tournamentId, $participants);
    } elseif ($format === 'gauntlet') {
        generateGauntletBracket($pdo, $tournamentId, $participants);
    } elseif ($format === 'team_relay') {
        generateTeamRelayBracket($pdo, $tournamentId, $participants);
    }

    // Redirect to bracket view
    header("Location: /admin/tournament-bracket?id=$tournamentId");
    exit;
}

/**
 * Generate Single Elimination Bracket
 * Creates 4-player matches with top 2 advancing (or 2-3 player matches with top 1 advancing)
 */
function generateSingleElimBracket($pdo, $tournamentId, $participants) {
    $numPlayers = count($participants);
    $playersPerMatch = 4;
    $matchNum = 1;

    // Group players into matches of 4 (with byes if needed)
    for ($i = 0; $i < $numPlayers; $i += $playersPerMatch) {
        $matchParticipants = array_slice($participants, $i, $playersPerMatch);
        $numMatchParticipants = count($matchParticipants);

        if ($numMatchParticipants == 0) continue;

        // Determine num_advance based on match size
        // 4 players = top 2 advance, 2-3 players = top 1 advances
        $numAdvance = ($numMatchParticipants >= 4) ? 2 : 1;

        // Create match
        if ($numMatchParticipants >= 2) {
            $status = 'pending';
            $player1 = $matchParticipants[0]['id'];
            $player2 = $matchParticipants[1]['id'] ?? null;

            $stmt = $pdo->prepare("
                INSERT INTO tournament_matches (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
                VALUES (?, 'R1', ?, 'winners', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tournamentId, $matchNum, $player1, $player2, $status, $numMatchParticipants, $numAdvance]);
            $matchId = $pdo->lastInsertId();

            // Add all participants to junction table
            foreach ($matchParticipants as $participant) {
                $stmt = $pdo->prepare("
                    INSERT INTO tournament_match_participants (match_id, racer_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$matchId, $participant['id']]);
            }
        } elseif ($numMatchParticipants === 1) {
            // Bye - single player auto-advances
            $status = 'bye';
            $player1 = $matchParticipants[0]['id'];

            $stmt = $pdo->prepare("
                INSERT INTO tournament_matches (tournament_id, round, match_number, bracket, player1_id, status, num_participants, num_advance, winner_id)
                VALUES (?, 'R1', ?, 'winners', ?, ?, 1, 1, ?)
            ");
            $stmt->execute([$tournamentId, $matchNum, $player1, $status, $player1]);
            $matchId = $pdo->lastInsertId();

            // Add to junction table and mark as winner
            $stmt = $pdo->prepare("
                INSERT INTO tournament_match_participants (match_id, racer_id, is_winner)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$matchId, $player1]);
        }

        $matchNum++;
    }
}

/**
 * Generate Double Elimination Bracket
 */
function generateDoubleElimBracket($pdo, $tournamentId, $participants) {
    // Start with same as single elim for winners bracket
    generateSingleElimBracket($pdo, $tournamentId, $participants);
    // Losers bracket matches will be created dynamically as players lose
}

/**
 * Generate standard tournament bracket pairings
 */
function generateStandardBracketPairings($seeds) {
    $n = count($seeds);
    if ($n <= 1) return [];
    if ($n == 2) return [[$seeds[0], $seeds[1]]];

    $pairings = [];
    $half = $n / 2;

    for ($i = 0; $i < $half; $i++) {
        $pairings[] = [$seeds[$i], $seeds[$n - 1 - $i]];
    }

    return $pairings;
}

/**
 * Generate Gauntlet Bracket
 * Top seed is the "Boss" - must defend against all challengers in order
 */
function generateGauntletBracket($pdo, $tournamentId, $participants) {
    $numPlayers = count($participants);

    // First player (highest ELO) is the Boss
    $boss = $participants[0];

    // Create matches: Boss vs each challenger
    $matchNum = 1;
    for ($i = 1; $i < $numPlayers; $i++) {
        $challenger = $participants[$i];

        $stmt = $pdo->prepare("
            INSERT INTO tournament_matches (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
            VALUES (?, 'R1', ?, 'gauntlet', ?, ?, 'pending', 2, 1)
        ");
        // Boss is always player1 for consistency
        $stmt->execute([$tournamentId, $matchNum, $boss['id'], $challenger['id']]);
        $matchId = $pdo->lastInsertId();

        // Add participants to junction table
        $stmt = $pdo->prepare("
            INSERT INTO tournament_match_participants (match_id, racer_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$matchId, $boss['id']]);
        $stmt->execute([$matchId, $challenger['id']]);

        $matchNum++;
    }
}

/**
 * Generate Team Relay Bracket
 * Split players into balanced teams, then run single-elim between teams
 */
function generateTeamRelayBracket($pdo, $tournamentId, $participants) {
    $numPlayers = count($participants);

    // Split into teams (snake draft for balance)
    // For 8 players: Team A gets seeds 1,4,5,8 / Team B gets 2,3,6,7
    $numTeams = 2; // Start with 2 teams for simplicity
    $teams = array_fill(0, $numTeams, []);

    $teamIndex = 0;
    $direction = 1;

    foreach ($participants as $idx => $player) {
        $teams[$teamIndex][] = $player;

        // Snake draft
        if ($direction === 1) {
            $teamIndex++;
            if ($teamIndex >= $numTeams) {
                $teamIndex = $numTeams - 1;
                $direction = -1;
            }
        } else {
            $teamIndex--;
            if ($teamIndex < 0) {
                $teamIndex = 0;
                $direction = 1;
            }
        }
    }

    // Store team assignments in tournament_participants
    foreach ($teams as $teamNum => $teamMembers) {
        foreach ($teamMembers as $member) {
            $stmt = $pdo->prepare("
                UPDATE tournament_participants
                SET final_placement = ?
                WHERE tournament_id = ? AND racer_id = ?
            ");
            // Use final_placement temporarily to store team number
            $stmt->execute([$teamNum + 1, $tournamentId, $member['id']]);
        }
    }

    // Create relay matches (each team member faces opponent in sequence)
    // Match 1: Team A member 1 vs Team B member 1, etc.
    $maxTeamSize = max(count($teams[0]), count($teams[1]));

    for ($i = 0; $i < $maxTeamSize; $i++) {
        $player1 = isset($teams[0][$i]) ? $teams[0][$i]['id'] : null;
        $player2 = isset($teams[1][$i]) ? $teams[1][$i]['id'] : null;

        if ($player1 && $player2) {
            $stmt = $pdo->prepare("
                INSERT INTO tournament_matches (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
                VALUES (?, 'R1', ?, 'team_relay', ?, ?, 'pending', 2, 1)
            ");
            $stmt->execute([$tournamentId, $i + 1, $player1, $player2]);
            $matchId = $pdo->lastInsertId();

            // Add participants to junction table
            $stmt = $pdo->prepare("
                INSERT INTO tournament_match_participants (match_id, racer_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$matchId, $player1]);
            $stmt->execute([$matchId, $player2]);
        }
    }
}

// If accessed via GET, redirect to tournaments list
header("Location: /admin/tournaments");
exit;
?>
