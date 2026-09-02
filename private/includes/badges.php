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

    // — Career-wide maps (season-independent), batched one query each —
    $careerCups = [];          // racer_id => [cup_name, ...]
    foreach ($pdo->query("SELECT DISTINCT racer_id, cup_name FROM results")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['cup_name'] !== null) $careerCups[(int)$r['racer_id']][] = $r['cup_name'];
    }
    $careerPerfectCups = [];   // racer_id => [cup_name with a 60, ...]
    foreach ($pdo->query("SELECT DISTINCT racer_id, cup_name FROM results WHERE gp_points = 60")->fetchAll(PDO::FETCH_ASSOC) as $r) {
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

    // — Mikkoliiga leader for this season (Mikkoligan) — standings[0], score>0. —
    $mikkoLeaderId = null;
    $ms = getMikkoliigaStandings($pdo, $season_id);
    if (!empty($ms) && ($ms[0]['score'] ?? 0) > 0) $mikkoLeaderId = (int)$ms[0]['id'];

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

    // ── Territory (cup ownership) — one chronological pass over the season ──
    //   held      : racer => cups held at season end (canonical territorySeason)
    //   takeovers : racer => times they took a cup off a DIFFERENT holder
    //   fortress  : racer => cups they held unchanged all season through 3+
    //               challengers (someone else posting on it and failing)
    $territoryHeld = [];
    foreach (territorySeason($pdo, $season_id)['by_racer'] as $rid => $cups) $territoryHeld[(int)$rid] = count($cups);

    $cupRows = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rows) {
        foreach ($rows as $r) {
            if (($r['cup_name'] ?? '') === '') continue;
            $cupRows[] = [(string)$r['race_date'], (int)$r['id'], (int)$rid, $r['cup_name'], (int)$r['gp_points']];
        }
    }
    usort($cupRows, fn($x, $y) => strcmp($x[0], $y[0]) ?: ($x[1] <=> $y[1]));
    $holder = []; $changed = []; $challengers = []; $territoryTakeovers = [];
    foreach ($cupRows as [$d, $id, $rid, $cup, $pts]) {
        if (!isset($holder[$cup])) { $holder[$cup] = [$rid, $pts]; $changed[$cup] = false; $challengers[$cup] = 0; continue; }
        [$h, $hp] = $holder[$cup];
        if ($rid !== $h) {
            $challengers[$cup]++;
            if ($pts > $hp) {   // strictly better takes it; a tie leaves it with the earlier post
                $holder[$cup] = [$rid, $pts]; $changed[$cup] = true;
                $territoryTakeovers[$rid] = ($territoryTakeovers[$rid] ?? 0) + 1;
            }
        } elseif ($pts > $hp) {
            $holder[$cup] = [$rid, $pts];   // holder improving on themselves is not a change of hands
        }
    }
    $territoryFortress = [];
    foreach ($holder as $cup => [$h, $hp]) {
        if (!$changed[$cup] && $challengers[$cup] >= 3) $territoryFortress[$h] = ($territoryFortress[$h] ?? 0) + 1;
    }

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

    // ── Dynasty: longest run of consecutive archived-season titles, by champion name ──
    $dynastyRun = []; $run = 0; $prevChamp = null;
    foreach ($pdo->query("SELECT season_id, champion_name FROM season_meta WHERE status = 'archived' AND champion_name IS NOT NULL AND champion_name != '' ORDER BY season_id ASC")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $n = trim((string)$c['champion_name']);
        $run = ($n === $prevChamp) ? $run + 1 : 1;
        $prevChamp = $n;
        $dynastyRun[$n] = max($dynastyRun[$n] ?? 0, $run);
    }

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

    return $cache[$season_id] = compact(
        'highestAttendance', 'firstGpId', 'firstGpRacers', 'leaderId', 'beatLeader',
        'scoringSystem', 'bbLeaderId', 'careerCups', 'careerPerfectCups', 'careerChars',
        'prevSeasonCount', 'seasonsPlayed', 'racerNames',
        'stickerHoldings', 'stickerSetTotals', 'stickerGrandTotal', 'packsOpened',
        'tourneyWins', 'pickemOracleIds', 'mikkoLeaderId', 'elo2000',
        'territoryHeld', 'territoryTakeovers', 'territoryFortress', 'deadHeat',
        'seasonGpTotal', 'dynastyRun', 'questmaster'
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
    if ($packs >= 1)  $badges[] = ['icon' => '📦', 'title' => 'Wax Cracker', 'desc' => 'Cracked open your first sticker pack.'];
    // 8 · Pack Rat — opened 25+ packs.
    if ($packs >= 25) $badges[] = ['icon' => '🐀', 'title' => 'Pack Rat', 'desc' => 'Opened 25 or more sticker packs all-time.'];

    // 3 · Full Album — own every card. 4 · Halfway Hero otherwise at 50%+.
    if ($distinct >= $grand)        $badges[] = ['icon' => '📖', 'title' => 'Full Album', 'desc' => 'Collected every sticker in the album. Completionist!'];
    elseif ($pct >= 0.5)            $badges[] = ['icon' => '🌗', 'title' => 'Halfway Hero', 'desc' => 'Collected at least half of the sticker album.'];

    // 2 · Set Sweeper (tiered).
    if ($setsComplete >= 5)     $badges[] = ['icon' => '🥇', 'title' => 'Set Sweeper, Gold',   'desc' => 'Completed 5 or more full sticker sets.'];
    elseif ($setsComplete >= 3) $badges[] = ['icon' => '🥈', 'title' => 'Set Sweeper, Silver', 'desc' => 'Completed 3 full sticker sets.'];
    elseif ($setsComplete >= 1) $badges[] = ['icon' => '🥉', 'title' => 'Set Sweeper, Bronze', 'desc' => 'Completed a full sticker set.'];

    // 5 · Foil Hunter — 5+ foils.
    if ($foil >= 5)         $badges[] = ['icon' => '✨', 'title' => 'Foil Hunter', 'desc' => 'Owns five or more shiny foil cards.'];
    // 6 · Got the Bot — the Kartificial chase foil.
    if (!empty($h['kart'])) $badges[] = ['icon' => '🎴', 'title' => 'Got the Bot', 'desc' => 'Pulled Kartificial #001 — the chase foil.'];
    // 7 · Stuck With Dupes — 5+ of one card.
    if ($maxDup >= 5)       $badges[] = ['icon' => '♻️', 'title' => 'Stuck With Dupes', 'desc' => 'Hoards 5+ copies of a single card. Got, got, need!'];
    // 9 · Lore Keeper — completed the lore set.
    if (($setTotals['lore'] ?? 0) > 0 && ($owned['lore'] ?? 0) >= $setTotals['lore'])
        $badges[] = ['icon' => '📜', 'title' => 'Lore Keeper', 'desc' => 'Completed the Lore set — every in-joke catalogued.'];
    // 10 · Whale — 250+ total copies.
    if ($total >= 250)      $badges[] = ['icon' => '🐳', 'title' => 'Whale', 'desc' => 'Amassed 250+ total cards. A true sticker tycoon.'];
}

/** Competition badges (11–16) — tournament/Mikkoliiga/Elo honours, from context. */
function appendCompetitionBadges(array &$badges, array $ctx, int $racer_id) {
    $tw = $ctx['tourneyWins'][$racer_id] ?? null;
    if ($tw) {
        // 12 · Tournament Champion — any format.
        if (($tw['total'] ?? 0) >= 1)
            $badges[] = ['icon' => '🏆', 'title' => 'Tournament Champion', 'desc' => 'Won a Kartfolio tournament.'];
        // 11 · Board Breaker — Snakes & Ladders.
        if (!empty($tw['formats']['snakes_ladders']))
            $badges[] = ['icon' => '🐍', 'title' => 'Board Breaker', 'desc' => 'Won a Snakes & Ladders tournament.'];
        // 13 · On Top of the World — World Cup.
        if (!empty($tw['formats']['world_cup']))
            $badges[] = ['icon' => '🌍', 'title' => 'On Top of the World', 'desc' => 'Lifted the World Cup trophy.'];
    }
    // 14 · Pick'em Oracle.
    if (!empty($ctx['pickemOracleIds'][$racer_id]))
        $badges[] = ['icon' => '🔮', 'title' => "Pick'em Oracle", 'desc' => "Topped a World Cup Pick'em leaderboard."];
    // 15 · Mikkoligan — leads this season's Mikkoliiga.
    if (($ctx['mikkoLeaderId'] ?? null) === $racer_id)
        $badges[] = ['icon' => '🌟', 'title' => 'Mikkoligan', 'desc' => 'Tops the Mikkoliiga this season.'];
    // 16 · Ascended — crossed 2000 Elo.
    if (!empty($ctx['elo2000'][$racer_id]))
        $badges[] = ['icon' => '🌠', 'title' => 'Ascended', 'desc' => 'Reached a 2000 Elo rating.'];
}

/**
 * Territory, tie-break, attendance and career-arc badges (all from context;
 * the only per-racer read is the season cache slice).
 */
function appendSeasonEventBadges(array &$badges, array $ctx, $pdo, int $racer_id, string $season_id) {
    // 🏘️ Landlord — 5+ cups held at once.
    if (($ctx['territoryHeld'][$racer_id] ?? 0) >= 5)
        $badges[] = ['icon' => '🏘️', 'title' => 'Landlord', 'desc' => 'Holds five or more cups at once this season.'];
    // 🗝️ Usurper — took a cup off someone else 5+ times.
    if (($ctx['territoryTakeovers'][$racer_id] ?? 0) >= 5)
        $badges[] = ['icon' => '🗝️', 'title' => 'Usurper', 'desc' => 'Took a cup off another racer five or more times this season.'];
    // 🏯 Fortress — a cup defended all season against 3+ challengers.
    if (($ctx['territoryFortress'][$racer_id] ?? 0) >= 1)
        $badges[] = ['icon' => '🏯', 'title' => 'Fortress', 'desc' => 'Held a cup all season through three or more challengers, never overtaken.'];
    // 🪙 Dead Heat — level on points with someone; the tie-break decided it.
    if (!empty($ctx['deadHeat'][$racer_id]))
        $badges[] = ['icon' => '🪙', 'title' => 'Dead Heat', 'desc' => 'Level on points with another racer — separated only by the tie-break.'];
    // 📅 Ever-Present — every GP of the season (3+ held).
    $mine = count(getRacerSeasonRows($pdo, $racer_id, $season_id));
    if (($ctx['seasonGpTotal'] ?? 0) >= 3 && $mine === (int)$ctx['seasonGpTotal'])
        $badges[] = ['icon' => '📅', 'title' => 'Ever-Present', 'desc' => 'Raced every single GP of the season.'];
    // 🏵️ Dynasty — 3+ consecutive season titles.
    $name = trim((string)($ctx['racerNames'][$racer_id] ?? ''));
    if ($name !== '' && ($ctx['dynastyRun'][$name] ?? 0) >= 3)
        $badges[] = ['icon' => '🏵️', 'title' => 'Dynasty', 'desc' => 'Won three or more seasons in a row.'];
    // 🌈 Full Roster — 10+ distinct characters, career.
    $chars = array_unique(array_map('normalizeCharacterName', $ctx['careerChars'][$racer_id] ?? []));
    if (count($chars) >= 10)
        $badges[] = ['icon' => '🌈', 'title' => 'Full Roster', 'desc' => 'Has raced ten or more different characters across their career.'];
    // 🧭 Questmaster — both side quests done this season.
    if (!empty($ctx['questmaster'][$racer_id]))
        $badges[] = ['icon' => '🧭', 'title' => 'Questmaster', 'desc' => 'Completed both side quests this season.'];
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
        if ($r['gp_points'] == 60) $perfect_games++;

        // Arrays for Stats (keep original name for display / uniqueness)
        $chars[] = $r['character_used'];
        $ranks[] = $r['rank'];
        $karts[] = $r['kart_setup'];
        $raced_cups[] = $r['cup_name'];
        if ($r['gp_points'] == 60) $perfect_cups[] = $r['cup_name'];

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
        if (stripos($r['kart_setup'], 'Standard') !== false) $standard_kart_count++;
        if (stripos($r['kart_setup'], 'Bike') !== false) $bike_count++;

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
        $badges[] = ['icon' => '🍌', 'title' => 'Slippery Slope', 'desc' => 'Triggered the "LOL" obstruction frequently.'];
    }

    if ($uniqueChars === 1 && $totalRaces >= 5) {
        $badges[] = ['icon' => '🎠', 'title' => 'One-Trick Pony', 'desc' => 'Has never changed their character.'];
    }

    if ($uniqueChars >= 5) {
        $badges[] = ['icon' => '🎭', 'title' => 'Identity Crisis', 'desc' => 'Played 5+ different characters this season.'];
    }

    if (($podiums / $totalRaces) >= 0.60) {
        $badges[] = ['icon' => '👑', 'title' => 'Podium Royalty', 'desc' => 'Finishes in the Top 3 over 60% of the time.'];
    }

    if ($avgRank >= 4 && $avgRank <= 7) {
        $badges[] = ['icon' => '🧱', 'title' => 'The Wall', 'desc' => 'Consistently holds the midfield, another brick in the wall.'];
    }

    if ($perfect_games >= 1) {
        $badges[] = ['icon' => '🤖', 'title' => 'Max Output', 'desc' => 'Achieved a perfect 60-point Grand Prix.'];
    }

    if (($seconds / $totalRaces) >= 0.25) {
        $badges[] = ['icon' => '💐', 'title' => 'The Bridesmaid', 'desc' => 'Finishes 2nd place >25% of the time.'];
    }

    if (($fourths / $totalRaces) >= 0.25) {
        $badges[] = ['icon' => '4️⃣', 'title' => 'The Fourth Wall', 'desc' => 'Stuck in the cursed 4th place position >25% of the time.'];
    }

    if ($max_win_streak >= 2) {
        $badges[] = ['icon' => '🔥', 'title' => 'Hot Hand', 'desc' => 'Won back-to-back Grand Prix events.'];
    }

    if (($baby_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🍼', 'title' => 'Baby Driver', 'desc' => 'Mains Baby characters over 50% of the time.'];
    }

    if (($heavy_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🦖', 'title' => 'Kaiju Protocol', 'desc' => 'Mains heavyweights over 50% of the time.'];
    }

    if ($avgRank >= 10) {
        $badges[] = ['icon' => '⚓', 'title' => 'The Anchor', 'desc' => 'For the ship to remain stable, someone needs to be at the bottom.'];
    }

    if (count(array_unique($winning_chars)) >= 3) {
        $badges[] = ['icon' => '🃏', 'title' => 'Jack of All Trades', 'desc' => 'Won a GP with 3 different characters.'];
    }

    if (($standard_kart_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🔰', 'title' => 'The Purist', 'desc' => 'Refuses to use meta vehicles; prefers Standard setups.'];
    }

    $variance = 0;
    foreach ($ranks as $r) {
        $variance += pow(($r - $avgRank), 2);
    }
    $stdDev = sqrt($variance / $totalRaces);
    
    if ($stdDev > 3.5) {
        $badges[] = ['icon' => '🎢', 'title' => 'Chaos Agent', 'desc' => 'Highly inconsistent results (High variance).'];
    }

    // --- NEW BADGES REQUESTED ---

    // 11. 📈 Vertical Limit
    $lastScore = end($gp_scores_by_date);
    if ($totalRaces >= 4 && $lastScore >= ($seasonAvgPoints + 15)) {
        $badges[] = ['icon' => '📈', 'title' => 'Vertical Limit', 'desc' => 'Latest performance was significantly higher than season average.'];
    }

    // 12. 🎰 High Roller
    $pointVariance = 0;
    foreach ($gp_scores_by_date as $pts) {
        $pointVariance += pow(($pts - $seasonAvgPoints), 2);
    }
    $pointStdDev = sqrt($pointVariance / $totalRaces);
    if ($pointStdDev > 15) {
        $badges[] = ['icon' => '🎰', 'title' => 'High Roller', 'desc' => 'Extreme swings in point totals between events.'];
    }

    // 13. 💤 Sandbagger
    if ($totalRaces >= 6) {
        $firstHalf = array_slice($gp_scores_by_date, 0, floor($totalRaces / 2));
        $secondHalf = array_slice($gp_scores_by_date, -floor($totalRaces / 2));
        if ((array_sum($secondHalf) / count($secondHalf)) > (array_sum($firstHalf) / count($firstHalf)) + 12) {
            $badges[] = ['icon' => '💤', 'title' => 'Sandbagger', 'desc' => 'Started the season poorly but finished much stronger.'];
        }
    }

    // 14. 🗓️ Longevity
    $highestAttendance = $ctx['highestAttendance'];
    if ($totalRaces >= $highestAttendance && $highestAttendance > 0) {
        $badges[] = ['icon' => '🗓️', 'title' => 'Longevity', 'desc' => 'Highest attendance record in the league.'];
    }

    // 15. 🏛️ Base 12 (Won all Base Game Cups)
    if (empty(array_diff(MK_BASE_CUPS, $won_cups_unique))) {
        $badges[] = ['icon' => '🏛️', 'title' => 'Base 12', 'desc' => 'Has won a Grand Prix in all 12 Base Game cups.'];
    }

    // 16. 🚀 Booster's Dozen (Won all DLC Cups)
    if (empty(array_diff(MK_BOOSTER_CUPS, $won_cups_unique))) {
        $badges[] = ['icon' => '🚀', 'title' => 'Booster\'s Dozen', 'desc' => 'Has won a Grand Prix in all 12 Booster Course Pass cups.'];
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
        $badges[] = ['icon' => '🎯', 'title' => 'Laser Focus', 'desc' => 'Finished within 5 points of season average in 70%+ of races.'];
    }

    // 18. 🏔️ Everest
    $peakDiff = max($gp_scores_by_date) - $seasonAvgPoints;
    if ($peakDiff >= 20) {
        $badges[] = ['icon' => '🏔️', 'title' => 'Everest', 'desc' => 'Achieved a personal best 20+ points above season average.'];
    }

    // 19. 🎪 Comeback Kid
    for ($i = 1; $i < count($results); $i++) {
        if ($results[$i]['rank'] == 1 && $results[$i-1]['rank'] >= 10) {
            $badges[] = ['icon' => '🎪', 'title' => 'Comeback Kid', 'desc' => 'Won a GP immediately after finishing in last place.'];
            break;
        }
    }

    // 20. 👻 Ghost Rider
    if (($spooky_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '👻', 'title' => 'Ghost Rider', 'desc' => 'Mains spooky characters (Boo, Dry Bones, King Boo) 50%+ of the time.'];
    }

    // 21. 🌟 Star Power
    if (($og_stars_count / $totalRaces) >= 0.60) {
        $badges[] = ['icon' => '⭐', 'title' => 'Star Power', 'desc' => 'Mains original Nintendo stars (Mario, Luigi, Peach, Daisy) 60%+ of the time.'];
    }

    // 22. 🏍️ Bike Brigade
    if (($bike_count / $totalRaces) >= 0.60) {
        $badges[] = ['icon' => '🏍️', 'title' => 'Bike Brigade', 'desc' => 'Uses bikes (not karts) in 60%+ of races.'];
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
        $badges[] = ['icon' => '🔄', 'title' => 'Groundhog Day', 'desc' => 'Finished in the exact same rank 4+ races in a row.'];
    }

    // 25. 🎲 Lucky 7
    if ($sevenths >= 3) {
        $badges[] = ['icon' => '🎲', 'title' => 'Lucky 7', 'desc' => 'Finished in 7th place in 3+ races.'];
    }

    // 26. 🦅 Perfect Landing
    if ($totalRaces >= 5 && ($podiums / $totalRaces) == 1.0) {
        $badges[] = ['icon' => '🦅', 'title' => 'Perfect Landing', 'desc' => 'Every race finish was on the podium (100% podium rate).'];
    }

    // --- NEW BADGES ---

    // 27. 🏅 Cup Collector — Raced in all 24 cups (any season, career)
    $careerCups = $ctx['careerCups'][$racer_id] ?? [];
    if (empty(array_diff(getMKAllCups(), $careerCups))) {
        $badges[] = ['icon' => '🏅', 'title' => 'Cup Collector', 'desc' => 'Has raced in all 24 Mario Kart 8 Deluxe cups across their career.'];
    }

    // 28. 💎 Perfectionist — Perfect 60 in 3+ different cups (any season)
    $careerPerfectCups = $ctx['careerPerfectCups'][$racer_id] ?? [];
    if (count($careerPerfectCups) >= 3) {
        $badges[] = ['icon' => '💎', 'title' => 'Perfectionist', 'desc' => 'Achieved a perfect 60 in 3+ different cups across their career.'];
    }

    // 30. 🐢 The Tortoise — Avg finish rank 8+ but has at least one win
    if ($avgRank >= 8.0 && $wins >= 1) {
        $badges[] = ['icon' => '🐢', 'title' => 'The Tortoise', 'desc' => 'Average finish of 8th or worse, yet still managed to win a GP.'];
    }

    // 31. 🎖️ Old Guard — Participated in both s00 (pre-season) and current season
    $prevSeasonCount = $ctx['prevSeasonCount'][$racer_id] ?? 0;
    if ($prevSeasonCount > 0 && $season_id !== 's00') {
        $badges[] = ['icon' => '🎖️', 'title' => 'Old Guard', 'desc' => 'A veteran who raced in the pre-season and has returned.'];
    }

    // 33. 🐓 Early Bird — Participated in the first GP of this season
    if (!empty($ctx['firstGpRacers'][$racer_id])) {
        $badges[] = ['icon' => '🐓', 'title' => 'Early Bird', 'desc' => 'Participated in the very first GP of this season.'];
    }

    // 34. 🥊 Giant Killer — Beat the current season leader in a head-to-head GP
    $leaderId = $ctx['leaderId'];
    if ($leaderId && $leaderId !== $racer_id && !empty($ctx['beatLeader'][$racer_id])) {
        $badges[] = ['icon' => '🥊', 'title' => 'Giant Killer', 'desc' => 'Finished ahead of the current season leader in at least one GP.'];
    }

    // 36. 🌸 Princess Protocol — Mains exclusively Peach, Daisy, or Rosalina (50%+)
    if (($royal_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🌸', 'title' => 'Princess Protocol', 'desc' => 'Mains royalty (Peach, Daisy, or Rosalina) in 50%+ of races.'];
    }

    // 37. 🍄 Mushroom Kingdom — Played every Mario-universe character at least once (career)
    $marioUniverse = ['Mario', 'Luigi', 'Peach', 'Daisy', 'Rosalina', 'Toad', 'Toadette',
        'Yoshi', 'Birdo', 'Wario', 'Waluigi', 'Donkey Kong', 'Bowser', 'Bowser Jr.',
        'Baby Mario', 'Baby Luigi', 'Baby Peach', 'Baby Daisy', 'Baby Rosalina'];
    $careerChars = $ctx['careerChars'][$racer_id] ?? [];
    // Normalise colour variants before comparing (e.g. "Yoshi (Orange)" → "Yoshi")
    $careerCharsNorm = array_unique(array_map('normalizeCharacterName', $careerChars));
    if (empty(array_diff($marioUniverse, $careerCharsNorm))) {
        $badges[] = ['icon' => '🏰', 'title' => 'Mushroom Kingdom', 'desc' => 'Has raced as every core Mario-universe character at their disposal.'];
    }

    // 38. 🗡️ Link Main — 5+ GPs played as Link this season
    if ($link_count >= 5) {
        $badges[] = ['icon' => '🗡️', 'title' => 'Link Main', 'desc' => 'Raced as Link in 5 or more GPs this season. Hyah!'];
    }

    // 39. 🍄 What a Fun Guy! — Mains Toad, Toadette, or Peachette ≥50% of the time
    if (($fungi_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🍄', 'title' => 'What a Fun Guy!', 'desc' => 'Mains Toad, Toadette, or Peachette in 50%+ of races. A real fungi.'];
    }

    // 40. 🧑 That\'s Just a Person? — Mains Mii, Inklings, or Villager ≥50% of the time
    if (($human_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🧑', 'title' => 'That\'s Just a Person?', 'desc' => 'Mains Mii, Inklings, or Villager in 50%+ of races. Keeping it real.'];
    }

    // 41. 🐱 Furcurious! — Mains Tanooki Mario or Cat Peach ≥50% of the time
    if (($furry_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🐱', 'title' => 'Furcurious!', 'desc' => 'Mains Tanooki Mario or Cat Peach in 50%+ of races. Suspiciously furry.'];
    }

    // 42. 😈 Koopa Klan — Mains Bowser, Koopalings, or Koopa Troopa ≥50% of the time
    if (($koopa_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '😈', 'title' => 'Koopa Klan', 'desc' => 'Mains Bowser and his crew in 50%+ of races. Embrace the dark side.'];
    }

    // ── Playstyle: Cold-Blooded ────────────────────────────────────────────────
    if (($reptile_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🦎', 'title' => 'Cold-Blooded', 'desc' => 'Mains reptilian characters (Yoshi, Koopa Troopa, Bowser and kin) in 50%+ of races.'];
    }

    // ── Streaks ───────────────────────────────────────────────────────────────
    // 🎩 Hat Trick — 3 GP wins in a row
    if ($max_win_streak >= 3) {
        $badges[] = ['icon' => '🎩', 'title' => 'Hat Trick', 'desc' => 'Won 3 Grand Prix events in a row.'];
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
        $badges[] = ['icon' => '↗️', 'title' => 'Ascendant', 'desc' => 'Improved finishing rank in 5 consecutive Grand Prix events.'];
    }

    // ── Career ────────────────────────────────────────────────────────────────
    // 🕰️ The Elder — competed in 3+ distinct seasons
    if (($ctx['seasonsPlayed'][$racer_id] ?? 0) >= 3) {
        $badges[] = ['icon' => '🕰️', 'title' => 'The Elder', 'desc' => 'Competed across 3 or more seasons.'];
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
                $badges[] = ['icon' => '🧗', 'title' => 'The Climber', 'desc' => 'Gained 100+ Elo points during this season.'];
            }
            // 📉 The Fall
            if ($eloDelta <= -100) {
                $badges[] = ['icon' => '📉', 'title' => 'The Fall', 'desc' => 'Lost 100+ Elo points during this season.'];
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
            $badges[] = ['icon' => '⚡', 'title' => 'Upset King', 'desc' => 'Finished ahead of a racer with 200+ higher Elo in 3 or more GPs.'];
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
            $badges[] = ['icon' => '🥶', 'title' => 'Stone Cold', 'desc' => 'Held the #1 Elo ranking for 5 consecutive Grand Prix events.'];
        }

        // ── MONSTER HUNT badges (only for monster_hunt seasons) ───────────────
        if ($ctx['scoringSystem'] === 'monster_hunt') {
            $mhDragonSlayer  = false;
            $mhHuntedCount   = 0;
            $mhWipeCount     = 0;
            $mhApexCount     = 0;
            $mhUnderdogDone  = false;
            $mhSurvivedCount = 0;

            foreach ($changelog as $gpid => $gpData) {
                if (strpos($gpid, $season_id) !== 0) continue;
                if (!isset($gpData[$racerName])) continue;
                if (count($gpData) < 2) continue;

                // Identify Monster — respects is_monster flag from Add Score form
                [$monsterName, $monsterElo] = pickMonster($gpid, $gpData, $pdo);
                if ($monsterName === null) continue;
                $monsterRank = $gpData[$monsterName]['rank'];

                // CR tier
                $advElos = [];
                foreach ($gpData as $n => $d) { if ($n !== $monsterName) $advElos[] = $d['old_elo']; }
                $avgAdv  = count($advElos) ? array_sum($advElos) / count($advElos) : $monsterElo;
                $eloGap  = max(0, $monsterElo - $avgAdv);
                $crTier  = $eloGap < 50 ? 1 : ($eloGap < 150 ? 2 : ($eloGap < 300 ? 3 : 4));

                // Adventurer outcomes
                $advWon = $advLost = 0;
                foreach ($gpData as $n => $d) {
                    if ($n === $monsterName) continue;
                    if ($d['rank'] < $monsterRank) $advWon++; else $advLost++;
                }
                $fullSlay = ($advLost === 0 && $advWon > 0); // all adventurers beat Monster
                $isTPK    = ($advWon === 0);                 // Monster beat all (TPK)

                if ($racerName === $monsterName) {
                    $mhHuntedCount++;
                    if ($isTPK) $mhApexCount++;
                } else {
                    $myRank = $gpData[$racerName]['rank'];
                    $iSlew  = $myRank < $monsterRank;
                    if ($iSlew) {
                        if ($crTier === 4) $mhDragonSlayer = true;
                        if ($fullSlay)     $mhWipeCount++;
                        // Underdog: slew Monster while having lowest Elo among adventurers
                        if (!$mhUnderdogDone) {
                            $myElo      = $gpData[$racerName]['old_elo'];
                            $isLowest   = true;
                            foreach ($gpData as $n => $d) {
                                if ($n === $monsterName || $n === $racerName) continue;
                                if ($d['old_elo'] < $myElo) { $isLowest = false; break; }
                            }
                            if ($isLowest) $mhUnderdogDone = true;
                        }
                    } else {
                        $mhSurvivedCount++;
                    }
                }
            }

            if ($mhDragonSlayer) {
                $badges[] = ['icon' => '🐉', 'title' => 'Dragon Slayer', 'desc' => 'Finished ahead of a CR 4 Dragon — the most feared Monster rating.'];
            }
            if ($mhHuntedCount >= 3) {
                $badges[] = ['icon' => '👹', 'title' => 'The Hunted', 'desc' => 'Designated as the Monster in 3 or more GPs this season.'];
            }
            if ($mhWipeCount >= 3) {
                $badges[] = ['icon' => '🎉', 'title' => 'Wipe Master', 'desc' => 'Participated in a Full Slay — every adventurer ahead of the Monster — in 3 or more GPs.'];
            }
            if ($mhApexCount >= 3) {
                $badges[] = ['icon' => '💀', 'title' => 'Apex Predator', 'desc' => 'Defeated every adventurer as the Monster in 3 or more GPs.'];
            }
            if ($mhUnderdogDone) {
                $badges[] = ['icon' => '🌑', 'title' => 'The Underdog', 'desc' => 'Slew the Monster while being the lowest-Elo adventurer in the race.'];
            }
            if ($mhSurvivedCount >= 5) {
                $badges[] = ['icon' => '🛡️', 'title' => 'Resilient', 'desc' => 'Survived without slaying the Monster in 5 or more GPs.'];
            }
        }
    }

    // ── Black Box ─────────────────────────────────────────────────────────────
    // 43. ⬛ Black Box — Leader of the Black Box scoring system this season.
    //     Leader computed once for the whole season in badgeSeasonContext().
    if ($ctx['bbLeaderId'] !== null && $ctx['bbLeaderId'] === $racer_id) {
        $badges[] = ['icon' => '⬛', 'title' => 'Black Box', 'desc' => 'The algorithm thinks you should be in the lead.'];
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
?>