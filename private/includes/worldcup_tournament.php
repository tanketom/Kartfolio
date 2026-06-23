<?php
/**
 * World Cup tournament format — 2026 FIFA-style group stage → knockout.
 *
 * Shape (scaled to league size):
 *   - Participants are drawn into groups of ~4 by Elo POTS (Pot 1 = the top
 *     seeds, one per group; then Pot 2, …) so no group is stacked.
 *   - Group stage: each group plays N "matchday" GPs (default 3) with all
 *     group members racing. Group tables award TABLE POINTS by placement in
 *     each matchday (1st=3, 2nd=2, 3rd=1, 4th=0); ties break on total GP
 *     points, then name.
 *   - Advancement, the 2026 hallmark: top 2 of each group qualify, then the
 *     BEST THIRD-PLACED racers fill the knockout field up to a power of two.
 *   - Knockout: head-to-head 1v1 matches (R16/QF/SF/F as size demands),
 *     winners advance via the admin "Advance Round" action.
 *
 * Conventions shared with the other formats:
 *   - Group number lives in tournament_participants.final_placement
 *     (same trick Team Relay and Team Scramble use).
 *   - Group matches: bracket='wc_group', round='G1'..'GN', all members in the
 *     junction table, num_advance = field size (a matchday eliminates nobody).
 *   - Knockout matches: bracket='wc_ko', 2 players, num_advance=1.
 *
 * Mascot: Kartificial 🍄🏎️ (assets/img/kartificial.png).
 *
 * Path: /cdnmk/private/includes/worldcup_tournament.php
 */

/** Table points per matchday by placement among the group (index 0 = 1st). */
const WC_TABLE_POINTS = [3, 2, 1, 0];

/** Target group size (last groups may be of 3 when N % 4 != 0). */
const WC_GROUP_SIZE = 4;

/**
 * Pot-seeded draw + group matchday creation.
 * $participants arrive Elo-sorted desc from tournament_setup (seed order).
 */
function generateWorldCupGroups(PDO $pdo, int $tournamentId, array $participants, int $groupGps = 3): void {
    $n = count($participants);
    $numGroups = max(1, (int)ceil($n / WC_GROUP_SIZE));
    $groupGps  = max(1, min(6, $groupGps));

    // Build pots: pot k = next $numGroups racers by seed. Shuffle within each
    // pot (that's the "draw"), then deal one per group.
    $groups = array_fill(1, $numGroups, []);
    $pots = array_chunk($participants, $numGroups);
    foreach ($pots as $pot) {
        shuffle($pot);
        $g = 1;
        foreach ($pot as $p) {
            // Find next group with room (handles the ragged last pot).
            $tries = 0;
            while (count($groups[$g]) >= (int)ceil($n / $numGroups) && $tries < $numGroups) {
                $g = $g % $numGroups + 1; $tries++;
            }
            $groups[$g][] = $p;
            $g = $g % $numGroups + 1;
        }
    }

    // Persist group number in final_placement.
    $upd = $pdo->prepare("UPDATE tournament_participants SET final_placement = ? WHERE tournament_id = ? AND racer_id = ?");
    foreach ($groups as $gNum => $members) {
        foreach ($members as $m) $upd->execute([$gNum, $tournamentId, $m['id']]);
    }

    // Create matchday GPs per group: round G1..GN, one match per group per day.
    $insMatch = $pdo->prepare("
        INSERT INTO tournament_matches
            (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
        VALUES (?, ?, ?, 'wc_group', ?, ?, 'pending', ?, ?)
    ");
    $insPart = $pdo->prepare("INSERT INTO tournament_match_participants (match_id, racer_id) VALUES (?, ?)");
    for ($day = 1; $day <= $groupGps; $day++) {
        foreach ($groups as $gNum => $members) {
            $size = count($members);
            $insMatch->execute([
                $tournamentId, 'G' . $day, $gNum,
                $members[0]['id'], $members[1]['id'] ?? null,
                $size, $size, // nobody is eliminated by a matchday
            ]);
            $matchId = (int)$pdo->lastInsertId();
            foreach ($members as $m) $insPart->execute([$matchId, $m['id']]);
        }
    }
}

/** group# => [ ['racer_id','name','seed','elo'] ... ], ordered by group then seed. */
function worldCupGroups(PDO $pdo, int $tournamentId): array {
    $stmt = $pdo->prepare("
        SELECT tp.racer_id, tp.seed, tp.elo_at_registration AS elo, tp.final_placement AS group_num, r.name
        FROM tournament_participants tp JOIN racers r ON r.id = tp.racer_id
        WHERE tp.tournament_id = ?
        ORDER BY tp.final_placement ASC, tp.seed ASC
    ");
    $stmt->execute([$tournamentId]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $groups[(int)$row['group_num']][] = [
            'racer_id' => (int)$row['racer_id'],
            'name'     => $row['name'],
            'seed'     => (int)$row['seed'],
            'elo'      => (int)$row['elo'],
        ];
    }
    return $groups;
}

/**
 * Group tables. Returns group# => rows sorted by (table pts desc, gp pts
 * desc, name): ['racer_id','name','played','table_pts','gp_pts','rank'].
 */
function worldCupGroupTables(PDO $pdo, int $tournamentId): array {
    $groups = worldCupGroups($pdo, $tournamentId);

    // All recorded group-match participant rows.
    $stmt = $pdo->prepare("
        SELECT m.id AS match_id, tmp.racer_id, tmp.placement, tmp.points
        FROM tournament_matches m
        JOIN tournament_match_participants tmp ON tmp.match_id = m.id
        WHERE m.tournament_id = ? AND m.bracket = 'wc_group' AND m.status = 'completed'
    ");
    $stmt->execute([$tournamentId]);
    $recorded = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tables = [];
    foreach ($groups as $gNum => $members) {
        $rows = [];
        foreach ($members as $m) {
            $rows[$m['racer_id']] = [
                'racer_id' => $m['racer_id'], 'name' => $m['name'],
                'played' => 0, 'table_pts' => 0, 'gp_pts' => 0,
            ];
        }
        $tables[$gNum] = $rows;
    }

    foreach ($recorded as $r) {
        $rid = (int)$r['racer_id'];
        foreach ($tables as &$rows) {
            if (!isset($rows[$rid])) continue;
            $rows[$rid]['played']++;
            $rows[$rid]['gp_pts'] += (int)$r['points'];
            $place = (int)$r['placement'];
            $rows[$rid]['table_pts'] += WC_TABLE_POINTS[max(0, $place - 1)] ?? 0;
        }
        unset($rows);
    }

    foreach ($tables as &$rows) {
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => ($b['table_pts'] <=> $a['table_pts'])
            ?: ($b['gp_pts'] <=> $a['gp_pts'])
            ?: strcmp($a['name'], $b['name']));
        foreach ($rows as $i => &$row) $row['rank'] = $i + 1;
        unset($row);
    }
    unset($rows);
    return $tables;
}

/** True once every wc_group match is completed. */
function worldCupGroupStageComplete(PDO $pdo, int $tournamentId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tournament_matches
        WHERE tournament_id = ? AND bracket = 'wc_group' AND status NOT IN ('completed','bye')
    ");
    $stmt->execute([$tournamentId]);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Qualifiers in seed order for the knockout: group winners (strongest table
 * first), then runners-up, then the BEST THIRDS until the field is a power
 * of two. Returns ['racer_id','name','group','rank','table_pts','gp_pts'].
 */
function worldCupQualifiers(PDO $pdo, int $tournamentId): array {
    $tables = worldCupGroupTables($pdo, $tournamentId);
    $byRank = [1 => [], 2 => [], 3 => []];
    foreach ($tables as $gNum => $rows) {
        foreach ($rows as $row) {
            if ($row['rank'] <= 3) {
                $byRank[$row['rank']][] = $row + ['group' => $gNum];
            }
        }
    }
    $strength = fn($a, $b) => ($b['table_pts'] <=> $a['table_pts'])
        ?: ($b['gp_pts'] <=> $a['gp_pts']) ?: strcmp($a['name'], $b['name']);
    usort($byRank[1], $strength);
    usort($byRank[2], $strength);
    usort($byRank[3], $strength);

    $auto = array_merge($byRank[1], $byRank[2]);
    // Fill with best thirds up to the next power of two (2026's signature).
    $target = 2 ** max(1, (int)ceil(log(max(2, count($auto)), 2)));
    $field = $auto;
    foreach ($byRank[3] as $third) {
        if (count($field) >= $target) break;
        $field[] = $third;
    }
    // If still not a power of two (tiny fields), trim from the bottom.
    $pow = 2 ** (int)floor(log(count($field), 2));
    return array_slice($field, 0, $pow);
}

/** KO round label for a field of $n: 16→R16, 8→QF, 4→SF, 2→F. */
function worldCupRoundLabel(int $n): string {
    return [16 => 'R16', 8 => 'QF', 4 => 'SF', 2 => 'F'][$n] ?? ('KO' . $n);
}

/**
 * Advance the tournament:
 *   - group stage finished + no knockout yet → seed the knockout round,
 *   - current KO round finished → pair the winners into the next round,
 *   - final finished → mark the tournament completed with its champion.
 * Returns a short status string for the admin flash message.
 */
function advanceWorldCup(PDO $pdo, int $tournamentId): string {
    // Any knockout matches yet?
    $koStmt = $pdo->prepare("SELECT * FROM tournament_matches WHERE tournament_id = ? AND bracket = 'wc_ko' ORDER BY id ASC");
    $koStmt->execute([$tournamentId]);
    $koMatches = $koStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($koMatches)) {
        if (!worldCupGroupStageComplete($pdo, $tournamentId)) {
            return 'Group stage is not finished yet — record every matchday first.';
        }
        $field = worldCupQualifiers($pdo, $tournamentId);
        if (count($field) < 2) return 'Not enough qualifiers to seed a knockout.';

        // Standard seeding: 1 vs last, 2 vs second-last…, with a swap pass to
        // avoid same-group clashes in the first round where possible.
        $n = count($field);
        $pairs = [];
        for ($i = 0; $i < $n / 2; $i++) $pairs[] = [$field[$i], $field[$n - 1 - $i]];
        for ($i = 0; $i < count($pairs); $i++) {
            if ($pairs[$i][0]['group'] === $pairs[$i][1]['group']) {
                for ($j = 0; $j < count($pairs); $j++) {
                    if ($j === $i) continue;
                    if ($pairs[$j][1]['group'] !== $pairs[$i][0]['group']
                        && $pairs[$i][1]['group'] !== $pairs[$j][0]['group']) {
                        [$pairs[$i][1], $pairs[$j][1]] = [$pairs[$j][1], $pairs[$i][1]];
                        break;
                    }
                }
            }
        }

        worldCupCreateKoMatches($pdo, $tournamentId, worldCupRoundLabel($n), $pairs);
        return 'Knockout seeded — ' . count($pairs) . ' ' . worldCupRoundLabel($n) . ' ties created. 🏆';
    }

    // Find the latest KO round and check completion.
    $latestRound = end($koMatches)['round'];
    $current = array_values(array_filter($koMatches, fn($m) => $m['round'] === $latestRound));
    foreach ($current as $m) {
        if ($m['status'] !== 'completed') return "Finish all $latestRound matches before advancing.";
    }

    // Collect winners in match order.
    $winners = [];
    $wStmt = $pdo->prepare("SELECT racer_id FROM tournament_match_participants WHERE match_id = ? AND is_winner = 1 LIMIT 1");
    foreach ($current as $m) {
        $wStmt->execute([$m['id']]);
        $rid = $wStmt->fetchColumn();
        if ($rid !== false) $winners[] = (int)$rid;
    }

    if (count($winners) <= 1) {
        // Final played — crown the champion.
        if (!empty($winners)) {
            $pdo->prepare("UPDATE tournaments SET status='completed', winner_id=?, end_date=datetime('now') WHERE id=?")
                ->execute([$winners[0], $tournamentId]);
            return 'World Cup complete — we have a champion! 🏆';
        }
        return 'Final is recorded but no winner was marked.';
    }

    // Pair adjacent winners into the next round.
    $nameStmt = $pdo->prepare("SELECT name FROM racers WHERE id = ?");
    $pairs = [];
    for ($i = 0; $i < count($winners); $i += 2) {
        $a = ['racer_id' => $winners[$i]];
        $b = ['racer_id' => $winners[$i + 1]];
        $pairs[] = [$a, $b];
    }
    $label = worldCupRoundLabel(count($winners));
    worldCupCreateKoMatches($pdo, $tournamentId, $label, $pairs);
    return "Advanced to $label — " . count($pairs) . ' ties created.';
}

/** Insert one KO round's 1v1 matches (+ junction rows). */
function worldCupCreateKoMatches(PDO $pdo, int $tournamentId, string $round, array $pairs): void {
    $insMatch = $pdo->prepare("
        INSERT INTO tournament_matches
            (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
        VALUES (?, ?, ?, 'wc_ko', ?, ?, 'pending', 2, 1)
    ");
    $insPart = $pdo->prepare("INSERT INTO tournament_match_participants (match_id, racer_id) VALUES (?, ?)");
    foreach ($pairs as $i => [$a, $b]) {
        $insMatch->execute([$tournamentId, $round, $i + 1, $a['racer_id'], $b['racer_id']]);
        $matchId = (int)$pdo->lastInsertId();
        $insPart->execute([$matchId, $a['racer_id']]);
        $insPart->execute([$matchId, $b['racer_id']]);
    }
}

/** Champion: winner of the completed 'F' wc_ko match, or null. */
function worldCupWinnerId(PDO $pdo, int $tournamentId): ?int {
    $stmt = $pdo->prepare("
        SELECT tmp.racer_id
        FROM tournament_matches m
        JOIN tournament_match_participants tmp ON tmp.match_id = m.id
        WHERE m.tournament_id = ? AND m.bracket = 'wc_ko' AND m.round = 'F'
          AND m.status = 'completed' AND tmp.is_winner = 1
        LIMIT 1
    ");
    $stmt->execute([$tournamentId]);
    $rid = $stmt->fetchColumn();
    return $rid !== false ? (int)$rid : null;
}

/**
 * Live Pick'em leaderboard for a World Cup tournament — the single source of
 * truth for predictor scoring (used by /wc-pickem and the Pick'em Oracle badge).
 * Scoring: +2 per correctly picked qualifier, +1 if that pick won its group,
 * +10 for the correct champion. Returns rows best-first:
 *   [ ['name' => ..., 'points' => int, 'champion' => name], ... ]
 */
function worldCupPickemBoard(PDO $pdo, int $tournamentId): array {
    $tables         = worldCupGroupTables($pdo, $tournamentId);
    $groupStageDone = worldCupGroupStageComplete($pdo, $tournamentId);
    $championId     = worldCupWinnerId($pdo, $tournamentId);

    $actualQualifiers = []; $actualWinners = [];
    if ($groupStageDone) {
        foreach ($tables as $gNum => $rows) {
            foreach ($rows as $row) {
                if ($row['rank'] <= 2) $actualQualifiers[$gNum][] = $row['racer_id'];
                if ($row['rank'] === 1) $actualWinners[$gNum] = $row['racer_id'];
            }
        }
    }

    $names = $pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_KEY_PAIR);

    $predStmt = $pdo->prepare("SELECT predictor_name, picks_json FROM wc_predictions WHERE tournament_id = ?");
    $predStmt->execute([$tournamentId]);
    $board = [];
    foreach ($predStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $picks = json_decode($p['picks_json'], true) ?: [];
        $pts = 0;
        if ($groupStageDone) {
            foreach (($picks['groups'] ?? []) as $gNum => $sel) {
                foreach ($sel as $rid) {
                    if (in_array((int)$rid, $actualQualifiers[$gNum] ?? [], true)) $pts += 2;
                    if (($actualWinners[$gNum] ?? null) === (int)$rid) $pts += 1;
                }
            }
        }
        $champPick = (int)($picks['champion'] ?? 0);
        if ($championId !== null && $champPick === $championId) $pts += 10;
        $board[] = [
            'name'     => $p['predictor_name'],
            'points'   => $pts,
            'champion' => $names[$champPick] ?? '—',
        ];
    }
    usort($board, fn($a, $b) => ($b['points'] <=> $a['points']) ?: strcmp($a['name'], $b['name']));
    return $board;
}

/** The Group of Death: highest average registration Elo. Returns [group#, avgElo]. */
function worldCupGroupOfDeath(PDO $pdo, int $tournamentId): ?array {
    $groups = worldCupGroups($pdo, $tournamentId);
    if (count($groups) < 2) return null;
    $best = null;
    foreach ($groups as $gNum => $members) {
        $avg = array_sum(array_column($members, 'elo')) / max(1, count($members));
        if ($best === null || $avg > $best[1]) $best = [$gNum, (int)round($avg)];
    }
    return $best;
}
