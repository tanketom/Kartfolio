<?php
/**
 * Record Book - All-Time Records & Superlatives
 * Path: /cdnmk/public_html/records.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$pageTitle = "Record Book - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';

// ============================================================================
// DATA LAYER — Compute all 17 records
// ============================================================================

// --- Fetch all season results ---
$allResults = $pdo->query("
    SELECT res.id, res.gpid, res.racer_id, r.name, res.gp_points, res.rank,
           res.character_used, res.cup_name, res.is_lol, res.race_date, res.kart_setup
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE 's%'
    ORDER BY res.race_date ASC, res.gpid ASC, res.rank ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- Fetch all racers ---
$allRacers = $pdo->query("SELECT id, name, nickname FROM racers")->fetchAll(PDO::FETCH_ASSOC);
$racerNames = [];
foreach ($allRacers as $r) {
    $racerNames[$r['id']] = $r['name'];
}

// --- Fetch season meta ---
$seasonMeta = $pdo->query("SELECT season_id, status, scoring_system, champion_name, season_name FROM season_meta ORDER BY season_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- ELO data ---
$eloData = calculateAllELORatings($pdo);

// Index results by GP for participant counting
$gpParticipants = [];
foreach ($allResults as $row) {
    $gpParticipants[$row['gpid']][] = $row;
}

// Helper: build records array
$records = [];

// ============================================================================
// 1. HIGHEST SINGLE GP SCORE
// ============================================================================
$topScores = $allResults;
usort($topScores, function($a, $b) {
    return $b['gp_points'] <=> $a['gp_points'] ?: strcmp($a['race_date'], $b['race_date']);
});

if (!empty($topScores)) {
    $best = $topScores[0];
    $runners = [];
    $seen = [$best['name']];
    foreach (array_slice($topScores, 1) as $r) {
        if (!in_array($r['name'], $seen)) {
            $runners[] = ['name' => $r['name'], 'value' => $r['gp_points'] . ' pts'];
            $seen[] = $r['name'];
        }
        if (count($runners) >= 2) break;
    }
    $records[] = [
        'icon' => "\u{1F3C6}", // trophy
        'title' => 'Highest Single GP Score',
        'holder' => $best['name'],
        'value' => $best['gp_points'] . ' pts',
        'context' => htmlspecialchars($best['cup_name'] ?? 'Unknown') . ' Cup — ' . date('M j, Y', strtotime($best['race_date'])) . ' — ' . $best['gpid'],
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F3C6}",
        'title' => 'Highest Single GP Score',
        'holder' => 'No data',
        'value' => '—',
        'context' => '',
        'runners' => [],
    ];
}

// ============================================================================
// 2. MOST PERFECT 60s
// ============================================================================
$perfect60s = [];
foreach ($allResults as $row) {
    if ((int)$row['gp_points'] === MK_MAX_GP_POINTS) {
        $name = $row['name'];
        $perfect60s[$name] = ($perfect60s[$name] ?? 0) + 1;
    }
}
arsort($perfect60s);

if (!empty($perfect60s)) {
    $topName = array_key_first($perfect60s);
    $topVal = $perfect60s[$topName];
    $runners = [];
    $i = 0;
    foreach ($perfect60s as $name => $count) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $count . ' perfect GPs'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F4AF}",
        'title' => 'Most Perfect 60s',
        'holder' => $topName,
        'value' => $topVal . ' perfect GPs',
        'context' => 'All-time season grand prix perfection count',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F4AF}",
        'title' => 'Most Perfect 60s',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'Nobody has scored a perfect 60 yet',
        'runners' => [],
    ];
}

// ============================================================================
// 3. BIGGEST ELO GAIN (single GP)
// ============================================================================
$allChanges = $eloData['all_changes'];
$seasonChanges = array_filter($allChanges, fn($c) => str_starts_with($c['gpid'], 's'));
$sortedGains = $seasonChanges;
usort($sortedGains, fn($a, $b) => $b['change'] <=> $a['change']);

if (!empty($sortedGains)) {
    $best = $sortedGains[0];
    $runners = [];
    $seen = [$best['racer']];
    foreach (array_slice($sortedGains, 1) as $c) {
        if (!in_array($c['racer'], $seen)) {
            $runners[] = ['name' => $c['racer'], 'value' => '+' . round($c['change'], 1) . ' ELO'];
            $seen[] = $c['racer'];
        }
        if (count($runners) >= 2) break;
    }
    $records[] = [
        'icon' => "\u{2B50}",
        'title' => 'Biggest ELO Gain',
        'holder' => $best['racer'],
        'value' => '+' . round($best['change'], 1) . ' ELO',
        'context' => 'Rank #' . $best['rank'] . ' — ' . $best['gpid'] . ' — ' . date('M j, Y', strtotime($best['date'])),
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{2B50}",
        'title' => 'Biggest ELO Gain',
        'holder' => 'No data',
        'value' => '—',
        'context' => '',
        'runners' => [],
    ];
}

// ============================================================================
// 5 & 6 & 7. STREAK CALCULATIONS (Win, Podium, Drought)
// ============================================================================
// Group results by racer, ordered chronologically
$racerGPs = [];
foreach ($allResults as $row) {
    $racerGPs[$row['name']][] = $row;
}

// 5. Longest Win Streak
$winStreaks = [];
foreach ($racerGPs as $name => $gps) {
    $maxStreak = 0;
    $currentStreak = 0;
    $streakStart = null;
    $bestStreakStart = null;
    $bestStreakEnd = null;
    foreach ($gps as $gp) {
        if ((int)$gp['rank'] === 1) {
            if ($currentStreak === 0) $streakStart = $gp;
            $currentStreak++;
            if ($currentStreak > $maxStreak) {
                $maxStreak = $currentStreak;
                $bestStreakStart = $streakStart;
                $bestStreakEnd = $gp;
            }
        } else {
            $currentStreak = 0;
        }
    }
    if ($maxStreak > 0) {
        $winStreaks[$name] = [
            'streak' => $maxStreak,
            'start' => $bestStreakStart,
            'end' => $bestStreakEnd,
        ];
    }
}
uasort($winStreaks, fn($a, $b) => $b['streak'] <=> $a['streak']);

if (!empty($winStreaks)) {
    $topName = array_key_first($winStreaks);
    $topData = $winStreaks[$topName];
    $runners = [];
    $i = 0;
    foreach ($winStreaks as $name => $data) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $data['streak'] . ' wins'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F525}",
        'title' => 'Longest Win Streak',
        'holder' => $topName,
        'value' => $topData['streak'] . ' consecutive wins',
        'context' => date('M j', strtotime($topData['start']['race_date'])) . ' — ' . date('M j, Y', strtotime($topData['end']['race_date'])),
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F525}",
        'title' => 'Longest Win Streak',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'No wins recorded yet',
        'runners' => [],
    ];
}

// 6. Longest Podium Streak (rank <= 3)
$podiumStreaks = [];
foreach ($racerGPs as $name => $gps) {
    $maxStreak = 0;
    $currentStreak = 0;
    $streakStart = null;
    $bestStreakStart = null;
    $bestStreakEnd = null;
    foreach ($gps as $gp) {
        if ((int)$gp['rank'] <= 3) {
            if ($currentStreak === 0) $streakStart = $gp;
            $currentStreak++;
            if ($currentStreak > $maxStreak) {
                $maxStreak = $currentStreak;
                $bestStreakStart = $streakStart;
                $bestStreakEnd = $gp;
            }
        } else {
            $currentStreak = 0;
        }
    }
    if ($maxStreak > 0) {
        $podiumStreaks[$name] = [
            'streak' => $maxStreak,
            'start' => $bestStreakStart,
            'end' => $bestStreakEnd,
        ];
    }
}
uasort($podiumStreaks, fn($a, $b) => $b['streak'] <=> $a['streak']);

if (!empty($podiumStreaks)) {
    $topName = array_key_first($podiumStreaks);
    $topData = $podiumStreaks[$topName];
    $runners = [];
    $i = 0;
    foreach ($podiumStreaks as $name => $data) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $data['streak'] . ' GPs'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F3C5}",
        'title' => 'Longest Podium Streak',
        'holder' => $topName,
        'value' => $topData['streak'] . ' consecutive top-3s',
        'context' => date('M j', strtotime($topData['start']['race_date'])) . ' — ' . date('M j, Y', strtotime($topData['end']['race_date'])),
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F3C5}",
        'title' => 'Longest Podium Streak',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'No podium finishes recorded yet',
        'runners' => [],
    ];
}

// 7. Longest Drought Without Win (must have at least 1 win to qualify)
$droughts = [];
foreach ($racerGPs as $name => $gps) {
    $hasWin = false;
    foreach ($gps as $gp) {
        if ((int)$gp['rank'] === 1) { $hasWin = true; break; }
    }
    if (!$hasWin) continue;

    $maxDrought = 0;
    $currentDrought = 0;
    $droughtStart = null;
    $bestDroughtStart = null;
    $bestDroughtEnd = null;
    foreach ($gps as $gp) {
        if ((int)$gp['rank'] !== 1) {
            if ($currentDrought === 0) $droughtStart = $gp;
            $currentDrought++;
            if ($currentDrought > $maxDrought) {
                $maxDrought = $currentDrought;
                $bestDroughtStart = $droughtStart;
                $bestDroughtEnd = $gp;
            }
        } else {
            $currentDrought = 0;
        }
    }
    if ($maxDrought > 0) {
        $droughts[$name] = [
            'drought' => $maxDrought,
            'start' => $bestDroughtStart,
            'end' => $bestDroughtEnd,
        ];
    }
}
uasort($droughts, fn($a, $b) => $b['drought'] <=> $a['drought']);

if (!empty($droughts)) {
    $topName = array_key_first($droughts);
    $topData = $droughts[$topName];
    $runners = [];
    $i = 0;
    foreach ($droughts as $name => $data) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $data['drought'] . ' GPs'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F3DC}\u{FE0F}",
        'title' => 'Longest Drought Without Win',
        'holder' => $topName,
        'value' => $topData['drought'] . ' GPs without a win',
        'context' => date('M j', strtotime($topData['start']['race_date'])) . ' — ' . date('M j, Y', strtotime($topData['end']['race_date'])),
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F3DC}\u{FE0F}",
        'title' => 'Longest Drought Without Win',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'Not enough data to compute',
        'runners' => [],
    ];
}

// ============================================================================
// 8. MOST CUPS IN A SEASON
// ============================================================================
$cupsBySeason = [];
foreach ($allResults as $row) {
    if (empty($row['cup_name'])) continue;
    $seasonId = substr($row['gpid'], 0, 3); // e.g., s01
    $key = $row['name'] . '|' . $seasonId;
    if (!isset($cupsBySeason[$key])) {
        $cupsBySeason[$key] = ['name' => $row['name'], 'season' => $seasonId, 'cups' => []];
    }
    $cupsBySeason[$key]['cups'][$row['cup_name']] = true;
}
$cupsCount = [];
foreach ($cupsBySeason as $key => $data) {
    $cupsCount[$key] = [
        'name' => $data['name'],
        'season' => strtoupper($data['season']),
        'count' => count($data['cups']),
    ];
}
usort($cupsCount, fn($a, $b) => $b['count'] <=> $a['count']);

if (!empty($cupsCount)) {
    $best = $cupsCount[0];
    $runners = [];
    $seen = [$best['name'] . $best['season']];
    foreach (array_slice($cupsCount, 1) as $c) {
        $ck = $c['name'] . $c['season'];
        if (!in_array($ck, $seen)) {
            $runners[] = ['name' => $c['name'], 'value' => $c['count'] . ' cups (' . $c['season'] . ')'];
            $seen[] = $ck;
        }
        if (count($runners) >= 2) break;
    }
    $records[] = [
        'icon' => "\u{1F5FA}\u{FE0F}",
        'title' => 'Most Cups in a Season',
        'holder' => $best['name'],
        'value' => $best['count'] . ' unique cups',
        'context' => 'Season ' . $best['season'],
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F5FA}\u{FE0F}",
        'title' => 'Most Cups in a Season',
        'holder' => 'No data',
        'value' => '—',
        'context' => '',
        'runners' => [],
    ];
}

// ============================================================================
// 9. MOST UNIQUE CHARACTERS
// ============================================================================
$charsByRacer = [];
foreach ($allResults as $row) {
    if (empty($row['character_used'])) continue;
    $charsByRacer[$row['name']][$row['character_used']] = true;
}
$charCounts = [];
foreach ($charsByRacer as $name => $chars) {
    $charCounts[$name] = count($chars);
}
arsort($charCounts);

if (!empty($charCounts)) {
    $topName = array_key_first($charCounts);
    $topVal = $charCounts[$topName];
    $runners = [];
    $i = 0;
    foreach ($charCounts as $name => $count) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $count . ' characters'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F3AD}",
        'title' => 'Most Unique Characters',
        'holder' => $topName,
        'value' => $topVal . ' characters',
        'context' => 'All-time unique character selections',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F3AD}",
        'title' => 'Most Unique Characters',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'No character data recorded',
        'runners' => [],
    ];
}

// ============================================================================
// 10. HIGHEST SEASON AVERAGE (minimum 5 GPs)
// ============================================================================
$seasonAverages = [];
foreach ($allResults as $row) {
    $seasonId = substr($row['gpid'], 0, 3);
    $key = $row['name'] . '|' . $seasonId;
    if (!isset($seasonAverages[$key])) {
        $seasonAverages[$key] = ['name' => $row['name'], 'season' => $seasonId, 'points' => []];
    }
    $seasonAverages[$key]['points'][] = (int)$row['gp_points'];
}
$avgResults = [];
foreach ($seasonAverages as $key => $data) {
    if (count($data['points']) < 5) continue;
    $avg = array_sum($data['points']) / count($data['points']);
    $avgResults[] = [
        'name' => $data['name'],
        'season' => strtoupper($data['season']),
        'avg' => round($avg, 2),
        'gps' => count($data['points']),
    ];
}
usort($avgResults, fn($a, $b) => $b['avg'] <=> $a['avg']);

if (!empty($avgResults)) {
    $best = $avgResults[0];
    $runners = [];
    $seen = [$best['name'] . $best['season']];
    foreach (array_slice($avgResults, 1) as $a) {
        $ak = $a['name'] . $a['season'];
        if (!in_array($ak, $seen)) {
            $runners[] = ['name' => $a['name'], 'value' => $a['avg'] . ' avg (' . $a['season'] . ')'];
            $seen[] = $ak;
        }
        if (count($runners) >= 2) break;
    }
    $records[] = [
        'icon' => "\u{1F4CA}",
        'title' => 'Highest Season Average',
        'holder' => $best['name'],
        'value' => $best['avg'] . ' pts/GP',
        'context' => 'Season ' . $best['season'] . ' — ' . $best['gps'] . ' GPs (min. 5)',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F4CA}",
        'title' => 'Highest Season Average',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'Need at least 5 GPs in a season',
        'runners' => [],
    ];
}

// ============================================================================
// 12. MOST CONSISTENT (lowest standard deviation, min 10 GPs all-time)
// ============================================================================
$allTimePoints = [];
foreach ($allResults as $row) {
    $allTimePoints[$row['name']][] = (int)$row['gp_points'];
}
$consistencyData = [];
foreach ($allTimePoints as $name => $pts) {
    if (count($pts) < 10) continue;
    $mean = array_sum($pts) / count($pts);
    $variance = 0;
    foreach ($pts as $p) {
        $variance += ($p - $mean) ** 2;
    }
    $stddev = sqrt($variance / count($pts));
    $consistencyData[] = [
        'name' => $name,
        'stddev' => round($stddev, 2),
        'mean' => round($mean, 2),
        'gps' => count($pts),
    ];
}
usort($consistencyData, fn($a, $b) => $a['stddev'] <=> $b['stddev']);

if (!empty($consistencyData)) {
    $best = $consistencyData[0];
    $runners = [];
    foreach (array_slice($consistencyData, 1, 2) as $c) {
        $runners[] = ['name' => $c['name'], 'value' => "\u{03C3} " . $c['stddev'] . ' (' . $c['mean'] . ' avg)'];
    }
    $records[] = [
        'icon' => "\u{1F3AF}",
        'title' => 'Most Consistent',
        'holder' => $best['name'],
        'value' => "\u{03C3} " . $best['stddev'],
        'context' => 'Avg ' . $best['mean'] . ' pts across ' . $best['gps'] . ' GPs (min. 10)',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F3AF}",
        'title' => 'Most Consistent',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'Need at least 10 GPs all-time',
        'runners' => [],
    ];
}

// ============================================================================
// 13. MOST LOLs (Ludwig Obstruction Law penalties)
// ============================================================================
$lolCounts = [];
foreach ($allResults as $row) {
    if (!empty($row['is_lol']) && $row['is_lol']) {
        $lolCounts[$row['name']] = ($lolCounts[$row['name']] ?? 0) + 1;
    }
}
arsort($lolCounts);

if (!empty($lolCounts)) {
    $topName = array_key_first($lolCounts);
    $topVal = $lolCounts[$topName];
    $runners = [];
    $i = 0;
    foreach ($lolCounts as $name => $count) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $count . ' LOLs'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F608}",
        'title' => 'Most LOLs',
        'holder' => $topName,
        'value' => $topVal . ' LOL penalties',
        'context' => 'Ludwig Obstruction Law infractions',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F608}",
        'title' => 'Most LOLs',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'No LOL penalties recorded',
        'runners' => [],
    ];
}

// ============================================================================
// 14. MOST APPEARANCES TOGETHER
// ============================================================================
$pairCounts = [];
foreach ($gpParticipants as $gpid => $participants) {
    if (!str_starts_with($gpid, 's')) continue;
    $racerIds = [];
    foreach ($participants as $p) {
        $racerIds[$p['racer_id']] = $p['name'];
    }
    $ids = array_keys($racerIds);
    sort($ids);
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $pairKey = $ids[$i] . '|' . $ids[$j];
            if (!isset($pairCounts[$pairKey])) {
                $pairCounts[$pairKey] = [
                    'name1' => $racerIds[$ids[$i]],
                    'name2' => $racerIds[$ids[$j]],
                    'count' => 0,
                ];
            }
            $pairCounts[$pairKey]['count']++;
        }
    }
}
usort($pairCounts, fn($a, $b) => $b['count'] <=> $a['count']);

if (!empty($pairCounts)) {
    $best = $pairCounts[0];
    $runners = [];
    foreach (array_slice($pairCounts, 1, 2) as $p) {
        $runners[] = ['name' => $p['name1'] . ' & ' . $p['name2'], 'value' => $p['count'] . ' GPs'];
    }
    $records[] = [
        'icon' => "\u{1F91D}",
        'title' => 'Most Appearances Together',
        'holder' => $best['name1'] . ' & ' . $best['name2'],
        'value' => $best['count'] . ' shared GPs',
        'context' => 'Most frequently paired rivals on the track',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F91D}",
        'title' => 'Most Appearances Together',
        'holder' => 'No data',
        'value' => '—',
        'context' => '',
        'runners' => [],
    ];
}

// ============================================================================
// 15. CLOSEST SEASON FINISH
// ============================================================================
$archivedSeasons = array_filter($seasonMeta, fn($s) => $s['status'] === 'archived');
$closestFinish = null;

foreach ($archivedSeasons as $season) {
    $sid = $season['season_id'];
    // Get all racers who participated in this season
    $racerStmt = $pdo->prepare("
        SELECT DISTINCT racer_id FROM results WHERE gpid LIKE ? AND gpid LIKE 's%'
    ");
    $racerStmt->execute([$sid . '%']);
    $seasonRacerIds = $racerStmt->fetchAll(PDO::FETCH_COLUMN);

    $scores = [];
    foreach ($seasonRacerIds as $rid) {
        $score = calculateGPScore($pdo, $rid, $sid);
        if ($score > 0) {
            $rName = $racerNames[$rid] ?? 'Unknown';
            $scores[] = ['name' => $rName, 'score' => $score];
        }
    }
    usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

    if (count($scores) >= 2) {
        $gap = $scores[0]['score'] - $scores[1]['score'];
        if ($closestFinish === null || $gap < $closestFinish['gap']) {
            $closestFinish = [
                'season' => strtoupper($sid),
                'season_name' => $season['season_name'] ?? strtoupper($sid),
                'first' => $scores[0],
                'second' => $scores[1],
                'gap' => round($gap, 2),
            ];
        }
    }
}

if ($closestFinish !== null) {
    $records[] = [
        'icon' => "\u{2694}\u{FE0F}",
        'title' => 'Closest Season Finish',
        'holder' => $closestFinish['first']['name'] . ' vs ' . $closestFinish['second']['name'],
        'value' => $closestFinish['gap'] . ' pts gap',
        'context' => 'Season ' . $closestFinish['season'] . ' — ' . round($closestFinish['first']['score'], 2) . ' vs ' . round($closestFinish['second']['score'], 2),
        'runners' => [],
    ];
} else {
    $records[] = [
        'icon' => "\u{2694}\u{FE0F}",
        'title' => 'Closest Season Finish',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'No archived seasons yet',
        'runners' => [],
    ];
}

// ============================================================================
// 16. MOST IMPROVED (ELO) — biggest positive change from first GP to current
// ============================================================================
$eloRatings = $eloData['ratings'];
$eloHistory = $eloData['history'];
$improvements = [];

foreach ($eloRatings as $name => $currentRating) {
    if (!isset($eloHistory[$name]) || empty($eloHistory[$name])) continue;
    // Find first non-zero-change entry (actual first GP)
    $firstRating = ELO_INITIAL_RATING; // All start at 1500
    $improvement = $currentRating - $firstRating;
    $gamesPlayed = $eloData['games_played'][$name] ?? 0;
    if ($gamesPlayed < 3) continue; // Need meaningful sample
    $improvements[] = [
        'name' => $name,
        'improvement' => round($improvement, 1),
        'current' => round($currentRating, 1),
        'gps' => $gamesPlayed,
    ];
}
usort($improvements, fn($a, $b) => $b['improvement'] <=> $a['improvement']);

if (!empty($improvements)) {
    $best = $improvements[0];
    $runners = [];
    foreach (array_slice($improvements, 1, 2) as $imp) {
        $prefix = $imp['improvement'] >= 0 ? '+' : '';
        $runners[] = ['name' => $imp['name'], 'value' => $prefix . $imp['improvement'] . ' ELO'];
    }
    $prefix = $best['improvement'] >= 0 ? '+' : '';
    $records[] = [
        'icon' => "\u{1F4C8}",
        'title' => 'Most Improved (ELO)',
        'holder' => $best['name'],
        'value' => $prefix . $best['improvement'] . ' ELO',
        'context' => '1500 start \u2192 ' . $best['current'] . ' current — ' . $best['gps'] . ' GPs played',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F4C8}",
        'title' => 'Most Improved (ELO)',
        'holder' => 'No data',
        'value' => '—',
        'context' => 'Need at least 3 GPs',
        'runners' => [],
    ];
}

// ============================================================================
// 17. BLUE SHELL AWARD — most last-place finishes
// ============================================================================
$lastPlaceCounts = [];
foreach ($gpParticipants as $gpid => $participants) {
    if (!str_starts_with($gpid, 's')) continue;
    $numRacers = count($participants);
    foreach ($participants as $p) {
        if ((int)$p['rank'] === $numRacers) {
            $lastPlaceCounts[$p['name']] = ($lastPlaceCounts[$p['name']] ?? 0) + 1;
        }
    }
}
arsort($lastPlaceCounts);

if (!empty($lastPlaceCounts)) {
    $topName = array_key_first($lastPlaceCounts);
    $topVal = $lastPlaceCounts[$topName];
    // Total GPs for this racer for context
    $totalGPs = count($racerGPs[$topName] ?? []);
    $runners = [];
    $i = 0;
    foreach ($lastPlaceCounts as $name => $count) {
        if ($i > 0 && $i <= 2) {
            $runners[] = ['name' => $name, 'value' => $count . ' last-place finishes'];
        }
        $i++;
        if ($i > 2) break;
    }
    $records[] = [
        'icon' => "\u{1F422}",
        'title' => 'Blue Shell Award',
        'holder' => $topName,
        'value' => $topVal . ' last-place finishes',
        'context' => 'Out of ' . $totalGPs . ' total GPs raced',
        'runners' => $runners,
    ];
} else {
    $records[] = [
        'icon' => "\u{1F422}",
        'title' => 'Blue Shell Award',
        'holder' => 'No data',
        'value' => '—',
        'context' => '',
        'runners' => [],
    ];
}

// ============================================================================
// RENDER
// ============================================================================
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <div class="racer-card rec-header-card">
        <h1 class="rec-title">The Record Book</h1>
        <p class="rec-subtitle">Every superlative, every milestone, every moment of glory (and shame)</p>
    </div>

    <div class="rec-grid">
        <?php foreach ($records as $rec): ?>
        <div class="racer-card rec-card">
            <div class="rec-icon"><?= $rec['icon'] ?></div>
            <h3 class="rec-card-title"><?= htmlspecialchars($rec['title']) ?></h3>
            <div class="rec-holder">
                <span class="rec-holder-name"><?= htmlspecialchars($rec['holder']) ?></span>
                <span class="rec-holder-value"><?= htmlspecialchars($rec['value']) ?></span>
            </div>
            <?php if (!empty($rec['context'])): ?>
            <div class="rec-context"><?= $rec['context'] ?></div>
            <?php endif; ?>
            <?php if (!empty($rec['runners'])): ?>
            <div class="rec-runners">
                <?php foreach ($rec['runners'] as $idx => $runner): ?>
                <div class="rec-runner"><?= ($idx + 2) ?>. <?= htmlspecialchars($runner['name']) ?> — <?= htmlspecialchars($runner['value']) ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.rec-card');
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) {
                e.target.classList.add('rec-visible');
                observer.unobserve(e.target);
            }
        });
    }, { threshold: 0.1 });
    cards.forEach(function(c) { observer.observe(c); });
});
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
