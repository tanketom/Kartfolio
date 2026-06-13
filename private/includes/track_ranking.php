<?php
/**
 * Track preference ranking — Elo over head-to-head votes.
 *
 * Each row in `track_preferences` is "voter prefers winner_track over
 * loser_track". We replay the preferences in vote order applying Elo
 * (K=32, base 1500) to produce a global ranking that tournament
 * organisers can use as a seed list for which tracks to feature.
 *
 * Path: /cdnmk/private/includes/track_ranking.php
 */

require_once __DIR__ . '/mk_data.php';

const TRACK_PREF_BASE_ELO = 1500;
const TRACK_PREF_K_FACTOR = 32;

/**
 * Read or create the voter cookie. Returns the voter ID string.
 *
 * Set as a 1-year httponly cookie. Anyone can vote — no login required.
 * Same cookie is reused across sessions so a voter's "recent pairs"
 * suppression keeps working.
 */
function trackPrefVoterId(): string {
    if (!empty($_COOKIE['track_voter'])) {
        $id = $_COOKIE['track_voter'];
        if (preg_match('/^[a-f0-9]{16,64}$/', $id)) return $id;
    }
    $id = bin2hex(random_bytes(16));
    setcookie('track_voter', $id, [
        'expires'  => time() + 365 * 86400,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);
    $_COOKIE['track_voter'] = $id;
    return $id;
}

/**
 * Compute current Elo ratings + per-track vote stats from the full
 * preference history. Returns:
 *   [
 *     'Mario Kart Stadium' => ['elo'=>1612, 'votes_total'=>14, 'wins'=>9, 'losses'=>5, 'win_pct'=>64, 'cup'=>'Mushroom'],
 *     ...
 *   ]
 * Tracks with zero votes still appear, sitting at the base rating.
 */
function trackRankings(PDO $pdo): array {
    // Per-request memoization. Replaying the full vote history through the
    // Elo loop is pure but grows with vote volume; several pages call this
    // more than once per request. Cache keyed on a cheap table signature so
    // a new vote within the same request invalidates it automatically.
    static $cache = [];
    $sig = $pdo->query("SELECT COUNT(*) || ':' || COALESCE(MAX(id),0) FROM track_preferences")->fetchColumn();
    if (isset($cache[$sig])) return $cache[$sig];

    $tracks = getMKAllTracks();
    $ratings = [];
    $stats   = [];
    foreach ($tracks as $t) {
        $ratings[$t] = (float)TRACK_PREF_BASE_ELO;
        $stats[$t]   = ['votes_total' => 0, 'wins' => 0, 'losses' => 0];
    }

    $rows = $pdo->query("SELECT winner_track, loser_track FROM track_preferences ORDER BY voted_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $w = $r['winner_track'];
        $l = $r['loser_track'];
        if (!isset($ratings[$w]) || !isset($ratings[$l])) continue;

        $expW = 1.0 / (1.0 + pow(10, ($ratings[$l] - $ratings[$w]) / 400.0));
        $delta = TRACK_PREF_K_FACTOR * (1.0 - $expW);
        $ratings[$w] += $delta;
        $ratings[$l] -= $delta;

        $stats[$w]['votes_total']++;
        $stats[$l]['votes_total']++;
        $stats[$w]['wins']++;
        $stats[$l]['losses']++;
    }

    $out = [];
    foreach ($tracks as $t) {
        $vt = $stats[$t]['votes_total'];
        $out[$t] = [
            'elo'         => (int)round($ratings[$t]),
            'votes_total' => $vt,
            'wins'        => $stats[$t]['wins'],
            'losses'      => $stats[$t]['losses'],
            'win_pct'     => $vt > 0 ? (int)round($stats[$t]['wins'] / $vt * 100) : null,
            'cup'         => getMKTrackCup($t),
        ];
    }
    return $cache[$sig] = $out;
}

/**
 * Pick a pair of tracks to present next.
 *
 * Strategy: bias toward tracks that have been seen fewer times (so the
 * field doesn't get dominated by whichever tracks happen to surface
 * first), and avoid showing the current voter the same pair they just
 * rated within the last 5 votes.
 */
function pickTrackPair(PDO $pdo, string $voterId): array {
    $tracks = getMKAllTracks();
    if (count($tracks) < 2) return [];

    // Per-track exposure count: how often it's appeared (as winner OR loser).
    $counts = array_fill_keys($tracks, 0);
    foreach ($pdo->query("SELECT winner_track AS t, COUNT(*) AS n FROM track_preferences GROUP BY winner_track")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['t']])) $counts[$r['t']] += (int)$r['n'];
    }
    foreach ($pdo->query("SELECT loser_track AS t, COUNT(*) AS n FROM track_preferences GROUP BY loser_track")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['t']])) $counts[$r['t']] += (int)$r['n'];
    }

    // Build a weighted pool — fewer appearances => higher weight.
    $pool = [];
    foreach ($tracks as $t) {
        $w = max(1, 80 - $counts[$t]);
        for ($i = 0; $i < $w; $i++) $pool[] = $t;
    }

    // Pull last 5 pairs this voter has seen so we don't repeat.
    $recent = [];
    $rStmt = $pdo->prepare("SELECT winner_track, loser_track FROM track_preferences WHERE voter_id = ? ORDER BY voted_at DESC LIMIT 5");
    $rStmt->execute([$voterId]);
    foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['winner_track'] < $r['loser_track']
            ? $r['winner_track'] . '|' . $r['loser_track']
            : $r['loser_track']  . '|' . $r['winner_track'];
        $recent[$key] = true;
    }

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $a = $pool[array_rand($pool)];
        $b = $pool[array_rand($pool)];
        if ($a === $b) continue;
        $key = $a < $b ? $a . '|' . $b : $b . '|' . $a;
        if (!isset($recent[$key])) return [$a, $b];
    }
    do { $a = $tracks[array_rand($tracks)]; $b = $tracks[array_rand($tracks)]; } while ($a === $b);
    return [$a, $b];
}

/** Total votes from all users. */
function trackPrefTotalVotes(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM track_preferences")->fetchColumn();
}

/** Votes submitted by this voter. */
function trackPrefVoterVotes(PDO $pdo, string $voterId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM track_preferences WHERE voter_id = ?");
    $stmt->execute([$voterId]);
    return (int)$stmt->fetchColumn();
}
