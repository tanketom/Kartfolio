<?php
/**
 * Team Scramble — a one-night team event (NOT a bracket).
 *
 * The field is snake-drafted into K balanced teams, everyone races a single
 * mega-GP, and the team with the highest combined points wins. Modelled on
 * the Survivor engine (one big match, all racers in the junction table) but
 * with team aggregation instead of elimination. Team number is stored in
 * tournament_participants.final_placement — the same convention Team Relay
 * uses — so no schema change is needed.
 *
 * Path: /cdnmk/private/includes/scramble_tournament.php
 */

/** Snake-draft palette for up to 6 scramble teams. */
const SCRAMBLE_TEAM_NAMES  = ['Red', 'Blue', 'Green', 'Gold', 'Purple', 'Orange'];
const SCRAMBLE_TEAM_COLORS = ['#e60012', '#0066cc', '#2ebd59', '#FFD700', '#8e44ad', '#e67e22'];

/**
 * Build a scramble: snake-draft participants (already seeded by Elo) into
 * $numTeams teams, store the team number in final_placement, and create one
 * mega-match with every racer in the junction table.
 */
function generateScrambleBracket(PDO $pdo, int $tournamentId, array $participants, int $numTeams = 2): void {
    $numTeams = max(2, min(6, $numTeams));
    $n = count($participants);
    if ($n < $numTeams) $numTeams = max(2, $n); // never more teams than racers

    // Snake draft for balance: 1→T1, 2→T2, …, K→TK, K+1→TK, …, back down.
    $teamIndex = 0; $direction = 1;
    $teamOf = []; // racer_id => team_num (1-based)
    foreach ($participants as $p) {
        $teamOf[(int)$p['id']] = $teamIndex + 1;
        if ($direction === 1) {
            if (++$teamIndex >= $numTeams) { $teamIndex = $numTeams - 1; $direction = -1; }
        } else {
            if (--$teamIndex < 0) { $teamIndex = 0; $direction = 1; }
        }
    }

    // Persist team number in final_placement (Team Relay's convention).
    $upd = $pdo->prepare("UPDATE tournament_participants SET final_placement = ? WHERE tournament_id = ? AND racer_id = ?");
    foreach ($teamOf as $rid => $teamNum) {
        $upd->execute([$teamNum, $tournamentId, $rid]);
    }

    // One mega-match with all racers (Survivor-style).
    $pdo->prepare("
        INSERT INTO tournament_matches
            (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
        VALUES (?, 'R1', 1, 'team_scramble', ?, ?, 'pending', ?, ?)
    ")->execute([
        $tournamentId,
        $participants[0]['id'],
        $participants[1]['id'] ?? null,
        $n, $n,
    ]);
    $matchId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO tournament_match_participants (match_id, racer_id) VALUES (?, ?)");
    foreach ($participants as $p) $ins->execute([$matchId, $p['id']]);
}

/**
 * Team standings for a scramble: each team's combined points from the
 * recorded mega-match. Returns rows sorted by total desc:
 *   [ ['team_num','name','color','total','members'=>[['name','points','placement']]], ... ]
 * Empty until the match is recorded.
 */
function scrambleStandings(PDO $pdo, int $tournamentId): array {
    // racer_id => team_num
    $teamStmt = $pdo->prepare("SELECT racer_id, final_placement AS team_num FROM tournament_participants WHERE tournament_id = ?");
    $teamStmt->execute([$tournamentId]);
    $teamOf = [];
    foreach ($teamStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $teamOf[(int)$r['racer_id']] = (int)$r['team_num'];

    // The single mega-match's recorded participants (points + placement).
    $resStmt = $pdo->prepare("
        SELECT tmp.racer_id, tmp.points, tmp.placement, r.name
        FROM tournament_match_participants tmp
        JOIN tournament_matches m ON m.id = tmp.match_id
        JOIN racers r ON r.id = tmp.racer_id
        WHERE m.tournament_id = ? AND m.bracket = 'team_scramble'
    ");
    $resStmt->execute([$tournamentId]);

    $teams = [];
    foreach ($resStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tn = $teamOf[(int)$row['racer_id']] ?? 1;
        if (!isset($teams[$tn])) {
            $idx = $tn - 1;
            $teams[$tn] = [
                'team_num' => $tn,
                'name'     => 'Team ' . (SCRAMBLE_TEAM_NAMES[$idx] ?? $tn),
                'color'    => SCRAMBLE_TEAM_COLORS[$idx] ?? '#888',
                'total'    => 0,
                'members'  => [],
            ];
        }
        $teams[$tn]['total'] += (int)$row['points'];
        $teams[$tn]['members'][] = [
            'name'      => $row['name'],
            'points'    => (int)$row['points'],
            'placement' => $row['placement'] !== null ? (int)$row['placement'] : null,
        ];
    }

    // Sort members within a team by points desc, teams by total desc.
    foreach ($teams as &$t) {
        usort($t['members'], fn($a, $b) => $b['points'] <=> $a['points']);
    }
    unset($t);
    usort($teams, fn($a, $b) => $b['total'] <=> $a['total']);
    return $teams;
}

/**
 * Winning team's representative racer id (highest-seeded member), or null if
 * the scramble hasn't been recorded. Used to set tournaments.winner_id —
 * matching how Team Relay records a single champion id.
 */
function scrambleWinnerId(PDO $pdo, int $tournamentId): ?int {
    $standings = scrambleStandings($pdo, $tournamentId);
    if (empty($standings) || $standings[0]['total'] === 0) return null;
    $winningTeamNum = $standings[0]['team_num'];

    // Highest-seeded (lowest seed number) member of the winning team.
    $stmt = $pdo->prepare("
        SELECT racer_id FROM tournament_participants
        WHERE tournament_id = ? AND final_placement = ?
        ORDER BY seed ASC LIMIT 1
    ");
    $stmt->execute([$tournamentId, $winningTeamNum]);
    $rid = $stmt->fetchColumn();
    return $rid !== false ? (int)$rid : null;
}
