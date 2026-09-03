<?php
/**
 * Build a small synthetic league database for checks and CI.
 *
 *   php bin/make_fixture.php /path/to/fixture.db
 *
 * Bootstraps the schema through db.php (so the fresh-install path is what
 * gets exercised), then adds 8 racers and three seasons: s01 archived on
 * GPScore™, s02 live on Territory (the map), s03 upcoming — ~40 GPs with
 * 4–8 humans each, every cup used, a few perfects, an is_monster flag.
 * Deterministic (seeded), so page output is stable between runs.
 */
$out = $argv[1] ?? sys_get_temp_dir() . '/kartfolio_fixture.db';
foreach ([$out, "$out-wal", "$out-shm"] as $f) if (file_exists($f)) unlink($f);
putenv("KARTFOLIO_DB=$out");
require __DIR__ . '/../private/includes/db.php';
require __DIR__ . '/../private/includes/gp_logic.php';

mt_srand(42);
$names = ['Ada', 'Bjørn', 'Cleo', 'Dag', 'Elin', 'Finn', 'Greta', 'Hugo'];
$chars = ['Birdo', 'Toadette', 'Peachette', 'Peach', 'Yoshi', 'Dry Bones', 'Mario', 'Luigi'];
$ins = $pdo->prepare("INSERT INTO racers (name, nickname, in_mikkoliiga) VALUES (?, ?, ?)");
foreach ($names as $i => $n) $ins->execute([$n, $i % 3 === 0 ? strtoupper(substr($n, 0, 3)) : null, $i < 4 ? 1 : 0]);
$racerIds = $pdo->query("SELECT id FROM racers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$pdo->exec("DELETE FROM season_meta");
$seasons = [
    ['s01', 'archived', 'average_attendance', 'Fixture One',   date('Y-m-d', strtotime('-200 days')), date('Y-m-d', strtotime('-101 days'))],
    ['s02', 'active',   'territory',          'Fixture Two',   date('Y-m-d', strtotime('-100 days')), date('Y-m-d', strtotime('+60 days'))],
    ['s03', 'upcoming', 'positional_points',  'Fixture Three', date('Y-m-d', strtotime('+61 days')),  date('Y-m-d', strtotime('+160 days'))],
];
$sm = $pdo->prepare("INSERT INTO season_meta (season_id, status, scoring_system, season_name, start_date, end_date, attendance_weight, drop_rate, weekly_bonus_cap, min_races_threshold, best_n_count, tt_decay_gps, champion_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($seasons as [$id, $st, $sys, $nm, $a, $b]) $sm->execute([$id, $st, $sys, $nm, $a, $b, $sys === 'average_attendance' ? 1.0 : 0.0, $sys === 'average_attendance' ? 10 : 0, 2, 3, 15, 4, $st === 'archived' ? 'Ada' : null]);

$cups = getMKAllCups();
$res = $pdo->prepare("INSERT INTO results (gpid, racer_id, gp_points, rank, character_used, is_lol, race_date, cup_name, is_monster) VALUES (?,?,?,?,?,?,?,?,?)");
$gp = function (string $season, int $n, string $date, string $cup) use ($res, $racerIds, $chars) {
    $field = mt_rand(4, 8);
    $ids = $racerIds; shuffle($ids); $ids = array_slice($ids, 0, $field);
    $scores = []; foreach ($ids as $rid) $scores[$rid] = mt_rand(18, 60);
    if (mt_rand(1, 6) === 1) $scores[$ids[0]] = 60;
    arsort($scores);
    // distinct finishing places on a 12-kart grid (CPUs fill the gaps)
    $grid = range(1, 12); shuffle($grid); $places = array_slice($grid, 0, $field); sort($places);
    foreach (array_keys($scores) as $i => $rid) {
        $pts = $scores[$rid]; $rank = $places[$i];
        $res->execute([sprintf('%sgp%02d', $season, $n), $rid, $pts, $rank, $chars[($rid - 1) % count($chars)], mt_rand(1, 9) === 1 ? 1 : 0, $date, $cup, ($i === 0 && mt_rand(1, 4) === 1) ? 1 : 0]);
    }
};
$d = strtotime('-190 days'); for ($n = 1; $n <= 16; $n++) { $gp('s01', $n, date('Y-m-d', $d), $cups[($n - 1) % 24]); if ($n % 2 === 0) $d += 7 * 86400; }
$d = strtotime('-95 days');  for ($n = 1; $n <= 26; $n++) { $gp('s02', $n, date('Y-m-d', $d), $cups[($n * 5) % 24]); if ($n % 2 === 0) $d += 6 * 86400; }
$pdo->exec("UPDATE racers SET is_retired = 1 WHERE id = " . (int)end($racerIds));
echo "fixture: $out · racers " . count($racerIds) . " · results " . $pdo->query("SELECT COUNT(*) FROM results")->fetchColumn() . " · seasons " . count($seasons) . "\n";
