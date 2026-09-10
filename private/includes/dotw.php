<?php
/**
 * Driver of the Week — the league's one opinion poll.
 *
 * Everything else on the site is computed: Elo, GPScore, badges, the Crystal
 * Ball. This is the only thing that asks people what they thought.
 *
 * **You vote on the week that just ended, not the one ahead.** The ballot
 * lives on the fantasy form, and fantasy picks are predictions for the coming
 * week — so a vote cast there has to look backwards or it would be a
 * prediction too, which is what the MVP pick already is. Concretely: when you
 * submit picks before Sunday's deadline, the driver you name is the driver of
 * the week that closed at the LAST deadline, whose racing you have seen.
 *
 * The ballot is only the racers who actually raced that week. Voting for
 * somebody who was not there is not an opinion, it is a mistake.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/fantasy.php';

/**
 * The week currently open for voting: the one that closed at the last
 * deadline, plus the window of race dates it covers.
 *
 * @return array{week_key:string, from:string, to:string, closes:DateTime}
 */
function dotwVotingWeek(?DateTime $now = null): array {
    $fd    = fantasyDeadline($now);
    $close = $fd['deadline'];                       // the NEXT deadline: voting shuts then

    $ended = (clone $close)->modify('-7 days');     // the deadline the voted-on week closed at
    $began = (clone $ended)->modify('-7 days');

    return [
        'week_key' => $ended->format('Y-\WW'),
        'from'     => $began->format('Y-m-d'),
        'to'       => $ended->format('Y-m-d'),
        'closes'   => $close,
    ];
}

/** Racers who raced at least one GP in that window — the ballot. */
function dotwBallot(PDO $pdo, string $from, string $to): array {
    try {
        $st = $pdo->prepare("
            SELECT DISTINCT r.id, r.name
            FROM results res JOIN racers r ON r.id = res.racer_id
            WHERE res.gpid LIKE 's%' AND date(res.race_date) > date(?) AND date(res.race_date) <= date(?)
            ORDER BY r.name ASC");
        $st->execute([$from, $to]);
        return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (PDOException $e) { return []; }
}

/**
 * Record a vote. One per predictor per week — a second one replaces the first,
 * so changing your mind before the deadline is allowed.
 * Returns false if the racer is not on that week's ballot.
 */
function dotwCastVote(PDO $pdo, string $weekKey, int $predictorId, int $racerId, array $ballot): bool {
    if ($predictorId <= 0 || !isset($ballot[$racerId])) return false;
    try {
        $pdo->prepare("INSERT OR REPLACE INTO dotw_votes (week_key, predictor_id, racer_id, voted_at)
                       VALUES (?, ?, ?, datetime('now'))")
            ->execute([$weekKey, $predictorId, $racerId]);
        return true;
    } catch (PDOException $e) { return false; }
}

/**
 * The count for a week, winner first. Ties are kept — the caller decides
 * whether to name them all, the way the fantasy champion does.
 *
 * @return array<int, array{racer_id:int, name:string, votes:int}>
 */
function dotwResults(PDO $pdo, string $weekKey): array {
    try {
        // Name breaks the tie, so the order never wanders between requests (§10).
        $st = $pdo->prepare("
            SELECT v.racer_id, r.name, COUNT(*) AS votes
            FROM dotw_votes v JOIN racers r ON r.id = v.racer_id
            WHERE v.week_key = ?
            GROUP BY v.racer_id
            ORDER BY votes DESC, r.name ASC");
        $st->execute([$weekKey]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) { return []; }

    return array_map(fn($r) => [
        'racer_id' => (int)$r['racer_id'],
        'name'     => (string)$r['name'],
        'votes'    => (int)$r['votes'],
    ], $rows);
}

/** What this predictor already picked this week, or 0. */
function dotwExistingVote(PDO $pdo, string $weekKey, int $predictorId): int {
    if ($predictorId <= 0) return 0;
    try {
        $st = $pdo->prepare("SELECT racer_id FROM dotw_votes WHERE week_key = ? AND predictor_id = ?");
        $st->execute([$weekKey, $predictorId]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) { return 0; }
}
