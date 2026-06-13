<?php
/**
 * Season Awards — shared business logic.
 *
 * Used by /admin/season_awards.php (display) and /api/generate_season_awards.php
 * (generation). Kept here so the slow Gemini call can run from an AJAX endpoint
 * instead of blocking the admin page (which shared-host proxies cut off).
 *
 * Path: /cdnmk/private/includes/season_awards_logic.php
 */

require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/gemini_client.php';

/** Fixed core awards. Same shape used by display and generation paths. */
function seasonAwardFixedCategories(): array {
    return [
        'Champion'         => ['icon' => '🏆', 'desc' => 'Highest overall GPScore'],
        'Most Improved'    => ['icon' => '📈', 'desc' => 'Biggest avg improvement from 1st to 2nd half'],
        'Consistency King' => ['icon' => '🎯', 'desc' => 'Lowest score variance (min 5 GPs)'],
        'Comeback Player'  => ['icon' => '👑', 'desc' => 'Best single-GP rank jump from previous GP'],
        'Most Entertaining'=> ['icon' => '🎭', 'desc' => 'Highest score variance — the wildcard'],
        'Best Rivalry'     => ['icon' => '⚔️', 'desc' => 'Closest head-to-head record between two racers'],
    ];
}

/** Distinct racers (id + name) who raced in this season. */
function seasonAwardRacers(PDO $pdo, string $seasonId): array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.id, r.name
        FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
        ORDER BY r.name ASC
    ");
    $stmt->execute([$seasonId . "%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Gather comprehensive per-racer stats for a season. */
function gatherRacerStats(PDO $pdo, array $racers, string $seasonId): array {
    $allStats = [];
    foreach ($racers as $r) {
        $rid = $r['id'];

        $statsStmt = $pdo->prepare("
            SELECT
                COUNT(*) as gps,
                AVG(gp_points) as avg_score,
                MAX(gp_points) as best_score,
                MIN(gp_points) as worst_score,
                SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN gp_points = 60 THEN 1 ELSE 0 END) as perfect_60s,
                SUM(gp_points) as total_pts
            FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%'
        ");
        $statsStmt->execute([$rid, $seasonId . '%']);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $charStmt = $pdo->prepare("SELECT character_used, COUNT(*) as c FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' GROUP BY character_used ORDER BY c DESC LIMIT 1");
        $charStmt->execute([$rid, $seasonId . '%']);
        $mainChar = $charStmt->fetchColumn() ?: 'Unknown';

        $allScoresStmt = $pdo->prepare("SELECT gp_points, rank, gpid FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' ORDER BY race_date ASC, gpid ASC");
        $allScoresStmt->execute([$rid, $seasonId . '%']);
        $scoreRows = $allScoresStmt->fetchAll(PDO::FETCH_ASSOC);
        $scores = array_column($scoreRows, 'gp_points');
        $ranks  = array_column($scoreRows, 'rank');

        $mid = max(1, intdiv(count($scores), 2));
        $firstHalfAvg  = count($scores) > 1 ? array_sum(array_slice($scores, 0, $mid)) / $mid : 0;
        $secondHalfAvg = count($scores) > 1 ? array_sum(array_slice($scores, $mid)) / max(1, count($scores) - $mid) : 0;

        $avg = (float)$stats['avg_score'];
        $variance = 0;
        if (count($scores) > 1) {
            foreach ($scores as $s) $variance += ($s - $avg) ** 2;
            $variance /= count($scores);
        }
        $stdDev = sqrt($variance);

        $bestComeback = 0;
        for ($i = 1; $i < count($ranks); $i++) {
            $jump = (int)$ranks[$i - 1] - (int)$ranks[$i];
            if ($jump > $bestComeback) $bestComeback = $jump;
        }

        $cupStmt = $pdo->prepare("SELECT cup_name, AVG(gp_points) as avg FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' GROUP BY cup_name ORDER BY avg DESC LIMIT 1");
        $cupStmt->execute([$rid, $seasonId . '%']);
        $favCup = $cupStmt->fetch(PDO::FETCH_ASSOC);

        $gpScore = calculateGPScore($pdo, $rid, $seasonId);

        $allStats[] = [
            'id'              => $rid,
            'name'            => $r['name'],
            'gps'             => (int)$stats['gps'],
            'avg'             => round($avg, 1),
            'best'            => (int)$stats['best_score'],
            'worst'           => (int)$stats['worst_score'],
            'wins'            => (int)$stats['wins'],
            'perfect_60s'     => (int)$stats['perfect_60s'],
            'total_pts'       => (int)$stats['total_pts'],
            'main_char'       => $mainChar,
            'first_half_avg'  => round($firstHalfAvg, 1),
            'second_half_avg' => round($secondHalfAvg, 1),
            'improvement'     => round($secondHalfAvg - $firstHalfAvg, 1),
            'std_dev'         => round($stdDev, 2),
            'best_comeback'   => $bestComeback,
            'gp_score'        => round((float)$gpScore, 2),
            'fav_cup'         => $favCup['cup_name'] ?? 'N/A',
            'fav_cup_avg'     => round((float)($favCup['avg'] ?? 0), 1),
        ];
    }
    return $allStats;
}

/** Auto-determine the 6 fixed awards from stats. Returns {awards, assigned}. */
function determineFixedAwards(array $racerStats, PDO $pdo, string $seasonId): array {
    $awards   = [];
    $assigned = [];

    // 1. Champion — highest GPScore
    usort($racerStats, fn($a, $b) => $b['gp_score'] <=> $a['gp_score']);
    $champion = $racerStats[0];
    $awards['Champion'] = [
        'winner' => $champion['name'],
        'reason' => 'Season champion with a GPScore of ' . $champion['gp_score'] . ' — ' . $champion['wins'] . ' win(s) across ' . $champion['gps'] . ' GPs',
    ];
    $assigned[] = $champion['name'];

    // 2. Most Improved
    $candidates = array_values(array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['gps'] >= 3));
    usort($candidates, fn($a, $b) => $b['improvement'] <=> $a['improvement']);
    if (!empty($candidates)) {
        $improved = $candidates[0];
        $awards['Most Improved'] = [
            'winner' => $improved['name'],
            'reason' => 'Average rose from ' . $improved['first_half_avg'] . ' to ' . $improved['second_half_avg'] . ' (+' . $improved['improvement'] . ' pts)',
        ];
        $assigned[] = $improved['name'];
    }

    // 3. Consistency King
    $candidates = array_values(array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['gps'] >= 5));
    usort($candidates, fn($a, $b) => $a['std_dev'] <=> $b['std_dev']);
    if (!empty($candidates)) {
        $c = $candidates[0];
        $awards['Consistency King'] = [
            'winner' => $c['name'],
            'reason' => 'Only ±' . $c['std_dev'] . ' point variance across ' . $c['gps'] . ' GPs (avg ' . $c['avg'] . ')',
        ];
        $assigned[] = $c['name'];
    }

    // 4. Comeback Player
    $candidates = array_values(array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['best_comeback'] > 0));
    usort($candidates, fn($a, $b) => $b['best_comeback'] <=> $a['best_comeback']);
    if (!empty($candidates)) {
        $c = $candidates[0];
        $awards['Comeback Player'] = [
            'winner' => $c['name'],
            'reason' => 'Jumped ' . $c['best_comeback'] . ' position(s) between consecutive GPs — never gave up',
        ];
        $assigned[] = $c['name'];
    }

    // 5. Most Entertaining
    $candidates = array_values(array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['gps'] >= 3));
    usort($candidates, fn($a, $b) => $b['std_dev'] <=> $a['std_dev']);
    if (!empty($candidates)) {
        $w = $candidates[0];
        $awards['Most Entertaining'] = [
            'winner' => $w['name'],
            'reason' => '±' . $w['std_dev'] . ' pts variance — ranged from ' . $w['worst'] . ' to ' . $w['best'] . ' points',
        ];
        $assigned[] = $w['name'];
    }

    // 6. Best Rivalry (doesn't consume a player slot — it's a pair)
    $bestRivalry = findBestRivalry($pdo, $seasonId, $assigned);
    if ($bestRivalry) {
        $awards['Best Rivalry'] = [
            'winner' => $bestRivalry['pair'],
            'reason' => $bestRivalry['reason'],
        ];
    }

    return ['awards' => $awards, 'assigned' => $assigned];
}

/** Find the closest H2H rivalry between two racers (excluding $assigned). */
function findBestRivalry(PDO $pdo, string $seasonId, array $assigned): ?array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.id, r.name FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
    ");
    $stmt->execute([$seasonId . '%']);
    $racerList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bestScore = -1;
    $bestPair  = null;

    for ($i = 0; $i < count($racerList); $i++) {
        for ($j = $i + 1; $j < count($racerList); $j++) {
            $r1 = $racerList[$i];
            $r2 = $racerList[$j];

            $matchStmt = $pdo->prepare("
                SELECT res1.gp_points as p1, res2.gp_points as p2
                FROM results res1
                JOIN results res2 ON res1.gpid = res2.gpid
                WHERE res1.racer_id = ? AND res2.racer_id = ?
                AND res1.gpid LIKE ? AND res1.gpid LIKE 's%'
            ");
            $matchStmt->execute([$r1['id'], $r2['id'], $seasonId . '%']);
            $matchups = $matchStmt->fetchAll(PDO::FETCH_ASSOC);

            $sharedGPs = count($matchups);
            if ($sharedGPs < 3) continue;

            $r1Wins = $r2Wins = $closeCalls = 0;
            foreach ($matchups as $m) {
                if ($m['p1'] > $m['p2']) $r1Wins++;
                elseif ($m['p2'] > $m['p1']) $r2Wins++;
                if (abs($m['p1'] - $m['p2']) <= 5) $closeCalls++;
            }
            $total = $r1Wins + $r2Wins;
            if ($total === 0) continue;
            $closeness    = 1 - abs($r1Wins - $r2Wins) / $total;
            $rivalryScore = $closeness * $sharedGPs + ($closeCalls * 0.5);

            if ($rivalryScore > $bestScore) {
                $bestScore = $rivalryScore;
                $bestPair = [
                    'pair'   => $r1['name'] . ' vs ' . $r2['name'],
                    'reason' => $r1['name'] . ' (' . $r1Wins . ') vs ' . $r2['name'] . ' (' . $r2Wins . ') across ' . $sharedGPs . ' shared GPs — ' . $closeCalls . ' within 5 pts',
                ];
            }
        }
    }
    return $bestPair;
}

/** Call Gemini for personalized awards. Returns ['awards' => [...], 'error' => '']. */
function generateAIAwards(PDO $pdo, array $config, array $racerStats, string $seasonId, array $assignedNames, array $fixedCategories): array {
    $unassigned = array_values(array_filter($racerStats, fn($r) => !in_array($r['name'], $assignedNames)));
    $autoCount  = count($unassigned);
    if ($autoCount === 0) return ['awards' => [], 'error' => ''];

    $apiKey = $config['gemini_api_key'] ?? '';
    if ($apiKey === '') {
        return ['awards' => [], 'error' => 'gemini_api_key is missing from config.php'];
    }

    $statsForAI = array_map(fn($r) => [
        'name'        => $r['name'],
        'gps'         => $r['gps'],
        'avg'         => $r['avg'],
        'best'        => $r['best'],
        'worst'       => $r['worst'],
        'wins'        => $r['wins'],
        'perfect_60s' => $r['perfect_60s'],
        'main_char'   => $r['main_char'],
        'improvement' => $r['improvement'],
        'std_dev'     => $r['std_dev'],
        'fav_cup'     => $r['fav_cup'],
        'fav_cup_avg' => $r['fav_cup_avg'],
    ], $unassigned);
    $statsJson = json_encode($statsForAI, JSON_PRETTY_PRINT);

    $leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
    $prompt  = "You are the awards committee for a Mario Kart 8 Deluxe league called {$leagueName}.\n\n";
    $prompt .= "Season {$seasonId} is complete. We already assigned these core awards to other players:\n";
    foreach ($fixedCategories as $cat => $info) $prompt .= "- {$cat}\n";
    $prompt .= "\nWe need exactly {$autoCount} ADDITIONAL unique, creative, personalized awards — one for each of these remaining players:\n\n";
    $prompt .= "REMAINING PLAYERS + STATS:\n{$statsJson}\n\n";
    $prompt .= "RULES:\n";
    $prompt .= "1. Every player in the list above MUST receive exactly ONE award.\n";
    $prompt .= "2. Award names should be SHORT (2-4 words), fun, creative, and Mario Kart themed.\n";
    $prompt .= "3. Each award MUST be grounded in at least one NUMERIC stat from that player's data:\n";
    $prompt .= "   gps, avg, best, worst, wins, perfect_60s, improvement, std_dev, fav_cup, fav_cup_avg.\n";
    $prompt .= "   The reason must cite that stat (e.g. \"averaged 42.1 across 17 GPs\", \"perfect 60 three times\",\n";
    $prompt .= "   \"dominant on Special Cup with a 51.2 avg\", \"swing of ±9.4 pts\", etc.).\n";
    $prompt .= "4. DO NOT base an award solely on their main_char — character can flavor the name or icon,\n";
    $prompt .= "   but the *reason* must point at a number or a cup result, never just 'plays Mario'.\n";
    $prompt .= "5. Make each award DISTINCT — vary which stat you highlight across players. Avoid giving\n";
    $prompt .= "   multiple players the same kind of award (don't hand out three 'most consistent' clones).\n";
    $prompt .= "6. BRIEF reason (max 14 words) based on actual stats.\n";
    $prompt .= "7. Keep tone playful and positive — even low performers get fun awards, not insulting ones.\n";
    $prompt .= "8. Do NOT duplicate any of the fixed category names listed above.\n\n";
    $prompt .= "RESPONSE FORMAT (strict JSON array, no markdown fences, no extra text):\n";
    $prompt .= "[{\"category\":\"Award Name\",\"icon\":\"emoji\",\"winner\":\"Exact Player Name\",\"reason\":\"Brief reason\"}]\n";
    $prompt .= "Return ONLY the raw JSON array. No ```json blocks. No explanation.";

    $payload = [
        'contents'        => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => 8192],
        'safetySettings'  => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ],
    ];

    // Resilience: retry on transient 503/429/UNAVAILABLE with exponential
    // backoff, and fall back to lighter flash models if the primary keeps
    // failing. See gemini_client.php.
    $modelChain = geminiDefaultModelChain($config['model_name'] ?? 'gemini-2.5-flash');
    [$response, $httpCode, $lastError, $modelUsed] = callGeminiWithRetry($modelChain, $apiKey, $payload);

    if ($response === null) {
        return ['awards' => [], 'error' => $lastError];
    }
    // If we ended up on a fallback, surface that in the error path only;
    // success path doesn't need to mention it.

    $json    = json_decode($response, true);
    $rawText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $rawText = trim($rawText);
    $rawText = preg_replace('/^```(?:json)?\s*\n?/i', '', $rawText);
    $rawText = preg_replace('/\n?\s*```\s*$/', '', $rawText);
    $rawText = trim($rawText);

    if (!isset($rawText[0]) || $rawText[0] !== '[') {
        $arrStart = strpos($rawText, '[');
        if ($arrStart !== false) $rawText = substr($rawText, $arrStart);
    }
    $rawText = rtrim($rawText);
    if (substr($rawText, -1) !== ']') {
        $lastBrace = strrpos($rawText, '}');
        if ($lastBrace !== false) $rawText = substr($rawText, 0, $lastBrace + 1) . ']';
    }

    $awards = json_decode($rawText, true);
    if (!is_array($awards)) {
        return ['awards' => [], 'error' => 'Failed to parse AI response: ' . substr($rawText, 0, 500)];
    }
    return ['awards' => $awards, 'error' => ''];
}

/**
 * Run the full generation: fixed awards + AI awards, save to DB.
 * Returns ['success' => bool, 'fixed' => [...], 'ai' => [...], 'error' => ''].
 */
function generateAndSaveSeasonAwards(PDO $pdo, array $config, string $seasonId): array {
    $racers = seasonAwardRacers($pdo, $seasonId);
    if (count($racers) < 2) {
        return ['success' => false, 'fixed' => [], 'ai' => [], 'error' => 'Need at least 2 racers to generate awards.'];
    }

    $racerStats        = gatherRacerStats($pdo, $racers, $seasonId);
    $fixedResult       = determineFixedAwards($racerStats, $pdo, $seasonId);
    $fixedAwards       = $fixedResult['awards'];
    $assignedNames     = $fixedResult['assigned'];
    $fixedCategories   = seasonAwardFixedCategories();

    $aiResult = ['awards' => [], 'error' => ''];
    $unassignedCount = count(array_filter($racerStats, fn($r) => !in_array($r['name'], $assignedNames)));
    if ($unassignedCount > 0) {
        $aiResult = generateAIAwards($pdo, $config, $racerStats, $seasonId, $assignedNames, $fixedCategories);
        if ($aiResult['error']) {
            return ['success' => false, 'fixed' => $fixedAwards, 'ai' => [], 'error' => $aiResult['error']];
        }
    }

    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM season_awards WHERE season_id = ?");
        $del->execute([$seasonId]);
        $ins = $pdo->prepare("INSERT INTO season_awards (season_id, award_category, winner_name, votes, voters, status) VALUES (?, ?, ?, 0, 0, 'final')");
        foreach ($fixedAwards as $category => $data) {
            $ins->execute([$seasonId, $category, $data['winner']]);
        }
        foreach ($aiResult['awards'] as $ga) {
            if (!empty($ga['winner']) && !empty($ga['category'])) {
                $ins->execute([$seasonId, $ga['category'], $ga['winner']]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'fixed' => $fixedAwards, 'ai' => $aiResult['awards'], 'error' => 'Database error: ' . $e->getMessage()];
    }

    return ['success' => true, 'fixed' => $fixedAwards, 'ai' => $aiResult['awards'], 'error' => ''];
}
