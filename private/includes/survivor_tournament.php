<?php
/**
 * Survivor tournament format.
 *
 * Mechanics:
 *   - Start with N alive racers.
 *   - Each round = a single multi-participant GP with all alive racers.
 *   - Bottom finisher(s) are eliminated each round
 *     (eliminations_per_round, default 1, configurable per tournament).
 *   - Repeat until 1 racer remains → champion.
 *
 * Uses the standard tournament_matches schema:
 *   - One match per round, num_participants = alive_count,
 *     num_advance = alive_count − eliminations_per_round.
 *   - tournament_match_participants.is_winner = 1 for survivors, 0 for eliminated.
 *   - tournament_participants.final_placement set on elimination.
 *
 * Path: /cdnmk/private/includes/survivor_tournament.php
 */

/**
 * Generate the first Survivor round: one match containing everyone.
 *
 * @param PDO   $pdo
 * @param int   $tournamentId
 * @param array $participants     [{id, name, elo, ...}] (already seeded high→low)
 * @param int   $elimPerRound     How many bottom finishers to eliminate per round.
 */
function generateSurvivorBracket(PDO $pdo, int $tournamentId, array $participants, int $elimPerRound = 1): void {
    $n = count($participants);
    if ($n < 2) {
        throw new InvalidArgumentException('Survivor needs at least 2 participants.');
    }
    $elim = max(1, min($elimPerRound, $n - 1));
    $numAdvance = $n - $elim;

    $stmt = $pdo->prepare("
        INSERT INTO tournament_matches
            (tournament_id, round, match_number, bracket, player1_id, player2_id,
             status, num_participants, num_advance)
        VALUES (?, 'R1', 1, 'survivor', ?, ?, 'pending', ?, ?)
    ");
    $stmt->execute([
        $tournamentId,
        $participants[0]['id'],
        $participants[1]['id'] ?? null,
        $n,
        $numAdvance,
    ]);
    $matchId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO tournament_match_participants (match_id, racer_id) VALUES (?, ?)");
    foreach ($participants as $p) {
        $ins->execute([$matchId, $p['id']]);
    }
}

/**
 * Advance a Survivor tournament after the current round was recorded.
 *
 * - Marks eliminated racers' final_placement on tournament_participants.
 * - If ≤ 1 racer remains, sets tournament status='completed' + winner_id.
 * - Otherwise creates the next-round match with the survivors.
 *
 * Returns ['status' => 'next_round' | 'completed', ...] for callers that
 * want to display a flash message.
 */
function advanceSurvivor(PDO $pdo, int $tournamentId): array {
    // Pull the latest round's match (Survivor = always one match per round).
    $stmt = $pdo->prepare("
        SELECT id, round, num_participants, num_advance
        FROM tournament_matches
        WHERE tournament_id = ? AND bracket = 'survivor' AND status = 'completed'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$tournamentId]);
    $lastMatch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lastMatch) {
        return ['status' => 'noop', 'message' => 'No completed Survivor match to advance from.'];
    }

    // Survivors (is_winner=1) and eliminated (is_winner=0).
    $partStmt = $pdo->prepare("
        SELECT racer_id, is_winner, placement, points
        FROM tournament_match_participants
        WHERE match_id = ?
        ORDER BY placement ASC, points DESC
    ");
    $partStmt->execute([$lastMatch['id']]);
    $rows = $partStmt->fetchAll(PDO::FETCH_ASSOC);

    $survivors   = array_values(array_filter($rows, fn($r) => (int)$r['is_winner'] === 1));
    $eliminated  = array_values(array_filter($rows, fn($r) => (int)$r['is_winner'] === 0));

    // Figure out how many alive racers there will be after this round (= survivor count).
    // Eliminations get final_placement = current alive count down to (survivors + 1).
    // E.g. if 6 alive and 2 eliminated, finishers get placements 5 and 6 in the
    // overall tournament — top survivors remain unranked until they're knocked out.
    $aliveBefore = (int)$lastMatch['num_participants'];
    $place       = $aliveBefore;
    // Eliminated, sorted last-place first.
    $eliminatedDescending = array_reverse($eliminated);
    $placeStmt = $pdo->prepare("
        UPDATE tournament_participants
        SET final_placement = ?
        WHERE tournament_id = ? AND racer_id = ? AND final_placement IS NULL
    ");
    foreach ($eliminatedDescending as $r) {
        $placeStmt->execute([$place, $tournamentId, $r['racer_id']]);
        $place--;
    }

    // ── End condition: 1 survivor → champion ──────────────────────────
    if (count($survivors) <= 1) {
        $winnerId = !empty($survivors) ? (int)$survivors[0]['racer_id'] : null;
        if ($winnerId) {
            $finalize = $pdo->prepare("
                UPDATE tournament_participants
                SET final_placement = 1
                WHERE tournament_id = ? AND racer_id = ? AND final_placement IS NULL
            ");
            $finalize->execute([$tournamentId, $winnerId]);

            $closeT = $pdo->prepare("
                UPDATE tournaments
                SET status = 'completed', winner_id = ?, end_date = datetime('now')
                WHERE id = ?
            ");
            $closeT->execute([$winnerId, $tournamentId]);
        }
        return ['status' => 'completed', 'winner_id' => $winnerId];
    }

    // ── Next round: one match with the survivors ──────────────────────
    $tStmt = $pdo->prepare("SELECT eliminations_per_round FROM tournaments WHERE id = ?");
    $tStmt->execute([$tournamentId]);
    $elimPerRound = max(1, (int)($tStmt->fetchColumn() ?: 1));

    $alive       = count($survivors);
    $elimThisRnd = min($elimPerRound, $alive - 1); // never kill the last person
    $numAdvance  = $alive - $elimThisRnd;

    $nextRound = survivorNextRound($lastMatch['round'], $alive);

    $insMatch = $pdo->prepare("
        INSERT INTO tournament_matches
            (tournament_id, round, match_number, bracket, player1_id, player2_id,
             status, num_participants, num_advance)
        VALUES (?, ?, 1, 'survivor', ?, ?, 'pending', ?, ?)
    ");
    $insMatch->execute([
        $tournamentId,
        $nextRound,
        $survivors[0]['racer_id'],
        $survivors[1]['racer_id'] ?? null,
        $alive,
        $numAdvance,
    ]);
    $nextMatchId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO tournament_match_participants (match_id, racer_id) VALUES (?, ?)");
    foreach ($survivors as $s) {
        $ins->execute([$nextMatchId, $s['racer_id']]);
    }

    return [
        'status'         => 'next_round',
        'next_round'     => $nextRound,
        'survivors'      => $alive,
        'eliminations'   => $elimThisRnd,
    ];
}

/**
 * Pick a label for the next Survivor round.
 *   - "F" once we're down to ≤ 3 alive (the deathmatch).
 *   - "R{n+1}" otherwise.
 */
function survivorNextRound(string $currentRound, int $aliveCount): string {
    if ($aliveCount <= 3) return 'F';
    if (preg_match('/^R(\d+)$/', $currentRound, $m)) {
        return 'R' . ((int)$m[1] + 1);
    }
    return 'R2';
}

/**
 * Snapshot of every racer's life status in a Survivor tournament.
 * Returns [{racer_id, name, status: 'alive'|'eliminated', round_eliminated, final_placement}].
 */
function survivorRoster(PDO $pdo, int $tournamentId): array {
    // Find each racer's elimination round (if any).
    $stmt = $pdo->prepare("
        SELECT tp.racer_id, r.name, tp.seed, tp.final_placement, tp.elo_at_registration,
               (
                   SELECT m.round
                   FROM tournament_matches m
                   JOIN tournament_match_participants tmp ON tmp.match_id = m.id
                   WHERE m.tournament_id = tp.tournament_id
                     AND tmp.racer_id = tp.racer_id
                     AND tmp.is_winner = 0
                     AND m.bracket = 'survivor'
                   ORDER BY m.id DESC LIMIT 1
               ) AS elimination_round
        FROM tournament_participants tp
        JOIN racers r ON r.id = tp.racer_id
        WHERE tp.tournament_id = ?
        ORDER BY tp.seed ASC
    ");
    $stmt->execute([$tournamentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['status'] = $r['elimination_round'] === null ? 'alive' : 'eliminated';
    }
    unset($r);
    return $rows;
}
