<?php
/**
 * Kartfolio checks — the executable half of CLAUDE.md's test checklist.
 * Run through bin/check.sh (which also lints and builds the fixture).
 *
 *   php bin/check.php /path/to/fixture.db
 *
 * Fails (exit 1) on: any PHP warning/notice/deprecation while rendering a
 * page, a page that fatals, a badge icon used twice, a registry entry missing
 * its calculate/breakdown/tooltip, a replay helper that disagrees with its
 * live calculator, a map stop count that isn't the cup count, or db.php
 * costing more than its steady-state statements per request.
 */
declare(strict_types=1);
$db = $argv[1] ?? '';
if ($db === '' || !is_file($db)) { fwrite(STDERR, "usage: php bin/check.php <fixture.db>\n"); exit(2); }
putenv("KARTFOLIO_DB=$db");
error_reporting(E_ALL);
$root = realpath(__DIR__ . '/..');
$fail = 0; $pass = 0;
$ok = function (string $what, bool $cond, string $detail = '') use (&$fail, &$pass) { if ($cond) { $pass++; echo "  ✓ $what\n"; } else { $fail++; echo "  ✗ $what" . ($detail !== '' ? " — $detail" : '') . "\n"; } };

require "$root/private/includes/db.php";
require "$root/private/includes/gp_logic.php";
require "$root/private/includes/feed.php";
require "$root/private/includes/badges.php";

// ── Roll Call ──
echo "roll call\n";
require_once __DIR__ . '/../private/includes/team_season.php';
$rcK = ['show' => 1, 'beat' => 3, 'swap' => true];
$rcRow = fn($rid, $n, $pts, $date) => ['racer_id' => $rid, 'gpid' => sprintf('s09gp%02d', $n), 'gp_points' => $pts, 'race_date' => $date];
$rc = rollCallFromRows([
    $rcRow(1, 1, 30, '2026-11-05'), $rcRow(1, 2, 50, '2026-11-06'), $rcRow(1, 3, 55, '2026-11-08'),   // veteran on 40: one week, one beat, however many GPs
    $rcRow(1, 4, 40, '2026-11-09'),                                                                    // exactly the number is not a beat, and Monday is a new week
    $rcRow(2, 5, 20, '2026-11-09'), $rcRow(2, 6, 21, '2026-11-10'), $rcRow(2, 7, 10, '2026-11-16'),    // newcomer: debut beats, then own running average
], [1 => 40.0], $rcK);
$ok('a week pays once, however many GPs', $rc['racers'][1]['by_week']['2026-11-02']['pts'] === 3 && $rc['racers'][1]['by_week']['2026-11-02']['gps'] === 3);
$ok('level with your number is not a beat', $rc['racers'][1]['by_week']['2026-11-09']['pts'] === 1);
$ok('a season score is its weeks', $rc['racers'][1]['points'] === 4 && $rc['racers'][1]['weeks'] === 2 && $rc['racers'][1]['beats'] === 1);
$ok("a newcomer's debut is a beat, then their own average", $rc['racers'][2]['by_week']['2026-11-09']['beat'] && !$rc['racers'][2]['by_week']['2026-11-16']['beat'] && abs($rc['racers'][2]['number'] - 17.0) < 0.001);
$ok('the last frame equals the score', $rc['frames']['s09gp07'][2]['score'] === 4.0 && $rc['frames']['s09gp04'][1]['score'] === 4.0);
$ok('the swap is the Monday nearest halfway', rollCallSwapDate(['start_date' => '2026-11-05', 'end_date' => '2026-12-21']) === '2026-11-30' && rollCallSwapDate(['start_date' => '2026-11-05', 'end_date' => '2026-12-27']) === '2026-11-30');
$ok('the swap can be switched off', rollCallSwapDate(['start_date' => '2026-11-05', 'end_date' => '2026-12-21', 'rc_swap' => 0]) === null);
$rcStints = [1 => [['from' => '2026-11-02', 'team' => 7, 'reason' => 'draw'], ['from' => '2026-11-09', 'team' => 8, 'reason' => 'swap']], 2 => [['from' => '2026-11-09', 'team' => 8, 'reason' => 'join']]];
$rcTotals = rollCallTeamTotals($rc, $rcStints, [7, 8]);
$ok('points stay with the team that earned them', $rcTotals[7]['score'] === 3 && $rcTotals[8]['score'] === 1 + 3 + 1);
$ok('a week before the first stint goes to the first team', rollCallTeamInWeek($rcStints[2], '2026-11-02') === 8);
$rcPick = teamSeasonPickSwap([7 => 20, 8 => 9, 9 => 12], [7 => [1 => 9, 2 => 7, 3 => 4], 8 => [4 => 9, 5 => 0], 9 => [6 => 12]], [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D', 5 => 'E', 6 => 'F']);
$ok("the swap: most-present team's second, least-present team's last", $rcPick === [2, 7, 5, 8]);
$ok('no swap between level teams', teamSeasonPickSwap([7 => 5, 8 => 5], [7 => [1 => 5], 8 => [2 => 5]], []) === null);
$rcTable = getTeamStandings($pdo, 's04');
$rcSum = 0; foreach (rollCallSeason($pdo, 's04', getSeasonRules($pdo, 's04'))['racers'] as $x) $rcSum += $x['points'];
$ok('every point in the fixture season reached a team', count($rcTable) === 3 && array_sum(array_column($rcTable, 'score')) === $rcSum, array_sum(array_column($rcTable, 'score')) . " of $rcSum");
$ok('nobody who raced is left without a team', !array_diff(array_keys(getSeasonResultsByRacer($pdo, 's04')), array_merge(...array_map(fn($t) => array_keys($t['members']), $rcTable))));

$ok('a draw can come from one season, the last three, or all', teamSeasonRefSeasons($pdo, 's04', 's01') === ['s01'] && teamSeasonRefSeasons($pdo, 's04', 'all') === ['s03', 's02', 's01'] && teamSeasonRefSeasons($pdo, 's04', 'last3') === ['s03', 's02', 's01'] && teamSeasonRefSeasons($pdo, 's03', 'last3') === ['s02', 's01'] && teamSeasonRefSeasons($pdo, 's04', 'nonsense') === []);
$ok('pots from several seasons respect the size asked for', count(array_merge(...teamSeasonPots($pdo, 's04', 'all', 3, 2))) === 6);
$rcEloPots = array_column(array_merge(...teamSeasonPots($pdo, 's04', 'elo', 3, 2)), 'elo');
$rcEloSorted = $rcEloPots; rsort($rcEloSorted);
$ok('pots by Elo run highest first and respect the size asked for', count($rcEloPots) === 6 && $rcEloPots === $rcEloSorted);
$rcEloIds = array_column(array_merge(...teamSeasonPots($pdo, 's02', 'elo', 6, 10)), 'id');
$ok('pots by Elo only hold racers from the seasons before this one', $rcEloIds && !array_diff($rcEloIds, array_keys(getSeasonResultsByRacer($pdo, 's01'))));
$ok('teams level on points share a rank', array_column(teamStandingRanks([['score' => 0], ['score' => 0], ['score' => 0]]), 'rank') === [1, 1, 1] && array_column(teamStandingRanks([['score' => 9], ['score' => 5], ['score' => 5], ['score' => 2]]), 'rank') === [1, 2, 2, 4]);
$rcChamps = teamSeasonChampions($pdo, 's04');
$rcPlace = seasonPlacements($pdo, 's04')['place'];
$ok('every racer on the top team who raced is a champion', $rcChamps && count($rcChamps) === count(array_intersect_key($rcTable[0]['members'], getSeasonResultsByRacer($pdo, 's04'))));
$ok('the champions finish joint first, and the next racer after them', !array_diff_key($rcChamps, array_filter($rcPlace, fn($p) => $p === 1)) && count(array_keys($rcPlace, 1, true)) === count($rcChamps) && (count($rcPlace) === count($rcChamps) || min(array_diff($rcPlace, [1])) === count($rcChamps) + 1));
$ok('the season title holders are the racers, not the team', (seasonTitleHolders($pdo)['s04'] ?? []) === array_values($rcChamps));

echo "team badges\n";
$tbRc = rollCallFromRows([
    // Week 1 (2 Nov): 1, 2, 3 on team 7 all beat. Week 2 (9 Nov): 1 and 2 beat, 3 misses. Week 3 (16 Nov): 1 beats, 4 on team 8 beats.
    $rcRow(1, 1, 50, '2026-11-03'), $rcRow(2, 1, 50, '2026-11-03'), $rcRow(3, 1, 50, '2026-11-03'),
    $rcRow(1, 2, 50, '2026-11-10'), $rcRow(2, 2, 50, '2026-11-10'), $rcRow(3, 2, 10, '2026-11-10'),
    $rcRow(1, 3, 50, '2026-11-17'), $rcRow(4, 3, 50, '2026-11-17'),
    $rcRow(5, 3, 50, '2026-11-17'),                                  // a newcomer's single race, on team 8
    $rcRow(1, 4, 50, '2026-11-24'), $rcRow(6, 4, 10, '2026-11-24'),  // week 4: 1 on team 7 again; 6 raced once and missed, team 8
], [1 => 40.0, 2 => 40.0, 3 => 40.0, 4 => 40.0, 6 => 40.0], $rcK);
$tbStints = [
    1 => [['from' => '2026-11-02', 'team' => 7, 'reason' => 'draw']],
    2 => [['from' => '2026-11-02', 'team' => 7, 'reason' => 'draw']],
    3 => [['from' => '2026-11-02', 'team' => 7, 'reason' => 'draw']],
    4 => [['from' => '2026-11-02', 'team' => 8, 'reason' => 'draw'], ['from' => '2026-11-16', 'team' => 7, 'reason' => 'swap']],
    5 => [['from' => '2026-11-17', 'team' => 7, 'reason' => 'join']],
    6 => [['from' => '2026-11-24', 'team' => 8, 'reason' => 'join']],
];
$tbTable = rollCallTeamTotals($tbRc, $tbStints, [7, 8]);
$tbSorted = [['id' => 7, 'score' => $tbTable[7]['score']], ['id' => 8, 'score' => $tbTable[8]['score']]];
$tbLive = rollCallBadgeFacts($tbRc, $tbStints, $tbSorted, [], false, '2026-11-05');
$ok('mid-season: only Traded and finished Full Houses', isset($tbLive[4]['team_traded']) && !isset($tbLive[1]['team_full_house']) && !isset($tbLive[5]['team_cameo']) && !isset($tbLive[1]['team_present']));
$tbMid = rollCallBadgeFacts($tbRc, $tbStints, $tbSorted, [], false, '2026-11-09');
$ok('Full House lands once the week has ended, for the team that week', isset($tbMid[1]['team_full_house'], $tbMid[2]['team_full_house'], $tbMid[3]['team_full_house']) && !isset($tbMid[5]['team_full_house']) && !isset($tbMid[4]['team_full_house']));
$tbEnd = rollCallBadgeFacts($tbRc, $tbStints, $tbSorted, [1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D', 5 => 'E'], true, '2026-12-01');
$ok('Cameo: one week, beat the number (a newcomer counts, a miss does not)', isset($tbEnd[5]['team_cameo']) && !isset($tbEnd[6]['team_cameo']));
$ok('By the Numbers needs four weeks, all beaten', isset($tbEnd[1]['team_by_the_numbers']) && !isset($tbEnd[2]['team_by_the_numbers']));
$ok('Present is every week anyone raced', isset($tbEnd[1]['team_present']) && !isset($tbEnd[2]['team_present']));
$ok('Top Scorer is the most points, alone', isset($tbEnd[1]['team_top_scorer']) && count(array_filter($tbEnd, fn($b) => isset($b['team_top_scorer']))) === 1);
$ok('Walk-On: joined by racing, on the winning team', isset($tbEnd[5]['team_walk_on']) && !isset($tbEnd[1]['team_walk_on']) && !isset($tbEnd[6]['team_walk_on']));
$tbClose = [['id' => 7, 'score' => 20], ['id' => 8, 'score' => 17]];
$tbTight = rollCallBadgeFacts($tbRc, $tbStints, $tbClose, [1 => 'A'], true, '2026-12-01');
$tbWide  = rollCallBadgeFacts($tbRc, $tbStints, [['id' => 7, 'score' => 20], ['id' => 8, 'score' => 16]], [1 => 'A'], true, '2026-12-01');
$ok('The Decider: final-week points at least the margin', isset($tbTight[1]['team_decider']) && !isset($tbWide[1]['team_decider']));

echo "omkredits\n";
// A require behind a try/catch fails silently, so a wrong path never shows up as a broken page.
$badRequires = [];
foreach (array_merge(glob("$root/public_html/*.php"), glob("$root/public_html/*/*.php"), glob("$root/private/includes/*.php"), glob("$root/private/templates/*.php")) as $phpFile) {
    if (preg_match_all("/require(?:_once)?\\s+__DIR__\\s*\\.\\s*'([^']+)'/", (string)file_get_contents($phpFile), $reqs)) {
        foreach ($reqs[1] as $req) if (!is_file(dirname($phpFile) . $req)) $badRequires[] = str_replace("$root/", '', $phpFile) . ' -> ' . $req;
    }
}
$ok('every require points at a file that exists', !$badRequires, implode(', ', array_slice($badRequires, 0, 3)));
require_once "$root/private/includes/omk.php";
$omkR = (int)$pdo->query("SELECT id FROM racers ORDER BY id LIMIT 1")->fetchColumn();
$omkR2 = (int)$pdo->query("SELECT id FROM racers ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn();
$omkKeys = stickerByKey($pdo); $omkFoil = null; $omkPlain = null;
foreach ($omkKeys as $k => $s) { if ($s['rarity'] === 'foil') $omkFoil ??= $k; elseif ($s['rarity'] === 'common') $omkPlain ??= $k; }
$omkSave = $pdo->prepare("INSERT OR REPLACE INTO racer_stickers (racer_id, sticker_key, count) VALUES (?, ?, ?)");
$omkSave->execute([$omkR, $omkPlain, 4]); $omkSave->execute([$omkR, $omkFoil, 2]);
$omkRefused = function (callable $fn) { try { $fn(); return false; } catch (OmkRefused $e) { return true; } };
$ok('a duplicate is worth 1, a foil 3', omkDupeValue('common') === 1 && omkDupeValue('rare') === 1 && omkDupeValue('foil') === 3);
$ok('you cannot trade your last copy', $omkRefused(fn() => omkTradeIn($pdo, $omkR, [$omkPlain => 4])) && omkBalance($pdo, $omkR) === 0);
$omkT = omkTradeIn($pdo, $omkR, [$omkPlain => 2, $omkFoil => 1]);
$omkLeft = $pdo->prepare("SELECT count FROM racer_stickers WHERE racer_id = ? AND sticker_key = ?"); $omkLeft->execute([$omkR, $omkPlain]);
$ok('trade individual copies: 2 plain and a foil pay 5, and 2 are left', $omkT === ['copies' => 3, 'credits' => 5] && omkBalance($pdo, $omkR) === 5 && (int)$omkLeft->fetchColumn() === 2);
$ok('a trade of nothing is refused', $omkRefused(fn() => omkTradeIn($pdo, $omkR, [$omkPlain => 0])));
$ok('you cannot spend what you do not have', $omkRefused(fn() => omkBuySponsor($pdo, $omkR)) && omkBalance($pdo, $omkR) === 5 && !omkSponsorQueue($pdo));
omkEntry($pdo, $omkR, 100, 'test', 'test'); omkEntry($pdo, $omkR2, 100, 'test', 'test');
$omkFav = omkBuyBribe($pdo, $omkR);
$omkFavCheck = $pdo->prepare("SELECT cup_name FROM results WHERE racer_id = ? AND gpid LIKE 's%' GROUP BY cup_name HAVING COUNT(*) >= 3 ORDER BY AVG(gp_points) DESC, COUNT(*) DESC, cup_name ASC LIMIT 1"); $omkFavCheck->execute([$omkR]);
$ok("a bribe goes on the briber's best cup, and one at a time", $omkFav['cup'] === $omkFavCheck->fetchColumn() && $omkRefused(fn() => omkBuyBribe($pdo, $omkR)) && omkBalance($pdo, $omkR) === 95);
$omkOpen = omkOpenBribes($pdo);
$omkW = omkApplyBribes([$omkFav['cup'] => 1, 'Other' => 30, 'Another' => 30], $omkOpen, [$omkR]);
$ok('a bribed cup gets 1 draw in 4, only if the briber is in it', $omkW[$omkFav['cup']] === 20 && $omkW['Other'] === 30 && omkApplyBribes([$omkFav['cup'] => 1, 'Other' => 30], $omkOpen, [$omkR2])[$omkFav['cup']] === 1);
$ok('a bribe does nothing for a cup out of the draw', !isset(omkApplyBribes(['Other' => 30], $omkOpen, [$omkR])[$omkFav['cup']]));
$ok('he admits it when it lands, and keeps the money when it does not', str_contains((string)omkBribeLine($omkOpen, $omkFav['cup'], [$omkR]), "I'm suggesting") && str_contains((string)omkBribeLine($omkOpen, '__other__', [$omkR]), 'keeping the money') && omkBribeLine($omkOpen, $omkFav['cup'], [$omkR2]) === null);
$ok('you cannot counter yourself', $omkRefused(fn() => omkBuyCounter($pdo, $omkR, (int)$omkOpen[0]['id'])));
$omkG = $pdo->prepare("SELECT gpid FROM results WHERE racer_id = ? AND gpid LIKE 's%' LIMIT 1"); $omkG->execute([$omkR]); $omkGp = (string)$omkG->fetchColumn();
$omkG = $pdo->prepare("SELECT gpid FROM results WHERE gpid LIKE 's%' AND gpid NOT IN (SELECT gpid FROM results WHERE racer_id = ?) LIMIT 1"); $omkG->execute([$omkR]); $omkElse = (string)$omkG->fetchColumn();
if ($omkElse !== '') omkAfterGpSaved($pdo, $omkElse);
$ok('a GP the briber did not race leaves the bribe alone', count(array_filter(omkOpenBribes($pdo), fn($b) => $b['status'] === 'pending')) === 1);
omkAfterGpSaved($pdo, $omkGp);
$ok('a GP the briber raced uses it up', !omkOpenBribes($pdo));
omkBuyBribe($pdo, $omkR);
omkBuyCounter($pdo, $omkR2, (int)omkOpenBribes($pdo)[0]['id']);
$omkOpen = omkOpenBribes($pdo);
$ok('a counter-bribe cancels it, costs both, and he keeps both', $omkOpen[0]['status'] === 'countered' && omkApplyBribes(['X' => 30, $omkOpen[0]['cup_name'] => 2], $omkOpen, [$omkR])[$omkOpen[0]['cup_name']] === 2 && str_contains((string)omkBribeLine($omkOpen, 'X', [$omkR]), 'keeping both') && omkBalance($pdo, $omkR2) === 85);
omkAfterGpSaved($pdo, $omkGp);
$ok('a countered bribe closes with the next GP', !omkOpenBribes($pdo));
omkBuySponsor($pdo, $omkR);
$ok('one sponsorship at a time, queued for the next newscast', $omkRefused(fn() => omkBuySponsor($pdo, $omkR)) && (int)(omkNextSponsorship($pdo)['racer_id'] ?? 0) === $omkR);
$pdo->exec("INSERT INTO recap_archive (season_id, recap_text, headline, status) VALUES ('s01', 'x', 'x', 'draft')"); $omkRecap = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE omk_sponsorships SET recap_id = ?")->execute([$omkRecap]);
$ok('a used sponsorship leaves the queue', omkNextSponsorship($pdo) === null);
$pdo->exec("DELETE FROM recap_archive WHERE id = $omkRecap");
$ok('deleting the draft frees it for the next newscast', (int)(omkNextSponsorship($pdo)['racer_id'] ?? 0) === $omkR);
$omkAd = omkAdLine('Kart Core Team', 'Hanna', 'Honk honk!');
$ok('the ad read', $omkAd === 'Kart Core Team is brought to you by Hanna. Honk honk!');
$ok('the ad is appended only if the writer left it out, bold or not', omkEnsureAdRead('Racing.', 'Hanna', $omkAd) === "Racing.\n\n$omkAd" && omkEnsureAdRead('Now, brought to you by **Hanna**!', 'Hanna', $omkAd) === 'Now, brought to you by **Hanna**!');
$omkBefore = omkBalance($pdo, $omkR2);
$ok('a commissioner can give OMKredits, and a reason is kept to one line', omkGift($pdo, $omkR2, 7, "for\nsomething") === 7 && omkBalance($pdo, $omkR2) === $omkBefore + 7 && str_contains((string)$pdo->query("SELECT detail FROM omk_ledger ORDER BY id DESC LIMIT 1")->fetchColumn(), 'for something'));
$ok('taking back never goes below zero', omkGift($pdo, $omkR2, -100000) === -($omkBefore + 7) && omkBalance($pdo, $omkR2) === 0 && omkGift($pdo, $omkR2, -5) === 0);
$ok('the sign is omega plus a combining diaeresis', OMK_SYMBOL === "\u{03A9}\u{0308}" && omkFormat(10) === "10\u{03A9}\u{0308}");
$ok('in HTML the sign is drawn, and text around it is still escaped', omkFormatHtml(10) === '10' . omkSign() && omkHtml('Gave <b>' . omkFormat(5)) === 'Gave &lt;b&gt;5' . omkSign());
$omkNow = 1000000;
$ok('an account is open only for that racer, and only until it times out', omkCounterIsOpen(['omk_counter' => ['racer' => 5, 'until' => $omkNow + 1]], 5, $omkNow) && !omkCounterIsOpen(['omk_counter' => ['racer' => 5, 'until' => $omkNow + 1]], 6, $omkNow) && !omkCounterIsOpen(['omk_counter' => ['racer' => 5, 'until' => $omkNow]], 5, $omkNow) && !omkCounterIsOpen([], 5, $omkNow) && !omkCounterIsOpen([], 0, $omkNow));
// The vanity shelf.
omkEntry($pdo, $omkR, 1000, 'test', 'test');
$omkT0 = 2000000000;
$omkU1 = omkBuyGold($pdo, $omkR, $omkT0); $omkU2 = omkBuyGold($pdo, $omkR, $omkT0);
$ok('gold lasts 7 days and buying more adds to the end', $omkU1 === $omkT0 + 7 * 86400 && $omkU2 === $omkT0 + 14 * 86400);
$pdo->exec("DELETE FROM omk_cosmetics WHERE kind = 'gold_name'");
omkBuyGold($pdo, $omkR);
$ok('a gold name shows for its owner, not for others, and expires', isset(omkGoldNames($pdo)[$omkR]) && !isset(omkGoldNames($pdo)[$omkR2]));
$pdo->exec("UPDATE omk_cosmetics SET until_ts = 1 WHERE kind = 'gold_name'");
$ok('an expired gold name is gone', !isset(omkGoldNames($pdo)[$omkR]));
$omkLol = (int)$pdo->query("SELECT id FROM results WHERE gpid LIKE 's%' AND racer_id = $omkR ORDER BY id LIMIT 1")->fetchColumn();
$omkLolWas = (int)$pdo->query("SELECT is_lol FROM results WHERE id = $omkLol")->fetchColumn();
$pdo->exec("UPDATE results SET is_lol = 1 WHERE id = $omkLol");
$omkOther = (int)$pdo->query("SELECT id FROM results WHERE gpid LIKE 's%' AND racer_id != $omkR ORDER BY id LIMIT 1")->fetchColumn();
$ok("you cannot offset someone else's LOL", $omkRefused(fn() => omkBuyOffset($pdo, $omkR, $omkOther)));
$omkLolGp = omkBuyOffset($pdo, $omkR, $omkLol)['gpid'];
$ok('an offset tags the LOL once, and it stays a LOL', isset(omkLolOffsets($pdo, $omkLolGp)[$omkLol]) && $omkRefused(fn() => omkBuyOffset($pdo, $omkR, $omkLol)) && !in_array($omkLol, array_map('intval', array_column(omkUnoffsetLols($pdo, $omkR), 'id')), true) && (int)$pdo->query("SELECT is_lol FROM results WHERE id = $omkLol")->fetchColumn() === 1);
$pdo->exec("UPDATE results SET is_lol = $omkLolWas WHERE id = $omkLol");
$ok('the first Founder is #001, once each', omkBuyFounder($pdo, $omkR) === 1 && $omkRefused(fn() => omkBuyFounder($pdo, $omkR)) && omkFounders($pdo)[$omkR] === 1);
$ok("the Founder's badge is bought, not in the earned catalogue, with a unique icon", omkFounderBadge(3)['title'] === 'Founder #003' && !in_array(omkFounderBadge(3)['icon'], array_column(badgeCatalog(), 'icon'), true) && !in_array('Founder #003', array_column(badgeCatalog(), 'title'), true));
$pdo->exec("INSERT INTO omk_cosmetics (racer_id, kind, serial) VALUES (901,'founder',2),(902,'founder',3),(903,'founder',4),(904,'founder',5),(905,'founder',6),(906,'founder',7),(907,'founder',8),(908,'founder',9),(909,'founder',10)");
$ok('Founders sell out at 10', $omkRefused(fn() => omkBuyFounder($pdo, $omkR2)));
$omkPacks = fn() => (int)$pdo->query("SELECT COUNT(*) FROM racer_packs WHERE racer_id = $omkR AND source = 'loot_box'")->fetchColumn();
$omkP0 = $omkPacks(); $omkBal0 = omkBalance($pdo, $omkR);
$ok('a loot box is one pack for 8', omkBuyLootBox($pdo, $omkR, 2)['golden'] === false && $omkPacks() === $omkP0 + 1 && omkBalance($pdo, $omkR) === $omkBal0 - 8);
$ok('and one in a million is golden', omkBuyLootBox($pdo, $omkR, 1)['golden'] === true && omkGoldenPulls($pdo) !== []);
$omkOdds = omkLootOdds();
$ok('the published odds are the real weights', abs(array_sum(array_column($omkOdds, 'card')) - 100) < 0.001 && abs($omkOdds[0]['card'] - 100 * STICKER_WEIGHTS['foil'] / array_sum(STICKER_WEIGHTS)) < 0.001 && abs($omkOdds[0]['pack'] - 100 * (1 - 0.98 ** STICKER_PACK_SIZE)) < 0.001);
$pdo->exec("DELETE FROM racer_packs WHERE source = 'loot_box'");
$pdo->exec("DELETE FROM omk_cosmetics");
$pdo->prepare("DELETE FROM racer_stickers WHERE racer_id = ? AND sticker_key = 'lore_kartificial'")->execute([$omkR]);
// Race weeks, on a clean ledger.
$pdo->exec("DELETE FROM omk_ledger");
$pdo->exec("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('omk_epoch', '" . date('Y-m-d', strtotime('-110 days')) . "')");
settingsMap($pdo, true);
$omkFirst = omkGrantRaceWeeks($pdo); $omkSecond = omkGrantRaceWeeks($pdo);
$omkMax = (int)$pdo->query("SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM omk_ledger WHERE kind = 'race_week' GROUP BY racer_id, ref)")->fetchColumn();
$ok('a race week pays 2, once, however many GPs', $omkFirst > 0 && $omkSecond === 0 && $omkMax === 1 && (int)$pdo->query("SELECT MIN(amount) FROM omk_ledger")->fetchColumn() === 2 && (int)$pdo->query("SELECT MAX(amount) FROM omk_ledger")->fetchColumn() === 2);
foreach (['omk_ledger', 'omk_bribes', 'omk_sponsorships'] as $omkT2) $pdo->exec("DELETE FROM $omkT2");
$pdo->prepare("DELETE FROM racer_stickers WHERE racer_id = ? AND sticker_key IN (?, ?)")->execute([$omkR, $omkPlain, $omkFoil]);
$pdo->exec("DELETE FROM settings WHERE setting_key = 'omk_epoch'");
settingsMap($pdo, true);

echo "wrapped\n";
require_once "$root/private/includes/wrapped_personas.php";
$wp = wrappedPeriodFor('2025-11-19', 2026);
$ok('a Wrapped year runs anniversary to anniversary, and 2026 is Year 1', $wp['start'] === '2025-11-19' && $wp['end'] === '2026-11-19' && $wp['label'] === 'Year 1' && $wp['range'] === '19 Nov 2025 – 18 Nov 2026');
$ok('Wrapped opens on the anniversary and closes after six weeks', !wrappedIsOpenOn('2025-11-19', '2026-11-18') && wrappedIsOpenOn('2025-11-19', '2026-11-19') && wrappedIsOpenOn('2025-11-19', '2026-12-31') && !wrappedIsOpenOn('2025-11-19', '2027-01-01') && wrappedIsOpenOn('2025-11-19', '2027-11-19'));
$ok('Wrapped is shut on the day the league begins, and with no racing', !wrappedIsOpenOn('2025-11-19', '2025-11-19') && !wrappedIsOpenOn('2025-11-19', '2025-12-25') && !wrappedIsOpenOn(null, '2026-11-19'));
$ok('Wrapped says when it next opens', wrappedNextOpening('2025-11-19', '2026-11-05') === '2026-11-19' && wrappedNextOpening('2025-11-19', '2027-01-02') === '2027-11-19' && wrappedNextOpening('2025-11-19', '2025-12-01') === '2026-11-19' && wrappedNextOpening(null, '2026-11-05') === null);
echo "the computer\n";
$cpu = cpuAheadFromRows([
    ['gpid' => 's09gp01', 'racer_id' => 1, 'rank' => 1], ['gpid' => 's09gp01', 'racer_id' => 2, 'rank' => 4],   // 2 CPUs between them
    ['gpid' => 's09gp02', 'racer_id' => 1, 'rank' => 3], ['gpid' => 's09gp02', 'racer_id' => 2, 'rank' => 12],  // 2, then 10
]);
$ok('CPUs ahead is your place, minus 1, minus the humans ahead', $cpu['racers'][1] === ['gps' => 2, 'ahead' => 2, 'clean' => 1, 'worst' => 2] && $cpu['racers'][2] === ['gps' => 2, 'ahead' => 12, 'clean' => 0, 'worst' => 10]);
$cpuHuman = [];
for ($n = 1; $n <= 20; $n++) { $cpuHuman[] = ['gpid' => sprintf('s08gp%02d', $n), 'racer_id' => 1, 'rank' => 1]; $cpuHuman[] = ['gpid' => sprintf('s08gp%02d', $n), 'racer_id' => 2, 'rank' => 2]; }
$ok('a season that only ranked the humans is left out', cpuAheadFromRows($cpuHuman)['skipped'] === ['s08'] && !cpuAheadFromRows($cpuHuman)['racers']);
$ok('a young season counts, however clean its grids', cpuAheadFromRows(array_slice($cpuHuman, 0, 8))['skipped'] === []);
$ok('feed: valid RSS with only published broadcasts', (function () use ($pdo) { $pdo->beginTransaction(); $pdo->exec("INSERT INTO recap_archive (season_id, recap_text, headline, status) VALUES ('s01', 'Published & **bold** text', 'Public <one>', 'published'), ('s01', 'Secret', 'Draft one', 'draft')"); $x = @simplexml_load_string(rssFeedXml($pdo, 'League & co')); $pdo->rollBack(); $titles = $x ? array_map('strval', $x->xpath('//item/title')) : []; return in_array('Public <one>', $titles, true) && !in_array('Draft one', $titles, true); })());

// ── registry shape ──
echo "registry\n";
foreach (getScoringSystemRegistry() as $key => $def) {
    // breakdown/tooltip may be null (the dispatchers have safe fallbacks) but the keys must exist and non-null values must be callable
    $ok("$key has name/icon/calculate/breakdown/tooltip", !empty($def['name']) && !empty($def['icon']) && isset($def['calculate']) && is_callable($def['calculate']) && array_key_exists('breakdown', $def) && array_key_exists('tooltip', $def) && ($def['breakdown'] === null || is_callable($def['breakdown'])) && ($def['tooltip'] === null || is_callable($def['tooltip'])));
    if (!empty($def['tiebreak'])) $ok("$key tiebreak has metric/level/ahead/both", is_callable($def['tiebreak']['metric'] ?? null) && isset($def['tiebreak']['level'], $def['tiebreak']['ahead'], $def['tiebreak']['both']));
}

// ── badges ──
echo "badges\n";
$cat = badgeCatalog();
$icons = array_column($cat, 'icon');
$ok('every badge icon is unique (' . count($cat) . ' badges)', count($icons) === count(array_unique($icons)), implode(' ', array_keys(array_filter(array_count_values($icons), fn($n) => $n > 1))));
$ok('every catalogue category is in the category order', !array_diff(array_unique(array_column($cat, 'category')), badgeCategoryOrder()));

// /wrapped: light text on an aura's tint must stay readable, whatever colours
// an aura uses. Raw auras painted behind the text gave white on yellow at 1.5:1.
require_once "$root/private/includes/wrapped_personas.php";
$wrText = ['#ffffff', '#FFD700', '#bba6ff', '#e8e3ff', '#c4bbe6', '#b9aee0', '#cbb9ff', '#ffe9a8', '#4fd67a', '#ff9c9c'];
$wrWorst = 99.0; $wrAt = '';
foreach (wrappedAuras() as $a) foreach ($a['grad'] as $c) foreach ($wrText as $t) {
    $cr = (wrappedLuminance($t) + 0.05) / (wrappedLuminance(wrappedTint($c)) + 0.05);
    if ($cr < $wrWorst) { $wrWorst = $cr; $wrAt = "{$a['label']} $c with $t"; }
}
$ok('every /wrapped aura tint keeps slide text at 4.5:1 (worst ' . round($wrWorst, 2) . ')', $wrWorst >= 4.5, $wrAt);
$emitted = [];
foreach (['s01', 's02'] as $s) foreach (array_keys(getSeasonResultsByRacer($pdo, $s)) as $rid) foreach (getRacerBadges($pdo, (int)$rid, $s) as $b) $emitted[$b['title']] = true;
$ok('badge emitters produce catalogue titles only', !array_diff(array_keys($emitted), array_column($cat, 'title')), implode(', ', array_diff(array_keys($emitted), array_column($cat, 'title'))));

// ── replay helpers agree with live calculators ──
echo "scoring\n";
$bad = [];
foreach (['s01', 's02'] as $s) { $rules = getSeasonRules($pdo, $s); foreach (array_keys(getSeasonResultsByRacer($pdo, $s)) as $rid) { $rows = getRacerSeasonRows($pdo, (int)$rid, $s);
    foreach ([['average_attendance', 'calculateAverageAttendanceScore'], ['preseason', 'calculatePreSeasonScore'], ['positional_points', 'calculatePositionalScore'], ['median', 'calculateMedianScore'], ['form', 'calculateFormScore'],
              ['best_n_gps', 'calculateBestNGPsScore'], ['cup_based', 'calculateCupBasedScore'], ['drop_worst', 'calculateDropWorstScore'], ['perfect_hunt', 'calculatePerfectHuntScore'], ['top_12_unique', 'calculateTop12UniqueScore'],
              ['black_box', 'calculateBlackBoxScore'], ['random_cup_draw', 'calculateRandomCupDrawScore']] as [$sys, $fn]) {
        $live = $fn($pdo, (int)$rid, $s, $rules); if ($sys === 'average_attendance' && $live === 0) continue;   // threshold gate lives in the calculator
        $rep = progressiveRacerScore($pdo, $sys, $s, (array)$rules, (int)$rid, $rows);
        if (abs((float)$rep - (float)$live) > 1e-9) $bad[] = "$s/$rid/$sys $rep≠$live";
    } } }
$ok('progressiveRacerScore == live calculators (12 per-racer replays)', !$bad, implode(' ', array_slice($bad, 0, 5)));
// every system's calculator returns a number for every racer (the weird ones included)
$bad = [];
foreach (getScoringSystemRegistry() as $key => $def) foreach (array_keys(getSeasonResultsByRacer($pdo, 's02')) as $rid) { $v = ($def['calculate'])($pdo, (int)$rid, 's02', getSeasonRules($pdo, 's02')); if (!is_numeric($v)) $bad[] = "$key/$rid"; }
$ok('every registry calculate() returns a number on s02 (' . count(getScoringSystemRegistry()) . ' systems)', !$bad, implode(' ', array_slice($bad, 0, 5)));
foreach (['s01', 's02'] as $s) { $sp = seasonPlacements($pdo, $s); $ok("seasonPlacements($s) ranks the qualifiers 1..n", array_values($sp['place']) === range(1, $sp['field'])); }
$t = territorySeason($pdo, 's02');
$ok('territorySeason: every held cup has a holder who is a racer', !array_diff(array_column($t['hold'], 'racer_id'), array_map('intval', $pdo->query("SELECT id FROM racers")->fetchAll(PDO::FETCH_COLUMN))));
$ok('territory events only claim/beat/tie/decay', !array_diff(array_unique(array_column($t['events'], 'type')), ['claim', 'beat', 'tie', 'decay']));
$payload = territoryMapPayload($pdo, 's02');
$ok('territoryMapPayload has one entry per cup (' . count(getMKAllCups()) . ')', count($payload['cups']) === count(getMKAllCups()));

// ── db.php per-request cost ──
echo "database\n";
$dbSrc = file_get_contents("$root/private/includes/db.php");
$counting = 'class CPDO extends PDO { public $n=0; #[\ReturnTypeWillChange] function prepare($q,$o=[]){$this->n++;return parent::prepare($q,$o);} #[\ReturnTypeWillChange] function query($q,...$a){$this->n++;return parent::query($q,...$a);} #[\ReturnTypeWillChange] function exec($q){$this->n++;return parent::exec($q);} }';
$src = str_replace(['new PDO(', '__DIR__', '__FILE__'], ['new CPDO(', var_export("$root/private/includes", true), var_export("$root/private/includes/db.php", true)], $dbSrc);
$probe = "<?php $counting ?>$src<?php echo \$pdo->n;";
$tmp = tempnam(sys_get_temp_dir(), 'dbprobe'); file_put_contents($tmp, $probe);
$runDb = fn() => (int)trim((string)shell_exec('KARTFOLIO_DB=' . escapeshellarg($db) . ' php ' . escapeshellarg($tmp) . ' 2>/dev/null'));
// The settling window: for a few minutes after a schema change the block keeps
// running, because the host's opcache can still be serving the old db.php.
$winPdo = new PDO('sqlite:' . $db); $winPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$winPdo->exec('PRAGMA application_id = ' . time());
$winPdo->exec('DROP TABLE IF EXISTS result_flags');
$runDb();
$ok('inside the settling window, migrations still run and a missing table comes back', (bool)$winPdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='result_flags'")->fetchColumn());
$provisional = (int)$winPdo->query('PRAGMA user_version')->fetchColumn();
$winPdo->exec('PRAGMA application_id = 1');   // the window has passed
$runDb();
$ok('after the window, the signature goes final', (int)$winPdo->query('PRAGMA user_version')->fetchColumn() === ($provisional ^ 1));
$n = $runDb(); unlink($tmp);
$ok("db.php steady state is ≤ 6 statements per request (got $n)", $n > 0 && $n <= 6);

// ── render every public page, warnings are failures ──
echo "pages\n";
$pages = [];
foreach (glob("$root/public_html/*.php") as $f) $pages[basename($f, '.php')] = [];
unset($pages['login'], $pages['logout']);
$variants = ['teams' => ['', 'season=s04'], 'index' => ['', 'season=s01', 'season=s02', 'season=s03', 'season=s04'], 'racer' => ['id=1', 'id=2'], 'scoring' => ['season=s01', 'season=s02', 'season=s03', 'season=s04'], 'stats' => ['', 'season=s01', 'season=s02', 'season=all'], 'timeline_gp' => ['gp=s02gp01'], 'cup_detail' => ['cup=mushroom'], 'view_season_report' => ['season=s01', 'season=s02'], 'animate_season' => ['season=s02'], 'wrapped' => ['racer=1'], 'badges_overview' => ['season=s02'], 'season_chart' => ['season=s02'], 'mh_dashboard' => ['season=s02'], 'rank_graphic' => ['season=s02'], 'yearbook' => ['', 'season=s01', 'season=s02'], 'cup_mastery' => ['season=s02'], 'territory' => ['', 'season=s01', 'season=s02', 'season=zz'], 'predictions' => [''], 'season' => null];
$racerIds = $pdo->query("SELECT id FROM racers ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$renderer = tempnam(sys_get_temp_dir(), 'render'); file_put_contents($renderer, '<?php
// Renders one page from the CLI. A page may exit() (a redirect, a "not found"),
// so the verdict is delivered from a shutdown function. No session: pages that
// need an admin redirect and exit, which counts as rendering cleanly.
error_reporting(E_ALL); ini_set("display_errors", "1");
[$_, $file, $qs] = $argv; parse_str($qs, $_GET);
$_SERVER["REQUEST_URI"] = "/" . basename($file, ".php") . ($qs !== "" ? "?$qs" : ""); $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["SERVER_NAME"] = "localhost"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
ob_start();
register_shutdown_function(function () {
    $o = (string)ob_get_clean();
    $e = error_get_last();
    if ($e && in_array($e["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) { echo "PROBLEM fatal: " . $e["message"] . " @" . basename($e["file"]) . ":" . $e["line"]; return; }
    if (preg_match_all("/(?:Fatal error|Parse error|Warning|Notice|Deprecated): [^\\n]{0,180}/", $o, $m)) { echo "PROBLEM " . implode(" | ", array_slice(array_unique($m[0]), 0, 3)); return; }
    echo "ok";
});
require $file;');
foreach ($pages as $name => $_) {
    $qsList = $variants[$name] ?? [''];
    foreach ($qsList as $qs) {
        $qs = str_replace('id=1', 'id=' . ($racerIds[0] ?? 1), str_replace('id=2', 'id=' . ($racerIds[1] ?? 1), $qs));
        $out = trim((string)shell_exec('cd ' . escapeshellarg("$root/public_html") . ' && KARTFOLIO_DB=' . escapeshellarg($db) . ' php ' . escapeshellarg($renderer) . ' ' . escapeshellarg("$root/public_html/$name.php") . ' ' . escapeshellarg($qs) . ' 2>&1 | tail -1'));
        $ok("$name.php" . ($qs !== '' ? "?$qs" : ''), $out === 'ok', $out);
    }
}
unlink($renderer);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
