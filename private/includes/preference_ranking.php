<?php
/**
 * Preference ranking — Elo over head-to-head votes (tracks).
 *
 * A vote row says "voter prefers winner over loser". Replaying the votes in
 * order through Elo (K=32, base 1500) gives a global ranking organisers can
 * use as a seed list. Parameterised by prefConfig(kind): today only 'track'
 * (track_favourites.php + api/vote_track_preference.php); a second kind is
 * one more config entry. (A 'cup' kind existed until /cup-favourites was
 * retired in favour of /track-favourites.)
 *
 * Path: /cdnmk/private/includes/preference_ranking.php
 */

require_once __DIR__ . '/mk_data.php';

const PREF_BASE_ELO = 1500;
const PREF_K_FACTOR = 32;

/** Everything that differs between the two kinds of vote. */
function prefConfig(string $kind): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'track' => [
                'table'    => 'track_preferences',
                'win_col'  => 'winner_track',
                'lose_col' => 'loser_track',
                'cookie'   => 'track_voter',
                'items'    => fn() => getMKAllTracks(),
                'extra'    => fn(string $item) => ['cup' => getMKTrackCup($item)],
                'pool_base'=> 80,
                'recent'   => 5,
                'attempts' => 20,
            ],
        ];
    }
    return $cfg[$kind];
}

/**
 * Read or create the voter cookie (1-year, httponly). Anyone can vote — no
 * login. The same cookie persists so "recent pairs" suppression keeps working.
 */
function prefVoterId(string $kind): string {
    $cookie = prefConfig($kind)['cookie'];
    if (!empty($_COOKIE[$cookie])) {
        $id = $_COOKIE[$cookie];
        if (preg_match('/^[a-f0-9]{16,64}$/', $id)) return $id;
    }
    $id = bin2hex(random_bytes(16));
    setcookie($cookie, $id, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
    $_COOKIE[$cookie] = $id;   // available immediately in this request
    return $id;
}

/**
 * Elo ratings + vote stats per item from the full history:
 *   item => ['elo', 'votes_total', 'wins', 'losses', 'win_pct' (null with no votes), …extra]
 * Items with zero votes still appear at the base rating. Memoised per request
 * on a table signature, so a vote landing mid-request invalidates it.
 */
function prefRankings(PDO $pdo, string $kind): array {
    static $cache = [];
    $c   = prefConfig($kind);
    $sig = $kind . ':' . $pdo->query("SELECT COUNT(*) || ':' || COALESCE(MAX(id),0) FROM {$c['table']}")->fetchColumn();
    if (isset($cache[$sig])) return $cache[$sig];

    $items = ($c['items'])();
    $ratings = []; $stats = [];
    foreach ($items as $it) { $ratings[$it] = (float)PREF_BASE_ELO; $stats[$it] = ['votes_total' => 0, 'wins' => 0, 'losses' => 0]; }

    $rows = $pdo->query("SELECT {$c['win_col']} AS w, {$c['lose_col']} AS l FROM {$c['table']} ORDER BY voted_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $w = $r['w']; $l = $r['l'];
        if (!isset($ratings[$w]) || !isset($ratings[$l])) continue;
        $expW  = 1.0 / (1.0 + pow(10, ($ratings[$l] - $ratings[$w]) / 400.0));
        $delta = PREF_K_FACTOR * (1.0 - $expW);
        $ratings[$w] += $delta;
        $ratings[$l] -= $delta;
        $stats[$w]['votes_total']++; $stats[$l]['votes_total']++;
        $stats[$w]['wins']++;        $stats[$l]['losses']++;
    }

    $out = [];
    foreach ($items as $it) {
        $vt = $stats[$it]['votes_total'];
        $out[$it] = [
            'elo'         => (int)round($ratings[$it]),
            'votes_total' => $vt,
            'wins'        => $stats[$it]['wins'],
            'losses'      => $stats[$it]['losses'],
            'win_pct'     => $vt > 0 ? (int)round($stats[$it]['wins'] / $vt * 100) : null,
        ] + ($c['extra'])($it);
    }
    return $cache[$sig] = $out;
}

/**
 * Next pair to show: bias toward items seen fewer times (so the field isn't
 * dominated by whatever surfaced first) and avoid the voter's recent pairs.
 */
function prefPickPair(PDO $pdo, string $kind, string $voterId): array {
    $c = prefConfig($kind);
    $items = ($c['items'])();
    if (count($items) < 2) return [];

    // Exposure count: appearances as winner OR loser.
    $counts = array_fill_keys($items, 0);
    foreach ($pdo->query("SELECT {$c['win_col']} AS i, COUNT(*) AS n FROM {$c['table']} GROUP BY {$c['win_col']}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['i']])) $counts[$r['i']] += (int)$r['n'];
    }
    foreach ($pdo->query("SELECT {$c['lose_col']} AS i, COUNT(*) AS n FROM {$c['table']} GROUP BY {$c['lose_col']}")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['i']])) $counts[$r['i']] += (int)$r['n'];
    }

    // Weighted pool — fewer appearances => higher weight.
    $pool = [];
    foreach ($items as $it) {
        $w = max(1, $c['pool_base'] - $counts[$it]);
        for ($i = 0; $i < $w; $i++) $pool[] = $it;
    }

    // The voter's last N pairs, so we don't immediately repeat one.
    $recent = [];
    $rStmt = $pdo->prepare("SELECT {$c['win_col']} AS w, {$c['lose_col']} AS l FROM {$c['table']} WHERE voter_id = ? ORDER BY voted_at DESC LIMIT {$c['recent']}");
    $rStmt->execute([$voterId]);
    foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $recent[$r['w'] < $r['l'] ? $r['w'] . '|' . $r['l'] : $r['l'] . '|' . $r['w']] = true;
    }

    for ($attempt = 0; $attempt < $c['attempts']; $attempt++) {
        $a = $pool[array_rand($pool)];
        $b = $pool[array_rand($pool)];
        if ($a === $b) continue;
        $key = $a < $b ? $a . '|' . $b : $b . '|' . $a;
        if (!isset($recent[$key])) return [$a, $b];
    }
    // Fallback: any distinct pair.
    do { $a = $items[array_rand($items)]; $b = $items[array_rand($items)]; } while ($a === $b);
    return [$a, $b];
}

/** Total votes from all users. */
function prefTotalVotes(PDO $pdo, string $kind): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM " . prefConfig($kind)['table'])->fetchColumn();
}

/** Votes submitted by this voter. */
function prefVoterVotes(PDO $pdo, string $kind, string $voterId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM " . prefConfig($kind)['table'] . " WHERE voter_id = ?");
    $stmt->execute([$voterId]);
    return (int)$stmt->fetchColumn();
}
