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
require_once __DIR__ . '/dotw.php';
require_once __DIR__ . '/leaderboard.php';   // the Power Rankings card reads the standings

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

/**
 * On this day — a race night from the same date in an earlier year, or failing
 * that the same day of an earlier month ("Seven months ago today").
 *
 * Years first, then the longest month gap, so the card reaches as far back as
 * the league's history allows and is the same card all day. Months exist
 * because the league is young: with results only from late 2025, a strict
 * "a year ago today" would be empty most of the year. Measured on the live
 * data, the month fallback gives every day of the coming year a card.
 *
 * The night's fact is a debut if anyone raced their first ever GP that night,
 * otherwise the top single-GP score. Two queries: the dates, then one night.
 */
function frontBoxOnThisDay(PDO $pdo, ?DateTimeImmutable $today = null): ?array {
    $today = $today ?? new DateTimeImmutable('today');
    try {
        $nights = array_flip($pdo->query("SELECT DISTINCT date(race_date) FROM results
                                          WHERE gpid LIKE 's%' AND race_date IS NOT NULL")
                                 ->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) { return null; }
    if (!$nights) return null;

    $words = [1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
              'Ten', 'Eleven', 'Twelve'];
    $when = null; $date = null;
    // A year back is always the better story; a month gap only fills in for it.
    foreach ([['year', 10], ['month', 36]] as [$unit, $max]) {
        for ($k = $max; $k >= 1; $k--) {
            $d = $today->modify("-$k $unit");
            // "-1 month" from 31 March lands on 3 March; that is not "a month ago today".
            if ($d->format('d') !== $today->format('d')) continue;
            if (isset($nights[$d->format('Y-m-d')])) {
                $n = $words[$k] ?? (string)$k;
                $when = ($k === 1 ? "One $unit" : "$n {$unit}s") . ' ago today';
                $date = $d->format('Y-m-d');
                break 2;
            }
        }
    }
    if ($date === null) return null;

    try {
        $st = $pdo->prepare("
            SELECT res.racer_id, res.gpid, res.gp_points, res.cup_name, r.name,
                   (SELECT MIN(date(p.race_date)) FROM results p
                    WHERE p.racer_id = res.racer_id AND p.gpid LIKE 's%') AS first_night
            FROM results res JOIN racers r ON r.id = res.racer_id
            WHERE date(res.race_date) = ? AND res.gpid LIKE 's%'
            ORDER BY res.gp_points DESC, res.gpid ASC, res.id ASC
        ");
        $st->execute([$date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return null; }
    if (!$rows) return null;

    $gpids = array_values(array_unique(array_column($rows, 'gpid')));
    sort($gpids, SORT_NATURAL);                      // s02gp99 before s02gp100
    $gpCount = count($gpids);
    $span = $gpCount === 1 ? $gpids[0] : $gpids[0] . '–' . end($gpids);
    $night = $gpCount === 1 ? 'a one-GP night' : "a $gpCount-GP night";

    $debuts = [];
    foreach ($rows as $r) {
        if ($r['first_night'] === $date) $debuts[$r['name']] = true;
    }
    $top = $rows[0];
    $cup = trim((string)($top['cup_name'] ?? ''));
    $onCup = $cup !== '' ? ' on the ' . $cup . ' Cup' : '';
    $pts = (int)$top['gp_points'];
    // 15 points × 4 races: nobody can score more.
    $score = $pts === 60 ? 'a perfect 60' : $pts . ' points';
    $topLine = 'Top score: ' . $top['name'] . ', ' . $score . $onCup . '.';

    $debuts = [];
    foreach ($rows as $r) {
        if ($r['first_night'] === $date) $debuts[$r['name']] = true;
    }
    $names = array_keys($debuts);
    $firstNight = $date === min(array_keys($nights));

    if ($firstNight) {
        $headline = "The league's first race night";
        $line = count($names) . ' racers, ' . $gpCount . " GPs ($span). " . $topLine;
    } elseif (count($names) > 2) {
        $headline = count($names) . ' debuts in one night';
        $line = implode(', ', $names) . " — $night ($span). " . $topLine;
    } elseif ($names) {
        $headline = implode(' and ', $names) . "'s first Grand Prix";
        $line = (count($names) === 1 ? 'The debut' : 'The debuts') . " came on $night ($span). " . $topLine;
    } else {
        $headline = $top['name'] . ' · ' . $score . $onCup;
        $line = 'The top score of ' . $night . " ($span).";
    }

    return [
        'key' => 'onthisday', 'icon' => '🗓️', 'kicker' => 'On this day · ' . $when,
        'headline' => $headline, 'line' => $line,
        'href' => '/timeline/' . rawurlencode((string)$top['gpid']),
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
 * Driver of the Week — the league's only vote, so it gets its own card
 * whether or not anyone has voted yet. Votes are cast on the fantasy form and
 * cover the week that just ended (see dotw.php).
 */
function frontBoxDotw(PDO $pdo): ?array {
    $week   = dotwVotingWeek();
    $ballot = dotwBallot($pdo, $week['from'], $week['to']);
    if (!$ballot) return null;                     // nobody raced: nothing to vote on

    $span    = date('M j', strtotime($week['from'])) . '–' . date('M j', strtotime($week['to']));
    $results = dotwResults($pdo, $week['week_key']);
    if (!$results) {
        return [
            'key' => 'dotw', 'icon' => '🏅', 'kicker' => 'Driver of the Week',
            'headline' => 'No votes yet',
            'line' => 'Nobody has said who drove best over ' . $span . '. Voting is on the fantasy form.',
            'href' => '/fantasy?submit',
        ];
    }

    $total = array_sum(array_column($results, 'votes'));
    $top   = $results[0]['votes'];
    // Ties share it, the way the fantasy champion does.
    $leaders = array_map(fn($r) => $r['name'], array_filter($results, fn($r) => $r['votes'] === $top));

    return [
        'key' => 'dotw', 'icon' => '🏅', 'kicker' => 'Driver of the Week',
        'headline' => implode(' & ', $leaders),
        'line' => $span . ' · ' . $top . ' of ' . $total . ' vote' . ($total === 1 ? '' : 's')
                  . (count($leaders) > 1 ? ' each, level at the top.' : '.'),
        'href' => '/fantasy?submit',
    ];
}

/**
 * Power Rankings — a composite of Elo, recent form and consistency, on a page
 * nothing linked to. The card names the mover rather than the leader: who is
 * top is already the whole front page.
 */
function frontBoxPowerRankings(PDO $pdo): ?array {
    $key = 'frontbox:power:' . frontBoxSignature($pdo);
    $hit = simCacheGet($pdo, $key);
    if ($hit !== null) return $hit['card'] ?? null;

    $card = null;
    try {
        $season = getCurrentSeasonNumber();
        $rows = leaderboardRows($pdo, $season, 0, false);
        $ranked = array_values(array_filter($rows, fn($r) => $r['qualifies']));
        // The biggest climber since the last race night is the story; ties go
        // to the better-placed racer so the card is stable between reloads.
        $best = null;
        foreach ($ranked as $r) {
            $mv = $r['rank_change'];
            if ($mv === null || $mv <= 0) continue;
            if ($best === null || $mv > $best['rank_change']) $best = $r;
        }
        if ($best) {
            $card = [
                'key' => 'power', 'icon' => '🎙️', 'kicker' => 'Power Rankings',
                'headline' => $best['name'] . ' up ' . (int)$best['rank_change']
                              . ' to #' . (int)$best['rank'],
                'line' => 'Elo, recent form and consistency blended into one number.',
                'href' => '/power-rankings',
            ];
        } elseif ($ranked) {
            $card = [
                'key' => 'power', 'icon' => '🎙️', 'kicker' => 'Power Rankings',
                'headline' => 'Nobody moved',
                'line' => 'The order held on the last race night. Elo, form and consistency, blended.',
                'href' => '/power-rankings',
            ];
        }
    } catch (Throwable $e) { $card = null; }

    simCachePut($pdo, $key, ['card' => $card]);
    return $card;
}

/**
 * Every card worth showing, urgent ones first. Order is otherwise stable so
 * the rotation does not reshuffle between reloads.
 */
function frontBoxCards(PDO $pdo): array {
    $cards = array_values(array_filter([
        frontBoxFantasy($pdo),
        frontBoxDotw($pdo),
        frontBoxPowerRankings($pdo),
        frontBoxPredictions($pdo),
        frontBoxElo($pdo),
        frontBoxMultiverse($pdo),
        frontBoxOnThisDay($pdo),
        frontBoxVault($pdo),
        frontBoxLexicon($pdo),
    ]));

    usort($cards, fn($a, $b) => (int)!empty($b['urgent']) <=> (int)!empty($a['urgent']));
    return $cards;
}
