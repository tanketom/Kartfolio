<?php
/**
 * Gemini AI Recap Generator - Auto-Linking Edition
 * Path: /cdnmk/public_html/api/gemini_recap.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/ecology_text.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';

// 1. CONFIGURATION
$config = require __DIR__ . '/../../private/config/config.php';
if (!isset($config['gemini_api_key']) || empty($config['gemini_api_key'])) {
    die("Error: 'gemini_api_key' missing in config.php");
}
$apiKey = $config['gemini_api_key'];
// gemini-1.5-flash has been retired from v1beta; default to current flash.
$modelName = $config['model_name'] ?? 'gemini-2.5-flash';

require_admin();
verify_csrf();

@set_time_limit(300);
ignore_user_abort(true);

// 2. GATHER DATA (Last Week of Races)
// Focus on races from the last 7 days for timely broadcasts (unless Director's Notes override)
$currentSeason = getCurrentSeasonNumber();

// Check if director wants a custom time range or length
$userNotes = trim($_POST['notes'] ?? '');
$customTimeRange = null;
$customLength = null;

// Parse time range
if (preg_match('/last\s+(\d+)\s+(day|week|month)s?/i', $userNotes, $matches)) {
    $amount = (int)$matches[1];
    $unit = strtolower($matches[2]);
    if ($unit === 'week') $amount *= 7;
    if ($unit === 'month') $amount *= 30;
    $customTimeRange = $amount;
}

// Parse length (e.g., "200 words", "short", "long", "detailed")
if (preg_match('/(\d+)\s*words?/i', $userNotes, $matches)) {
    $customLength = (int)$matches[1];
} elseif (preg_match('/\b(short|brief)\b/i', $userNotes)) {
    $customLength = 150;
} elseif (preg_match('/\b(long|detailed|extended)\b/i', $userNotes)) {
    $customLength = 500;
}

$daysBack = $customTimeRange ?? 7;
$targetLength = $customLength ?? 300;
$cutoffDate = date('Y-m-d', strtotime("-{$daysBack} days"));

$stmt = $pdo->prepare("SELECT res.*, r.name, r.nickname
                       FROM results res
                       JOIN racers r ON res.racer_id = r.id
                       WHERE res.gpid LIKE ? AND res.race_date >= ?
                       ORDER BY res.race_date DESC, res.gpid DESC");
$stmt->execute([$currentSeason . "%", $cutoffDate]);
$raceData = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($raceData)) { die("Error: No race data found for Season $currentSeason in the last {$daysBack} days."); }

// 3. FETCH SEASON RULES/PARAMETERS
$seasonRules = null;
try {
    $rulesStmt = $pdo->prepare("
        SELECT attendance_weight, weekly_bonus_cap, min_races_threshold, drop_rate
        FROM season_meta
        WHERE season_id = ?
    ");
    $rulesStmt->execute([$currentSeason]);
    $seasonRules = $rulesStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fail silently if season_meta doesn't exist
}

// 4. BUILD CONTEXT & CAPTURE GPIDs
$cups = [];
$racers = [];
$gpidList = []; // New: Capture IDs for linking

$dataContext = "SEASON: $currentSeason.\n";

// Add season rules if available
if ($seasonRules) {
    $dataContext .= "\n*** SEASON RULES & PARAMETERS ***\n";
    $dataContext .= "Attendance Weight: {$seasonRules['attendance_weight']}x (encourages consistent participation)\n";
    $dataContext .= "Weekly Bonus Cap: {$seasonRules['weekly_bonus_cap']} races max count toward attendance bonuses\n";
    $dataContext .= "Minimum Races Threshold: {$seasonRules['min_races_threshold']} races required before averaging kicks in\n";
    $dataContext .= "Drop Rate: Bottom {$seasonRules['drop_rate']}% of scores dropped for final GPScore calculation\n";
    $dataContext .= "Scoring System: GPScore™ uses average performance + attendance bonuses, not just raw placement\n\n";
}

// Organize data by GPID to make it clearer for the AI
$groupedRaces = [];
foreach ($raceData as $row) {
    $cups[] = $row['cup_name'];
    $racers[] = $row['name'];
    $gpidList[] = $row['gpid']; // Capture ID
    $groupedRaces[$row['gpid']][] = $row;
}

// De-duplicate lists
$cups = array_unique($cups);
$racers = array_unique($racers);
$gpidList = array_unique($gpidList);

// Format Text for AI
$dataContext .= "RECENT CUPS: " . implode(', ', $cups) . ".\n";
$dataContext .= "ACTIVE RACERS: " . implode(', ', $racers) . ".\n\n";
$dataContext .= "RACE RESULTS (Newest first):\n";

foreach ($groupedRaces as $gpid => $results) {
    $cupName = $results[0]['cup_name'];
    $raceDate = $results[0]['race_date'];
    $dataContext .= "--- GP $gpid ($cupName Cup) - " . date('M j', strtotime($raceDate)) . " ---\n";
    foreach ($results as $row) {
        $lol = $row['is_lol'] ? "[LUDWIG OBSTRUCTION]" : "";
        $dataContext .= "Rank {$row['rank']}: {$row['name']} ({$row['gp_points']}pts) - {$row['character_used']}. $lol\n";
    }
    $dataContext .= "\n";
}

// 5. FETCH NEMESIS OF THE WEEK
$topNemesis = null;
try {
    $feudStmt = $pdo->prepare("
        SELECT r1.name as p1, r2.name as p2,
               COUNT(*) as meetings,
               SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) as p1_wins
        FROM results res1
        JOIN results res2 ON res1.gpid = res2.gpid AND res1.cup_name = res2.cup_name
        JOIN racers r1 ON res1.racer_id = r1.id
        JOIN racers r2 ON res2.racer_id = r2.id
        WHERE res1.racer_id < res2.racer_id
          AND res1.gpid LIKE ?
        GROUP BY res1.racer_id, res2.racer_id
        HAVING meetings >= 2
        ORDER BY (COUNT(*) * (1.0 - ABS((CAST(SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) AS FLOAT) / COUNT(*)) - 0.5) * 2.0)) DESC
        LIMIT 1
    ");
    $feudStmt->execute([$currentSeason . "%"]);
    $topNemesis = $feudStmt->fetch(PDO::FETCH_ASSOC);

    if ($topNemesis) {
        $p1WinRate = round(($topNemesis['p1_wins'] / $topNemesis['meetings']) * 100, 1);
        $p2WinRate = round(100 - $p1WinRate, 1);
        $dataContext .= "\n*** NEMESIS OF THE WEEK ***\n";
        $dataContext .= "{$topNemesis['p1']} vs {$topNemesis['p2']}\n";
        $dataContext .= "Meetings: {$topNemesis['meetings']} | {$topNemesis['p1']}: {$p1WinRate}% | {$topNemesis['p2']}: {$p2WinRate}%\n";
        $dataContext .= "Status: Locked in a tight struggle with very close win rates.\n\n";
    }
} catch (Exception $e) {
    // Fail silently
}

// 6. FETCH CURRENT FORM RANKINGS (Last 5 races per racer)
$formData = [];
try {
    $formStmt = $pdo->prepare("
        SELECT r.name, res.gp_points, res.race_date
        FROM results res
        JOIN racers r ON res.racer_id = r.id
        WHERE res.gpid LIKE ?
        ORDER BY r.name, res.race_date DESC
    ");
    $formStmt->execute([$currentSeason . "%"]);
    $allResults = $formStmt->fetchAll(PDO::FETCH_ASSOC);

    $racerScores = [];
    foreach ($allResults as $row) {
        if (!isset($racerScores[$row['name']])) {
            $racerScores[$row['name']] = [];
        }
        $racerScores[$row['name']][] = $row['gp_points'];
    }

    foreach ($racerScores as $name => $scores) {
        $last5 = array_slice($scores, 0, 5);
        $formAvg = array_sum($last5) / count($last5);
        $formData[] = ['name' => $name, 'form' => round($formAvg, 2)];
    }

    usort($formData, fn($a, $b) => $b['form'] <=> $a['form']);

    if (!empty($formData)) {
        $dataContext .= "*** CURRENT FORM RANKINGS (Last 5 GPs Average) ***\n";
        foreach (array_slice($formData, 0, 5) as $idx => $racer) {
            $rank = $idx + 1;
            $dataContext .= "{$rank}. {$racer['name']}: {$racer['form']} pts\n";
        }
        $dataContext .= "\n";
    }
} catch (Exception $e) {
    // Fail silently
}

// 8. PERSONA LOGIC
$pKey = $_POST['program'] ?? 'random';

// Reject non-AI programs (e.g. press_office) — those have their own
// publishing path that bypasses Gemini entirely. Falling through here
// would produce AI text tagged with a non-AI program key.
if ($pKey === 'press_office') {
    die("Error: 'press_office' is a hand-written program. Use /api/press-release instead.");
}

if ($pKey === 'random') {
    $availableKeys = array_diff(array_keys($ecology_personas), ['random']);
    $pKey = $availableKeys[array_rand($availableKeys)];
}
$persona = $ecology_personas[$pKey] ?? $ecology_personas['core_team'];

// 8b. FETCH LAST 2 BROADCASTS FOR THIS SHOW (For Continuity)
$previousBroadcasts = "";
try {
    $prevStmt = $pdo->prepare("
        SELECT headline, recap_text, created_at
        FROM recap_archive
        WHERE program_key = ?
        ORDER BY created_at DESC
        LIMIT 2
    ");
    $prevStmt->execute([$pKey]);
    $prevRecaps = $prevStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($prevRecaps)) {
        $previousBroadcasts = "\n*** PREVIOUS BROADCASTS (For Continuity & Callbacks) ***\n";
        $previousBroadcasts .= "You can reference these past shows if relevant, but focus on NEW developments:\n\n";

        foreach ($prevRecaps as $idx => $prev) {
            $broadcastNum = $idx + 1;
            $airDate = date('M j', strtotime($prev['created_at']));
            $previousBroadcasts .= "--- Previous Show #{$broadcastNum} ({$airDate}) ---\n";
            $previousBroadcasts .= "Headline: {$prev['headline']}\n";
            $previousBroadcasts .= "Summary: " . mb_substr($prev['recap_text'], 0, 300, 'UTF-8') . "...\n\n";
        }
    }
} catch (Exception $e) {
    // Fail silently if no previous broadcasts
}

// 9. DIRECTOR'S NOTES
$notesInstruction = "";
if (!empty($userNotes)) {
    $notesInstruction = "\n\n*** DIRECTOR'S PRIORITY INSTRUCTIONS ***\nYou MUST include these specific points/focus in your script:\n" . $userNotes . "\n******************************************\n";
}

// 10. CONSTRUCT PROMPT
$fullPrompt = "TASK: Write a broadcast script analysing the recent Mario Kart results.\n\n";
$fullPrompt .= "PERSONA: " . $persona['prompt'] . "\n\n";
$fullPrompt .= "LENGTH TARGET: Aim for approximately {$targetLength} words in the script body (excluding headline and quote).\n\n";
$fullPrompt .= "CRITICAL FORMATTING INSTRUCTIONS:\n";
$fullPrompt .= "1. The VERY FIRST line must be 'HEADLINE: [Insert 5-8 word punchy headline here]'\n";
$fullPrompt .= "2. The SECOND line must be 'QUOTE: [Insert a short, memorable quote from the script]'\n";
$fullPrompt .= "3. Use **Double Asterisks** around racer names (e.g. **Mario**).\n";
$fullPrompt .= "4. Leave a blank line, then start the actual script.\n\n";
$fullPrompt .= "DATA SOURCE:\n" . $dataContext;
$fullPrompt .= $previousBroadcasts;
$fullPrompt .= $notesInstruction;

// 11. CALL GEMINI API (shared client: retry + model fallback)
$payload = [
    "contents" => [["parts" => [["text" => $fullPrompt]]]],
    "safetySettings" => [
        ["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_ONLY_HIGH"]
    ]
];

[$response, $httpCode, $lastError, $modelUsed] =
    callGeminiWithRetry(geminiDefaultModelChain($modelName), $apiKey, $payload);

// 12. HANDLE RESPONSE & SAVE
if ($httpCode === 200 && $response) {
    $json = json_decode($response, true);
    
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        $rawText = $json['candidates'][0]['content']['parts'][0]['text'];
        
        $headline = "Breaking News: Season " . $currentSeason . " Update"; 
        $quote = "Live results coming in..."; 
        $bodyText = $rawText;

        // Parse Output
        if (preg_match('/^HEADLINE:\s*(.*)$/m', $rawText, $matches)) {
            $headline = trim($matches[1]);
            $bodyText = str_replace($matches[0], '', $bodyText);
        }
        if (preg_match('/^QUOTE:\s*(.*)$/m', $rawText, $matches)) {
            $quote = trim($matches[1]);
            $bodyText = str_replace($matches[0], '', $bodyText);
        }
        $bodyText = trim($bodyText);

        // Prepare Linked IDs string (e.g., "s01g04,s01g05")
        $linkedIDsString = implode(',', $gpidList);

        // Save to DB
        $save = $pdo->prepare("
            INSERT INTO recap_archive 
            (season_id, recap_text, headline, key_quote, program_key, linked_gpids) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $save->execute([$currentSeason, $bodyText, $headline, $quote, $pKey, $linkedIDsString]);
        
        $newId = $pdo->lastInsertId();
        header("Location: /view-recap/$newId");
        exit;
    } else {
        echo "<h1>AI Generation Failed</h1>";
        echo "<pre>" . htmlspecialchars(print_r($json, true)) . "</pre>";
        exit;
    }
} else {
    // Shared client surfaces a cumulative error across all attempted models.
    echo "<h1>API Connection Error ($httpCode)</h1>";
    echo "<pre>" . htmlspecialchars($lastError) . "</pre>";
    exit;
}