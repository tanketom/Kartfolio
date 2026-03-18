<?php
/**
 * Badge Logic - Achievements & Playstyle Analysis
 * Path: /cdnmk/private/includes/badges.php
 */

function getRacerBadges($pdo, $racer_id, $season_id) {
    $badges = [];

    // 1. Fetch data sorted by date (important for streak logic)
    $stmt = $pdo->prepare("SELECT * FROM results WHERE racer_id = ? AND gpid LIKE ? ORDER BY race_date ASC, id ASC");
    $stmt->execute([$racer_id, $season_id . "%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalRaces = count($results);
    if ($totalRaces < 3) return []; // Minimum races required to earn badges

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
    $babies = ['Baby Mario', 'Baby Luigi', 'Baby Peach', 'Baby Daisy', 'Baby Rosalina'];
    $heavies = ['Bowser', 'Dry Bowser', 'Morton', 'Wario', 'Donkey Kong', 'Funky Kong'];
    $spooky = ['Boo', 'Dry Bones', 'King Boo'];
    $og_stars = ['Mario', 'Luigi', 'Peach', 'Daisy'];
    $royals = ['Peach', 'Daisy', 'Rosalina'];
    $fungi = ['Toad', 'Toadette', 'Peachette'];
    $humans = ['Mii', 'Inkling Boy', 'Inkling Girl', 'Villager', 'Villager (M)', 'Villager (F)'];
    $furry = ['Tanooki Mario', 'Cat Peach'];
    $koopa_clan = ['Bowser', 'Dry Bowser', 'Bowser Jr.', 'Koopa Troopa', 'Lakitu', 'Larry', 'Roy', 'Wendy', 'Ludwig', 'Iggy', 'Morton', 'Lemmy', 'Kamek', 'Dry Bones'];
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

    // Streak Logic
    $current_win_streak = 0;
    $max_win_streak = 0;
    $winning_chars = [];
    $prev_rank = null;

    foreach ($results as $r) {
        $total_gp_points += $r['gp_points'];
        $gp_scores_by_date[] = $r['gp_points'];

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

        // Arrays for Stats
        $chars[] = $r['character_used'];
        $ranks[] = $r['rank'];
        $karts[] = $r['kart_setup'];
        $raced_cups[] = $r['cup_name'];
        if ($r['gp_points'] == 60) $perfect_cups[] = $r['cup_name'];

        // Archetype Checks
        if (in_array($r['character_used'], $babies)) $baby_count++;
        if (in_array($r['character_used'], $heavies)) $heavy_count++;
        if (in_array($r['character_used'], $spooky)) $spooky_count++;
        if (in_array($r['character_used'], $og_stars)) $og_stars_count++;
        if (in_array($r['character_used'], $royals)) $royal_count++;
        if ($r['character_used'] === 'Link') $link_count++;
        if (in_array($r['character_used'], $fungi)) $fungi_count++;
        if (in_array($r['character_used'], $humans)) $human_count++;
        if (in_array($r['character_used'], $furry)) $furry_count++;
        if (in_array($r['character_used'], $koopa_clan)) $koopa_count++;
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
        $badges[] = ['icon' => '🥈', 'title' => 'The Bridesmaid', 'desc' => 'Finishes 2nd place >25% of the time.'];
    }

    if (($fourths / $totalRaces) >= 0.25) {
        $badges[] = ['icon' => '💀', 'title' => 'The Fourth Wall', 'desc' => 'Stuck in the cursed 4th place position >25% of the time.'];
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
    $maxAttStmt = $pdo->prepare("SELECT COUNT(*) as c FROM results WHERE gpid LIKE ? GROUP BY racer_id ORDER BY c DESC LIMIT 1");
    $maxAttStmt->execute([$season_id . "%"]);
    $highestAttendance = $maxAttStmt->fetchColumn();
    if ($totalRaces >= $highestAttendance && $highestAttendance > 0) {
        $badges[] = ['icon' => '🗓️', 'title' => 'Longevity', 'desc' => 'Highest attendance record in the league.'];
    }

    // 15. 🏛️ Base 12 (Won all Base Game Cups)
    $baseCupsList = [
        'Mushroom', 'Flower', 'Star', 'Special',
        'Shell', 'Banana', 'Leaf', 'Lightning',
        'Egg', 'Triforce', 'Crossing', 'Bell'
    ];
    // Check if user has won ALL base cups (array_diff returns empty if won_cups contains all base cups)
    if (empty(array_diff($baseCupsList, $won_cups_unique))) {
        $badges[] = ['icon' => '🏛️', 'title' => 'Base 12', 'desc' => 'Has won a Grand Prix in all 12 Base Game cups.'];
    }

    // 16. 🚀 Booster\'s Dozen (Won all DLC Cups)
    $boosterCupsList = [
        'Golden Dash', 'Lucky Cat', 'Turnip', 'Propeller',
        'Rock', 'Moon', 'Fruit', 'Boomerang',
        'Feather', 'Cherry', 'Acorn', 'Spiny'
    ];
    if (empty(array_diff($boosterCupsList, $won_cups_unique))) {
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
        $badges[] = ['icon' => '🌟', 'title' => 'Star Power', 'desc' => 'Mains original Nintendo stars (Mario, Luigi, Peach, Daisy) 60%+ of the time.'];
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
    $allCups = [
        'Mushroom', 'Flower', 'Star', 'Special',
        'Shell', 'Banana', 'Leaf', 'Lightning',
        'Egg', 'Triforce', 'Crossing', 'Bell',
        'Golden Dash', 'Lucky Cat', 'Turnip', 'Propeller',
        'Rock', 'Moon', 'Fruit', 'Boomerang',
        'Feather', 'Cherry', 'Acorn', 'Spiny'
    ];
    $careerCupsStmt = $pdo->prepare("SELECT DISTINCT cup_name FROM results WHERE racer_id = ?");
    $careerCupsStmt->execute([$racer_id]);
    $careerCups = $careerCupsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty(array_diff($allCups, $careerCups))) {
        $badges[] = ['icon' => '🏅', 'title' => 'Cup Collector', 'desc' => 'Has raced in all 24 Mario Kart 8 Deluxe cups across their career.'];
    }

    // 28. 💎 Perfectionist — Perfect 60 in 3+ different cups (any season)
    $careerPerfectCupsStmt = $pdo->prepare("SELECT DISTINCT cup_name FROM results WHERE racer_id = ? AND gp_points = 60");
    $careerPerfectCupsStmt->execute([$racer_id]);
    $careerPerfectCups = $careerPerfectCupsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($careerPerfectCups) >= 3) {
        $badges[] = ['icon' => '💎', 'title' => 'Perfectionist', 'desc' => 'Achieved a perfect 60 in 3+ different cups across their career.'];
    }

    // 30. 🐢 The Tortoise — Avg finish rank 8+ but has at least one win
    if ($avgRank >= 8.0 && $wins >= 1) {
        $badges[] = ['icon' => '🐢', 'title' => 'The Tortoise', 'desc' => 'Average finish of 8th or worse, yet still managed to win a GP.'];
    }

    // 31. 🎖️ Old Guard — Participated in both s00 (pre-season) and current season
    $prevSeasonStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE 's00%'");
    $prevSeasonStmt->execute([$racer_id]);
    $prevSeasonCount = (int)$prevSeasonStmt->fetchColumn();
    if ($prevSeasonCount > 0 && $season_id !== 's00') {
        $badges[] = ['icon' => '🎖️', 'title' => 'Old Guard', 'desc' => 'A veteran who raced in the pre-season and has returned.'];
    }

    // 33. 🐓 Early Bird — Participated in the first GP of this season
    $firstGpStmt = $pdo->prepare("SELECT gpid FROM results WHERE gpid LIKE ? ORDER BY race_date ASC, id ASC LIMIT 1");
    $firstGpStmt->execute([$season_id . '%']);
    $firstGpId = $firstGpStmt->fetchColumn();
    if ($firstGpId) {
        $inFirstGpStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid = ?");
        $inFirstGpStmt->execute([$racer_id, $firstGpId]);
        if ((int)$inFirstGpStmt->fetchColumn() > 0) {
            $badges[] = ['icon' => '🐓', 'title' => 'Early Bird', 'desc' => 'Participated in the very first GP of this season.'];
        }
    }

    // 34. 🥊 Giant Killer — Beat the current season leader in a head-to-head GP
    $leaderStmt = $pdo->prepare("
        SELECT racer_id FROM (
            SELECT racer_id, COUNT(*) as gp_count FROM results WHERE gpid LIKE ? GROUP BY racer_id HAVING gp_count >= 3
        ) qualified
        ORDER BY (
            SELECT SUM(gp_points) FROM results WHERE racer_id = qualified.racer_id AND gpid LIKE ?
        ) DESC
        LIMIT 1
    ");
    $leaderStmt->execute([$season_id . '%', $season_id . '%']);
    $leaderId = (int)$leaderStmt->fetchColumn();
    if ($leaderId && $leaderId !== (int)$racer_id) {
        $killedStmt = $pdo->prepare("
            SELECT COUNT(*) FROM results a
            JOIN results b ON a.gpid = b.gpid
            WHERE a.racer_id = ? AND b.racer_id = ? AND a.gpid LIKE ? AND a.rank < b.rank
        ");
        $killedStmt->execute([$racer_id, $leaderId, $season_id . '%']);
        if ((int)$killedStmt->fetchColumn() > 0) {
            $badges[] = ['icon' => '🥊', 'title' => 'Giant Killer', 'desc' => 'Finished ahead of the current season leader in at least one GP.'];
        }
    }

    // 36. 🌸 Princess Protocol — Mains exclusively Peach, Daisy, or Rosalina (50%+)
    if (($royal_count / $totalRaces) >= 0.50) {
        $badges[] = ['icon' => '🌸', 'title' => 'Princess Protocol', 'desc' => 'Mains royalty (Peach, Daisy, or Rosalina) in 50%+ of races.'];
    }

    // 37. 🍄 Mushroom Kingdom — Played every Mario-universe character at least once (career)
    $marioUniverse = ['Mario', 'Luigi', 'Peach', 'Daisy', 'Rosalina', 'Toad', 'Toadette',
        'Yoshi', 'Birdo', 'Wario', 'Waluigi', 'Donkey Kong', 'Bowser', 'Bowser Jr.',
        'Baby Mario', 'Baby Luigi', 'Baby Peach', 'Baby Daisy', 'Baby Rosalina'];
    $careerCharsStmt = $pdo->prepare("SELECT DISTINCT character_used FROM results WHERE racer_id = ?");
    $careerCharsStmt->execute([$racer_id]);
    $careerChars = $careerCharsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty(array_diff($marioUniverse, $careerChars))) {
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

    // 43. ⬛ Black Box — Leader of the Black Box scoring system this season
    require_once __DIR__ . '/gp_logic.php';
    $bbAllStmt = $pdo->prepare("SELECT DISTINCT racer_id FROM results WHERE gpid LIKE ? AND gpid LIKE 's%'");
    $bbAllStmt->execute([$season_id . '%']);
    $bbAllRacers = $bbAllStmt->fetchAll(PDO::FETCH_COLUMN);
    $bbScores = [];
    foreach ($bbAllRacers as $rid) {
        $score = calculateBlackBoxScore($pdo, $rid, $season_id, []);
        if ($score > 0) {
            $bbScores[$rid] = $score;
        }
    }
    if (!empty($bbScores)) {
        arsort($bbScores);
        $bbLeaderId = array_key_first($bbScores);
        if ((int)$bbLeaderId === (int)$racer_id) {
            $badges[] = ['icon' => '⬛', 'title' => 'Black Box', 'desc' => 'The algorithm thinks you should be in the lead.'];
        }
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