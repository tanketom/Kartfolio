<?php
/**
 * Badges Overview - All Badges, Holders, and Progress
 * Path: /cdnmk/public_html/badges_overview.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/mk_data.php';

$currentSeason = $_GET['season'] ?? getCurrentSeasonNumber();
$isAllTime = ($currentSeason === 'all');
$pageTitle = "Badges Overview - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// 0. Fetch Available Seasons
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// 1. Fetch all racers
$racersStmt = $pdo->query("SELECT id, name FROM racers ORDER BY name");
$racers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Define all possible badges with their criteria
$allBadgeDefinitions = [
    'performance' => [
        ['icon' => '👑', 'title' => 'Podium Royalty', 'desc' => 'Finishes in the Top 3 over 60% of the time', 'key' => 'podium_royalty'],
        ['icon' => '🤖', 'title' => 'Max Output', 'desc' => 'Achieved a perfect 60-point Grand Prix', 'key' => 'max_output'],
        ['icon' => '🥈', 'title' => 'The Bridesmaid', 'desc' => 'Finishes 2nd place >25% of the time', 'key' => 'bridesmaid'],
        ['icon' => '💀', 'title' => 'The Fourth Wall', 'desc' => 'Stuck in 4th place >25% of the time', 'key' => 'fourth_wall'],
        ['icon' => '🔥', 'title' => 'Hot Hand', 'desc' => 'Won back-to-back Grand Prix events', 'key' => 'hot_hand'],
        ['icon' => '🧱', 'title' => 'The Wall', 'desc' => 'Consistently holds the midfield (avg rank 4-7)', 'key' => 'the_wall'],
        ['icon' => '⚓', 'title' => 'The Anchor', 'desc' => 'Average rank ≥10', 'key' => 'the_anchor'],
    ],
    'playstyle' => [
        ['icon' => '🎠', 'title' => 'One-Trick Pony', 'desc' => 'Never changed character (5+ races)', 'key' => 'one_trick'],
        ['icon' => '🎭', 'title' => 'Identity Crisis', 'desc' => 'Played 5+ different characters', 'key' => 'identity_crisis'],
        ['icon' => '🃏', 'title' => 'Jack of All Trades', 'desc' => 'Won a GP with 3 different characters', 'key' => 'jack_of_trades'],
        ['icon' => '🍼', 'title' => 'Baby Driver', 'desc' => 'Mains Baby characters >50% of the time', 'key' => 'baby_driver'],
        ['icon' => '🦖', 'title' => 'Kaiju Protocol', 'desc' => 'Mains heavyweights >50% of the time', 'key' => 'kaiju'],
        ['icon' => '🔰', 'title' => 'The Purist', 'desc' => 'Uses Standard setups >50% of the time', 'key' => 'purist'],
        ['icon' => '👻', 'title' => 'Ghost Rider', 'desc' => 'Mains spooky characters >50% of the time', 'key' => 'ghost_rider'],
        ['icon' => '🌟', 'title' => 'Star Power', 'desc' => 'Mains original stars >60% of the time', 'key' => 'star_power'],
        ['icon' => '🏍️', 'title' => 'Bike Brigade', 'desc' => 'Uses bikes >60% of the time', 'key' => 'bike_brigade'],
    ],
    'volatility' => [
        ['icon' => '🍌', 'title' => 'Slippery Slope', 'desc' => 'Triggered LOL obstruction 3+ times', 'key' => 'slippery'],
        ['icon' => '🎢', 'title' => 'Chaos Agent', 'desc' => 'Highly inconsistent rank results', 'key' => 'chaos'],
        ['icon' => '🎰', 'title' => 'High Roller', 'desc' => 'Extreme swings in point totals', 'key' => 'high_roller'],
        ['icon' => '🎯', 'title' => 'Laser Focus', 'desc' => 'Within 5pts of average in 70%+ races', 'key' => 'laser_focus'],
        ['icon' => '🔄', 'title' => 'Groundhog Day', 'desc' => 'Same rank 4+ races in a row', 'key' => 'groundhog'],
    ],
    'progression' => [
        ['icon' => '📈', 'title' => 'Vertical Limit', 'desc' => 'Latest GP ≥15pts above season average', 'key' => 'vertical_limit'],
        ['icon' => '💤', 'title' => 'Sandbagger', 'desc' => 'Second half avg >12pts higher than first half', 'key' => 'sandbagger'],
        ['icon' => '🏔️', 'title' => 'Everest', 'desc' => 'Personal best 20+ pts above average', 'key' => 'everest'],
        ['icon' => '🎪', 'title' => 'Comeback Kid', 'desc' => 'Won GP after finishing last', 'key' => 'comeback_kid'],
        ['icon' => '🐢', 'title' => 'The Tortoise', 'desc' => 'Average finish rank ≥8 but still won a GP', 'key' => 'tortoise'],
    ],
    'special' => [
        ['icon' => '🎲', 'title' => 'Lucky 7', 'desc' => 'Finished 7th place in 3+ races', 'key' => 'lucky_7'],
        ['icon' => '🦅', 'title' => 'Perfect Landing', 'desc' => 'Every race on the podium (100% rate)', 'key' => 'perfect_landing'],
        ['icon' => '🐓', 'title' => 'Early Bird', 'desc' => 'Participated in the very first GP of this season', 'key' => 'early_bird'],
        ['icon' => '🥊', 'title' => 'Giant Killer', 'desc' => 'Finished ahead of the season leader in a GP', 'key' => 'giant_killer'],
        ['icon' => '⬛', 'title' => 'Black Box', 'desc' => 'The algorithm thinks you should be in the lead', 'key' => 'black_box'],
    ],
    'attendance' => [
        ['icon' => '🗓️', 'title' => 'Longevity', 'desc' => 'Highest attendance in the league', 'key' => 'longevity'],
        ['icon' => '🎖️', 'title' => 'Old Guard', 'desc' => 'Raced in the pre-season and returned', 'key' => 'old_guard'],
    ],
    'cups' => [
        ['icon' => '🏛️', 'title' => 'Base 12', 'desc' => 'Won all 12 Base Game cups', 'key' => 'base_12'],
        ['icon' => '🚀', 'title' => 'Booster\'s Dozen', 'desc' => 'Won all 12 Booster Course Pass cups', 'key' => 'boosters_dozen'],
        ['icon' => '🏅', 'title' => 'Cup Collector', 'desc' => 'Raced in all 24 MK8D cups (career)', 'key' => 'cup_collector'],
        ['icon' => '💎', 'title' => 'Perfectionist', 'desc' => 'Perfect 60 in 3+ different cups (career)', 'key' => 'perfectionist'],
    ],
    'characters' => [
        ['icon' => '🌸', 'title' => 'Princess Protocol', 'desc' => 'Mains Peach, Daisy, or Rosalina ≥50% of races', 'key' => 'princess_protocol'],
        ['icon' => '🏰', 'title' => 'Mushroom Kingdom', 'desc' => 'Raced as every core Mario-universe character (career)', 'key' => 'mushroom_kingdom'],
        ['icon' => '🗡️', 'title' => 'Link Main', 'desc' => 'Raced as Link in 5+ GPs this season', 'key' => 'link_main'],
        ['icon' => '🍄', 'title' => 'What a Fun Guy!', 'desc' => 'Mains Toad, Toadette, or Peachette ≥50% of races', 'key' => 'fun_guy'],
        ['icon' => '🧑', 'title' => 'That\'s Just a Person?', 'desc' => 'Mains Mii, Inklings, or Villager ≥50% of races', 'key' => 'just_a_person'],
        ['icon' => '🐱', 'title' => 'Furcurious!', 'desc' => 'Mains Tanooki Mario or Cat Peach ≥50% of races', 'key' => 'furcurious'],
        ['icon' => '😈', 'title' => 'Koopa Klan', 'desc' => 'Mains Bowser and his evil crew ≥50% of races', 'key' => 'koopa_klan'],
        ['icon' => '🦎', 'title' => 'Cold-Blooded', 'desc' => 'Mains reptilian characters (Yoshi, Koopa Troopa, Bowser and kin) ≥50% of races', 'key' => 'cold_blooded'],
    ],
    'streaks' => [
        ['icon' => '🎩', 'title' => 'Hat Trick', 'desc' => 'Won 3 Grand Prix events in a row', 'key' => 'hat_trick'],
        ['icon' => '↗️', 'title' => 'Ascendant', 'desc' => 'Improved finishing rank in 5 consecutive GPs', 'key' => 'ascendant'],
    ],
    'career' => [
        ['icon' => '🕰️', 'title' => 'The Elder', 'desc' => 'Competed across 3 or more seasons', 'key' => 'the_elder'],
    ],
    'elo' => [
        ['icon' => '🧗', 'title' => 'The Climber', 'desc' => 'Gained 100+ Elo points during a single season', 'key' => 'elo_climber'],
        ['icon' => '📉', 'title' => 'The Fall', 'desc' => 'Lost 100+ Elo points during a single season', 'key' => 'elo_fall'],
        ['icon' => '⚡', 'title' => 'Upset King', 'desc' => 'Finished ahead of a racer with 200+ higher Elo in 3+ GPs', 'key' => 'upset_king'],
        ['icon' => '🥶', 'title' => 'Stone Cold', 'desc' => 'Held the #1 Elo ranking for 5 consecutive GPs', 'key' => 'stone_cold'],
    ],
    'monster_hunt' => [
        ['icon' => '🐉', 'title' => 'Dragon Slayer', 'desc' => 'Finished ahead of a CR 4 Dragon in at least one GP', 'key' => 'mh_dragon_slayer'],
        ['icon' => '👹', 'title' => 'The Hunted', 'desc' => 'Designated as the Monster in 3+ GPs this season', 'key' => 'mh_hunted'],
        ['icon' => '🎉', 'title' => 'Wipe Master', 'desc' => 'Participated in a Full Slay (every adventurer beat the Monster) in 3+ GPs', 'key' => 'mh_wipe_master'],
        ['icon' => '💀', 'title' => 'Apex Predator', 'desc' => 'Achieved a TPK as the Monster (beat every adventurer) in 3+ GPs', 'key' => 'mh_apex'],
        ['icon' => '🌑', 'title' => 'The Underdog', 'desc' => 'Slew the Monster while being the lowest-Elo adventurer', 'key' => 'mh_underdog'],
        ['icon' => '🛡️', 'title' => 'Resilient', 'desc' => 'Survived without slaying the Monster in 5+ GPs', 'key' => 'mh_resilient'],
    ],
    'collection' => [
        ['icon' => '📦', 'title' => 'Wax Cracker', 'desc' => 'Cracked open your first sticker pack', 'key' => 'wax_cracker'],
        ['icon' => '🐀', 'title' => 'Pack Rat', 'desc' => 'Opened 25 or more sticker packs all-time', 'key' => 'pack_rat'],
        ['icon' => '🌗', 'title' => 'Halfway Hero', 'desc' => 'Collected at least half of the sticker album', 'key' => 'halfway_hero'],
        ['icon' => '📖', 'title' => 'Full Album', 'desc' => 'Collected every sticker in the album', 'key' => 'full_album'],
        ['icon' => '🥉', 'title' => 'Set Sweeper, Bronze', 'desc' => 'Completed a full sticker set', 'key' => 'set_sweeper_bronze'],
        ['icon' => '🥈', 'title' => 'Set Sweeper, Silver', 'desc' => 'Completed 3 full sticker sets', 'key' => 'set_sweeper_silver'],
        ['icon' => '🥇', 'title' => 'Set Sweeper, Gold', 'desc' => 'Completed 5 or more full sticker sets', 'key' => 'set_sweeper_gold'],
        ['icon' => '✨', 'title' => 'Foil Hunter', 'desc' => 'Owns five or more shiny foil cards', 'key' => 'foil_hunter'],
        ['icon' => '🍄', 'title' => 'Got the Bot', 'desc' => 'Pulled Kartificial #001, the chase foil', 'key' => 'got_the_bot'],
        ['icon' => '♻️', 'title' => 'Stuck With Dupes', 'desc' => 'Hoards 5+ copies of a single card', 'key' => 'stuck_with_dupes'],
        ['icon' => '📜', 'title' => 'Lore Keeper', 'desc' => 'Completed the Lore set', 'key' => 'lore_keeper'],
        ['icon' => '🐳', 'title' => 'Whale', 'desc' => 'Amassed 250+ total cards', 'key' => 'whale'],
    ],
    'honours' => [
        ['icon' => '🎖️', 'title' => 'Tournament Champion', 'desc' => 'Won a Kartfolio tournament', 'key' => 'tournament_champion'],
        ['icon' => '🐍', 'title' => 'Board Breaker', 'desc' => 'Won a Snakes & Ladders tournament', 'key' => 'board_breaker'],
        ['icon' => '🌍', 'title' => 'On Top of the World', 'desc' => 'Lifted the World Cup trophy', 'key' => 'on_top_of_the_world'],
        ['icon' => '🔮', 'title' => "Pick'em Oracle", 'desc' => "Topped a World Cup Pick'em leaderboard", 'key' => 'pickem_oracle'],
        ['icon' => '🌟', 'title' => 'Mikkoligan', 'desc' => 'Tops the Mikkoliiga this season', 'key' => 'mikkoligan'],
        ['icon' => '🧗', 'title' => 'Ascended', 'desc' => 'Reached a 2000 Elo rating', 'key' => 'ascended'],
    ],
];

// 3. Calculate badges and progress for all racers
$badgeData = [];
foreach ($racers as $racer) {
    $badges = getRacerBadges($pdo, $racer['id'], $currentSeason);
    $progress = calculateBadgeProgress($pdo, $racer['id'], $currentSeason);

    $badgeData[$racer['id']] = [
        'name' => $racer['name'],
        'badges' => $badges,
        'progress' => $progress
    ];
}

// Helper function to calculate progress towards each badge.
// All league-wide inputs read from badgeSeasonContext() and the shared
// season-results cache — this used to run ~13 queries per racer (500+ for a
// page of 36 racers); now the whole page shares one batched context.
function calculateBadgeProgress($pdo, $racer_id, $season_id) {
    $progress = [];
    $racer_id = (int)$racer_id;
    $ctx = badgeSeasonContext($pdo, $season_id);

    // Season rows from the shared cache (gp_points ASC), re-sorted to the
    // chronological order the old direct query used (race_date ASC, id ASC).
    $results = getRacerSeasonRows($pdo, $racer_id, $season_id);
    usort($results, function ($a, $b) {
        if ($a['race_date'] !== $b['race_date']) return strcmp($a['race_date'], $b['race_date']);
        return (int)$a['id'] <=> (int)$b['id'];
    });

    $totalRaces = count($results);
    if ($totalRaces < 3) {
        return ['insufficient_data' => true, 'races_needed' => 3 - $totalRaces];
    }

    // Calculate stats
    $lols = 0;
    $podiums = 0;
    $wins = 0;
    $seconds = 0;
    $fourths = 0;
    $perfect_games = 0;
    $total_gp_points = 0;
    $chars = [];
    $ranks = [];
    $gp_scores_by_date = [];
    $won_cups = [];
    $current_win_streak = 0;
    $max_win_streak = 0;
    $winning_chars = [];
    $standard_kart_count = 0;

    // Character groups from the canonical registry (gp_logic.php) — the same
    // lists badges.php awards from, so progress can't drift from the badges.
    $groups   = getCharacterGroups();
    $babies   = $groups['babies'];
    $heavies  = $groups['heavies'];
    $spooky   = $groups['spooky'];
    $og_stars = $groups['og_stars'];
    $baby_count = 0;
    $heavy_count = 0;
    $spooky_count = 0;
    $og_stars_count = 0;
    $bike_count = 0;
    $sevenths = 0;

    foreach ($results as $r) {
        $total_gp_points += $r['gp_points'];
        $gp_scores_by_date[] = $r['gp_points'];

        if ($r['is_lol']) $lols++;
        if ($r['rank'] <= 3) $podiums++;
        if ($r['rank'] == 1) {
            $wins++;
            $current_win_streak++;
            $winning_chars[] = $r['character_used'];
            $won_cups[] = $r['cup_name'];
        } else {
            $current_win_streak = 0;
        }
        if ($current_win_streak > $max_win_streak) $max_win_streak = $current_win_streak;

        if ($r['rank'] == 2) $seconds++;
        if ($r['rank'] == 4) $fourths++;
        if ($r['rank'] == 7) $sevenths++;
        if ($r['gp_points'] == 60) $perfect_games++;

        $chars[] = $r['character_used'];
        $ranks[] = $r['rank'];

        // Normalise colour variants ("Yoshi (Orange)" → "Yoshi") before group
        // checks — matches badges.php, which counted these correctly while
        // this page's raw comparison missed them.
        $charNorm = normalizeCharacterName($r['character_used']);
        if (in_array($charNorm, $babies)) $baby_count++;
        if (in_array($charNorm, $heavies)) $heavy_count++;
        if (in_array($charNorm, $spooky)) $spooky_count++;
        if (in_array($charNorm, $og_stars)) $og_stars_count++;
        if (stripos($r['kart_setup'] ?? '', 'Standard') !== false) $standard_kart_count++;
        if (stripos($r['kart_setup'] ?? '', 'Bike') !== false) $bike_count++;
    }

    $uniqueChars = count(array_unique($chars));
    $avgRank = array_sum($ranks) / $totalRaces;
    $seasonAvgPoints = $total_gp_points / $totalRaces;
    $won_cups_unique = array_unique($won_cups);

    // Calculate variance
    $variance = 0;
    foreach ($ranks as $r) {
        $variance += pow(($r - $avgRank), 2);
    }
    $stdDev = sqrt($variance / $totalRaces);

    $pointVariance = 0;
    foreach ($gp_scores_by_date as $pts) {
        $pointVariance += pow(($pts - $seasonAvgPoints), 2);
    }
    $pointStdDev = sqrt($pointVariance / $totalRaces);

    // Attendance check — from the shared context.
    $highestAttendance = $ctx['highestAttendance'];

    // Cup progress
    $baseCupsList    = MK_BASE_CUPS;
    $boosterCupsList = MK_BOOSTER_CUPS;

    // Build progress array
    $progress['slippery'] = ['current' => $lols, 'target' => 3, 'percent' => min(100, round(($lols / 3) * 100))];
    $progress['one_trick'] = ['current' => $uniqueChars, 'target' => 1, 'races' => $totalRaces, 'min_races' => 5, 'percent' => ($uniqueChars === 1 && $totalRaces >= 5) ? 100 : 0];
    $progress['identity_crisis'] = ['current' => $uniqueChars, 'target' => 5, 'percent' => min(100, round(($uniqueChars / 5) * 100))];
    $progress['podium_royalty'] = ['current' => round(($podiums / $totalRaces) * 100, 1), 'target' => 60, 'percent' => min(100, round((($podiums / $totalRaces) / 0.60) * 100))];
    $progress['the_wall'] = ['current' => round($avgRank, 2), 'target' => '4-7', 'percent' => ($avgRank >= 4 && $avgRank <= 7) ? 100 : 0];
    $progress['max_output'] = ['current' => $perfect_games, 'target' => 1, 'percent' => min(100, $perfect_games * 100)];
    $progress['bridesmaid'] = ['current' => round(($seconds / $totalRaces) * 100, 1), 'target' => 25, 'percent' => min(100, round((($seconds / $totalRaces) / 0.25) * 100))];
    $progress['fourth_wall'] = ['current' => round(($fourths / $totalRaces) * 100, 1), 'target' => 25, 'percent' => min(100, round((($fourths / $totalRaces) / 0.25) * 100))];
    $progress['hot_hand'] = ['current' => $max_win_streak, 'target' => 2, 'percent' => min(100, round(($max_win_streak / 2) * 100))];
    $progress['baby_driver'] = ['current' => round(($baby_count / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($baby_count / $totalRaces) / 0.50) * 100))];
    $progress['kaiju'] = ['current' => round(($heavy_count / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($heavy_count / $totalRaces) / 0.50) * 100))];
    $progress['the_anchor'] = ['current' => round($avgRank, 2), 'target' => '≥10', 'percent' => ($avgRank >= 10) ? 100 : min(100, round(($avgRank / 10) * 100))];
    $progress['jack_of_trades'] = ['current' => count(array_unique($winning_chars)), 'target' => 3, 'percent' => min(100, round((count(array_unique($winning_chars)) / 3) * 100))];
    $progress['purist'] = ['current' => round(($standard_kart_count / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($standard_kart_count / $totalRaces) / 0.50) * 100))];
    $progress['chaos'] = ['current' => round($stdDev, 2), 'target' => '>3.5', 'percent' => ($stdDev > 3.5) ? 100 : min(100, round(($stdDev / 3.5) * 100))];
    $progress['high_roller'] = ['current' => round($pointStdDev, 2), 'target' => '>15', 'percent' => ($pointStdDev > 15) ? 100 : min(100, round(($pointStdDev / 15) * 100))];

    // Vertical Limit
    $lastScore = end($gp_scores_by_date);
    $verticalDiff = $lastScore - $seasonAvgPoints;
    $progress['vertical_limit'] = ['current' => round($verticalDiff, 1), 'target' => '≥15', 'races' => $totalRaces, 'min_races' => 4, 'percent' => ($totalRaces >= 4 && $verticalDiff >= 15) ? 100 : min(100, max(0, round(($verticalDiff / 15) * 100)))];

    // Sandbagger
    if ($totalRaces >= 6) {
        $firstHalf = array_slice($gp_scores_by_date, 0, floor($totalRaces / 2));
        $secondHalf = array_slice($gp_scores_by_date, -floor($totalRaces / 2));
        $improvementDiff = (array_sum($secondHalf) / count($secondHalf)) - (array_sum($firstHalf) / count($firstHalf));
        $progress['sandbagger'] = ['current' => round($improvementDiff, 1), 'target' => '>12', 'races' => $totalRaces, 'min_races' => 6, 'percent' => ($improvementDiff > 12) ? 100 : min(100, max(0, round(($improvementDiff / 12) * 100)))];
    } else {
        $progress['sandbagger'] = ['current' => 0, 'target' => '>12', 'races' => $totalRaces, 'min_races' => 6, 'percent' => 0];
    }

    $progress['longevity'] = ['current' => $totalRaces, 'target' => $highestAttendance, 'percent' => ($totalRaces >= $highestAttendance && $highestAttendance > 0) ? 100 : ($highestAttendance > 0 ? round(($totalRaces / $highestAttendance) * 100) : 0)];

    // Cup badges
    $baseCupsWon = array_intersect($baseCupsList, $won_cups_unique);
    $boosterCupsWon = array_intersect($boosterCupsList, $won_cups_unique);
    $progress['base_12'] = ['current' => count($baseCupsWon), 'target' => 12, 'percent' => min(100, round((count($baseCupsWon) / 12) * 100)), 'missing' => array_diff($baseCupsList, $won_cups_unique)];
    $progress['boosters_dozen'] = ['current' => count($boosterCupsWon), 'target' => 12, 'percent' => min(100, round((count($boosterCupsWon) / 12) * 100)), 'missing' => array_diff($boosterCupsList, $won_cups_unique)];

    // NEW BADGES PROGRESS

    // Ghost Rider
    $progress['ghost_rider'] = ['current' => round(($spooky_count / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($spooky_count / $totalRaces) / 0.50) * 100))];

    // Star Power
    $progress['star_power'] = ['current' => round(($og_stars_count / $totalRaces) * 100, 1), 'target' => 60, 'percent' => min(100, round((($og_stars_count / $totalRaces) / 0.60) * 100))];

    // Bike Brigade
    $progress['bike_brigade'] = ['current' => round(($bike_count / $totalRaces) * 100, 1), 'target' => 60, 'percent' => min(100, round((($bike_count / $totalRaces) / 0.60) * 100))];

    // Laser Focus
    $withinFiveCount = 0;
    foreach ($gp_scores_by_date as $pts) {
        if (abs($pts - $seasonAvgPoints) <= 5) {
            $withinFiveCount++;
        }
    }
    $laserPercent = round(($withinFiveCount / $totalRaces) * 100, 1);
    $progress['laser_focus'] = ['current' => $laserPercent, 'target' => '70% + Low Variance', 'percent' => min(100, round(($laserPercent / 70) * 100))];

    // Everest
    $peakDiff = max($gp_scores_by_date) - $seasonAvgPoints;
    $progress['everest'] = ['current' => round($peakDiff, 1), 'target' => '≥20', 'percent' => min(100, max(0, round(($peakDiff / 20) * 100)))];

    // Comeback Kid (binary - either happened or not)
    $comebackDetected = false;
    for ($i = 1; $i < count($results); $i++) {
        if ($results[$i]['rank'] == 1 && $results[$i-1]['rank'] >= 10) {
            $comebackDetected = true;
            break;
        }
    }
    $progress['comeback_kid'] = ['current' => $comebackDetected ? 'Yes' : 'No', 'target' => 'Yes', 'percent' => $comebackDetected ? 100 : 0];

    // Groundhog Day
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
    $progress['groundhog'] = ['current' => $maxIdenticalStreak, 'target' => 4, 'percent' => min(100, round(($maxIdenticalStreak / 4) * 100))];

    // Lucky 7
    $progress['lucky_7'] = ['current' => $sevenths, 'target' => 3, 'percent' => min(100, round(($sevenths / 3) * 100))];

    // Perfect Landing
    $podiumRate = round(($podiums / $totalRaces) * 100, 1);
    $progress['perfect_landing'] = ['current' => $podiumRate . '%', 'target' => '100%', 'races' => $totalRaces, 'min_races' => 5, 'percent' => ($totalRaces >= 5 && $podiumRate == 100) ? 100 : min(100, round($podiumRate))];

    // The Tortoise
    $progress['tortoise'] = ['current' => round($avgRank, 2) . ' avg rank, ' . $wins . ' win(s)', 'target' => 'Avg ≥8 + 1 win', 'percent' => ($avgRank >= 8.0 && $wins >= 1) ? 100 : 0];

    // Early Bird — participated in the first GP of this season (from context).
    $inFirstGp = isset($ctx['firstGpRacers'][$racer_id]);
    $progress['early_bird'] = ['current' => $inFirstGp ? 'Yes' : 'No', 'target' => 'Yes', 'percent' => $inFirstGp ? 100 : 0];

    // Giant Killer (binary) — leader + beat-the-leader set from context.
    $giantKiller = ($ctx['leaderId'] && $ctx['leaderId'] !== $racer_id) && isset($ctx['beatLeader'][$racer_id]);
    $progress['giant_killer'] = ['current' => $giantKiller ? 'Yes' : 'No', 'target' => 'Yes', 'percent' => $giantKiller ? 100 : 0];

    // Black Box — leader of the Black Box scoring system
    static $bbScoresCache = null;
    if ($bbScoresCache === null) {
        require_once __DIR__ . '/../private/includes/gp_logic.php';
        $bbAllStmt = $pdo->prepare("SELECT DISTINCT racer_id FROM results WHERE gpid LIKE ? AND gpid LIKE 's%'");
        $bbAllStmt->execute([$season_id . '%']);
        $bbAllRacers = $bbAllStmt->fetchAll(PDO::FETCH_COLUMN);
        $bbScoresCache = [];
        foreach ($bbAllRacers as $rid) {
            $score = calculateBlackBoxScore($pdo, $rid, $season_id, []);
            if ($score > 0) $bbScoresCache[$rid] = $score;
        }
        arsort($bbScoresCache);
    }
    $bbLeaderId = !empty($bbScoresCache) ? array_key_first($bbScoresCache) : null;
    $isBlackBoxLeader = ($bbLeaderId !== null && (int)$bbLeaderId === (int)$racer_id);
    $myBBScore = $bbScoresCache[$racer_id] ?? 0;
    $topBBScore = !empty($bbScoresCache) ? reset($bbScoresCache) : 0;
    $bbPercent = ($topBBScore > 0 && $myBBScore > 0) ? min(100, round(($myBBScore / $topBBScore) * 100)) : 0;
    $progress['black_box'] = ['current' => $isBlackBoxLeader ? 'Leader' : round($myBBScore, 1), 'target' => 'Lead', 'percent' => $isBlackBoxLeader ? 100 : $bbPercent];

    // Old Guard — pre-season attendance from context.
    $hasPreseason = ($ctx['prevSeasonCount'][$racer_id] ?? 0) > 0;
    $progress['old_guard'] = ['current' => $hasPreseason ? 'Pre-season ✓' : 'No pre-season races', 'target' => 'Pre-season + active', 'percent' => ($hasPreseason && $season_id !== 's00') ? 100 : ($hasPreseason ? 50 : 0)];

    // Cup Collector (career — all 24 cups raced) — from context.
    $allCupsList = getMKAllCups();
    $careerCupsRaced = $ctx['careerCups'][$racer_id] ?? [];
    $cupsRacedCount = count(array_intersect($allCupsList, $careerCupsRaced));
    $progress['cup_collector'] = ['current' => $cupsRacedCount, 'target' => 24, 'percent' => min(100, round(($cupsRacedCount / 24) * 100)), 'missing' => array_diff($allCupsList, $careerCupsRaced)];

    // Perfectionist (career — perfect 60 in 3+ distinct cups) — from context.
    $careerPerfectCups = $ctx['careerPerfectCups'][$racer_id] ?? [];
    $progress['perfectionist'] = ['current' => count($careerPerfectCups), 'target' => 3, 'percent' => min(100, round((count($careerPerfectCups) / 3) * 100))];

    // Princess Protocol
    $royalCount = 0;
    foreach ($results as $r) { if (in_array(normalizeCharacterName($r['character_used']), $groups['royals'])) $royalCount++; }
    $progress['princess_protocol'] = ['current' => round(($royalCount / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($royalCount / $totalRaces) / 0.50) * 100))];

    // Mushroom Kingdom (career — all 19 core chars) — from context, with the
    // same colour-variant normalisation badges.php awards with.
    $marioUniverse = ['Mario','Luigi','Peach','Daisy','Rosalina','Toad','Toadette','Yoshi','Birdo','Wario','Waluigi','Donkey Kong','Bowser','Bowser Jr.','Baby Mario','Baby Luigi','Baby Peach','Baby Daisy','Baby Rosalina'];
    $careerChars = array_unique(array_map('normalizeCharacterName', $ctx['careerChars'][$racer_id] ?? []));
    $charsFound = count(array_intersect($marioUniverse, $careerChars));
    $progress['mushroom_kingdom'] = ['current' => $charsFound, 'target' => count($marioUniverse), 'percent' => min(100, round(($charsFound / count($marioUniverse)) * 100)), 'missing' => array_diff($marioUniverse, $careerChars)];

    // Link Main
    $linkCount = 0;
    foreach ($results as $r) { if ($r['character_used'] === 'Link') $linkCount++; }
    $progress['link_main'] = ['current' => $linkCount, 'target' => 5, 'percent' => min(100, round(($linkCount / 5) * 100))];

    // What a Fun Guy! (Toad, Toadette, Peachette)
    $fungiCount = 0;
    foreach ($results as $r) { if (in_array(normalizeCharacterName($r['character_used']), $groups['fungi'])) $fungiCount++; }
    $progress['fun_guy'] = ['current' => round(($fungiCount / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($fungiCount / $totalRaces) / 0.50) * 100))];

    // That's Just a Person? (Mii, Inklings, Villager)
    $humanCount = 0;
    foreach ($results as $r) { if (in_array(normalizeCharacterName($r['character_used']), $groups['humans'])) $humanCount++; }
    $progress['just_a_person'] = ['current' => round(($humanCount / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($humanCount / $totalRaces) / 0.50) * 100))];

    // Furcurious! (Tanooki Mario, Cat Peach)
    $furryCount = 0;
    foreach ($results as $r) { if (in_array(normalizeCharacterName($r['character_used']), $groups['furry'])) $furryCount++; }
    $progress['furcurious'] = ['current' => round(($furryCount / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($furryCount / $totalRaces) / 0.50) * 100))];

    // Koopa Klan (Bowser, Dry Bowser, Bowser Jr., Koopa Troopa, Lakitu, Koopalings)
    $koopaCount = 0;
    foreach ($results as $r) { if (in_array(normalizeCharacterName($r['character_used']), $groups['koopa_clan'])) $koopaCount++; }
    $progress['koopa_klan'] = ['current' => round(($koopaCount / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($koopaCount / $totalRaces) / 0.50) * 100))];

    // Cold-Blooded (reptilian characters — normalisation matters here: the
    // league's coloured Yoshis count as Yoshi, exactly as badges.php awards)
    $reptileCount = 0;
    foreach ($results as $r) { if (in_array(normalizeCharacterName($r['character_used']), $groups['reptiles'])) $reptileCount++; }
    $progress['cold_blooded'] = ['current' => round(($reptileCount / $totalRaces) * 100, 1), 'target' => 50, 'percent' => min(100, round((($reptileCount / $totalRaces) / 0.50) * 100))];

    // Hat Trick (3-win streak)
    $curWinStreak = $maxWinStreak = 0;
    foreach ($results as $r) {
        if ($r['rank'] == 1) { $curWinStreak++; $maxWinStreak = max($maxWinStreak, $curWinStreak); }
        else $curWinStreak = 0;
    }
    $progress['hat_trick'] = ['current' => $maxWinStreak, 'target' => 3, 'percent' => min(100, round(($maxWinStreak / 3) * 100))];

    // Ascendant (5 consecutive rank improvements)
    $maxImprove = 1; $curImprove = 1;
    for ($i = 1; $i < count($ranks); $i++) {
        if ($ranks[$i] < $ranks[$i - 1]) { $curImprove++; $maxImprove = max($maxImprove, $curImprove); }
        else $curImprove = 1;
    }
    $progress['ascendant'] = ['current' => $maxImprove, 'target' => 5, 'percent' => min(100, round(($maxImprove / 5) * 100))];

    // The Elder (3+ seasons) — from context.
    $distinctSeasons = (int)($ctx['seasonsPlayed'][$racer_id] ?? 0);
    $progress['the_elder'] = ['current' => $distinctSeasons, 'target' => 3, 'percent' => min(100, round(($distinctSeasons / 3) * 100))];

    // ── ELO progress ──────────────────────────────────────────────────────────
    if (!function_exists('calculateAllELORatings')) require_once __DIR__ . '/../private/includes/elo_engine.php';
    $racerName2 = $ctx['racerNames'][$racer_id] ?? null;

    // Defaults
    $progress['elo_climber'] = ['current' => 0, 'target' => 100, 'percent' => 0];
    $progress['elo_fall']    = ['current' => 0, 'target' => -100, 'percent' => 0];
    $progress['upset_king']  = ['current' => 0, 'target' => 3, 'percent' => 0];
    $progress['stone_cold']  = ['current' => 0, 'target' => 5, 'percent' => 0];
    $progress['mh_dragon_slayer'] = ['current' => 'No', 'target' => 'Yes', 'percent' => 0];
    $progress['mh_hunted']        = ['current' => 0, 'target' => 3, 'percent' => 0];
    $progress['mh_wipe_master']   = ['current' => 0, 'target' => 3, 'percent' => 0];
    $progress['mh_apex']          = ['current' => 0, 'target' => 3, 'percent' => 0];
    $progress['mh_underdog']      = ['current' => 'No', 'target' => 'Yes', 'percent' => 0];
    $progress['mh_resilient']     = ['current' => 0, 'target' => 5, 'percent' => 0];

    if ($racerName2) {
        static $changelogCache2 = null;
        if ($changelogCache2 === null) $changelogCache2 = getMonsterHuntEloChangelog($pdo);
        $cl = $changelogCache2;

        // Season Elo delta
        $seasonEloMap = [];
        foreach ($cl as $gpid => $gpData) {
            if (strpos($gpid, $season_id) !== 0) continue;
            if (!isset($gpData[$racerName2])) continue;
            $seasonEloMap[$gpid] = $gpData[$racerName2]['old_elo'];
        }
        ksort($seasonEloMap);
        if (count($seasonEloMap) >= 2) {
            $eloArr  = array_values($seasonEloMap);
            $delta   = end($eloArr) - $eloArr[0];
            $progress['elo_climber'] = ['current' => $delta, 'target' => 100, 'percent' => $delta > 0 ? min(100, round(($delta / 100) * 100)) : 0];
            $progress['elo_fall']    = ['current' => $delta, 'target' => -100, 'percent' => $delta < 0 ? min(100, round((abs($delta) / 100) * 100)) : 0];
        }

        // Upset King
        $upsets = 0;
        foreach ($cl as $gpid => $gpData) {
            if (strpos($gpid, $season_id) !== 0) continue;
            if (!isset($gpData[$racerName2])) continue;
            $myE = $gpData[$racerName2]['old_elo']; $myR = $gpData[$racerName2]['rank'];
            foreach ($gpData as $on => $od) {
                if ($on === $racerName2) continue;
                if ($od['old_elo'] >= $myE + 200 && $myR < $od['rank']) { $upsets++; break; }
            }
        }
        $progress['upset_king'] = ['current' => $upsets, 'target' => 3, 'percent' => min(100, round(($upsets / 3) * 100))];

        // Stone Cold
        $eloLeaders = [];
        foreach ($cl as $gpid => $gpData) {
            if (strpos($gpid, $season_id) !== 0) continue;
            $top = PHP_INT_MIN; $topN = null;
            foreach ($gpData as $n => $d) { if ($d['old_elo'] > $top) { $top = $d['old_elo']; $topN = $n; } }
            $eloLeaders[$gpid] = $topN;
        }
        ksort($eloLeaders);
        $scS = 0; $scM = 0;
        foreach ($eloLeaders as $topN) {
            if ($topN === $racerName2) { $scS++; $scM = max($scM, $scS); } else $scS = 0;
        }
        $progress['stone_cold'] = ['current' => $scM, 'target' => 5, 'percent' => min(100, round(($scM / 5) * 100))];

        // MONSTER HUNT progress (only relevant for MH seasons)
        if ($ctx['scoringSystem'] === 'monster_hunt') {
            $mhDragon = false; $mhHunted = 0; $mhWipe = 0;
            $mhApex = 0; $mhUnder = false; $mhSurv = 0;

            foreach ($cl as $gpid => $gpData) {
                if (strpos($gpid, $season_id) !== 0) continue;
                if (!isset($gpData[$racerName2])) continue;
                if (count($gpData) < 2) continue;

                $monN = null; $monE = PHP_INT_MIN;
                foreach ($gpData as $n => $d) {
                    if ($d['old_elo'] > $monE || ($d['old_elo'] === $monE && strcmp($n, $monN) < 0)) {
                        $monE = $d['old_elo']; $monN = $n;
                    }
                }
                $monR = $gpData[$monN]['rank'];
                $advE = [];
                foreach ($gpData as $n => $d) { if ($n !== $monN) $advE[] = $d['old_elo']; }
                $gap = max(0, $monE - (count($advE) ? array_sum($advE) / count($advE) : $monE));
                $cr  = $gap < 50 ? 1 : ($gap < 150 ? 2 : ($gap < 300 ? 3 : 4));

                $aW = $aL = 0;
                foreach ($gpData as $n => $d) {
                    if ($n === $monN) continue;
                    if ($d['rank'] < $monR) $aW++; else $aL++;
                }
                $wipe = ($aL === 0 && $aW > 0);
                $mWon = ($aW === 0);

                if ($racerName2 === $monN) {
                    $mhHunted++;
                    if ($mWon) $mhApex++;
                } else {
                    $myR2 = $gpData[$racerName2]['rank'];
                    if ($myR2 < $monR) {
                        if ($cr === 4) $mhDragon = true;
                        if ($wipe) $mhWipe++;
                        if (!$mhUnder) {
                            $myE2 = $gpData[$racerName2]['old_elo'];
                            $low  = true;
                            foreach ($gpData as $n => $d) {
                                if ($n === $monN || $n === $racerName2) continue;
                                if ($d['old_elo'] < $myE2) { $low = false; break; }
                            }
                            if ($low) $mhUnder = true;
                        }
                    } else {
                        $mhSurv++;
                    }
                }
            }

            $progress['mh_dragon_slayer'] = ['current' => $mhDragon ? 'Yes' : 'No', 'target' => 'Yes', 'percent' => $mhDragon ? 100 : 0];
            $progress['mh_hunted']        = ['current' => $mhHunted, 'target' => 3, 'percent' => min(100, round(($mhHunted / 3) * 100))];
            $progress['mh_wipe_master']   = ['current' => $mhWipe,   'target' => 3, 'percent' => min(100, round(($mhWipe   / 3) * 100))];
            $progress['mh_apex']          = ['current' => $mhApex,   'target' => 3, 'percent' => min(100, round(($mhApex   / 3) * 100))];
            $progress['mh_underdog']      = ['current' => $mhUnder ? 'Yes' : 'No', 'target' => 'Yes', 'percent' => $mhUnder ? 100 : 0];
            $progress['mh_resilient']     = ['current' => $mhSurv,   'target' => 5, 'percent' => min(100, round(($mhSurv   / 5) * 100))];
        }
    }

    return $progress;
}

// Helper to check if racer has badge
function hasBadge($badges, $title) {
    foreach ($badges as $badge) {
        if ($badge['title'] === $title) return true;
    }
    return false;
}
?>


<div class="badge-overview-container">
    <div class="badge-overview-header">
        <h1 class="badge-overview-title">🏅 Badge Encyclopedia</h1>
        <div class="season-selector">
            <form method="GET" action="/badges_overview" class="season-selector-form">
                <label for="seasonSelect" class="season-selector-label">Season:</label>
                <select name="season" id="seasonSelect" onchange="this.form.submit()" class="season-selector-select">
                    <?php foreach ($availableSeasons as $season):
                        $label = 'Season ' . strtoupper($season['season_id']) . ($season['status'] === 'archived' ? ' (Archived)' : '');
                    ?>
                        <option value="<?= htmlspecialchars($season['season_id']) ?>" <?= ($season['season_id'] === $currentSeason) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="all" <?= $isAllTime ? 'selected' : '' ?>>All-Time</option>
                </select>
            </form>
        </div>
    </div>

    <!-- Unique Awards Section -->
    <?php
    // Check if any racer has unique badges
    $hasUniqueAwards = false;
    $uniqueAwardHolders = [];
    foreach ($racers as $racer) {
        $uniqueBadges = getUniqueBadges($pdo, $racer['id'], $currentSeason);
        if (!empty($uniqueBadges)) {
            $hasUniqueAwards = true;
            $uniqueAwardHolders[$racer['id']] = [
                'name' => $racer['name'],
                'badges' => $uniqueBadges
            ];
        }
    }

    if ($hasUniqueAwards):
    ?>
    <div class="badge-category">
        <h2>🌟 Unique Awards</h2>
        <div class="badge-card">
            <div class="badge-header">
                <div class="badge-info">
                    <h3>Special Recognition Awards</h3>
                    <p>One-of-a-kind honors bestowed upon exceptional individuals</p>
                </div>
            </div>

            <div class="badge-holders">
                <?php foreach ($uniqueAwardHolders as $racerId => $holder): ?>
                    <?php foreach ($holder['badges'] as $badge): ?>
                        <div class="holder-card earned holder-card-centered">
                            <img src="<?= htmlspecialchars($badge['img']) ?>" alt="<?= htmlspecialchars($badge['title']) ?>" class="unique-badge-img">
                            <div class="holder-name"><?= htmlspecialchars($holder['name']) ?></div>
                            <div class="unique-badge-title">
                                <?= htmlspecialchars($badge['title']) ?>
                            </div>
                            <div class="holder-status">
                                <?= htmlspecialchars($badge['desc']) ?>
                            </div>
                            <div class="unique-badge-awarded">
                                ✅ <strong>Awarded!</strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($allBadgeDefinitions as $categoryName => $badges): ?>
        <div class="badge-category">
            <h2><?= ucfirst($categoryName) ?> Badges</h2>

            <?php foreach ($badges as $badgeDef): ?>
                <div class="badge-card">
                    <div class="badge-header">
                        <div class="badge-icon"><?= $badgeDef['icon'] ?></div>
                        <div class="badge-info">
                            <h3><?= htmlspecialchars($badgeDef['title']) ?></h3>
                            <p><?= htmlspecialchars($badgeDef['desc']) ?></p>
                        </div>
                    </div>

                    <div class="badge-holders">
                        <?php
                        $hasHolders = false;
                        foreach ($badgeData as $racerId => $data):
                            $earned = hasBadge($data['badges'], $badgeDef['title']);
                            $prog = $data['progress'][$badgeDef['key']] ?? null;

                            // Skip if no progress data and not earned
                            if (!$earned && (!$prog || (isset($prog['percent']) && $prog['percent'] < 5))) continue;

                            $hasHolders = true;
                        ?>
                            <div class="holder-card <?= $earned ? 'earned' : 'in-progress' ?>">
                                <div class="holder-name"><?= htmlspecialchars($data['name']) ?></div>
                                <div class="holder-status">
                                    <?php if ($earned): ?>
                                        ✅ <strong>Earned!</strong>
                                    <?php elseif (isset($prog['insufficient_data'])): ?>
                                        Need <?= $prog['races_needed'] ?> more race<?= $prog['races_needed'] > 1 ? 's' : '' ?>
                                    <?php elseif (isset($prog['min_races']) && $prog['races'] < $prog['min_races']): ?>
                                        Need <?= $prog['min_races'] - $prog['races'] ?> more race<?= ($prog['min_races'] - $prog['races']) > 1 ? 's' : '' ?>
                                    <?php else: ?>
                                        <?= $prog['current'] ?? '?' ?> / <?= $prog['target'] ?? '?' ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$earned && $prog): ?>
                                    <div class="progress-bar">
                                        <div class="progress-fill <?= $prog['percent'] >= 100 ? 'earned' : '' ?>" style="width: <?= min(100, $prog['percent']) ?>%;"></div>
                                    </div>
                                    <div class="progress-text"><?= round($prog['percent']) ?>% complete</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if (!$hasHolders): ?>
                            <div class="no-holders">No one has earned this badge yet, and no one is close to earning it.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
