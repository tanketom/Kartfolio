<?php
/**
 * The standings rows every leaderboard surface shows — the homepage and the
 * three signage screens (Lounge, Game Room, auto-rotator).
 *
 * They all used to build their own rows, and drifted: the homepage sorted
 * badges by rarity and marked the ones earned on the latest race night, the
 * signs showed them in emission order with no marking; the homepage explained
 * a tie, the signs printed two identical scores with no hint why one was
 * above the other. A sign that disagrees with the homepage is a bug people
 * notice from across the room, so there is one builder now.
 *
 * Everything reads through the per-request caches in gp_logic.php and
 * badges.php, so a second caller on the same request costs almost nothing.
 */

require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/badges.php';
require_once __DIR__ . '/settings.php';

/**
 * @param int  $badgeLimit  Keep at most this many badges per racer (rarest
 *                          first). 0 = all of them. The signs cap; the web
 *                          page does not.
 *  @param bool $withBadges Compute badges at all. Badges are by far the
 *                          expensive part of a row — they pull in the Elo
 *                          engine, stickers, tournaments and the whole badge
 *                          context — so a consumer that does not display them
 *                          (the JSON API) passes false and pays 1 query
 *                          instead of 83. Everything else about the row, the
 *                          ranking included, is unchanged either way.
 *
 * Each row: id, name, score, char, raceCount, qualifies, rank, rank_change,
 * tie, badges (each with icon/title/desc/held/is_new), badge_overflow,
 * breakdown, tooltip, cupsCounted, mikko.
 */
function leaderboardRows(PDO $pdo, string $seasonId, int $badgeLimit = 0, bool $withBadges = true): array {
    static $cache = [];
    // $withBadges is part of the key: without it a badge-free call would poison
    // the cache for the homepage, which renders the badges on the same request.
    $key = $seasonId . '|' . $badgeLimit . '|' . ($withBadges ? 'b' : 'n');
    if (isset($cache[$key])) return $cache[$key];

    $rules       = getSeasonRules($pdo, $seasonId);
    $system      = $rules['scoring_system'] ?? 'average_attendance';
    $latestDate  = getLatestRaceDate($pdo, $seasonId);
    $previous    = calculatePreviousStandings($pdo, $seasonId, $latestDate, $rules);
    $holderCount = $withBadges ? badgeHolderCounts($pdo, $seasonId) : [];
    $newTonight  = $withBadges ? badgeNewThisNight($pdo, $seasonId) : [];

    // Mikkoliiga rank per racer, when the module is on.
    $mikkoByRacer = []; $mikkoTotal = 0;
    if (moduleEnabled($pdo, 'mikkoliiga') && function_exists('getMikkoliigaStandings')) {
        $mikkoStandings = getMikkoliigaStandings($pdo, $seasonId);
        $mikkoTotal = count($mikkoStandings);
        foreach ($mikkoStandings as $i => $m) {
            $mikkoByRacer[(int)$m['id']] = ['rank' => $i + 1, 'score' => (int)($m['score'] ?? 0), 'gps' => (int)($m['gps_counted'] ?? 0), 'total' => $mikkoTotal];
        }
    }

    $rows = [];
    foreach (getActiveRacers($pdo, $seasonId) as $r) {
        $rid       = (int)$r['id'];
        $raceCount = getRaceCount($pdo, $rid, $seasonId);
        $breakdown = getScoringBreakdown($pdo, $rid, $seasonId);

        // Badges: rarest first, so the most interesting one leads — and on a
        // sign, so the cap keeps the ones worth showing.
        $badges = ($withBadges && $raceCount >= 3)
            ? sortBadgesByRarity(getRacerBadges($pdo, $rid, $seasonId), $holderCount)
            : [];
        foreach ($badges as &$b) {
            $b['held']   = $holderCount[$b['title']] ?? 1;
            $b['is_new'] = isset($newTonight[$rid . '|' . $b['title']]);
        }
        unset($b);
        $total = count($badges);
        if ($badgeLimit > 0 && $total > $badgeLimit) {
            // A badge earned tonight is the news, so it survives the cut even
            // if it is a common one; the rest fill up in rarity order.
            $fresh = array_values(array_filter($badges, fn($b) => $b['is_new']));
            $rest  = array_values(array_filter($badges, fn($b) => !$b['is_new']));
            $badges = array_slice(array_merge($fresh, $rest), 0, $badgeLimit);
        }

        $rows[] = [
            'id'          => $rid,
            'name'        => $r['name'],
            'score'       => calculateGPScore($pdo, $rid, $seasonId),
            'char'        => getMostUsedCharacter($pdo, $rid, $seasonId),
            'raceCount'   => $raceCount,
            'qualifies'   => racerQualifies($raceCount, $rules),
            'badges'      => $badges,
            'badge_overflow' => $badgeLimit > 0 ? max(0, $total - $badgeLimit) : 0,
            'breakdown'   => $breakdown,
            'tooltip'     => scoringTooltipFromBreakdown($breakdown),
            'cupsCounted' => (int)($breakdown['components']['cups_counted'] ?? 0),
            'mikko'       => $mikkoByRacer[$rid] ?? null,
        ];
    }

    sortStandingsByScoring($rows, $system, $pdo, $seasonId);

    // Racers who have not raced enough to qualify drop below everyone who has,
    // and take no rank number with them. They used to sit wherever their score
    // put them and silently consume a place: on a season with the threshold at
    // 20, Tegan was the 4th eligible racer and seasonPlacements() called her
    // 4th, while the homepage showed #5 because an ineligible racer above her
    // had eaten rank 4. Both halves of the sort are already score-ordered, and
    // array_filter preserves order, so this is a stable partition.
    $eligible   = array_values(array_filter($rows, fn($r) => $r['qualifies']));
    $ineligible = array_values(array_filter($rows, fn($r) => !$r['qualifies']));
    $rows = array_merge($eligible, $ineligible);

    $place = 0;
    foreach ($rows as $i => &$row) {
        // null, not a number, for anyone who does not hold a place.
        $row['rank'] = $row['qualifies'] ? ++$place : null;
        $prevRank = $row['qualifies'] ? ($previous[$row['id']] ?? null) : null;
        $row['rank_change'] = $prevRank !== null ? $prevRank - $row['rank'] : null;
        // Level with the racer above? Say what separated them (registry tie_explain, §2a).
        $row['tie'] = ($i > 0 && $row['qualifies'] && $rows[$i - 1]['score'] == $row['score'])
            ? explainStandingsTie($pdo, $seasonId, $system, $rows[$i - 1], $row)
            : null;
    }
    unset($row);

    return $cache[$key] = $rows;
}

/** Badges earned on the latest race night, across the whole season. For a sign's "tonight" strip. */
function leaderboardNewTonight(PDO $pdo, string $seasonId): array {
    $out = [];
    foreach (leaderboardRows($pdo, $seasonId) as $row) {
        foreach ($row['badges'] as $b) if (!empty($b['is_new'])) $out[] = ['name' => $row['name'], 'icon' => $b['icon'], 'title' => $b['title']];
    }
    return $out;
}
