<?php
/**
 * Fantasy helpers — the leaderboard and its per-season reset.
 *
 * A fantasy week runs deadline-to-deadline (Sunday 18:00), so it is keyed by
 * ISO week, not by GP. Which season a week belongs to is therefore stored on
 * the week row (`fantasy_weeks.season_id`), written when the row is born and
 * backfilled once in db.php for the weeks that predate the column. Nothing
 * re-derives it at read time: two places used to guess it from race dates and
 * could disagree.
 *
 * The board resets every season. `/fantasy` shows the current season, the
 * all-time board is still one click away, and closing a season freezes its
 * champion into season_meta next to the racing champion.
 */

/**
 * Aggregate board. $seasonId null = all time; otherwise that season only.
 * Rows: predictor_id, racer_id, guest_name, display_name, total_points,
 * weeks_played, hit counts, locks, accuracy_pct.
 */
function fantasyLeaderboard(PDO $pdo, ?string $seasonId = null): array {
    // Hit/total counts ignore pushes (points_earned = 1 on an H2H tie) and
    // count only strict positives — same rule the page has always used.
    $sql = "
        SELECT fp.id AS predictor_id, fp.racer_id, fp.guest_name,
               COALESCE(SUM(fb.points_earned), 0) AS total_points,
               COUNT(DISTINCT fb.week_key) AS weeks_played,
               SUM(CASE WHEN fb.bet_type = 'mvp'  AND fb.points_earned  > 0 THEN 1 ELSE 0 END) AS mvp_hits,
               SUM(CASE WHEN fb.bet_type = 'h2h'  AND fb.points_earned >= 3 THEN 1 ELSE 0 END) AS h2h_hits,
               SUM(CASE WHEN fb.bet_type = 'prop' AND fb.points_earned  > 0 THEN 1 ELSE 0 END) AS prop_hits,
               SUM(CASE WHEN fb.points_earned > 0 THEN 1 ELSE 0 END) AS total_hits,
               SUM(CASE WHEN fb.points_earned IS NOT NULL AND fb.points_earned != 1 THEN 1 ELSE 0 END) AS graded_bets,
               SUM(CASE WHEN fb.confidence = 3 THEN 1 ELSE 0 END) AS locks_made,
               SUM(CASE WHEN fb.confidence = 3 AND fb.points_earned > 0 THEN 1 ELSE 0 END) AS locks_hit
        FROM fantasy_predictors fp
        JOIN fantasy_bets fb ON fp.id = fb.predictor_id
        LEFT JOIN fantasy_weeks fw ON fw.week_key = fb.week_key
        WHERE fb.points_earned IS NOT NULL
    ";
    $args = [];
    if ($seasonId !== null) { $sql .= " AND fw.season_id = ? "; $args[] = $seasonId; }
    $sql .= " GROUP BY fp.id ORDER BY total_points DESC, weeks_played ASC, fp.id ASC";

    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $names = [];
    foreach ($pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['id']] = $r['name'];

    foreach ($rows as &$lb) {
        $lb['accuracy_pct'] = (int)$lb['graded_bets'] > 0 ? (int)round((int)$lb['total_hits'] / (int)$lb['graded_bets'] * 100) : 0;
        $lb['display_name'] = ($lb['racer_id'] && isset($names[(int)$lb['racer_id']]))
            ? $names[(int)$lb['racer_id']]
            : ($lb['guest_name'] ?: 'Unknown');
    }
    unset($lb);
    return $rows;
}

/** Seasons that have at least one graded fantasy bet, newest first. */
function fantasySeasonsPlayed(PDO $pdo): array {
    try {
        return $pdo->query("
            SELECT DISTINCT fw.season_id
            FROM fantasy_weeks fw JOIN fantasy_bets fb ON fb.week_key = fw.week_key
            WHERE fw.season_id IS NOT NULL AND fb.points_earned IS NOT NULL
            ORDER BY fw.season_id DESC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) { return []; }
}

/**
 * Freeze a season's fantasy champion. Called from close_season.php next to
 * the other snapshots. Safe to re-run — it replaces the stored value, so
 * re-opening and re-archiving recomputes.
 */
function snapshotFantasyChampion(PDO $pdo, string $seasonId): int {
    try {
        $board = fantasyLeaderboard($pdo, $seasonId);
    } catch (PDOException $e) { return 0; }
    if (!$board || (float)$board[0]['total_points'] <= 0) return 0;

    // Ties share the crown, the way the Fantasy Champion badge already does.
    $top = (float)$board[0]['total_points'];
    $winners = [];
    foreach ($board as $r) { if ((float)$r['total_points'] < $top) break; $winners[] = $r['display_name']; }

    $pdo->prepare("UPDATE season_meta SET fantasy_champion = ?, fantasy_champion_points = ? WHERE season_id = ?")
        ->execute([implode(' & ', $winners), (int)$top, $seasonId]);
    return count($winners);
}

/**
 * racer_id => true for every racer who topped one of $seasonIds. Guests can
 * win a season but cannot hold a badge, so they are skipped here (they still
 * appear in the frozen `fantasy_champion` name).
 */
function fantasyChampionRacerIds(PDO $pdo, array $seasonIds): array {
    $out = [];
    foreach ($seasonIds as $sid) {
        try { $board = fantasyLeaderboard($pdo, (string)$sid); } catch (PDOException $e) { return $out; }
        if (!$board || (float)$board[0]['total_points'] <= 0) continue;
        $top = (float)$board[0]['total_points'];
        foreach ($board as $r) {
            if ((float)$r['total_points'] < $top) break;
            if (!empty($r['racer_id'])) $out[(int)$r['racer_id']] = true;
        }
    }
    return $out;
}
