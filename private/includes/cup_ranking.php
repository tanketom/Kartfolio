<?php
/**
 * Cup preference ranking — Elo over head-to-head votes.
 *
 * Each row in `cup_preferences` is a head-to-head: voter prefers
 * winner_cup over loser_cup. We replay the preferences in vote order
 * applying Elo (K=32, base 1500) to produce a global ranking that
 * tournament organisers can use as a seed list.
 *
 * Path: /cdnmk/private/includes/cup_ranking.php
 */

require_once __DIR__ . '/mk_data.php';

const CUP_PREF_BASE_ELO = 1500;
const CUP_PREF_K_FACTOR = 32;

/**
 * Read or create the voter cookie. Returns the voter ID string.
 *
 * Set as a 1-year httponly cookie. We don't tie it to a racer — anyone
 * can vote, no login required.
 */
function cupPrefVoterId(): string {
    if (!empty($_COOKIE['cup_voter'])) {
        $id = $_COOKIE['cup_voter'];
        // Belt-and-suspenders sanity check on the cookie contents.
        if (preg_match('/^[a-f0-9]{16,64}$/', $id)) return $id;
    }
    $id = bin2hex(random_bytes(16));
    setcookie('cup_voter', $id, [
        'expires'  => time() + 365 * 86400,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);
    // Make it available immediately in this request.
    $_COOKIE['cup_voter'] = $id;
    return $id;
}

/**
 * Compute current Elo ratings + per-cup vote stats from the full
 * preference history. Returns:
 *   [
 *     'Mushroom' => ['elo'=>1612, 'votes_total'=>14, 'wins'=>9, 'losses'=>5, 'win_pct'=>64],
 *     ...
 *   ]
 * Cups with zero votes still appear, sitting at the base rating.
 */
function cupRankings(PDO $pdo): array {
    $cups = getMKAllCups();
    $ratings = [];
    $stats   = [];
    foreach ($cups as $cup) {
        $ratings[$cup] = (float)CUP_PREF_BASE_ELO;
        $stats[$cup]   = ['votes_total' => 0, 'wins' => 0, 'losses' => 0];
    }

    $rows = $pdo->query("SELECT winner_cup, loser_cup FROM cup_preferences ORDER BY voted_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $w = $r['winner_cup'];
        $l = $r['loser_cup'];
        if (!isset($ratings[$w]) || !isset($ratings[$l])) continue;

        $expW = 1.0 / (1.0 + pow(10, ($ratings[$l] - $ratings[$w]) / 400.0));
        $delta = CUP_PREF_K_FACTOR * (1.0 - $expW);
        $ratings[$w] += $delta;
        $ratings[$l] -= $delta;

        $stats[$w]['votes_total']++;
        $stats[$l]['votes_total']++;
        $stats[$w]['wins']++;
        $stats[$l]['losses']++;
    }

    $out = [];
    foreach ($cups as $cup) {
        $vt = $stats[$cup]['votes_total'];
        $out[$cup] = [
            'elo'         => (int)round($ratings[$cup]),
            'votes_total' => $vt,
            'wins'        => $stats[$cup]['wins'],
            'losses'      => $stats[$cup]['losses'],
            'win_pct'     => $vt > 0 ? (int)round($stats[$cup]['wins'] / $vt * 100) : null,
        ];
    }
    return $out;
}

/**
 * Pick a pair of cups to present next. Strategy: favour cups that have
 * been seen fewer times (so the field doesn't get dominated by whatever
 * we picked first), and avoid showing the current voter the same pair
 * they just rated.
 */
function pickCupPair(PDO $pdo, string $voterId): array {
    $cups = getMKAllCups();
    if (count($cups) < 2) return [];

    // Per-cup exposure count: how many times has this cup appeared (as winner OR loser)?
    $counts = array_fill_keys($cups, 0);
    $rows = $pdo->query("SELECT winner_cup AS c, COUNT(*) AS n FROM cup_preferences GROUP BY winner_cup")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { if (isset($counts[$r['c']])) $counts[$r['c']] += (int)$r['n']; }
    $rows = $pdo->query("SELECT loser_cup AS c, COUNT(*) AS n FROM cup_preferences GROUP BY loser_cup")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { if (isset($counts[$r['c']])) $counts[$r['c']] += (int)$r['n']; }

    // Build a weighted pool — fewer appearances => higher weight.
    $pool = [];
    foreach ($cups as $cup) {
        $w = max(1, 50 - $counts[$cup]);
        for ($i = 0; $i < $w; $i++) $pool[] = $cup;
    }

    // Pull last 3 pairs this voter has seen so we don't immediately repeat.
    $recent = [];
    $rStmt = $pdo->prepare("SELECT winner_cup, loser_cup FROM cup_preferences WHERE voter_id = ? ORDER BY voted_at DESC LIMIT 3");
    $rStmt->execute([$voterId]);
    foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = implode('|', $r['winner_cup'] < $r['loser_cup'] ? [$r['winner_cup'], $r['loser_cup']] : [$r['loser_cup'], $r['winner_cup']]);
        $recent[$key] = true;
    }

    // Try a few times to avoid the recent set.
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $a = $pool[array_rand($pool)];
        $b = $pool[array_rand($pool)];
        if ($a === $b) continue;
        $sorted = $a < $b ? [$a, $b] : [$b, $a];
        $key = implode('|', $sorted);
        if (!isset($recent[$key])) return [$a, $b];
    }
    // Fallback: any distinct pair.
    do { $a = $cups[array_rand($cups)]; $b = $cups[array_rand($cups)]; } while ($a === $b);
    return [$a, $b];
}

/** Total votes submitted by all users. */
function cupPrefTotalVotes(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM cup_preferences")->fetchColumn();
}

/** Votes submitted by this voter. */
function cupPrefVoterVotes(PDO $pdo, string $voterId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cup_preferences WHERE voter_id = ?");
    $stmt->execute([$voterId]);
    return (int)$stmt->fetchColumn();
}
