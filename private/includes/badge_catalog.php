<?php
/**
 * THE BADGE CATALOGUE — the single source of truth for every badge's icon,
 * title, earned text and criterion. badges.php emits badgeDef('key'); the
 * /badges overview renders badgeCatalog() grouped by category. This used to be
 * declared twice (91 entries in each file, kept in sync by hand).
 *
 * Keep icons unique: the inventory check in the test checklist greps this file.
 */

function badgeCategoryOrder(): array {
    return ['performance', 'playstyle', 'volatility', 'progression', 'special', 'attendance', 'cups', 'characters', 'streaks', 'career', 'elo', 'monster_hunt', 'collection', 'honours', 'territory', 'legacy'];
}

/** key => ['icon','title','desc' (earned text),'criteria' (short rule for the overview),'category'] */
function badgeCatalog(): array {
    static $c = null;
    if ($c !== null) return $c;
    return $c = [
        'podium_royalty' => ['icon' => '👑', 'title' => 'Podium Royalty', 'desc' => 'Finishes in the Top 3 over 60% of the time.', 'criteria' => 'Finishes in the Top 3 over 60% of the time', 'category' => 'performance'],
        'max_output' => ['icon' => '🤖', 'title' => 'Max Output', 'desc' => 'Achieved a perfect 60-point Grand Prix.', 'criteria' => 'Achieved a perfect 60-point Grand Prix', 'category' => 'performance'],
        'bridesmaid' => ['icon' => '💐', 'title' => 'The Bridesmaid', 'desc' => 'Finishes 2nd place >25% of the time.', 'criteria' => 'Finishes 2nd place >25% of the time', 'category' => 'performance'],
        'fourth_wall' => ['icon' => '4️⃣', 'title' => 'The Fourth Wall', 'desc' => 'Stuck in the cursed 4th place position >25% of the time.', 'criteria' => 'Stuck in 4th place >25% of the time', 'category' => 'performance'],
        'hot_hand' => ['icon' => '🔥', 'title' => 'Hot Hand', 'desc' => 'Won back-to-back Grand Prix events.', 'criteria' => 'Won back-to-back Grand Prix events', 'category' => 'performance'],
        'the_wall' => ['icon' => '🧱', 'title' => 'The Wall', 'desc' => 'Consistently holds the midfield, another brick in the wall.', 'criteria' => 'Consistently holds the midfield (avg rank 4-7)', 'category' => 'performance'],
        'the_anchor' => ['icon' => '⚓', 'title' => 'The Anchor', 'desc' => 'For the ship to remain stable, someone needs to be at the bottom.', 'criteria' => 'Average rank ≥10', 'category' => 'performance'],
        'one_trick' => ['icon' => '🎠', 'title' => 'One-Trick Pony', 'desc' => 'Has never changed their character.', 'criteria' => 'Never changed character (5+ races)', 'category' => 'playstyle'],
        'identity_crisis' => ['icon' => '🎭', 'title' => 'Identity Crisis', 'desc' => 'Played 5+ different characters this season.', 'criteria' => 'Played 5+ different characters', 'category' => 'playstyle'],
        'jack_of_trades' => ['icon' => '🃏', 'title' => 'Jack of All Trades', 'desc' => 'Won a GP with 3 different characters.', 'criteria' => 'Won a GP with 3 different characters', 'category' => 'playstyle'],
        'baby_driver' => ['icon' => '🍼', 'title' => 'Baby Driver', 'desc' => 'Mains Baby characters over 50% of the time.', 'criteria' => 'Mains Baby characters >50% of the time', 'category' => 'playstyle'],
        'kaiju' => ['icon' => '🦖', 'title' => 'Kaiju Protocol', 'desc' => 'Mains heavyweights over 50% of the time.', 'criteria' => 'Mains heavyweights >50% of the time', 'category' => 'playstyle'],
        'purist' => ['icon' => '🔰', 'title' => 'The Purist', 'desc' => 'Refuses to use meta vehicles; prefers Standard setups.', 'criteria' => 'Uses Standard setups >50% of the time', 'category' => 'playstyle'],
        'ghost_rider' => ['icon' => '👻', 'title' => 'Ghost Rider', 'desc' => 'Mains spooky characters (Boo, Dry Bones, King Boo) 50%+ of the time.', 'criteria' => 'Mains spooky characters >50% of the time', 'category' => 'playstyle'],
        'star_power' => ['icon' => '⭐', 'title' => 'Star Power', 'desc' => 'Mains original Nintendo stars (Mario, Luigi, Peach, Daisy) 60%+ of the time.', 'criteria' => 'Mains original stars >60% of the time', 'category' => 'playstyle'],
        'bike_brigade' => ['icon' => '🏍️', 'title' => 'Bike Brigade', 'desc' => 'Uses bikes (not karts) in 60%+ of races.', 'criteria' => 'Uses bikes >60% of the time', 'category' => 'playstyle'],
        'slippery' => ['icon' => '🍌', 'title' => 'Slippery Slope', 'desc' => 'Triggered the "LOL" obstruction frequently.', 'criteria' => 'Triggered LOL obstruction 3+ times', 'category' => 'volatility'],
        'chaos' => ['icon' => '🎢', 'title' => 'Chaos Agent', 'desc' => 'Highly inconsistent results (High variance).', 'criteria' => 'Highly inconsistent rank results', 'category' => 'volatility'],
        'high_roller' => ['icon' => '🎰', 'title' => 'High Roller', 'desc' => 'Extreme swings in point totals between events.', 'criteria' => 'Extreme swings in point totals', 'category' => 'volatility'],
        'laser_focus' => ['icon' => '🎯', 'title' => 'Laser Focus', 'desc' => 'Finished within 5 points of season average in 70%+ of races.', 'criteria' => 'Within 5pts of average in 70%+ races', 'category' => 'volatility'],
        'groundhog' => ['icon' => '🔄', 'title' => 'Groundhog Day', 'desc' => 'Finished in the exact same rank 4+ races in a row.', 'criteria' => 'Same rank 4+ races in a row', 'category' => 'volatility'],
        'vertical_limit' => ['icon' => '📈', 'title' => 'Vertical Limit', 'desc' => 'Latest performance was significantly higher than season average.', 'criteria' => 'Latest GP ≥15pts above season average', 'category' => 'progression'],
        'sandbagger' => ['icon' => '💤', 'title' => 'Sandbagger', 'desc' => 'Started the season poorly but finished much stronger.', 'criteria' => 'Second half avg >12pts higher than first half', 'category' => 'progression'],
        'everest' => ['icon' => '🏔️', 'title' => 'Everest', 'desc' => 'Achieved a personal best 20+ points above season average.', 'criteria' => 'Personal best 20+ pts above average', 'category' => 'progression'],
        'comeback_kid' => ['icon' => '🎪', 'title' => 'Comeback Kid', 'desc' => 'Won a GP immediately after finishing in last place.', 'criteria' => 'Won GP after finishing last', 'category' => 'progression'],
        'tortoise' => ['icon' => '🐢', 'title' => 'The Tortoise', 'desc' => 'Average finish of 8th or worse, yet still managed to win a GP.', 'criteria' => 'Average finish rank ≥8 but still won a GP', 'category' => 'progression'],
        'purple_patch' => ['icon' => '🟣', 'title' => 'Purple Patch', 'desc' => 'Recent form (last 8 GPs) running 10+ points above their season average.', 'criteria' => 'Last-8 form 10+ pts above season average (12+ GPs)', 'category' => 'progression'],
        'lucky_7' => ['icon' => '🎲', 'title' => 'Lucky 7', 'desc' => 'Finished in 7th place in 3+ races.', 'criteria' => 'Finished 7th place in 3+ races', 'category' => 'special'],
        'perfect_landing' => ['icon' => '🦅', 'title' => 'Perfect Landing', 'desc' => 'Every race finish was on the podium (100% podium rate).', 'criteria' => 'Every race on the podium (100% rate)', 'category' => 'special'],
        'early_bird' => ['icon' => '🐓', 'title' => 'Early Bird', 'desc' => 'Participated in the very first GP of this season.', 'criteria' => 'Participated in the very first GP of this season', 'category' => 'special'],
        'giant_killer' => ['icon' => '🥊', 'title' => 'Giant Killer', 'desc' => 'Finished ahead of the current season leader in at least one GP.', 'criteria' => 'Finished ahead of the season leader in a GP', 'category' => 'special'],
        'black_box' => ['icon' => '⬛', 'title' => 'Black Box', 'desc' => 'The algorithm thinks you should be in the lead.', 'criteria' => 'The algorithm thinks you should be in the lead', 'category' => 'special'],
        'longevity' => ['icon' => '🗓️', 'title' => 'Longevity', 'desc' => 'Highest attendance record in the league.', 'criteria' => 'Highest attendance in the league', 'category' => 'attendance'],
        'old_guard' => ['icon' => '🎖️', 'title' => 'Old Guard', 'desc' => 'A veteran who raced in the pre-season and has returned.', 'criteria' => 'Raced in the pre-season and returned', 'category' => 'attendance'],
        'base_12' => ['icon' => '🏛️', 'title' => 'Base 12', 'desc' => 'Has won a Grand Prix in all 12 Base Game cups.', 'criteria' => 'Won all 12 Base Game cups', 'category' => 'cups'],
        'boosters_dozen' => ['icon' => '🚀', 'title' => 'Booster\'s Dozen', 'desc' => 'Has won a Grand Prix in all 12 Booster Course Pass cups.', 'criteria' => 'Won all 12 Booster Course Pass cups', 'category' => 'cups'],
        'cup_collector' => ['icon' => '🏅', 'title' => 'Cup Collector', 'desc' => 'Has raced in all 24 Mario Kart 8 Deluxe cups across their career.', 'criteria' => 'Raced in all 24 MK8D cups (career)', 'category' => 'cups'],
        'perfectionist' => ['icon' => '💎', 'title' => 'Perfectionist', 'desc' => 'Achieved a perfect 60 in 3+ different cups across their career.', 'criteria' => 'Perfect 60 in 3+ different cups (career)', 'category' => 'cups'],
        'princess_protocol' => ['icon' => '🌸', 'title' => 'Princess Protocol', 'desc' => 'Mains royalty (Peach, Daisy, or Rosalina) in 50%+ of races.', 'criteria' => 'Mains Peach, Daisy, or Rosalina ≥50% of races', 'category' => 'characters'],
        'mushroom_kingdom' => ['icon' => '🏰', 'title' => 'Mushroom Kingdom', 'desc' => 'Has raced as every core Mario-universe character at their disposal.', 'criteria' => 'Raced as every core Mario-universe character (career)', 'category' => 'characters'],
        'link_main' => ['icon' => '🗡️', 'title' => 'Link Main', 'desc' => 'Raced as Link in 5 or more GPs this season. Hyah!', 'criteria' => 'Raced as Link in 5+ GPs this season', 'category' => 'characters'],
        'fun_guy' => ['icon' => '🍄', 'title' => 'What a Fun Guy!', 'desc' => 'Mains Toad, Toadette, or Peachette in 50%+ of races. A real fungi.', 'criteria' => 'Mains Toad, Toadette, or Peachette ≥50% of races', 'category' => 'characters'],
        'just_a_person' => ['icon' => '🧑', 'title' => 'That\'s Just a Person?', 'desc' => 'Mains Mii, Inklings, or Villager in 50%+ of races. Keeping it real.', 'criteria' => 'Mains Mii, Inklings, or Villager ≥50% of races', 'category' => 'characters'],
        'furcurious' => ['icon' => '🐱', 'title' => 'Furcurious!', 'desc' => 'Mains Tanooki Mario or Cat Peach in 50%+ of races. Suspiciously furry.', 'criteria' => 'Mains Tanooki Mario or Cat Peach ≥50% of races', 'category' => 'characters'],
        'koopa_klan' => ['icon' => '😈', 'title' => 'Koopa Klan', 'desc' => 'Mains Bowser and his crew in 50%+ of races. Embrace the dark side.', 'criteria' => 'Mains Bowser and his evil crew ≥50% of races', 'category' => 'characters'],
        'cold_blooded' => ['icon' => '🦎', 'title' => 'Cold-Blooded', 'desc' => 'Mains reptilian characters (Yoshi, Koopa Troopa, Bowser and kin) in 50%+ of races.', 'criteria' => 'Mains reptilian characters (Yoshi, Koopa Troopa, Bowser and kin) ≥50% of races', 'category' => 'characters'],
        'hat_trick' => ['icon' => '🎩', 'title' => 'Hat Trick', 'desc' => 'Won 3 Grand Prix events in a row.', 'criteria' => 'Won 3 Grand Prix events in a row', 'category' => 'streaks'],
        'ascendant' => ['icon' => '↗️', 'title' => 'Ascendant', 'desc' => 'Improved finishing rank in 5 consecutive Grand Prix events.', 'criteria' => 'Improved finishing rank in 5 consecutive GPs', 'category' => 'streaks'],
        'the_elder' => ['icon' => '🕰️', 'title' => 'The Elder', 'desc' => 'Competed across 3 or more seasons.', 'criteria' => 'Competed across 3 or more seasons', 'category' => 'career'],
        'elo_climber' => ['icon' => '🧗', 'title' => 'The Climber', 'desc' => 'Gained 100+ Elo points during this season.', 'criteria' => 'Gained 100+ Elo points during a single season', 'category' => 'elo'],
        'elo_fall' => ['icon' => '📉', 'title' => 'The Fall', 'desc' => 'Lost 100+ Elo points during this season.', 'criteria' => 'Lost 100+ Elo points during a single season', 'category' => 'elo'],
        'upset_king' => ['icon' => '⚡', 'title' => 'Upset King', 'desc' => 'Finished ahead of a racer with 200+ higher Elo in 3 or more GPs.', 'criteria' => 'Finished ahead of a racer with 200+ higher Elo in 3+ GPs', 'category' => 'elo'],
        'stone_cold' => ['icon' => '🥶', 'title' => 'Stone Cold', 'desc' => 'Held the #1 Elo ranking for 5 consecutive Grand Prix events.', 'criteria' => 'Held the #1 Elo ranking for 5 consecutive GPs', 'category' => 'elo'],
        'mh_dragon_slayer' => ['icon' => '🐉', 'title' => 'Dragon Slayer', 'desc' => 'Finished ahead of a CR 4 Dragon — the most feared Monster rating.', 'criteria' => 'Finished ahead of a CR 4 Dragon in at least one GP', 'category' => 'monster_hunt'],
        'mh_hunted' => ['icon' => '👹', 'title' => 'The Hunted', 'desc' => 'Designated as the Monster in 3 or more GPs this season.', 'criteria' => 'Designated as the Monster in 3+ GPs this season', 'category' => 'monster_hunt'],
        'mh_wipe_master' => ['icon' => '🎉', 'title' => 'Wipe Master', 'desc' => 'Participated in a Full Slay — every adventurer ahead of the Monster — in 3 or more GPs.', 'criteria' => 'Participated in a Full Slay (every adventurer beat the Monster) in 3+ GPs', 'category' => 'monster_hunt'],
        'mh_apex' => ['icon' => '💀', 'title' => 'Apex Predator', 'desc' => 'Defeated every adventurer as the Monster in 3 or more GPs.', 'criteria' => 'Achieved a TPK as the Monster (beat every adventurer) in 3+ GPs', 'category' => 'monster_hunt'],
        'mh_underdog' => ['icon' => '🌑', 'title' => 'The Underdog', 'desc' => 'Slew the Monster while being the lowest-Elo adventurer in the race.', 'criteria' => 'Slew the Monster while being the lowest-Elo adventurer', 'category' => 'monster_hunt'],
        'mh_resilient' => ['icon' => '🛡️', 'title' => 'Resilient', 'desc' => 'Survived without slaying the Monster in 5 or more GPs.', 'criteria' => 'Survived without slaying the Monster in 5+ GPs', 'category' => 'monster_hunt'],
        'wax_cracker' => ['icon' => '📦', 'title' => 'Wax Cracker', 'desc' => 'Cracked open your first sticker pack.', 'criteria' => 'Cracked open your first sticker pack', 'category' => 'collection'],
        'pack_rat' => ['icon' => '🐀', 'title' => 'Pack Rat', 'desc' => 'Opened 25 or more sticker packs all-time.', 'criteria' => 'Opened 25 or more sticker packs all-time', 'category' => 'collection'],
        'halfway_hero' => ['icon' => '🌗', 'title' => 'Halfway Hero', 'desc' => 'Collected at least half of the sticker album.', 'criteria' => 'Collected at least half of the sticker album', 'category' => 'collection'],
        'full_album' => ['icon' => '📖', 'title' => 'Full Album', 'desc' => 'Collected every sticker in the album. Completionist!', 'criteria' => 'Collected every sticker in the album', 'category' => 'collection'],
        'set_sweeper_bronze' => ['icon' => '🥉', 'title' => 'Set Sweeper, Bronze', 'desc' => 'Completed a full sticker set.', 'criteria' => 'Completed a full sticker set', 'category' => 'collection'],
        'set_sweeper_silver' => ['icon' => '🥈', 'title' => 'Set Sweeper, Silver', 'desc' => 'Completed 3 full sticker sets.', 'criteria' => 'Completed 3 full sticker sets', 'category' => 'collection'],
        'set_sweeper_gold' => ['icon' => '🥇', 'title' => 'Set Sweeper, Gold', 'desc' => 'Completed 5 or more full sticker sets.', 'criteria' => 'Completed 5 or more full sticker sets', 'category' => 'collection'],
        'foil_hunter' => ['icon' => '✨', 'title' => 'Foil Hunter', 'desc' => 'Owns five or more shiny foil cards.', 'criteria' => 'Owns five or more shiny foil cards', 'category' => 'collection'],
        'got_the_bot' => ['icon' => '🎴', 'title' => 'Got the Bot', 'desc' => 'Pulled Kartificial #001 — the chase foil.', 'criteria' => 'Pulled Kartificial #001, the chase foil', 'category' => 'collection'],
        'stuck_with_dupes' => ['icon' => '♻️', 'title' => 'Stuck With Dupes', 'desc' => 'Hoards 5+ copies of a single card. Got, got, need!', 'criteria' => 'Hoards 5+ copies of a single card', 'category' => 'collection'],
        'lore_keeper' => ['icon' => '📜', 'title' => 'Lore Keeper', 'desc' => 'Completed the Lore set — every in-joke catalogued.', 'criteria' => 'Completed the Lore set', 'category' => 'collection'],
        'whale' => ['icon' => '🐳', 'title' => 'Whale', 'desc' => 'Amassed 250+ total cards. A true sticker tycoon.', 'criteria' => 'Amassed 250+ total cards', 'category' => 'collection'],
        'tournament_champion' => ['icon' => '🏆', 'title' => 'Tournament Champion', 'desc' => 'Won a Kartfolio tournament.', 'criteria' => 'Won a Kartfolio tournament', 'category' => 'honours'],
        'board_breaker' => ['icon' => '🐍', 'title' => 'Board Breaker', 'desc' => 'Won a Snakes & Ladders tournament.', 'criteria' => 'Won a Snakes & Ladders tournament', 'category' => 'honours'],
        'on_top_of_the_world' => ['icon' => '🌍', 'title' => 'On Top of the World', 'desc' => 'Lifted the World Cup trophy.', 'criteria' => 'Lifted the World Cup trophy', 'category' => 'honours'],
        'pickem_oracle' => ['icon' => '🔮', 'title' => 'Pick\'em Oracle', 'desc' => 'Topped a World Cup Pick\'em leaderboard.', 'criteria' => 'Topped a World Cup Pick\'em leaderboard', 'category' => 'honours'],
        'mikkoligan' => ['icon' => '🌟', 'title' => 'Mikkoligan', 'desc' => 'Tops the Mikkoliiga this season.', 'criteria' => 'Tops the Mikkoliiga this season', 'category' => 'honours'],
        'ascended' => ['icon' => '🌠', 'title' => 'Ascended', 'desc' => 'Reached a 2000 Elo rating.', 'criteria' => 'Reached a 2000 Elo rating', 'category' => 'honours'],
        'constructor' => ['icon' => '🏗️', 'title' => 'Constructor', 'desc' => 'Member of the winning team in a teams season.', 'criteria' => 'Member of the winning team in a teams season', 'category' => 'honours'],
        'fantasy_champion' => ['icon' => '🧙', 'title' => 'Fantasy Champion', 'desc' => 'Topped a fantasy season.', 'criteria' => 'Topped a fantasy season', 'category' => 'honours'],
        'bracket_buster' => ['icon' => '💥', 'title' => 'Bracket Buster', 'desc' => 'Won a tournament as the lowest seed.', 'criteria' => 'Won a tournament as the lowest seed', 'category' => 'honours'],
        'snake_bitten' => ['icon' => '🩹', 'title' => 'Snake Bitten', 'desc' => 'Landed on a snake in Snakes & Ladders. It happens to everyone.', 'criteria' => 'Landed on a snake in Snakes & Ladders', 'category' => 'honours'],
        'landlord' => ['icon' => '🏘️', 'title' => 'Landlord', 'desc' => 'Holds five or more cups at once this season.', 'criteria' => 'Holds 5+ cups at once this season', 'category' => 'territory'],
        'usurper' => ['icon' => '🗝️', 'title' => 'Usurper', 'desc' => 'Took a cup off another racer five or more times this season.', 'criteria' => 'Took a cup off another racer 5+ times this season', 'category' => 'territory'],
        'bingo' => ['icon' => '🅱️', 'title' => 'Bingo!', 'desc' => 'Completed the whole Kart Bingo card in one season.', 'criteria' => 'Full Kart Bingo card in a season', 'category' => 'territory'],
        'cursed' => ['icon' => '🪦', 'title' => 'Cursed', 'desc' => 'Wore the Cursed Crown when the season closed.', 'criteria' => 'Wearing the Cursed Crown at season close', 'category' => 'territory'],
        'squatter' => ['icon' => '🏕️', 'title' => "Squatter's Rights", 'desc' => 'Took a cup from a holder who left it undefended for the whole decay window.', 'criteria' => 'Took an undefended cup (holder absent through the decay window)', 'category' => 'territory'],
        'fortress' => ['icon' => '🏯', 'title' => 'Fortress', 'desc' => 'Held a cup all season through three or more challengers, never overtaken.', 'criteria' => 'Held a cup all season through 3+ challengers, never overtaken', 'category' => 'territory'],
        'dead_heat' => ['icon' => '🪙', 'title' => 'Dead Heat', 'desc' => 'Level on points with another racer — separated only by the tie-break.', 'criteria' => 'Level on points with another racer — separated only by the tie-break', 'category' => 'territory'],
        'dynasty' => ['icon' => '🏵️', 'title' => 'Dynasty', 'desc' => 'Won three or more seasons in a row.', 'criteria' => 'Won 3+ consecutive seasons', 'category' => 'legacy'],
        'ever_present' => ['icon' => '📅', 'title' => 'Ever-Present', 'desc' => 'Raced every single GP of the season.', 'criteria' => 'Raced every GP of the season', 'category' => 'legacy'],
        'full_roster' => ['icon' => '🌈', 'title' => 'Full Roster', 'desc' => 'Has raced ten or more different characters across their career.', 'criteria' => 'Raced 10+ different characters across their career', 'category' => 'legacy'],
        'questmaster' => ['icon' => '🧭', 'title' => 'Questmaster', 'desc' => 'Completed both side quests this season.', 'criteria' => 'Completed both side quests this season', 'category' => 'legacy'],
        'on_the_up' => ['icon' => '🪜', 'title' => 'On the Up', 'desc' => 'Improved their season placement three seasons running.', 'criteria' => 'Improved placement three seasons running', 'category' => 'legacy'],
        'from_the_back' => ['icon' => '🏹', 'title' => 'From the Back', 'desc' => 'Won a season after finishing in the bottom half of an earlier one.', 'criteria' => 'Won a season after a bottom-half finish in an earlier one', 'category' => 'legacy'],
    ];
}

/** The array getRacerBadges() emits for a badge: icon + title + earned text. $override patches any field. */
function badgeDef(string $key, array $override = []): array {
    $b = badgeCatalog()[$key] ?? ['icon' => '❓', 'title' => $key, 'desc' => ''];
    return array_merge(['icon' => $b['icon'], 'title' => $b['title'], 'desc' => $b['desc']], $override);
}

/** category => [[icon,title,desc(criteria),key], …] in catalogue order — what the overview renders. */
function badgeCatalogByCategory(): array {
    $out = array_fill_keys(badgeCategoryOrder(), []);
    foreach (badgeCatalog() as $key => $b) $out[$b['category']][] = ['icon' => $b['icon'], 'title' => $b['title'], 'desc' => $b['criteria'], 'key' => $key];
    return $out;
}
