<?php
/**
 * Record Tournament Match Results API
 * Path: /cdnmk/public_html/api/record_tournament_match.php
 *
 * Processes multi-player tournament match results with full GP data
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/mk_data.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method");
}
verify_csrf();

$tournamentId = $_POST['tournament_id'] ?? null;
$matchId = $_POST['match_id'] ?? null;
$cupName = $_POST['cup_name'] ?? '';

if (!$tournamentId || !$matchId) {
    die("Missing tournament_id or match_id");
}

if (empty($cupName)) {
    die("Cup name is required");
}

try {
    $pdo->beginTransaction();

    // Get match details
    $stmt = $pdo->prepare("SELECT * FROM tournament_matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception("Match not found");
    }

    // Get match participants
    $stmt = $pdo->prepare("
        SELECT tmp.*, r.name
        FROM tournament_match_participants tmp
        JOIN racers r ON tmp.racer_id = r.id
        WHERE tmp.match_id = ?
        ORDER BY tmp.id
    ");
    $stmt->execute([$matchId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($participants)) {
        throw new Exception("No participants found for this match");
    }

    // Generate GPID for tournament match
    $gpid = sprintf('t%03dm%03d', $tournamentId, $matchId);

    // Process each participant's results
    $resultsData = [];
    $participantIndex = 1;

    foreach ($participants as $participant) {
        $placement = $_POST["placement_$participantIndex"] ?? null;
        $points = $_POST["points_$participantIndex"] ?? null;
        $character = $_POST["character_$participantIndex"] ?? '';
        $kart = $_POST["kart_$participantIndex"] ?? '';

        if ($placement === null || $points === null) {
            throw new Exception("Missing placement or points for participant " . $participant['name']);
        }

        // Validate placement (1-12)
        $placement = (int)$placement;
        if ($placement < 1 || $placement > 12) {
            throw new Exception("Invalid placement for " . $participant['name'] . ". Must be between 1-12.");
        }

        // Validate points (0-60)
        $points = (int)$points;
        if ($points < 0 || $points > MK_MAX_GP_POINTS) {
            throw new Exception("Invalid points for " . $participant['name'] . ". Must be between 0-60.");
        }

        $resultsData[] = [
            'racer_id' => $participant['racer_id'],
            'placement' => $placement,
            'points' => $points,
            'character' => $character,
            'kart' => $kart
        ];

        $participantIndex++;
    }

    // Get tournament tiebreaker rule
    $stmt = $pdo->prepare("SELECT tiebreaker_rule FROM tournaments WHERE id = ?");
    $stmt->execute([$tournamentId]);
    $tiebreakerRule = $stmt->fetchColumn() ?: 'points';

    // Sort by tiebreaker rule
    usort($resultsData, function($a, $b) use ($tiebreakerRule) {
        if ($tiebreakerRule === 'placement') {
            // Better placement wins (lower is better)
            if ($a['placement'] !== $b['placement']) {
                return $a['placement'] - $b['placement'];
            }
            // Tie on placement, use points as secondary
            return $b['points'] - $a['points'];
        } else {
            // Default: 'points' - Higher points wins
            if ($a['points'] !== $b['points']) {
                return $b['points'] - $a['points'];
            }
            // Tie on points, use placement as secondary
            return $a['placement'] - $b['placement'];
        }
    });

    // Insert into results table (for ELO calculation)
    foreach ($resultsData as $result) {
        $stmt = $pdo->prepare("
            INSERT INTO results (gpid, racer_id, gp_points, rank, character_used, kart_setup, cup_name, race_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $gpid,
            $result['racer_id'],
            $result['points'],
            $result['placement'],
            $result['character'],
            $result['kart'],
            $cupName
        ]);
    }

    // Update tournament_match_participants with race data
    foreach ($resultsData as $result) {
        $stmt = $pdo->prepare("
            UPDATE tournament_match_participants
            SET placement = ?, points = ?, character_used = ?, kart_setup = ?
            WHERE match_id = ? AND racer_id = ?
        ");
        $stmt->execute([
            $result['placement'],
            $result['points'],
            $result['character'],
            $result['kart'],
            $matchId,
            $result['racer_id']
        ]);
    }

    // Determine winners based on num_advance
    $numAdvance = $match['num_advance'] ?? 1;
    $advancers = array_slice($resultsData, 0, $numAdvance);

    // Mark winners
    foreach ($advancers as $advancer) {
        $stmt = $pdo->prepare("
            UPDATE tournament_match_participants
            SET is_winner = 1
            WHERE match_id = ? AND racer_id = ?
        ");
        $stmt->execute([$matchId, $advancer['racer_id']]);
    }

    // Add cup_name column if it doesn't exist (SQLite ALTER TABLE compatibility)
    try {
        $pdo->exec("ALTER TABLE tournament_matches ADD COLUMN cup_name TEXT");
    } catch (PDOException $e) {
        // Column already exists, ignore
    }

    // Update tournament_matches table
    if ($numAdvance === 1) {
        // Single winner - set winner_id for compatibility
        $stmt = $pdo->prepare("
            UPDATE tournament_matches
            SET winner_id = ?, gpid = ?, cup_name = ?, status = 'completed', completed_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$advancers[0]['racer_id'], $gpid, $cupName, $matchId]);
    } else {
        // Multiple advancers - just mark completed
        $stmt = $pdo->prepare("
            UPDATE tournament_matches
            SET gpid = ?, cup_name = ?, status = 'completed', completed_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$gpid, $cupName, $matchId]);
    }

    // Insert into tournament_races table
    $stmt = $pdo->prepare("
        INSERT INTO tournament_races (match_id, race_number, gpid, winner_id, completed_at)
        VALUES (?, 1, ?, ?, datetime('now'))
    ");
    $stmt->execute([$matchId, $gpid, $advancers[0]['racer_id']]);

    $pdo->commit();

    // Redirect back to bracket with success message
    header("Location: /admin/tournament-bracket/" . $tournamentId . "?success=match_recorded");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error recording match: " . $e->getMessage());
}
