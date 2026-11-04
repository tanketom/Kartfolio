<?php
/**
 * Team seasons: the writes. Drawing the teams from pots, a racer joining on
 * their first race, the halfway swap, and an admin moving someone. The scoring
 * is in gp_logic.php (Roll Call) and only reads what is written here.
 *
 * Membership is stored rather than replayed from the results at read time. A
 * team is a social fact: once someone has been told they are on a team they
 * are on it, and correcting an old result must not quietly move them.
 */
require_once __DIR__ . '/gp_logic.php';

/** Colours for teams the draw creates. The teams choose their own later. */
const TEAM_SEASON_COLOURS = ['#e60012', '#0a84ff', '#2fb344', '#ffb400', '#9b59b6', '#00a3a3'];

/**
 * The racers a draw would use: the most active of $refSeason (weeks raced, then
 * GPs), retired racers excluded, split into pots of one per team. Pot 1 is the
 * most active. [[['id','name','weeks','gps'], …], …]
 */
function teamSeasonPots(PDO $pdo, string $refSeason, int $teams, int $perTeam): array {
    $teams = max(1, $teams);
    $st = $pdo->prepare("SELECT r.id, r.name, COUNT(DISTINCT strftime('%Y-%W', x.race_date)) AS weeks, COUNT(*) AS gps
                         FROM results x JOIN racers r ON r.id = x.racer_id
                         WHERE x.gpid LIKE ? AND COALESCE(r.is_retired, 0) = 0
                         GROUP BY r.id ORDER BY weeks DESC, gps DESC, r.name ASC LIMIT " . (int)($teams * max(1, $perTeam)));
    $st->execute([$refSeason . '%']);
    return array_chunk($st->fetchAll(PDO::FETCH_ASSOC), $teams);
}

/** The default season to draw from: the latest before this one with at least four weeks of racing. */
function teamSeasonDefaultReference(PDO $pdo, string $season_id): ?string {
    $st = $pdo->prepare("SELECT substr(gpid, 1, 3) AS s, COUNT(DISTINCT strftime('%Y-%W', race_date)) AS w
                         FROM results WHERE gpid LIKE 's%' AND substr(gpid, 1, 3) < ?
                         GROUP BY s ORDER BY s DESC");
    $st->execute([$season_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) if ((int)$r['w'] >= 4) return $r['s'];
    return $rows[0]['s'] ?? null;
}

/** Racers currently on a roster who have already raced this season. */
function teamSeasonRacedMembers(PDO $pdo, string $season_id): int {
    $st = $pdo->prepare("SELECT COUNT(DISTINCT m.racer_id) FROM team_members m
                         JOIN results x ON x.racer_id = m.racer_id AND x.gpid LIKE ? WHERE m.season_id = ?");
    $st->execute([$season_id . '%', $season_id]);
    return (int)$st->fetchColumn();
}

/**
 * Draw the teams: one racer from each pot to each team, at random. Creates the
 * teams if the season has none. Refused once a drawn racer has raced — by then
 * the teams are real. Returns [team name => [racer names]].
 */
function teamSeasonDraw(PDO $pdo, string $season_id, string $refSeason, int $teamCount, int $perTeam): array {
    if (teamSeasonRacedMembers($pdo, $season_id) > 0) {
        throw new RuntimeException('Racers on these teams have already raced, so the draw is closed. Move people by hand below.');
    }
    $rules = getSeasonRules($pdo, $season_id);
    $start = substr((string)($rules['start_date'] ?? ''), 0, 10) ?: date('Y-m-d');

    $pdo->beginTransaction();
    try {
        $teams = array_keys(getTeamConfig($pdo, $season_id));
        if (!$teams) {
            $ins = $pdo->prepare("INSERT INTO teams (season_id, name, color) VALUES (?, ?, ?)");
            for ($i = 0; $i < max(2, $teamCount); $i++) {
                $ins->execute([$season_id, 'Team ' . ($i + 1), TEAM_SEASON_COLOURS[$i % count(TEAM_SEASON_COLOURS)]]);
                $teams[] = (int)$pdo->lastInsertId();
            }
        }
        if (count($teams) < 2) throw new RuntimeException('A draw needs at least two teams.');

        $pdo->prepare("DELETE FROM team_members WHERE season_id = ?")->execute([$season_id]);
        $pdo->prepare("DELETE FROM team_stints WHERE season_id = ?")->execute([$season_id]);

        $size = array_fill_keys($teams, 0);
        foreach (teamSeasonPots($pdo, $refSeason, count($teams), $perTeam) as $pot) {
            shuffle($pot);
            // Shuffled, then smallest first: a short last pot must not all land on the same teams.
            $order = $teams; shuffle($order);
            usort($order, fn($a, $b) => $size[$a] <=> $size[$b]);
            foreach ($pot as $i => $r) {
                teamSeasonPlace($pdo, $season_id, (int)$r['id'], $order[$i], $start, 'draw');
                $size[$order[$i]]++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $out = [];
    foreach (getTeamConfig($pdo, $season_id) as $t) $out[$t['name']] = array_values($t['members']);
    return $out;
}

/** One membership change: the current roster and the history together. */
function teamSeasonPlace(PDO $pdo, string $season_id, int $racer_id, ?int $team_id, string $from, string $reason): void {
    $pdo->prepare("DELETE FROM team_members WHERE season_id = ? AND racer_id = ?")->execute([$season_id, $racer_id]);
    if ($team_id !== null) {
        $pdo->prepare("INSERT INTO team_members (season_id, team_id, racer_id) VALUES (?, ?, ?)")->execute([$season_id, $team_id, $racer_id]);
    }
    $pdo->prepare("INSERT OR REPLACE INTO team_stints (season_id, racer_id, from_date, team_id, reason) VALUES (?, ?, ?, ?, ?)")
        ->execute([$season_id, $racer_id, $from, $team_id, $reason]);
}

/**
 * An admin moving a racer. Before the season starts this just replaces their
 * team; once it is under way the move applies from today, and what they earned
 * on their old team stays there.
 */
function teamSeasonAdminMove(PDO $pdo, string $season_id, int $racer_id, ?int $team_id): void {
    $start = substr((string)(getSeasonRules($pdo, $season_id)['start_date'] ?? ''), 0, 10) ?: date('Y-m-d');
    $today = date('Y-m-d');
    $st = $pdo->prepare("SELECT team_id FROM team_members WHERE season_id = ? AND racer_id = ?");
    $st->execute([$season_id, $racer_id]);
    $old = $st->fetchColumn();
    if ($old !== false && (int)$old === $team_id) return;
    if ($old === false && $team_id === null) return;
    // Assigned before stints existed: record where they have been until now.
    if ($old !== false) {
        $pdo->prepare("INSERT OR IGNORE INTO team_stints (season_id, racer_id, from_date, team_id, reason) VALUES (?, ?, ?, ?, 'roster')")
            ->execute([$season_id, $racer_id, $start, (int)$old]);
    }
    teamSeasonPlace($pdo, $season_id, $racer_id, $team_id, max($start, $today), 'admin');
}

/** Results of a season, read fresh: this runs straight after a GP is saved, and the season cache may predate it. */
function teamSeasonFreshRows(PDO $pdo, string $season_id): array {
    $st = $pdo->prepare("SELECT racer_id, gpid, gp_points, race_date FROM results WHERE gpid LIKE ?");
    $st->execute([$season_id . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bring a team season up to date with the results: the halfway swap if it is
 * due, then a team for anyone who has raced without one. Called after every
 * saved GP and safe to call any time: it only ever does what has not been done.
 * Returns ['swapped' => [names], 'joined' => [names]]; a no-op off a team season.
 */
function teamSeasonSync(PDO $pdo, string $season_id): array {
    $done = ['swapped' => [], 'joined' => []];
    if (!isTeamSeason($pdo, $season_id)) return $done;
    $teams = getTeamConfig($pdo, $season_id);
    if (count($teams) < 1) return $done;

    $rules  = getSeasonRules($pdo, $season_id);
    $k      = rollCallKnobs($rules);
    $start  = substr((string)($rules['start_date'] ?? ''), 0, 10);
    $career = hauntedGhostBase($pdo, $season_id)['per'];
    $rows   = teamSeasonFreshRows($pdo, $season_id);
    if (!$rows) return $done;

    $pdo->beginTransaction();
    try {
        $done['swapped'] = teamSeasonApplySwap($pdo, $season_id, $rules, $rows, $career, $k, $start);
        $done['joined']  = teamSeasonApplyJoins($pdo, $season_id, $rows, $career, $k, $start);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $done;
}

/**
 * Who a swap would move, pure: [giver rid, from team, taker rid, to team], or
 * null when no swap should happen. $present is [team => racer-weeks], $racerWeeks
 * is [team => [rid => weeks]] for the current roster, including racers on 0.
 * The giver is the most-present team's SECOND most-present racer, because moving
 * its best attender would just move the imbalance.
 */
function teamSeasonPickSwap(array $present, array $racerWeeks, array $names): ?array {
    if (count($present) < 2) return null;
    $ids = array_keys($present);
    sort($ids);
    $most = $least = $ids[0];
    foreach ($ids as $id) {
        if ($present[$id] > $present[$most]) $most = $id;
        if ($present[$id] < $present[$least]) $least = $id;
    }
    if ($present[$most] === $present[$least]) return null;   // already even
    if (empty($racerWeeks[$most]) || empty($racerWeeks[$least])) return null;

    $rank = function (array $weeks, int $dir) use ($names) {
        $r = array_keys($weeks);
        usort($r, fn($a, $b) => (($weeks[$a] <=> $weeks[$b]) * $dir) ?: strcmp($names[$a] ?? '', $names[$b] ?? ''));
        return $r;
    };
    $givers = $rank($racerWeeks[$most], -1);
    $giver  = $givers[1] ?? $givers[0];
    $taker  = $rank($racerWeeks[$least], 1)[0];
    return [$giver, $most, $taker, $least];
}

function teamSeasonApplySwap(PDO $pdo, string $season_id, array $rules, array $rows, array $career, array $k, string $start): array {
    $date = rollCallSwapDate($rules);
    if ($date === null) return [];
    $latest = max(array_map(fn($r) => substr((string)$r['race_date'], 0, 10), $rows));
    if ($latest < $date) return [];
    if (rollCallSwapMoves($pdo, $season_id)) return [];   // done once, for good

    // Presence is counted up to the swap, not up to whenever it got noticed.
    $before = array_values(array_filter($rows, fn($r) => substr((string)$r['race_date'], 0, 10) < $date));
    $season = rollCallFromRows($before, $career, $k);
    $config = getTeamConfig($pdo, $season_id);
    $stints = rollCallStints($pdo, $season_id, $start);
    $totals = rollCallTeamTotals($season, $stints, array_keys($config));

    $present = []; $racerWeeks = [];
    foreach ($config as $id => $t) {
        $present[$id] = $totals[$id]['present'];
        foreach (array_keys($t['members']) as $rid) $racerWeeks[$id][$rid] = $season['racers'][$rid]['weeks'] ?? 0;
    }
    $pick = teamSeasonPickSwap($present, $racerWeeks, racerNamesMap($pdo));
    if ($pick === null) return [];

    [$giver, $from, $taker, $to] = $pick;
    teamSeasonPlace($pdo, $season_id, $giver, $to, $date, 'swap');
    teamSeasonPlace($pdo, $season_id, $taker, $from, $date, 'swap');
    $names = racerNamesMap($pdo);
    return [$names[$giver] ?? '?', $names[$taker] ?? '?'];
}

/**
 * A racer without a team joins one on their first race: the team with the
 * fewest racers who have raced, then the fewest points, then the oldest team.
 * In first-race order, so two newcomers on one night split up.
 */
function teamSeasonApplyJoins(PDO $pdo, string $season_id, array $rows, array $career, array $k, string $start): array {
    $first = [];
    foreach ($rows as $r) {
        $rid = (int)$r['racer_id']; $d = substr((string)$r['race_date'], 0, 10);
        if (!isset($first[$rid]) || $d < $first[$rid]) $first[$rid] = $d;
    }
    $stints = rollCallStints($pdo, $season_id, $start);
    $config = getTeamConfig($pdo, $season_id);
    $names  = racerNamesMap($pdo);
    $missing = array_diff_key($first, $stints);
    if (!$missing) return [];
    uksort($missing, fn($a, $b) => strcmp($first[$a], $first[$b]) ?: strcmp($names[$a] ?? '', $names[$b] ?? ''));

    $season = rollCallFromRows($rows, $career, $k);
    $joined = [];
    foreach ($missing as $rid => $date) {
        $totals = rollCallTeamTotals($season, $stints, array_keys($config));
        $best = null; $bestKey = null;
        foreach ($config as $id => $t) {
            $raced = 0;
            foreach (array_keys($t['members']) as $m) if (isset($first[$m])) $raced++;
            $key = [$raced, $totals[$id]['score'], $id];
            if ($bestKey === null || $key < $bestKey) { $bestKey = $key; $best = $id; }
        }
        teamSeasonPlace($pdo, $season_id, (int)$rid, $best, $date, 'join');
        $stints[$rid] = [['from' => $date, 'team' => $best, 'reason' => 'join']];
        $config[$best]['members'][$rid] = $names[$rid] ?? '?';
        $joined[] = $names[$rid] ?? '?';
    }
    return $joined;
}
