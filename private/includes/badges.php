<?php
/**
 * Badge Logic - Achievements & Playstyle Analysis
 * Path: /cdnmk/private/includes/badges.php
 */
require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/mk_data.php';
require_once __DIR__ . '/stickers.php';
require_once __DIR__ . '/worldcup_tournament.php';
require_once __DIR__ . '/quests.php';
require_once __DIR__ . '/snl_tournament.php';
require_once __DIR__ . '/badge_catalog.php';

/**
 * Career-wide badge inputs — season-independent, so built ONCE per request.
 * badgeSeasonContext() used to run all of this again for every season it was
 * asked about: a racer profile (6 seasons) paid ~60 identical queries and
 * 6 Elo signature checks for data that cannot differ between seasons.
 * Returns the career half of the context; badgeSeasonContext() merges it in.
 */
function badgeCareerContext($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;

    // — Career-wide maps (season-independent), batched one query each —
    $careerCups = [];          // racer_id => [cup_name, ...]
    foreach ($pdo->query("SELECT DISTINCT racer_id, cup_name FROM results")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['cup_name'] !== null) $careerCups[(int)$r['racer_id']][] = $r['cup_name'];
    }
    $careerPerfectCups = [];   // racer_id => [cup_name with a 60, ...]
    foreach ($pdo->query("SELECT DISTINCT racer_id, cup_name FROM results WHERE gp_points = " . MK_MAX_GP_POINTS)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['cup_name'] !== null) $careerPerfectCups[(int)$r['racer_id']][] = $r['cup_name'];
    }
    $careerChars = [];         // racer_id => [character_used, ...]
    foreach ($pdo->query("SELECT DISTINCT racer_id, character_used FROM results")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['character_used'] !== null) $careerChars[(int)$r['racer_id']][] = $r['character_used'];
    }
    $prevSeasonCount = [];     // racer_id => count of s00 results
    foreach ($pdo->query("SELECT racer_id, COUNT(*) AS c FROM results WHERE gpid LIKE 's00%' GROUP BY racer_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prevSeasonCount[(int)$r['racer_id']] = (int)$r['c'];
    }
    $seasonsPlayed = [];       // racer_id => distinct season count (career)
    foreach ($pdo->query("SELECT racer_id, COUNT(DISTINCT SUBSTR(gpid, 1, INSTR(gpid, 'g') - 1)) AS c FROM results WHERE gpid LIKE 's%' GROUP BY racer_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $seasonsPlayed[(int)$r['racer_id']] = (int)$r['c'];
    }
    $racerNames = $pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_KEY_PAIR);

    // — Sticker holdings (career; album persists across seasons) —
    //   One pass over racer_stickers + one over racer_packs, aggregated per
    //   racer. stickerByKey() maps each owned key to its set + rarity.
    //   $stickerSetTotals lets us tell when a set is fully collected.
    $byKey = stickerByKey($pdo);                 // key => catalog entry
    $stickerSetTotals = [];                      // set => total cards in that set
    $stickerGrandTotal = 0;
    foreach ($byKey as $s) { $stickerSetTotals[$s['set']] = ($stickerSetTotals[$s['set']] ?? 0) + 1; $stickerGrandTotal++; }

    $stickerHoldings = [];  // racer_id => ['distinct','total','foil','maxDup','kart','sets'=>[set=>owned]]
    try {
        foreach ($pdo->query("SELECT racer_id, sticker_key, count FROM racer_stickers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['sticker_key'];
            if (!isset($byKey[$key])) continue;  // ignore keys not in the live catalog
            $rid = (int)$r['racer_id']; $cnt = max(1, (int)$r['count']); $e = $byKey[$key];
            if (!isset($stickerHoldings[$rid])) {
                $stickerHoldings[$rid] = ['distinct' => 0, 'total' => 0, 'foil' => 0, 'maxDup' => 0, 'kart' => false, 'sets' => []];
            }
            $h =& $stickerHoldings[$rid];
            $h['distinct']++;
            $h['total'] += $cnt;
            if ($e['rarity'] === 'foil') $h['foil']++;
            if ($cnt > $h['maxDup']) $h['maxDup'] = $cnt;
            if ($key === 'lore_kartificial') $h['kart'] = true;
            $h['sets'][$e['set']] = ($h['sets'][$e['set']] ?? 0) + 1;
            unset($h);
        }
    } catch (PDOException $e) { /* sticker tables absent — no sticker badges */ }

    $packsOpened = [];  // racer_id => packs opened all-time
    try {
        foreach ($pdo->query("SELECT racer_id, COUNT(*) AS c FROM racer_packs WHERE opened_at IS NOT NULL GROUP BY racer_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $packsOpened[(int)$r['racer_id']] = (int)$r['c'];
        }
    } catch (PDOException $e) { /* table absent */ }

    // — Tournament wins (career), by format — for Tournament Champion / Board
    //   Breaker / On Top of the World. Completed tournaments only. —
    $tourneyWins = [];  // racer_id => ['total'=>n, 'formats'=>[format=>true]]
    try {
        foreach ($pdo->query("SELECT winner_id, format FROM tournaments WHERE status = 'completed' AND winner_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rid = (int)$r['winner_id']; if (!$rid) continue;
            if (!isset($tourneyWins[$rid])) $tourneyWins[$rid] = ['total' => 0, 'formats' => []];
            $tourneyWins[$rid]['total']++;
            $tourneyWins[$rid]['formats'][$r['format']] = true;
        }
    } catch (PDOException $e) { /* table absent */ }

    // — Pick'em Oracle: top predictor of each completed World Cup, matched to a
    //   racer by name (case-insensitive). Only completed WCs are scored. —
    $pickemOracleIds = [];  // racer_id => true
    try {
        $nameToId = [];
        foreach ($racerNames as $rid => $rn) $nameToId[mb_strtolower(trim((string)$rn))] = (int)$rid;
        $wcStmt = $pdo->query("SELECT id FROM tournaments WHERE format = 'world_cup' AND status = 'completed'");
        foreach ($wcStmt->fetchAll(PDO::FETCH_COLUMN) as $wcId) {
            $board = worldCupPickemBoard($pdo, (int)$wcId);
            if (empty($board) || $board[0]['points'] <= 0) continue;
            $topPts = $board[0]['points'];
            foreach ($board as $row) {
                if ($row['points'] < $topPts) break;   // board is sorted desc; tied leaders all win
                $rid = $nameToId[mb_strtolower(trim((string)$row['name']))] ?? null;
                if ($rid) $pickemOracleIds[$rid] = true;
            }
        }
    } catch (PDOException $e) { /* WC/predictions tables absent */ }

    // — Racers who have crossed 2000 Elo (Ascended). Ratings are memoised. —
    $elo2000 = [];  // racer_id => true
    if (!function_exists('calculateAllELORatings')) require_once __DIR__ . '/elo_engine.php';
    $eloData = calculateAllELORatings($pdo);
    $ratings = $eloData['ratings'] ?? [];
    if (!empty($ratings)) {
        $nameToIdElo = [];
        foreach ($racerNames as $rid => $rn) $nameToIdElo[(string)$rn] = (int)$rid;
        foreach ($ratings as $rn => $rating) {
            if ($rating >= 2000 && isset($nameToIdElo[$rn])) $elo2000[$nameToIdElo[$rn]] = true;
        }
    }

    // ── Dynasty: longest run of consecutive archived-season titles, by champion name ──
    $dynastyRun = []; $run = 0; $prevChamp = null;
    foreach ($pdo->query("SELECT season_id, champion_name FROM season_meta WHERE status = 'archived' AND champion_name IS NOT NULL AND champion_name != '' ORDER BY season_id ASC")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $n = trim((string)$c['champion_name']);
        $run = ($n === $prevChamp) ? $run + 1 : 1;
        $prevChamp = $n;
        $dynastyRun[$n] = max($dynastyRun[$n] ?? 0, $run);
    }

    // ── Career placements across ARCHIVED seasons, in season order —
    //    On the Up / From the Back. seasonPlacements() is registry-sorted and
    //    per-request cached, so this is one season-cache read per archived season. ──
    $archivedSeasons  = $pdo->query("SELECT season_id FROM season_meta WHERE status = 'archived' ORDER BY season_id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $careerPlacements = archivedSeasonPlacements($pdo);   // snapshot table: one query

    // ── Constructor: members of the winning team in an archived teams season ──
    $constructorWinners = [];
    try {
        foreach ($pdo->query("SELECT DISTINCT season_id FROM teams")->fetchAll(PDO::FETCH_COLUMN) as $ts) {
            if (!in_array($ts, $archivedSeasons, true)) continue;   // live seasons are provisional
            $st = getTeamStandings($pdo, $ts);
            if (empty($st) || ($st[0]['score'] ?? 0) <= 0) continue;
            foreach (array_keys($st[0]['members']) as $rid) $constructorWinners[(int)$rid] = true;
        }
    } catch (PDOException $e) { /* teams tables absent */ }

    // ── Fantasy Champion: top predictor of an archived season, mapped to a racer.
    //    Fantasy weeks are keyed by deadline date, not by GP, so a week belongs to
    //    the season whose race dates contain its deadline (7-day lead for the
    //    first week). Points come from fantasy_bets — the table /fantasy grades into. ──
    $fantasyChampions = [];
    try {
        $spans = [];   // season_id => [first race − 7d, last race]
        foreach ($pdo->query("SELECT SUBSTR(gpid, 1, INSTR(gpid, 'g') - 1) AS s, MIN(race_date) AS a, MAX(race_date) AS b FROM results WHERE gpid LIKE 's%' GROUP BY s")->fetchAll(PDO::FETCH_ASSOC) as $r)
            if (in_array($r['s'], $archivedSeasons, true)) $spans[$r['s']] = [date('Y-m-d', strtotime($r['a'] . ' -7 days')), substr((string)$r['b'], 0, 10)];
        $weekSeason = [];
        foreach ($pdo->query("SELECT week_key, deadline FROM fantasy_weeks WHERE scored = 1")->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $d = substr((string)$w['deadline'], 0, 10);
            foreach ($spans as $s => [$a, $b]) if ($d >= $a && $d <= $b) { $weekSeason[$w['week_key']] = $s; break; }
        }
        if ($weekSeason) {
            $best = [];
            $fq = $pdo->query("
                SELECT fb.week_key, fp.racer_id, SUM(fb.points_earned) AS pts
                FROM fantasy_bets fb JOIN fantasy_predictors fp ON fp.id = fb.predictor_id
                WHERE fp.racer_id IS NOT NULL AND fb.points_earned IS NOT NULL
                GROUP BY fb.week_key, fp.racer_id");
            $tot = [];   // season => racer => pts
            foreach ($fq->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $s = $weekSeason[$r['week_key']] ?? null;
                if ($s !== null) $tot[$s][(int)$r['racer_id']] = ($tot[$s][(int)$r['racer_id']] ?? 0) + (float)$r['pts'];
            }
            foreach ($tot as $s => $byRacer) {
                $max = max($byRacer);
                if ($max > 0) foreach ($byRacer as $rid => $pts) if ($pts == $max) $fantasyChampions[$rid] = true;
            }
        }
    } catch (PDOException $e) { /* fantasy tables absent */ }

    // ── Bracket Buster: won a completed tournament (4+ entrants) as the lowest seed ──
    $bracketBusters = [];
    try {
        $tq = $pdo->query("
            SELECT t.winner_id,
                   (SELECT seed FROM tournament_participants WHERE tournament_id = t.id AND racer_id = t.winner_id) AS wseed,
                   (SELECT MAX(seed) FROM tournament_participants WHERE tournament_id = t.id) AS maxseed,
                   (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) AS n
            FROM tournaments t WHERE t.status = 'completed' AND t.winner_id IS NOT NULL");
        foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['wseed'] !== null && (int)$r['n'] >= 4 && (int)$r['wseed'] === (int)$r['maxseed']) $bracketBusters[(int)$r['winner_id']] = true;
        }
    } catch (PDOException $e) { /* tournament tables absent */ }

    // ── Snake Bitten: hit a snake in any completed Snakes & Ladders tournament ──
    $snakeBitten = [];
    try {
        foreach ($pdo->query("SELECT id FROM tournaments WHERE format = 'snakes_ladders' AND status = 'completed'")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            foreach ((snlReplay($pdo, (int)$tid)['snakeHits'] ?? []) as $rid => $n) if ($n > 0) $snakeBitten[(int)$rid] = true;
        }
    } catch (Throwable $e) { /* no S&L data */ }

    return $cache = compact(
        'careerCups', 'careerPerfectCups', 'careerChars', 'prevSeasonCount', 'seasonsPlayed', 'racerNames', 'stickerHoldings', 'stickerSetTotals', 'stickerGrandTotal', 'packsOpened', 'tourneyWins', 'pickemOracleIds', 'elo2000', 'eloData', 'dynastyRun', 'careerPlacements', 'constructorWinners', 'fantasyChampions', 'bracketBusters', 'snakeBitten'
    );
}

/**
 * Season- and career-wide data shared by every racer's badge computation,
 * built once per (season) per request. Previously these were re-run as
 * separate queries inside getRacerBadges for EACH racer — on a leaderboard
 * with N racers that meant 6+ identical season queries × N, plus an O(N²)
 * Black Box pass (every racer's BB score recomputed inside every racer's
 * badge call). Memoised here so each underlying query runs once.
 */
function badgeSeasonContext($pdo, $season_id) {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];

    $like = $season_id . '%';

    // Career-wide inputs (stickers, tournaments, Elo, dynasties, archived
    // placements, …) come from the once-per-request career context.
    $career     = badgeCareerContext($pdo);
    $racerNames = $career['racerNames'];
    $eloData    = $career['eloData'];


    // — Highest single-racer attendance this season (badge 14, Longevity) —
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM results WHERE gpid LIKE ? GROUP BY racer_id ORDER BY c DESC LIMIT 1");
    $st->execute([$like]);
    $highestAttendance = (int)$st->fetchColumn();

    // — First GP of the season + who raced in it (badge 33, Early Bird) —
    $st = $pdo->prepare("SELECT gpid FROM results WHERE gpid LIKE ? ORDER BY race_date ASC, id ASC LIMIT 1");
    $st->execute([$like]);
    $firstGpId = $st->fetchColumn() ?: null;
    $firstGpRacers = [];
    if ($firstGpId) {
        $st = $pdo->prepare("SELECT DISTINCT racer_id FROM results WHERE gpid = ?");
        $st->execute([$firstGpId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) $firstGpRacers[(int)$rid] = true;
    }

    // — Current season leader by total points, min 3 GPs (badge 34, Giant Killer) —
    $st = $pdo->prepare("
        SELECT racer_id FROM (
            SELECT racer_id, COUNT(*) as gp_count FROM results WHERE gpid LIKE ? GROUP BY racer_id HAVING gp_count >= 3
        ) qualified
        ORDER BY (SELECT SUM(gp_points) FROM results WHERE racer_id = qualified.racer_id AND gpid LIKE ?) DESC
        LIMIT 1
    ");
    $st->execute([$like, $like]);
    $leaderId = (int)$st->fetchColumn();

    // Racers who finished ahead of the leader in any GP this season.
    $beatLeader = [];
    if ($leaderId) {
        $st = $pdo->prepare("
            SELECT DISTINCT a.racer_id FROM results a
            JOIN results b ON a.gpid = b.gpid
            WHERE b.racer_id = ? AND a.gpid LIKE ? AND a.rank < b.rank
        ");
        $st->execute([$leaderId, $like]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) $beatLeader[(int)$rid] = true;
    }

    // — Season scoring system (gates MONSTER HUNT badges) —
    $st = $pdo->prepare("SELECT scoring_system FROM season_meta WHERE season_id = ?");
    $st->execute([$season_id]);
    $scoringSystem = $st->fetchColumn() ?: null;

    // — Black Box leader this season (badge 43). One pass over all racers,
    //   replacing the per-racer O(N²) recompute. —
    $st = $pdo->prepare("SELECT DISTINCT racer_id FROM results WHERE gpid LIKE ? AND gpid LIKE 's%'");
    $st->execute([$like]);
    $bbScores = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $score = calculateBlackBoxScore($pdo, (int)$rid, $season_id, []);
        if ($score > 0) $bbScores[(int)$rid] = $score;
    }
    $bbLeaderId = null;
    if (!empty($bbScores)) { arsort($bbScores); $bbLeaderId = (int)array_key_first($bbScores); }

    // — Mikkoliiga leader for this season (Mikkoligan) — standings[0], score>0. —
    $mikkoLeaderId = null;
    $ms = getMikkoliigaStandings($pdo, $season_id);
    if (!empty($ms) && ($ms[0]['score'] ?? 0) > 0) $mikkoLeaderId = (int)$ms[0]['id'];

    // ── Territory (cup ownership) — one chronological pass over the season ──
    //   held      : racer => cups held at season end (canonical territorySeason)
    //   takeovers : racer => times they took a cup off a DIFFERENT holder
    //   fortress  : racer => cups they held unchanged all season through 3+
    //               challengers (someone else posting on it and failing)
    $territoryHeld = [];
    foreach (territorySeason($pdo, $season_id)['by_racer'] as $rid => $cups) $territoryHeld[(int)$rid] = count($cups);

    //   takeovers : racer => times they took a cup off a DIFFERENT holder
    //               (beaten, tied, or decayed) — from the engine's event log
    //   squats    : racer => takeovers by decay (the holder left it undefended)
    //   fortress  : racer => cups held unchanged all season through 3+ challengers
    $tSeason = territorySeason($pdo, $season_id);
    $territoryTakeovers = []; $territorySquats = [];
    foreach ($tSeason['events'] as $ev) {
        if ($ev['from'] === null || $ev['from'] === $ev['to']) continue;
        $territoryTakeovers[$ev['to']] = ($territoryTakeovers[$ev['to']] ?? 0) + 1;
        if ($ev['type'] === 'decay') $territorySquats[$ev['to']] = ($territorySquats[$ev['to']] ?? 0) + 1;
    }
    $territoryFortress = [];
    foreach ($tSeason['hold'] as $cup => $h) {
        if (empty($tSeason['changed'][$cup]) && ($tSeason['challengers'][$cup] ?? 0) >= 3) $territoryFortress[$h['racer_id']] = ($territoryFortress[$h['racer_id']] ?? 0) + 1;
    }

    // ── Kart Bingo: a full card ──
    $bingoFull = [];
    if ($scoringSystem === 'kart_bingo') { $rulesW = getSeasonRules($pdo, $season_id); foreach (array_keys(getSeasonResultsByRacer($pdo, $season_id)) as $rid) if (bingoProgress($pdo, (int)$rid, $season_id, (array)$rulesW)['full']) $bingoFull[(int)$rid] = true; }

    // ── Dead Heat: level on score with another qualifying racer this season ──
    $seasonRulesForTies = getSeasonRules($pdo, $season_id);
    $scoreGroups = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        if (!racerQualifies(count($rows), $seasonRulesForTies)) continue;
        $scoreGroups[number_format((float)calculateGPScore($pdo, (int)$rid, $season_id), 2, '.', '')][] = (int)$rid;
    }
    $deadHeat = [];
    foreach ($scoreGroups as $ids) if (count($ids) >= 2) foreach ($ids as $rid) $deadHeat[$rid] = true;

    // ── Ever-Present: GPs held this season (racer counts come from the cache) ──
    $stG = $pdo->prepare("SELECT COUNT(DISTINCT gpid) FROM results WHERE gpid LIKE ?");
    $stG->execute([$season_id . '%']);
    $seasonGpTotal = (int)$stG->fetchColumn();

    // ── Questmaster: both side quests completed. Reads racer_quests directly —
    //    getRacerQuests() would ASSIGN quests as a side effect for every racer on
    //    the board, so we evaluate the same check closures ourselves for racers
    //    who already have a draw. ──
    $questmaster = [];
    try {
        $stQ = $pdo->prepare("SELECT racer_id, quest_key FROM racer_quests WHERE season_id = ?");
        $stQ->execute([$season_id]);
        $assigned = [];
        foreach ($stQ->fetchAll(PDO::FETCH_ASSOC) as $q) $assigned[(int)$q['racer_id']][] = $q['quest_key'];
        $defs = function_exists('questByKey') ? questByKey() : [];
        $need = defined('QUESTS_PER_RACER') ? (int)QUESTS_PER_RACER : 2;
        foreach ($assigned as $rid => $keys) {
            if (count($keys) < $need || !function_exists('racerSeasonStats')) continue;
            $stats = racerSeasonStats($pdo, $rid, $season_id, $eloData); // Elo already computed above
            $all = true;
            foreach ($keys as $k) { if (!isset($defs[$k]) || !($defs[$k]['check'])($stats)) { $all = false; break; } }
            if ($all) $questmaster[$rid] = true;
        }
    } catch (PDOException $e) { /* quests table absent */ }

    return $cache[$season_id] = $career + compact(
        'highestAttendance', 'firstGpId', 'firstGpRacers', 'leaderId', 'beatLeader', 'scoringSystem', 'bbLeaderId', 'mikkoLeaderId', 'territoryHeld', 'territoryTakeovers', 'territoryFortress', 'territorySquats', 'bingoFull', 'deadHeat', 'seasonGpTotal', 'questmaster'
    );
}

/**
 * Sticker / collection badges (1–10). Reads only batched context — no queries.
 * Set Sweeper is tiered (1 / 3 / 5 completed sets → Bronze / Silver / Gold).
 */
function appendCollectionBadges(array &$badges, array $ctx, int $racer_id) {
    $h     = $ctx['stickerHoldings'][$racer_id] ?? null;
    $packs = (int)($ctx['packsOpened'][$racer_id] ?? 0);
    if (!$h && $packs <= 0) return; // never opened a pack, owns nothing

    $distinct  = (int)($h['distinct'] ?? 0);
    $total     = (int)($h['total'] ?? 0);
    $foil      = (int)($h['foil'] ?? 0);
    $maxDup    = (int)($h['maxDup'] ?? 0);
    $grand     = max(1, (int)($ctx['stickerGrandTotal'] ?? 168));
    $setTotals = $ctx['stickerSetTotals'] ?? [];
    $owned     = $h['sets'] ?? [];
    $pct       = $distinct / $grand;

    $setsComplete = 0;
    foreach ($setTotals as $set => $tot) {
        if (($owned[$set] ?? 0) >= $tot) $setsComplete++;
    }

    // 1 · Wax Cracker — opened your first pack.
    if ($packs >= 1)  $badges[] = badgeDef('wax_cracker');
    // 8 · Pack Rat — opened 25+ packs.
    if ($packs >= 25) $badges[] = badgeDef('pack_rat');

    // 3 · Full Album — own every card. 4 · Halfway Hero otherwise at 50%+.
    if ($distinct >= $grand)        $badges[] = badgeDef('full_album');
    elseif ($pct >= 0.5)            $badges[] = badgeDef('halfway_hero');

    // 2 · Set Sweeper (tiered).
    if ($setsComplete >= 5)     $badges[] = badgeDef('set_sweeper_gold');
    elseif ($setsComplete >= 3) $badges[] = badgeDef('set_sweeper_silver');
    elseif ($setsComplete >= 1) $badges[] = badgeDef('set_sweeper_bronze');

    // 5 · Foil Hunter — 5+ foils.
    if ($foil >= 5)         $badges[] = badgeDef('foil_hunter');
    // 6 · Got the Bot — the Kartificial chase foil.
    if (!empty($h['kart'])) $badges[] = badgeDef('got_the_bot');
    // 7 · Stuck With Dupes — 5+ of one card.
    if ($maxDup >= 5)       $badges[] = badgeDef('stuck_with_dupes');
    // 9 · Lore Keeper — completed the lore set.
    if (($setTotals['lore'] ?? 0) > 0 && ($owned['lore'] ?? 0) >= $setTotals['lore'])
        $badges[] = badgeDef('lore_keeper');
    // 10 · Whale — 250+ total copies.
    if ($total >= 250)      $badges[] = badgeDef('whale');
}

/** Competition badges (11–16) — tournament/Mikkoliiga/Elo honours, from context. */
function appendCompetitionBadges(array &$badges, array $ctx, int $racer_id) {
    $tw = $ctx['tourneyWins'][$racer_id] ?? null;
    if ($tw) {
        // 12 · Tournament Champion — any format.
        if (($tw['total'] ?? 0) >= 1)
            $badges[] = badgeDef('tournament_champion');
        // 11 · Board Breaker — Snakes & Ladders.
        if (!empty($tw['formats']['snakes_ladders']))
            $badges[] = badgeDef('board_breaker');
        // 13 · On Top of the World — World Cup.
        if (!empty($tw['formats']['world_cup']))
            $badges[] = badgeDef('on_top_of_the_world');
    }
    // 14 · Pick'em Oracle.
    if (!empty($ctx['pickemOracleIds'][$racer_id]))
        $badges[] = badgeDef('pickem_oracle');
    // 15 · Mikkoligan — leads this season's Mikkoliiga.
    if (($ctx['mikkoLeaderId'] ?? null) === $racer_id)
        $badges[] = badgeDef('mikkoligan');
    // 16 · Ascended — crossed 2000 Elo.
    if (!empty($ctx['elo2000'][$racer_id]))
        $badges[] = badgeDef('ascended');
}

/**
 * Territory, tie-break, attendance and career-arc badges (all from context;
 * the only per-racer read is the season cache slice).
 */
function appendSeasonEventBadges(array &$badges, array $ctx, $pdo, int $racer_id, string $season_id) {
    // 🏘️ Landlord — 5+ cups held at once.
    if (($ctx['territoryHeld'][$racer_id] ?? 0) >= 5)
        $badges[] = badgeDef('landlord');
    // 🗝️ Usurper — took a cup off someone else 5+ times.
    if (($ctx['territoryTakeovers'][$racer_id] ?? 0) >= 5)
        $badges[] = badgeDef('usurper');
    // 🏯 Fortress — a cup defended all season against 3+ challengers.
    if (($ctx['territoryFortress'][$racer_id] ?? 0) >= 1)
        $badges[] = badgeDef('fortress');
    // 🏕️ Squatter's Rights — took a cup whose holder left it undefended.
    if (($ctx['territorySquats'][$racer_id] ?? 0) >= 1)
        $badges[] = badgeDef('squatter');
    // 🅱️ Bingo! — a full Kart Bingo card.
    if (!empty($ctx['bingoFull'][$racer_id])) $badges[] = badgeDef('bingo');
    // 🪙 Dead Heat — level on points with someone; the tie-break decided it.
    if (!empty($ctx['deadHeat'][$racer_id]))
        $badges[] = badgeDef('dead_heat');
    // 📅 Ever-Present — every GP of the season (3+ held).
    $mine = count(getRacerSeasonRows($pdo, $racer_id, $season_id));
    if (($ctx['seasonGpTotal'] ?? 0) >= 3 && $mine === (int)$ctx['seasonGpTotal'])
        $badges[] = badgeDef('ever_present');
    // 🏵️ Dynasty — 3+ consecutive season titles.
    $name = trim((string)($ctx['racerNames'][$racer_id] ?? ''));
    if ($name !== '' && ($ctx['dynastyRun'][$name] ?? 0) >= 3)
        $badges[] = badgeDef('dynasty');
    // 🌈 Full Roster — 10+ distinct characters, career.
    $chars = array_unique(array_map('normalizeCharacterName', $ctx['careerChars'][$racer_id] ?? []));
    if (count($chars) >= 10)
        $badges[] = badgeDef('full_roster');
    // 🧭 Questmaster — both side quests done this season.
    if (!empty($ctx['questmaster'][$racer_id]))
        $badges[] = badgeDef('questmaster');

    // ── Career arc (archived seasons, in order) ──
    $arc = $ctx['careerPlacements'][$racer_id] ?? [];
    // 🪜 On the Up — placement improved three seasons running.
    $run = 1; $up = false;
    for ($i = 1; $i < count($arc); $i++) {
        $run = ($arc[$i][1] < $arc[$i - 1][1]) ? $run + 1 : 1;
        if ($run >= 3) { $up = true; break; }
    }
    if ($up) $badges[] = badgeDef('on_the_up');
    // 🏹 From the Back — won a season after a bottom-half finish in an earlier one.
    $wasBottom = false; $fromBack = false;
    foreach ($arc as [$s, $p, $field]) {
        if ($wasBottom && $p === 1) { $fromBack = true; break; }
        if ($field >= 4 && $p > $field / 2) $wasBottom = true;
    }
    if ($fromBack) $badges[] = badgeDef('from_the_back');

    // 🟣 Purple Patch — last-8 form 10+ points above the season average (12+ GPs).
    $rows = getRacerSeasonRows($pdo, $racer_id, $season_id);
    if (count($rows) >= 12) {
        usort($rows, fn($a, $b) => strcmp((string)$a['race_date'], (string)$b['race_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $pts = array_map(fn($r) => (int)$r['gp_points'], $rows);
        $avg = array_sum($pts) / count($pts);
        $last = array_slice($pts, -8);
        if (array_sum($last) / count($last) - $avg >= 10)
            $badges[] = badgeDef('purple_patch');
    }

    // ── Honours from other systems ──
    if (!empty($ctx['constructorWinners'][$racer_id]))
        $badges[] = badgeDef('constructor');
    if (!empty($ctx['fantasyChampions'][$racer_id]))
        $badges[] = badgeDef('fantasy_champion');
    if (!empty($ctx['bracketBusters'][$racer_id]))
        $badges[] = badgeDef('bracket_buster');
    if (!empty($ctx['snakeBitten'][$racer_id]))
        $badges[] = badgeDef('snake_bitten');
}

function getRacerBadges($pdo, $racer_id, $season_id) {
    $badges = [];
    $racer_id = (int)$racer_id;
    $ctx = badgeSeasonContext($pdo, $season_id);

    // ── Career badges (collection + competition) ────────────────────────────
    //   These are season-independent achievements (the album persists, trophies
    //   are forever), so they're computed BEFORE the "needs 3 races" gate and
    //   survive it — a collector who skipped this season still keeps them.
    appendCollectionBadges($badges, $ctx, $racer_id);
    appendCompetitionBadges($badges, $ctx, $racer_id);
    appendSeasonEventBadges($badges, $ctx, $pdo, $racer_id, $season_id);

    // 1. Season results, sorted by date (streak logic needs chronological order).
    //    Served from the shared per-request season cache (gp_points ASC), so we
    //    re-sort the slice to race_date ASC, id ASC — matching the old query.
    $results = getRacerSeasonRows($pdo, $racer_id, $season_id);
    usort($results, function ($a, $b) {
        if ($a['race_date'] !== $b['race_date']) return strcmp($a['race_date'], $b['race_date']);
        return (int)$a['id'] <=> (int)$b['id'];
    });

    $totalRaces = count($results);
    if ($totalRaces < 3) return $badges; // Minimum races for racing badges; career badges already added

    // Variables for calculation
    $lols = 0;
    $podiums = 0;
    $wins = 0;
    $seconds = 0;
    $fourths = 0;
    $perfect_games = 0;
    $total_gp_points = 0;

    $chars = [];
    $ranks = [];
    $karts = [];
    $gp_scores_by_date = [];
    $won_cups = []; // Track which cups were won
    $raced_cups = []; // Track all cups raced (for Cup Collector)
    $perfect_cups = []; // Track which cups had a perfect 60 (for Perfectionist)

    // Character Groups
    $groups    = getCharacterGroups();
    $babies    = $groups['babies'];
    $heavies   = $groups['heavies'];
    $spooky    = $groups['spooky'];
    $og_stars  = $groups['og_stars'];
    $royals    = $groups['royals'];
    $fungi     = $groups['fungi'];
    $humans    = $groups['humans'];
    $furry     = $groups['furry'];
    $koopa_clan = $groups['koopa_clan'];
    $reptiles  = $groups['reptiles'];
    $baby_count = 0;
    $heavy_count = 0;
    $standard_kart_count = 0;
    $spooky_count = 0;
    $og_stars_count = 0;
    $bike_count = 0;
    $sevenths = 0;
    $royal_count = 0;
    $link_count = 0;
    $fungi_count = 0;
    $human_count = 0;
    $furry_count = 0;
    $koopa_count = 0;
    $reptile_count = 0;

    // Streak Logic
    $current_win_streak = 0;
    $max_win_streak = 0;
    $winning_chars = [];
    $prev_rank = null;

    foreach ($results as $r) {
        $total_gp_points += $r['gp_points'];
        $gp_scores_by_date[] = $r['gp_points'];

        // Normalise colour variants so group checks work regardless of colour:
        // "Yoshi (Orange)" → "Yoshi", "Birdo (Blue)" → "Birdo"
        $charNorm = normalizeCharacterName($r['character_used']);

        // Basic Counts
        if ($r['is_lol']) $lols++;
        if ($r['rank'] <= 3) $podiums++;
        if ($r['rank'] == 1) {
            $wins++;
            $current_win_streak++;
            $winning_chars[] = $r['character_used'];
            $won_cups[] = $r['cup_name']; // Log the cup win
        } else {
            $current_win_streak = 0;
        }
        if ($current_win_streak > $max_win_streak) $max_win_streak = $current_win_streak;

        if ($r['rank'] == 2) $seconds++;
        if ($r['rank'] == 4) $fourths++;
        if ($r['rank'] == 7) $sevenths++;
        if ($r['gp_points'] == MK_MAX_GP_POINTS) $perfect_games++;

        // Arrays for Stats (keep original name for display / uniqueness)
        $chars[] = $r['character_used'];
        $ranks[] = $r['rank'];
        $karts[] = $r['kart_setup'];
        $raced_cups[] = $r['cup_name'];
        if ($r['gp_points'] == MK_MAX_GP_POINTS) $perfect_cups[] = $r['cup_name'];

        // Archetype Checks (use normalised name so colour variants count correctly)
        if (in_array($charNorm, $babies)) $baby_count++;
        if (in_array($charNorm, $heavies)) $heavy_count++;
        if (in_array($charNorm, $spooky)) $spooky_count++;
        if (in_array($charNorm, $og_stars)) $og_stars_count++;
        if (in_array($charNorm, $royals)) $royal_count++;
        if ($charNorm === 'Link') $link_count++;
        if (in_array($charNorm, $fungi)) $fungi_count++;
        if (in_array($charNorm, $humans)) $human_count++;
        if (in_array($charNorm, $furry)) $furry_count++;
        if (in_array($charNorm, $koopa_clan)) $koopa_count++;
        if (in_array($charNorm, $reptiles)) $reptile_count++;
        $kart = (string)($r['kart_setup'] ?? '');   // NULL on rows logged without a kart
        if (stripos($kart, 'Standard') !== false) $standard_kart_count++;
        if (stripos($kart, 'Bike') !== false) $bike_count++;

        $prev_rank = $r['rank'];
    }
    
    $uniqueChars = count(array_unique($chars));
    $avgRank = array_sum($ranks) / $totalRaces;
    $seasonAvgPoints = $total_gp_points / $totalRaces;
    $won_cups_unique = array_unique($won_cups);
    $raced_cups_unique = array_unique($raced_cups);
    $perfect_cups_unique = array_unique($perfect_cups);
    
    // --- EXISTING BADGES ---

    if ($lols >= 3) {
        $badges[] = badgeDef('slippery');
    }

    if ($uniqueChars === 1 && $totalRaces >= 5) {
        $badges[] = badgeDef('one_trick');
    }

    if ($uniqueChars >= 5) {
        $badges[] = badgeDef('identity_crisis');
    }

    if (($podiums / $totalRaces) >= 0.60) {
        $badges[] = badgeDef('podium_royalty');
    }

    if ($avgRank >= 4 && $avgRank <= 7) {
        $badges[] = badgeDef('the_wall');
    }

    if ($perfect_games >= 1) {
        $badges[] = badgeDef('max_output');
    }

    if (($seconds / $totalRaces) >= 0.25) {
        $badges[] = badgeDef('bridesmaid');
    }

    if (($fourths / $totalRaces) >= 0.25) {
        $badges[] = badgeDef('fourth_wall');
    }

    if ($max_win_streak >= 2) {
        $badges[] = badgeDef('hot_hand');
    }

    if (($baby_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('baby_driver');
    }

    if (($heavy_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('kaiju');
    }

    if ($avgRank >= 10) {
        $badges[] = badgeDef('the_anchor');
    }

    if (count(array_unique($winning_chars)) >= 3) {
        $badges[] = badgeDef('jack_of_trades');
    }

    if (($standard_kart_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('purist');
    }

    $variance = 0;
    foreach ($ranks as $r) {
        $variance += pow(($r - $avgRank), 2);
    }
    $stdDev = sqrt($variance / $totalRaces);
    
    if ($stdDev > 3.5) {
        $badges[] = badgeDef('chaos');
    }

    // --- NEW BADGES REQUESTED ---

    // 11. 📈 Vertical Limit
    $lastScore = end($gp_scores_by_date);
    if ($totalRaces >= 4 && $lastScore >= ($seasonAvgPoints + 15)) {
        $badges[] = badgeDef('vertical_limit');
    }

    // 12. 🎰 High Roller
    $pointVariance = 0;
    foreach ($gp_scores_by_date as $pts) {
        $pointVariance += pow(($pts - $seasonAvgPoints), 2);
    }
    $pointStdDev = sqrt($pointVariance / $totalRaces);
    if ($pointStdDev > 15) {
        $badges[] = badgeDef('high_roller');
    }

    // 13. 💤 Sandbagger
    if ($totalRaces >= 6) {
        $firstHalf = array_slice($gp_scores_by_date, 0, floor($totalRaces / 2));
        $secondHalf = array_slice($gp_scores_by_date, -floor($totalRaces / 2));
        if ((array_sum($secondHalf) / count($secondHalf)) > (array_sum($firstHalf) / count($firstHalf)) + 12) {
            $badges[] = badgeDef('sandbagger');
        }
    }

    // 14. 🗓️ Longevity
    $highestAttendance = $ctx['highestAttendance'];
    if ($totalRaces >= $highestAttendance && $highestAttendance > 0) {
        $badges[] = badgeDef('longevity');
    }

    // 15. 🏛️ Base 12 (Won all Base Game Cups)
    if (empty(array_diff(MK_BASE_CUPS, $won_cups_unique))) {
        $badges[] = badgeDef('base_12');
    }

    // 16. 🚀 Booster's Dozen (Won all DLC Cups)
    if (empty(array_diff(MK_BOOSTER_CUPS, $won_cups_unique))) {
        $badges[] = badgeDef('boosters_dozen');
    }

    // --- NEW BADGES (2025 EXPANSION) ---

    // 17. 🎯 Laser Focus
    $withinFiveCount = 0;
    foreach ($gp_scores_by_date as $pts) {
        if (abs($pts - $seasonAvgPoints) <= 5) {
            $withinFiveCount++;
        }
    }
    if ($pointStdDev < 8 && ($withinFiveCount / $totalRaces) >= 0.70) {
        $badges[] = badgeDef('laser_focus');
    }

    // 18. 🏔️ Everest
    $peakDiff = max($gp_scores_by_date) - $seasonAvgPoints;
    if ($peakDiff >= 20) {
        $badges[] = badgeDef('everest');
    }

    // 19. 🎪 Comeback Kid
    for ($i = 1; $i < count($results); $i++) {
        if ($results[$i]['rank'] == 1 && $results[$i-1]['rank'] >= 10) {
            $badges[] = badgeDef('comeback_kid');
            break;
        }
    }

    // 20. 👻 Ghost Rider
    if (($spooky_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('ghost_rider');
    }

    // 21. 🌟 Star Power
    if (($og_stars_count / $totalRaces) >= 0.60) {
        $badges[] = badgeDef('star_power');
    }

    // 22. 🏍️ Bike Brigade
    if (($bike_count / $totalRaces) >= 0.60) {
        $badges[] = badgeDef('bike_brigade');
    }

    // 24. 🔄 Groundhog Day
    $maxIdenticalStreak = 1;
    $currentIdenticalStreak = 1;
    for ($i = 1; $i < count($ranks); $i++) {
        if ($ranks[$i] === $ranks[$i-1]) {
            $currentIdenticalStreak++;
            if ($currentIdenticalStreak > $maxIdenticalStreak) {
                $maxIdenticalStreak = $currentIdenticalStreak;
            }
        } else {
            $currentIdenticalStreak = 1;
        }
    }
    if ($maxIdenticalStreak >= 4) {
        $badges[] = badgeDef('groundhog');
    }

    // 25. 🎲 Lucky 7
    if ($sevenths >= 3) {
        $badges[] = badgeDef('lucky_7');
    }

    // 26. 🦅 Perfect Landing
    if ($totalRaces >= 5 && ($podiums / $totalRaces) == 1.0) {
        $badges[] = badgeDef('perfect_landing');
    }

    // --- NEW BADGES ---

    // 27. 🏅 Cup Collector — Raced in all 24 cups (any season, career)
    $careerCups = $ctx['careerCups'][$racer_id] ?? [];
    if (empty(array_diff(getMKAllCups(), $careerCups))) {
        $badges[] = badgeDef('cup_collector');
    }

    // 28. 💎 Perfectionist — Perfect 60 in 3+ different cups (any season)
    $careerPerfectCups = $ctx['careerPerfectCups'][$racer_id] ?? [];
    if (count($careerPerfectCups) >= 3) {
        $badges[] = badgeDef('perfectionist');
    }

    // 30. 🐢 The Tortoise — Avg finish rank 8+ but has at least one win
    if ($avgRank >= 8.0 && $wins >= 1) {
        $badges[] = badgeDef('tortoise');
    }

    // 31. 🎖️ Old Guard — Participated in both s00 (pre-season) and current season
    $prevSeasonCount = $ctx['prevSeasonCount'][$racer_id] ?? 0;
    if ($prevSeasonCount > 0 && $season_id !== 's00') {
        $badges[] = badgeDef('old_guard');
    }

    // 33. 🐓 Early Bird — Participated in the first GP of this season
    if (!empty($ctx['firstGpRacers'][$racer_id])) {
        $badges[] = badgeDef('early_bird');
    }

    // 34. 🥊 Giant Killer — Beat the current season leader in a head-to-head GP
    $leaderId = $ctx['leaderId'];
    if ($leaderId && $leaderId !== $racer_id && !empty($ctx['beatLeader'][$racer_id])) {
        $badges[] = badgeDef('giant_killer');
    }

    // 36. 🌸 Princess Protocol — Mains exclusively Peach, Daisy, or Rosalina (50%+)
    if (($royal_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('princess_protocol');
    }

    // 37. 🍄 Mushroom Kingdom — Played every Mario-universe character at least once (career)
    $marioUniverse = ['Mario', 'Luigi', 'Peach', 'Daisy', 'Rosalina', 'Toad', 'Toadette',
        'Yoshi', 'Birdo', 'Wario', 'Waluigi', 'Donkey Kong', 'Bowser', 'Bowser Jr.',
        'Baby Mario', 'Baby Luigi', 'Baby Peach', 'Baby Daisy', 'Baby Rosalina'];
    $careerChars = $ctx['careerChars'][$racer_id] ?? [];
    // Normalise colour variants before comparing (e.g. "Yoshi (Orange)" → "Yoshi")
    $careerCharsNorm = array_unique(array_map('normalizeCharacterName', $careerChars));
    if (empty(array_diff($marioUniverse, $careerCharsNorm))) {
        $badges[] = badgeDef('mushroom_kingdom');
    }

    // 38. 🗡️ Link Main — 5+ GPs played as Link this season
    if ($link_count >= 5) {
        $badges[] = badgeDef('link_main');
    }

    // 39. 🍄 What a Fun Guy! — Mains Toad, Toadette, or Peachette ≥50% of the time
    if (($fungi_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('fun_guy');
    }

    // 40. 🧑 That\'s Just a Person? — Mains Mii, Inklings, or Villager ≥50% of the time
    if (($human_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('just_a_person');
    }

    // 41. 🐱 Furcurious! — Mains Tanooki Mario or Cat Peach ≥50% of the time
    if (($furry_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('furcurious');
    }

    // 42. 😈 Koopa Klan — Mains Bowser, Koopalings, or Koopa Troopa ≥50% of the time
    if (($koopa_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('koopa_klan');
    }

    // ── Playstyle: Cold-Blooded ────────────────────────────────────────────────
    if (($reptile_count / $totalRaces) >= 0.50) {
        $badges[] = badgeDef('cold_blooded');
    }

    // ── Streaks ───────────────────────────────────────────────────────────────
    // 🎩 Hat Trick — 3 GP wins in a row
    if ($max_win_streak >= 3) {
        $badges[] = badgeDef('hat_trick');
    }

    // ↗️ Ascendant — improved finishing rank in 5 consecutive GPs
    $maxImprovementStreak = 1;
    $curImprovementStreak = 1;
    for ($i = 1; $i < count($ranks); $i++) {
        if ($ranks[$i] < $ranks[$i - 1]) {
            $curImprovementStreak++;
            if ($curImprovementStreak > $maxImprovementStreak) $maxImprovementStreak = $curImprovementStreak;
        } else {
            $curImprovementStreak = 1;
        }
    }
    if ($maxImprovementStreak >= 5) {
        $badges[] = badgeDef('ascendant');
    }

    // ── Career ────────────────────────────────────────────────────────────────
    // 🕰️ The Elder — competed in 3+ distinct seasons
    if (($ctx['seasonsPlayed'][$racer_id] ?? 0) >= 3) {
        $badges[] = badgeDef('the_elder');
    }

    // ── ELO-based badges ──────────────────────────────────────────────────────
    if (!function_exists('calculateAllELORatings')) require_once __DIR__ . '/elo_engine.php';

    $racerName = $ctx['racerNames'][$racer_id] ?? null;

    if ($racerName) {
        $changelog = getMonsterHuntEloChangelog($pdo);

        // Collect season ELO entries for this racer, ordered by gpid
        $seasonElo = [];
        foreach ($changelog as $gpid => $gpData) {
            if (strpos($gpid, $season_id) !== 0) continue;
            if (!isset($gpData[$racerName])) continue;
            $seasonElo[$gpid] = $gpData[$racerName]['old_elo'];
        }
        ksort($seasonElo);

        if (count($seasonElo) >= 2) {
            $eloVals  = array_values($seasonElo);
            $eloDelta = end($eloVals) - $eloVals[0];

            // 🧗 The Climber
            if ($eloDelta >= 100) {
                $badges[] = badgeDef('elo_climber');
            }
            // 📉 The Fall
            if ($eloDelta <= -100) {
                $badges[] = badgeDef('elo_fall');
            }
        }

        // ⚡ Upset King — beat someone with 200+ higher Elo in 3+ GPs
        $upsetCount = 0;
        foreach ($changelog as $gpid => $gpData) {
            if (strpos($gpid, $season_id) !== 0) continue;
            if (!isset($gpData[$racerName])) continue;
            $myElo  = $gpData[$racerName]['old_elo'];
            $myRank = $gpData[$racerName]['rank'];
            foreach ($gpData as $oName => $oData) {
                if ($oName === $racerName) continue;
                if ($oData['old_elo'] >= $myElo + 200 && $myRank < $oData['rank']) {
                    $upsetCount++;
                    break; // one upset per GP is enough
                }
            }
        }
        if ($upsetCount >= 3) {
            $badges[] = badgeDef('upset_king');
        }

        // 🥶 Stone Cold — held the #1 Elo spot for 5+ consecutive GPs
        $gpEloLeader = [];
        foreach ($changelog as $gpid => $gpData) {
            if (strpos($gpid, $season_id) !== 0) continue;
            $topElo  = PHP_INT_MIN;
            $topName = null;
            foreach ($gpData as $n => $d) {
                if ($d['old_elo'] > $topElo) { $topElo = $d['old_elo']; $topName = $n; }
            }
            $gpEloLeader[$gpid] = $topName;
        }
        ksort($gpEloLeader);
        $scStreak = 0; $scMax = 0;
        foreach ($gpEloLeader as $topName) {
            if ($topName === $racerName) { $scStreak++; $scMax = max($scMax, $scStreak); }
            else $scStreak = 0;
        }
        if ($scMax >= 5) {
            $badges[] = badgeDef('stone_cold');
        }

        // ── MONSTER HUNT badges (only for monster_hunt seasons) ───────────────
        if ($ctx['scoringSystem'] === 'monster_hunt') {
            $mhDragonSlayer  = false;
            $mhHuntedCount   = 0;
            $mhWipeCount     = 0;
            $mhApexCount     = 0;
            $mhUnderdogDone  = false;
            $mhSurvivedCount = 0;

            // Every hunt from the MONSTER HUNT engine (mhSeasonHunts) — Monster
            // pick, CR tier and outcomes are decided in exactly one place.
            foreach (mhSeasonHunts($pdo, $season_id, getSeasonRules($pdo, $season_id)) as $h) {
                if ($h['solo'] || !isset($h['xp'][$racerName])) continue;

                if ($racerName === $h['monster']) {
                    $mhHuntedCount++;
                    if ($h['tpk']) $mhApexCount++;
                } elseif (in_array($racerName, $h['slayers'], true)) {
                    if ($h['cr_tier'] === 4) $mhDragonSlayer = true;
                    if ($h['full_slay'])     $mhWipeCount++;
                    // Underdog: slew Monster while having lowest Elo among adventurers
                    if (!$mhUnderdogDone) {
                        $myElo    = $h['elos'][$racerName];
                        $isLowest = true;
                        foreach ($h['elos'] as $n => $e) {
                            if ($n === $h['monster'] || $n === $racerName) continue;
                            if ($e < $myElo) { $isLowest = false; break; }
                        }
                        if ($isLowest) $mhUnderdogDone = true;
                    }
                } else {
                    $mhSurvivedCount++;
                }
            }

            if ($mhDragonSlayer) {
                $badges[] = badgeDef('mh_dragon_slayer');
            }
            if ($mhHuntedCount >= 3) {
                $badges[] = badgeDef('mh_hunted');
            }
            if ($mhWipeCount >= 3) {
                $badges[] = badgeDef('mh_wipe_master');
            }
            if ($mhApexCount >= 3) {
                $badges[] = badgeDef('mh_apex');
            }
            if ($mhUnderdogDone) {
                $badges[] = badgeDef('mh_underdog');
            }
            if ($mhSurvivedCount >= 5) {
                $badges[] = badgeDef('mh_resilient');
            }
        }
    }

    // ── Black Box ─────────────────────────────────────────────────────────────
    // 43. ⬛ Black Box — Leader of the Black Box scoring system this season.
    //     Leader computed once for the whole season in badgeSeasonContext().
    if ($ctx['bbLeaderId'] !== null && $ctx['bbLeaderId'] === $racer_id) {
        $badges[] = badgeDef('black_box');
    }

    return $badges;
}

/**
 * Get Unique Awards (special one-off badges awarded to specific racers)
 * Returns array of unique badges with custom images
 */
function getUniqueBadges($pdo, $racer_id, $season_id) {
    $uniqueBadges = [];

    // OMK Piece Price - Awarded especially to Tom (racer_id = 6)
    if ($racer_id == 6) {
        $uniqueBadges[] = [
            'img' => '/assets/img/omkpp.png',
            'title' => 'OMK Piece Price',
            'desc' => 'For selflessly striving for equality.'
        ];
    }

    // OMK No Fwurd Awurd - Awarded especially to Carlota (racer_id = 10)
    if ($racer_id == 10) {
        $uniqueBadges[] = [
            'img' => '/assets/img/omknfa.png',
            'title' => 'OMK No Fwurd Awurd',
            'desc' => 'For not cursing for a whole GP.'
        ];
    }

    return $uniqueBadges;
}

// ============================================================================
// BADGE RARITY — how many racers in a season hold each badge. Pages that show
// a racer's badges lead with the rarest, and the player card's featured
// "honour" is the rarest badge the racer holds, not the first one emitted.
// ============================================================================

/** title => number of racers holding it this season (cached per request). */
function badgeHolderCounts($pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];
    $counts = [];
    foreach (array_keys(getSeasonResultsByRacer($pdo, $season_id)) as $rid) {
        foreach (getRacerBadges($pdo, (int)$rid, $season_id) as $bd) $counts[$bd['title']] = ($counts[$bd['title']] ?? 0) + 1;
    }
    return $cache[$season_id] = $counts;
}

/** Rarest first (fewest holders), then catalogue order for stable ties. */
function sortBadgesByRarity(array $badges, array $counts): array {
    static $order = null;
    if ($order === null) $order = array_flip(array_column(badgeCatalog(), 'title'));
    usort($badges, fn($a, $b) => (($counts[$a['title']] ?? PHP_INT_MAX) <=> ($counts[$b['title']] ?? PHP_INT_MAX)) ?: (($order[$a['title']] ?? PHP_INT_MAX) <=> ($order[$b['title']] ?? PHP_INT_MAX)));
    return $badges;
}

// ============================================================================
// BADGE SIGHTINGS — "new this week". Whenever a GP is logged, every badge a
// racer holds that the log hasn't seen before is recorded with that GP night.
// The first time this runs for a season it backfills everything already held
// with a NULL night, so nothing pre-existing is announced as new.
// ============================================================================

/** Record unseen badges for every racer in the season. Returns how many were new. */
function recordBadgeSightings($pdo, string $season_id, ?string $gpid = null, ?string $date = null): int {
    $seen = [];
    foreach ($pdo->query("SELECT racer_id, badge_title FROM badge_log WHERE season_id = " . $pdo->quote($season_id))->fetchAll(PDO::FETCH_ASSOC) as $r) $seen[$r['racer_id'] . '|' . $r['badge_title']] = true;
    $backfill = empty($seen);
    if ($gpid === null || $date === null) {
        $st = $pdo->prepare("SELECT gpid, race_date FROM results WHERE gpid LIKE ? ORDER BY race_date DESC, id DESC LIMIT 1");
        $st->execute([$season_id . '%']);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) { $gpid = $gpid ?? $row['gpid']; $date = $date ?? substr((string)$row['race_date'], 0, 10); }
    }
    $ins = $pdo->prepare("INSERT OR IGNORE INTO badge_log (season_id, racer_id, badge_title, first_gpid, first_date) VALUES (?, ?, ?, ?, ?)");
    $new = 0;
    foreach (array_keys(getSeasonResultsByRacer($pdo, $season_id)) as $rid) {
        foreach (getRacerBadges($pdo, (int)$rid, $season_id) as $b) {
            if (isset($seen[$rid . '|' . $b['title']])) continue;
            $ins->execute([$season_id, (int)$rid, $b['title'], $backfill ? null : $gpid, $backfill ? null : $date]);
            if (!$backfill) $new++;
        }
    }
    return $new;
}

/** "racer_id|title" => true for badges first seen on the season's latest race night. */
function badgeNewThisNight($pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];
    $latest = getLatestRaceDate($pdo, $season_id);
    $out = [];
    if ($latest) {
        $st = $pdo->prepare("SELECT racer_id, badge_title FROM badge_log WHERE season_id = ? AND first_date = ?");
        $st->execute([$season_id, substr((string)$latest, 0, 10)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['racer_id'] . '|' . $r['badge_title']] = true;
    }
    return $cache[$season_id] = $out;
}
