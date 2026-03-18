<?php
/**
 * Season Awards - Fully Automatic Generation
 * 6 fixed awards auto-determined from stats + AI-generated personalized awards
 * Constraint: exactly one award per player
 * Path: /cdnmk/public_html/admin/season_awards.php
 */
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/settings.php';

require_admin();

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();
$config = require __DIR__ . '/../../private/config/config.php';

// Fetch all racers who participated in this season
$racersStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
    ORDER BY r.name ASC
");
$racersStmt->execute([$seasonId . "%"]);
$racers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);
$racerNames = array_column($racers, 'name');
$racerCount = count($racers);

// Fixed award definitions
$fixedCategories = [
    'Champion'         => ['icon' => '🏆', 'desc' => 'Highest overall GPScore'],
    'Most Improved'    => ['icon' => '📈', 'desc' => 'Biggest avg improvement from 1st to 2nd half'],
    'Consistency King' => ['icon' => '🎯', 'desc' => 'Lowest score variance (min 5 GPs)'],
    'Comeback Player'  => ['icon' => '👑', 'desc' => 'Best single-GP rank jump from previous GP'],
    'Most Entertaining'=> ['icon' => '🎭', 'desc' => 'Highest score variance — the wildcard'],
    'Best Rivalry'     => ['icon' => '⚔️', 'desc' => 'Closest head-to-head record between two racers'],
];

/**
 * Gather comprehensive stats for every racer
 */
function gatherRacerStats($pdo, $racers, $seasonId) {
    $allStats = [];
    foreach ($racers as $r) {
        $rid = $r['id'];

        // Basic stats
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

        // Most used character
        $charStmt = $pdo->prepare("SELECT character_used, COUNT(*) as c FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' GROUP BY character_used ORDER BY c DESC LIMIT 1");
        $charStmt->execute([$rid, $seasonId . '%']);
        $mainChar = $charStmt->fetchColumn() ?: 'Unknown';

        // All scores chronologically
        $allScoresStmt = $pdo->prepare("SELECT gp_points, rank, gpid FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' ORDER BY race_date ASC, gpid ASC");
        $allScoresStmt->execute([$rid, $seasonId . '%']);
        $scoreRows = $allScoresStmt->fetchAll(PDO::FETCH_ASSOC);
        $scores = array_column($scoreRows, 'gp_points');
        $ranks = array_column($scoreRows, 'rank');

        // Half-season split
        $mid = max(1, intdiv(count($scores), 2));
        $firstHalfAvg = count($scores) > 1 ? array_sum(array_slice($scores, 0, $mid)) / $mid : 0;
        $secondHalfAvg = count($scores) > 1 ? array_sum(array_slice($scores, $mid)) / max(1, count($scores) - $mid) : 0;

        // Standard deviation
        $avg = (float)$stats['avg_score'];
        $variance = 0;
        if (count($scores) > 1) {
            foreach ($scores as $s) {
                $variance += ($s - $avg) ** 2;
            }
            $variance /= count($scores);
        }
        $stdDev = sqrt($variance);

        // Best comeback (biggest rank improvement from one GP to the next)
        $bestComeback = 0;
        for ($i = 1; $i < count($ranks); $i++) {
            $jump = (int)$ranks[$i - 1] - (int)$ranks[$i]; // positive = improved
            if ($jump > $bestComeback) {
                $bestComeback = $jump;
            }
        }

        // Favorite cup
        $cupStmt = $pdo->prepare("SELECT cup_name, AVG(gp_points) as avg FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' GROUP BY cup_name ORDER BY avg DESC LIMIT 1");
        $cupStmt->execute([$rid, $seasonId . '%']);
        $favCup = $cupStmt->fetch(PDO::FETCH_ASSOC);

        // GPScore
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

/**
 * Auto-determine the 6 fixed awards from stats
 */
function determineFixedAwards($racerStats, $pdo, $seasonId) {
    $awards = [];
    $assigned = []; // Track who already has an award

    // 1. Champion — highest GPScore
    usort($racerStats, fn($a, $b) => $b['gp_score'] <=> $a['gp_score']);
    $champion = $racerStats[0];
    $awards['Champion'] = [
        'winner' => $champion['name'],
        'reason' => 'Season champion with a GPScore of ' . $champion['gp_score'] . ' — ' . $champion['wins'] . ' win(s) across ' . $champion['gps'] . ' GPs',
    ];
    $assigned[] = $champion['name'];

    // 2. Most Improved — biggest positive improvement (exclude champion)
    $candidates = array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['gps'] >= 3);
    usort($candidates, fn($a, $b) => $b['improvement'] <=> $a['improvement']);
    $candidates = array_values($candidates);
    if (!empty($candidates)) {
        $improved = $candidates[0];
        $awards['Most Improved'] = [
            'winner' => $improved['name'],
            'reason' => 'Average rose from ' . $improved['first_half_avg'] . ' to ' . $improved['second_half_avg'] . ' (+' . $improved['improvement'] . ' pts)',
        ];
        $assigned[] = $improved['name'];
    }

    // 3. Consistency King — lowest std deviation (min 5 GPs, exclude assigned)
    $candidates = array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['gps'] >= 5);
    usort($candidates, fn($a, $b) => $a['std_dev'] <=> $b['std_dev']);
    $candidates = array_values($candidates);
    if (!empty($candidates)) {
        $consistent = $candidates[0];
        $awards['Consistency King'] = [
            'winner' => $consistent['name'],
            'reason' => 'Only ±' . $consistent['std_dev'] . ' point variance across ' . $consistent['gps'] . ' GPs (avg ' . $consistent['avg'] . ')',
        ];
        $assigned[] = $consistent['name'];
    }

    // 4. Comeback Player — best single-GP rank jump (exclude assigned)
    $candidates = array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['best_comeback'] > 0);
    usort($candidates, fn($a, $b) => $b['best_comeback'] <=> $a['best_comeback']);
    $candidates = array_values($candidates);
    if (!empty($candidates)) {
        $comeback = $candidates[0];
        $awards['Comeback Player'] = [
            'winner' => $comeback['name'],
            'reason' => 'Jumped ' . $comeback['best_comeback'] . ' position(s) between consecutive GPs — never gave up',
        ];
        $assigned[] = $comeback['name'];
    }

    // 5. Most Entertaining — highest std deviation (wildcard, exclude assigned)
    $candidates = array_filter($racerStats, fn($r) => !in_array($r['name'], $assigned) && $r['gps'] >= 3);
    usort($candidates, fn($a, $b) => $b['std_dev'] <=> $a['std_dev']);
    $candidates = array_values($candidates);
    if (!empty($candidates)) {
        $wild = $candidates[0];
        $awards['Most Entertaining'] = [
            'winner' => $wild['name'],
            'reason' => '±' . $wild['std_dev'] . ' pts variance — ranged from ' . $wild['worst'] . ' to ' . $wild['best'] . ' points',
        ];
        $assigned[] = $wild['name'];
    }

    // 6. Best Rivalry — closest head-to-head between any two racers (exclude assigned)
    $bestRivalry = findBestRivalry($pdo, $seasonId, $assigned);
    if ($bestRivalry) {
        $awards['Best Rivalry'] = [
            'winner' => $bestRivalry['pair'],
            'reason' => $bestRivalry['reason'],
        ];
        // Rivalry doesn't consume a player slot (it's a pair)
    }

    return ['awards' => $awards, 'assigned' => $assigned];
}

/**
 * Find the closest rivalry — two racers who raced each other the most
 * with the closest win rate against each other
 */
function findBestRivalry($pdo, $seasonId, $assigned) {
    // Get all racer IDs with names
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.id, r.name FROM racers r
        JOIN results res ON r.id = res.racer_id
        WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
    ");
    $stmt->execute([$seasonId . '%']);
    $racerList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bestScore = -1;
    $bestPair = null;

    for ($i = 0; $i < count($racerList); $i++) {
        for ($j = $i + 1; $j < count($racerList); $j++) {
            $r1 = $racerList[$i];
            $r2 = $racerList[$j];

            // Find GPs where both raced
            $stmt = $pdo->prepare("
                SELECT res1.gp_points as p1, res2.gp_points as p2
                FROM results res1
                JOIN results res2 ON res1.gpid = res2.gpid
                WHERE res1.racer_id = ? AND res2.racer_id = ?
                AND res1.gpid LIKE ? AND res1.gpid LIKE 's%'
            ");
            $stmt->execute([$r1['id'], $r2['id'], $seasonId . '%']);
            $matchups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sharedGPs = count($matchups);
            if ($sharedGPs < 3) continue; // Need minimum matchups

            $r1Wins = 0;
            $r2Wins = 0;
            $closeCalls = 0;
            foreach ($matchups as $m) {
                if ($m['p1'] > $m['p2']) $r1Wins++;
                elseif ($m['p2'] > $m['p1']) $r2Wins++;
                if (abs($m['p1'] - $m['p2']) <= 5) $closeCalls++;
            }

            // Score: closer to 50/50 = better, more shared GPs = better
            $total = $r1Wins + $r2Wins;
            if ($total === 0) continue;
            $closeness = 1 - abs($r1Wins - $r2Wins) / $total; // 0 to 1
            $rivalryScore = $closeness * $sharedGPs + ($closeCalls * 0.5);

            if ($rivalryScore > $bestScore) {
                $bestScore = $rivalryScore;
                $bestPair = [
                    'pair' => $r1['name'] . ' vs ' . $r2['name'],
                    'reason' => $r1['name'] . ' (' . $r1Wins . ') vs ' . $r2['name'] . ' (' . $r2Wins . ') across ' . $sharedGPs . ' shared GPs — ' . $closeCalls . ' within 5 pts',
                ];
            }
        }
    }

    return $bestPair;
}

/**
 * Call Gemini to generate remaining personalized awards
 */
function generateAIAwards($config, $racerStats, $seasonId, $assignedNames, $fixedCategories) {
    $unassigned = array_filter($racerStats, fn($r) => !in_array($r['name'], $assignedNames));
    $autoCount = count($unassigned);

    if ($autoCount === 0) {
        return ['awards' => [], 'error' => ''];
    }

    // Prepare stats (only unassigned racers)
    $statsForAI = array_values(array_map(fn($r) => [
        'name'            => $r['name'],
        'gps'             => $r['gps'],
        'avg'             => $r['avg'],
        'best'            => $r['best'],
        'worst'           => $r['worst'],
        'wins'            => $r['wins'],
        'perfect_60s'     => $r['perfect_60s'],
        'main_char'       => $r['main_char'],
        'improvement'     => $r['improvement'],
        'std_dev'         => $r['std_dev'],
        'fav_cup'         => $r['fav_cup'],
        'fav_cup_avg'     => $r['fav_cup_avg'],
    ], $unassigned));

    $statsJson = json_encode($statsForAI, JSON_PRETTY_PRINT);

    $awardLeagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
    $prompt = "You are the awards committee for a Mario Kart 8 Deluxe league called {$awardLeagueName}.\n\n";
    $prompt .= "Season {$seasonId} is complete. We already assigned these core awards to other players:\n";
    foreach ($fixedCategories as $cat => $info) {
        $prompt .= "- {$cat}\n";
    }
    $prompt .= "\nWe need exactly {$autoCount} ADDITIONAL unique, creative, personalized awards — one for each of these remaining players:\n\n";
    $prompt .= "REMAINING PLAYERS + STATS:\n{$statsJson}\n\n";
    $prompt .= "RULES:\n";
    $prompt .= "1. Every player in the list above MUST receive exactly ONE award\n";
    $prompt .= "2. Award names should be SHORT (2-4 words), fun, creative, and Mario Kart themed\n";
    $prompt .= "3. Each award needs a BRIEF reason (max 12 words) based on their actual stats\n";
    $prompt .= "4. Keep tone playful and positive — even low performers get fun awards, not insulting ones\n";
    $prompt .= "5. Do NOT duplicate any of the fixed category names listed above\n\n";
    $prompt .= "RESPONSE FORMAT (strict JSON array, no markdown fences, no extra text):\n";
    $prompt .= "[{\"category\":\"Award Name\",\"icon\":\"emoji\",\"winner\":\"Exact Player Name\",\"reason\":\"Brief reason\"}]\n";
    $prompt .= "Return ONLY the raw JSON array. No ```json blocks. No explanation.";

    $apiKey = $config['gemini_api_key'];
    $modelName = $config['model_name'] ?? 'gemini-2.5-flash';
    $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";

    $payload = [
        "contents" => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => [
            "temperature" => 0.9,
            "maxOutputTokens" => 8192,
        ],
        "safetySettings" => [
            ["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_ONLY_HIGH"],
            ["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_ONLY_HIGH"],
            ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_ONLY_HIGH"],
            ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_ONLY_HIGH"]
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlError) {
        return ['awards' => [], 'error' => "cURL error ({$curlErrno}): {$curlError}"];
    }

    if ($httpCode !== 200 || !$response) {
        $detail = $response ? substr($response, 0, 300) : 'Empty response';
        return ['awards' => [], 'error' => "Gemini API error (HTTP {$httpCode}): {$detail}"];
    }

    $json = json_decode($response, true);
    $rawText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Strip markdown code fences (various formats)
    $rawText = trim($rawText);
    $rawText = preg_replace('/^```(?:json)?\s*\n?/i', '', $rawText);
    $rawText = preg_replace('/\n?\s*```\s*$/', '', $rawText);
    $rawText = trim($rawText);

    // Try to extract JSON array even if there's surrounding text
    if ($rawText[0] !== '[') {
        // Look for the array start
        $arrStart = strpos($rawText, '[');
        if ($arrStart !== false) {
            $rawText = substr($rawText, $arrStart);
        }
    }

    // Ensure the array is properly closed (handle truncation)
    $rawText = rtrim($rawText);
    if (substr($rawText, -1) !== ']') {
        // Try to find the last complete object and close the array
        $lastBrace = strrpos($rawText, '}');
        if ($lastBrace !== false) {
            $rawText = substr($rawText, 0, $lastBrace + 1) . ']';
        }
    }

    $awards = json_decode($rawText, true);
    if (!is_array($awards)) {
        return ['awards' => [], 'error' => "Failed to parse AI response: " . htmlspecialchars(substr($rawText, 0, 500))];
    }

    return ['awards' => $awards, 'error' => ''];
}

// ============================
// MAIN LOGIC
// ============================

$successMessage = '';
$errorMessage = '';
$allAwards = [];

// Gather stats
$racerStats = [];
if ($racerCount > 0) {
    $racerStats = gatherRacerStats($pdo, $racers, $seasonId);
}

// Handle generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_awards'])) {
    verify_csrf();
    if ($racerCount < 2) {
        $errorMessage = "Need at least 2 racers to generate awards.";
    } else {
        // Step 1: Auto-determine fixed awards
        $fixedResult = determineFixedAwards($racerStats, $pdo, $seasonId);
        $fixedAwards = $fixedResult['awards'];
        $assignedNames = $fixedResult['assigned'];

        // Step 2: Generate AI awards for remaining players
        $aiResult = ['awards' => [], 'error' => ''];
        $unassignedCount = count(array_filter($racerStats, fn($r) => !in_array($r['name'], $assignedNames)));

        if ($unassignedCount > 0) {
            $aiResult = generateAIAwards($config, $racerStats, $seasonId, $assignedNames, $fixedCategories);
            if ($aiResult['error']) {
                $errorMessage = $aiResult['error'];
            }
        }

        // Step 3: Save everything to database
        if (empty($errorMessage)) {
            try {
                $pdo->beginTransaction();

                $deleteStmt = $pdo->prepare("DELETE FROM season_awards WHERE season_id = ?");
                $deleteStmt->execute([$seasonId]);

                $insertStmt = $pdo->prepare("
                    INSERT INTO season_awards (season_id, award_category, winner_name, votes, voters, status)
                    VALUES (?, ?, ?, 0, 0, 'final')
                ");

                // Save fixed awards
                foreach ($fixedAwards as $category => $data) {
                    $insertStmt->execute([$seasonId, $category, $data['winner']]);
                }

                // Save AI awards
                foreach ($aiResult['awards'] as $ga) {
                    if (!empty($ga['winner']) && !empty($ga['category'])) {
                        $insertStmt->execute([$seasonId, $ga['category'], $ga['winner']]);
                    }
                }

                $pdo->commit();
                $successMessage = "All awards generated and saved successfully!";

                // Store generated details in session for display
                $_SESSION['award_details'] = [
                    'fixed' => $fixedAwards,
                    'ai' => $aiResult['awards'],
                ];
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMessage = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Fetch existing awards from DB
$awardsStmt = $pdo->prepare("SELECT * FROM season_awards WHERE season_id = ? ORDER BY id ASC");
$awardsStmt->execute([$seasonId]);
$existingAwards = $awardsStmt->fetchAll(PDO::FETCH_ASSOC);

// Merge session details for reasons/icons
$awardDetails = $_SESSION['award_details'] ?? ['fixed' => [], 'ai' => []];

// Build display data
$displayAwards = [];
foreach ($existingAwards as $award) {
    $cat = $award['award_category'];
    $isFixed = isset($fixedCategories[$cat]);
    $icon = '🌟';
    $reason = '';

    if ($isFixed) {
        $icon = $fixedCategories[$cat]['icon'];
        $reason = $awardDetails['fixed'][$cat]['reason'] ?? '';
    } else {
        // Look in AI details
        foreach ($awardDetails['ai'] as $ga) {
            if (($ga['category'] ?? '') === $cat) {
                $icon = $ga['icon'] ?? '🌟';
                $reason = $ga['reason'] ?? '';
                break;
            }
        }
    }

    $displayAwards[] = [
        'category' => $cat,
        'winner'   => $award['winner_name'],
        'icon'     => $icon,
        'reason'   => $reason,
        'is_fixed' => $isFixed,
    ];
}

// Check assignment coverage
$awardedNames = array_column($displayAwards, 'winner');
$uniqueAwarded = array_unique(array_filter($awardedNames, fn($n) => !str_contains($n, ' vs ')));
$missingRacers = array_diff($racerNames, $uniqueAwarded);

$pageTitle = "Season Awards - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <div class="admin-awards-header">
        <h1 class="admin-awards-title">Season Awards</h1>
        <p class="admin-awards-subtitle">
            Season <?= strtoupper($seasonId) ?> &mdash; <?= $racerCount ?> racers
        </p>
    </div>

    <?php if ($successMessage): ?>
        <div class="admin-alert-success-awards"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="admin-alert-error-awards"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <!-- Generate Button -->
    <form method="POST" class="awards-generate-form">
        <?= csrf_field() ?>
        <input type="hidden" name="generate_awards" value="1">
        <button type="submit" class="btn btn-primary awards-generate-btn" onclick="this.disabled=true; this.innerHTML='🔄 Generating... (this may take a moment)'; this.form.submit();">
            🤖 <?= empty($existingAwards) ? 'Generate All Awards Automatically' : 'Regenerate All Awards' ?>
        </button>
        <p class="awards-generate-hint">
            Automatically determines 6 core awards from stats, then generates <?= max(0, $racerCount - 6) ?> personalized awards with AI — one per racer.
        </p>
    </form>

    <!-- Awards Display -->
    <?php if (!empty($displayAwards)): ?>

        <?php if (!empty($missingRacers)): ?>
        <div class="admin-alert-error-awards awards-missing-alert">
            ⚠️ Missing awards for: <?= htmlspecialchars(implode(', ', $missingRacers)) ?>
        </div>
        <?php endif; ?>

        <!-- Core Awards -->
        <div class="awards-section">
            <h3 class="awards-section-title awards-section-core">Core Awards</h3>
            <div class="awards-ceremony-grid">
                <?php foreach ($displayAwards as $award):
                    if (!$award['is_fixed']) continue;
                    $charImg = 'Mii';
                    foreach ($racerStats as $rs) {
                        if ($rs['name'] === $award['winner']) {
                            $charImg = $rs['main_char'];
                            break;
                        }
                    }
                ?>
                <div class="award-card award-card-core">
                    <div class="award-card-icon"><?= $award['icon'] ?></div>
                    <h4 class="award-card-category"><?= htmlspecialchars($award['category']) ?></h4>
                    <div class="award-card-portrait">
                        <img src="/assets/img/<?= htmlspecialchars($charImg) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="">
                    </div>
                    <div class="award-card-winner"><?= htmlspecialchars($award['winner']) ?></div>
                    <?php if ($award['reason']): ?>
                    <p class="award-card-reason"><?= htmlspecialchars($award['reason']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Personalized Awards -->
        <?php
        $aiAwards = array_filter($displayAwards, fn($a) => !$a['is_fixed']);
        if (!empty($aiAwards)):
        ?>
        <div class="awards-section">
            <h3 class="awards-section-title awards-section-ai">Personalized Awards</h3>
            <div class="awards-ceremony-grid">
                <?php foreach ($aiAwards as $award):
                    $charImg = 'Mii';
                    foreach ($racerStats as $rs) {
                        if ($rs['name'] === $award['winner']) {
                            $charImg = $rs['main_char'];
                            break;
                        }
                    }
                ?>
                <div class="award-card award-card-ai">
                    <div class="award-card-icon"><?= $award['icon'] ?></div>
                    <h4 class="award-card-category"><?= htmlspecialchars($award['category']) ?></h4>
                    <div class="award-card-portrait">
                        <img src="/assets/img/<?= htmlspecialchars($charImg) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="">
                    </div>
                    <div class="award-card-winner"><?= htmlspecialchars($award['winner']) ?></div>
                    <?php if ($award['reason']): ?>
                    <p class="award-card-reason"><?= htmlspecialchars($award['reason']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="admin-awards-form-footer">
            <a href="/view-season-report?season=<?= $seasonId ?>" class="btn-secondary admin-btn-back">
                ← Back to Report
            </a>
            <form method="POST" class="awards-regen-form">
                <input type="hidden" name="generate_awards" value="1">
                <button type="submit" class="btn-secondary awards-regen-btn">
                    🔄 Regenerate
                </button>
            </form>
        </div>

    <?php else: ?>
        <div class="awards-empty-state">
            <div class="awards-empty-icon">🏅</div>
            <h3>No Awards Yet</h3>
            <p>Click the button above to automatically generate all season awards.</p>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
