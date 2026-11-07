<?php
/**
 * The league's birthday: the anniversary of its first race (Wrapped opens the same
 * day). On that date the homepage leads with a card and the Lounge sign gives the
 * birthday panel every other minute. Worked out from the first race, never
 * hard-coded, so every league running Kartfolio gets its own.
 *
 * ?anniversary=1 on either page shows it on any day, to check it in advance.
 */
require_once __DIR__ . '/wrapped_personas.php';   // wrappedFirstRace(), wrappedPeriodFor()
require_once __DIR__ . '/mk_data.php';            // ordinal()

/** Which birthday $today is (1 = the first), or null. A 29 February start is kept on 1 March, as Wrapped does. Pure. */
function leagueAnniversaryOn(?string $firstRace, string $today): ?int {
    if ($firstRace === null || $firstRace === '') return null;
    $p = wrappedPeriodFor($firstRace, (int)substr($today, 0, 4));
    return ($p['number'] >= 1 && $p['end'] === $today) ? $p['number'] : null;
}

/** The birthday to show today: a number, or null. $preview shows the coming (or latest) one on any day. */
function leagueAnniversaryToday(PDO $pdo, bool $preview = false, ?string $today = null): ?int {
    $today ??= date('Y-m-d');
    $first = wrappedFirstRace($pdo);
    $n = leagueAnniversaryOn($first, $today);
    if ($n !== null || !$preview || $first === null) return $n;
    return max(1, (int)substr($today, 0, 4) - (int)substr($first, 0, 4));
}

/**
 * Everything the card and the panel say, in four queries. Season GPs only.
 * ['number','first' (Y-m-d),'night' => ['gps','racers' => names],'gp' => ['gpid','cup','rows' => [[name, points], …]],
 *  'gps','racers','races','year_gps','busiest' => [name, gps]|null,'still' => names from night one who raced in the last 30 days]
 */
function leagueAnniversaryFacts(PDO $pdo, int $number, ?string $today = null): ?array {
    $today ??= date('Y-m-d');
    $first = wrappedFirstRace($pdo);
    if ($first === null) return null;
    try {
        $night = $pdo->prepare("SELECT x.gpid, x.cup_name, x.gp_points, x.racer_id, r.name FROM results x JOIN racers r ON r.id = x.racer_id
                                WHERE x.gpid LIKE 's%' AND date(x.race_date) = ? ORDER BY x.race_date ASC, x.gpid ASC, x.gp_points DESC, r.name ASC");
        $night->execute([$first]);
        $rows = $night->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $firstGp = $rows[0]['gpid'];
        $gpRows = array_values(array_filter($rows, fn($r) => $r['gpid'] === $firstGp));
        $nightNames = [];
        foreach ($rows as $r) { $nightNames[(int)$r['racer_id']] = $r['name']; }
        $all = $pdo->query("SELECT COUNT(DISTINCT gpid) AS gps, COUNT(DISTINCT racer_id) AS racers FROM results WHERE gpid LIKE 's%'")->fetch(PDO::FETCH_ASSOC);
        // The year just finished: from the last birthday (or the first race) up to today.
        $since = wrappedPeriodFor($first, (int)substr($today, 0, 4))['start'];
        if ($since > $today) $since = wrappedPeriodFor($first, (int)substr($today, 0, 4) - 1)['start'];
        $st = $pdo->prepare("SELECT r.name, COUNT(DISTINCT x.gpid) AS n FROM results x JOIN racers r ON r.id = x.racer_id
                             WHERE x.gpid LIKE 's%' AND date(x.race_date) >= ? AND date(x.race_date) <= ? GROUP BY x.racer_id ORDER BY n DESC, r.name ASC");
        $st->execute([$since, $today]);
        $year = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT COUNT(DISTINCT gpid) FROM results WHERE gpid LIKE 's%' AND date(race_date) >= ? AND date(race_date) <= ?");
        $st->execute([$since, $today]);
        $yearGps = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT DISTINCT racer_id FROM results WHERE gpid LIKE 's%' AND date(race_date) >= date(?, '-30 days')");
        $st->execute([$today]);
        $recent = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        error_log('anniversary: ' . $e->getMessage());
        return null;
    }
    return [
        'number' => $number, 'first' => $first,
        'night'  => ['gps' => count(array_unique(array_column($rows, 'gpid'))), 'racers' => array_values($nightNames)],
        'gp'     => ['gpid' => $firstGp, 'cup' => (string)$gpRows[0]['cup_name'], 'rows' => array_map(fn($r) => [$r['name'], (int)$r['gp_points']], $gpRows)],
        'gps'    => (int)$all['gps'], 'racers' => (int)$all['racers'], 'races' => 4 * (int)$all['gps'],
        'year_gps' => $yearGps,
        'busiest'  => $year ? [$year[0]['name'], (int)$year[0]['n']] : null,
        'still'    => array_values(array_intersect_key($nightNames, $recent)),
    ];
}

/** "Year 1", "Year 2"… and the ordinal for prose: "first birthday". */
function leagueAnniversaryLabel(int $n): string {
    $words = [1 => 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth'];
    return ($words[$n] ?? ordinal($n)) . ' birthday';
}
