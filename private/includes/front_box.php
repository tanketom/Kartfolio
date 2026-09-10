<?php
/**
 * The homepage's rotating box — one card at a time from the corners of the
 * site nothing links to.
 *
 * Fifteen public pages have no inbound link from anywhere: /lexicon, /vault,
 * /elo-trends, /predictions, /multiverse and /fantasy among them. They are
 * routed, finished and invisible. This pulls one true line out of each and
 * rotates them on the front page, so the pages are reachable and the line
 * itself is worth reading even if nobody clicks.
 *
 * Cost rules, because the homepage is a hot page (§9):
 *   - This is served by /api/front-box and fetched AFTER the page renders,
 *     so nothing here is on the homepage's critical path.
 *   - Cheap cards (lexicon, vault, fantasy) are plain queries.
 *   - Expensive ones (Elo, multiverse) are computed once and parked in
 *     `sim_cache` keyed on the results signature plus the day, the same
 *     pattern the Crystal Ball uses.
 *   - The Crystal Ball's own Monte Carlo is NOT re-run here. Its cached run
 *     is read if /predictions has been opened today, and the card is simply
 *     omitted otherwise — a second, cheaper simulation would give the front
 *     page different odds from the page it links to.
 *
 * Every card: key, icon, kicker, headline, line, href. A card that has no
 * true thing to say returns null and never appears.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/sim_cache.php';
require_once __DIR__ . '/fantasy.php';

/** Cheap signature of the results table: changes exactly when a GP is added. */
function frontBoxSignature(PDO $pdo): string {
    try {
        return (string)$pdo->query("SELECT COUNT(*) || ':' || COALESCE(MAX(id), 0) FROM results")->fetchColumn();
    } catch (PDOException $e) { return '0:0'; }
}

/** A lexicon entry — the league's own vocabulary, defined by hand. */
function frontBoxLexicon(PDO $pdo): ?array {
    try {
        $row = $pdo->query("SELECT term, slug, definition FROM lexicon_terms
                            WHERE definition IS NOT NULL AND definition != ''
                            ORDER BY RANDOM() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return null; }
    if (!$row) return null;

    $def = trim((string)$row['definition']);
    if (mb_strlen($def) > 190) $def = mb_substr($def, 0, 187) . '…';
    return [
        'key' => 'lexicon', 'icon' => '📖', 'kicker' => 'From the Lexicon',
        'headline' => (string)$row['term'], 'line' => $def,
        'href' => '/lexicon/' . rawurlencode((string)$row['slug']),
    ];
}

/** The Vault: the biggest single GP score anyone has ever posted. */
function frontBoxVault(PDO $pdo): ?array {
    try {
        $row = $pdo->query("
            SELECT res.gp_points, res.gpid, res.cup_name, r.name
            FROM results res JOIN racers r ON r.id = res.racer_id
            WHERE res.gpid LIKE 's%'
            ORDER BY res.gp_points DESC, res.race_date ASC, res.id ASC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return null; }
    if (!$row || (int)$row['gp_points'] <= 0) return null;

    $cup = trim((string)($row['cup_name'] ?? ''));
    return [
        'key' => 'vault', 'icon' => '🗄️', 'kicker' => 'From the Vault',
        'headline' => $row['name'] . ' · ' . (int)$row['gp_points'] . ' points',
        'line' => 'The best single Grand Prix anyone has ever posted'
                  . ($cup !== '' ? ', on the ' . $cup . ' Cup' : '') . '.',
        'href' => '/records#vault',
    ];
}

/** Biggest Elo mover on the most recent race night. */
function frontBoxElo(PDO $pdo): ?array {
    $key = 'frontbox:elo:' . frontBoxSignature($pdo);
    $hit = simCacheGet($pdo, $key);
    if ($hit !== null) return $hit['card'] ?? null;

    $card = null;
    try {
        require_once __DIR__ . '/elo_engine.php';
        $elo = calculateAllELORatings($pdo);
        // gp_changelog is a flat list of GP entries, each carrying a `racers`
        // array of per-racer moves — not a name => delta map.
        $log = $elo['gp_changelog'] ?? [];
        if ($log) {
            $last = $log[array_key_last($log)] ?? [];
            $best = null;
            foreach (($last['racers'] ?? []) as $m) {
                $name = (string)($m['name'] ?? '');
                $d    = (float)($m['change'] ?? 0);
                if ($name === '') continue;
                // Ties by name so the card does not change between reloads (§10).
                if ($best === null || abs($d) > abs($best['d'])
                    || (abs($d) === abs($best['d']) && strcmp($name, $best['name']) < 0)) {
                    $best = ['name' => $name, 'd' => $d];
                }
            }
            if ($best && abs($best['d']) >= 1) {
                $up = $best['d'] > 0;
                $card = [
                    'key' => 'elo', 'icon' => $up ? '📈' : '📉', 'kicker' => 'Elo movement',
                    'headline' => $best['name'] . ' ' . ($up ? '+' : '−') . round(abs($best['d'])),
                    'line' => 'The biggest rating swing of the last Grand Prix'
                              . (!empty($last['cup']) ? ', on the ' . $last['cup'] . ' Cup' : '') . '.',
                    'href' => '/elo-trends',
                ];
            }
        }
    } catch (Throwable $e) { $card = null; }

    simCachePut($pdo, $key, ['card' => $card]);
    return $card;
}

/**
 * The Multiverse: how many of the scoring systems the real champion would
 * still have won. Every system across every season is far too heavy for a
 * page load, so it is computed once per results signature.
 */
function frontBoxMultiverse(PDO $pdo): ?array {
    $key = 'frontbox:multiverse:' . frontBoxSignature($pdo);
    $hit = simCacheGet($pdo, $key);
    if ($hit !== null) return $hit['card'] ?? null;

    $card = null;
    try {
        $season = getCurrentSeasonNumber();
        $counts = [];
        // multiverseTop() (gp_logic.php) is what /multiverse itself uses, so
        // the two always agree. A universe with no verdict returns no top.
        foreach (array_keys(getScoringSystemRegistry()) as $sysKey) {
            $top = multiverseTop($pdo, $season, $sysKey)['top'] ?? [];
            if (!$top) continue;
            $winner = (int)$top[0]['id'];
            $counts[$winner] = ($counts[$winner] ?? 0) + 1;
        }
        if ($counts) {
            arsort($counts);
            $names   = racerNamesMap($pdo);
            $topId   = (int)array_key_first($counts);
            $total   = array_sum($counts);
            $leaders = count($counts);
            $card = [
                'key' => 'multiverse', 'icon' => '🌌', 'kicker' => 'The Multiverse',
                'headline' => ($names[$topId] ?? 'Someone') . ' wins ' . $counts[$topId] . ' of ' . $total . ' systems',
                'line' => $leaders === 1
                    ? 'Re-scored under every scoring system the league has, this season has one undisputed leader.'
                    : 'Re-scored under all ' . $total . ' systems, this season has ' . $leaders . ' different leaders.',
                'href' => '/multiverse',
            ];
        }
    } catch (Throwable $e) { $card = null; }

    simCachePut($pdo, $key, ['card' => $card]);
    return $card;
}

/**
 * The Crystal Ball. Reads a cached Monte Carlo only — see the header note on
 * why this never runs its own.
 */
function frontBoxPredictions(PDO $pdo): ?array {
    try {
        $season = getCurrentSeasonNumber();
        $row = $pdo->prepare("SELECT payload FROM sim_cache WHERE cache_key LIKE ? ORDER BY created_at DESC LIMIT 1");
        $row->execute(['predictions:' . $season . ':' . date('Y-m-d') . ':%']);
        $raw = $row->fetchColumn();
        if (!$raw) return null;
        $wins = json_decode((string)$raw, true)['wins'] ?? null;
        if (!is_array($wins) || !$wins) return null;
    } catch (PDOException $e) { return null; }

    arsort($wins);
    $total = array_sum($wins);
    if ($total <= 0) return null;
    $name = (string)array_key_first($wins);
    $pct  = (int)round(($wins[$name] / $total) * 100);

    return [
        'key' => 'predictions', 'icon' => '🔮', 'kicker' => 'The Crystal Ball',
        'headline' => $name . ' · ' . $pct . '% to take the title',
        'line' => 'From the season simulation, run over every remaining Grand Prix.',
        'href' => '/predictions',
    ];
}

/**
 * Fantasy. Always offered; the caller pins it when the deadline is close.
 * `urgent` is what makes it stick to the front of the rotation.
 */
function frontBoxFantasy(PDO $pdo): ?array {
    if (!moduleEnabled($pdo, 'fantasy')) return null;

    $fd = fantasyDeadline();
    if (!$fd['open']) return null;

    // Inside the last 24 hours the card stops rotating and stays put.
    $urgent = $fd['seconds_left'] <= 86400;

    $entries = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT predictor_id) FROM fantasy_bets WHERE week_key = ?");
        $st->execute([$fd['week_key']]);
        $entries = (int)$st->fetchColumn();
    } catch (PDOException $e) {}

    return [
        'key' => 'fantasy', 'icon' => '🎲', 'kicker' => $urgent ? 'Picks close soon' : 'Fantasy',
        'headline' => $fd['human'],
        'line' => $entries > 0
            ? $entries . ' ' . ($entries === 1 ? 'person has' : 'people have') . ' put their picks in for this week.'
            : 'Nobody has put picks in for this week yet.',
        'href' => '/fantasy',
        'urgent' => $urgent,
    ];
}

/**
 * Every card worth showing, urgent ones first. Order is otherwise stable so
 * the rotation does not reshuffle between reloads.
 */
function frontBoxCards(PDO $pdo): array {
    $cards = array_values(array_filter([
        frontBoxFantasy($pdo),
        frontBoxPredictions($pdo),
        frontBoxElo($pdo),
        frontBoxMultiverse($pdo),
        frontBoxVault($pdo),
        frontBoxLexicon($pdo),
    ]));

    usort($cards, fn($a, $b) => (int)!empty($b['urgent']) <=> (int)!empty($a['urgent']));
    return $cards;
}
