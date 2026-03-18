<?php
/**
 * Gemini AI Tournament Recap Generator
 * Path: /cdnmk/public_html/api/gemini_tournament_recap.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/ecology_text.php';

// 1. CONFIGURATION
$config = require __DIR__ . '/../../private/config/config.php';
if (!isset($config['gemini_api_key']) || empty($config['gemini_api_key'])) {
    die("Error: 'gemini_api_key' missing in config.php");
}
$apiKey = $config['gemini_api_key'];
$modelName = $config['model_name'] ?? 'gemini-1.5-flash';

require_admin();
verify_csrf();

$tournamentId = $_POST['tournament_id'] ?? null;
if (!$tournamentId) {
    die("Error: tournament_id is required");
}

// 2. FETCH TOURNAMENT DATA
$stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament || $tournament['status'] !== 'completed') {
    die("Error: Tournament not found or not completed");
}

// Get tournament participants
$stmt = $pdo->prepare("
    SELECT tp.*, r.name
    FROM tournament_participants tp
    JOIN racers r ON tp.racer_id = r.id
    WHERE tp.tournament_id = ?
    ORDER BY tp.seed ASC
");
$stmt->execute([$tournamentId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all matches with results
$stmt = $pdo->prepare("
    SELECT m.*, m.round, m.bracket, m.cup_name,
           w.name as winner_name
    FROM tournament_matches m
    LEFT JOIN racers w ON m.winner_id = w.id
    WHERE m.tournament_id = ? AND m.status = 'completed'
    ORDER BY m.round, m.match_number
");
$stmt->execute([$tournamentId]);
$matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load match participants for each match
foreach ($matches as &$match) {
    $stmt = $pdo->prepare("
        SELECT tmp.*, r.name
        FROM tournament_match_participants tmp
        JOIN racers r ON tmp.racer_id = r.id
        WHERE tmp.match_id = ?
        ORDER BY COALESCE(tmp.placement, 99), tmp.points DESC
    ");
    $stmt->execute([$match['id']]);
    $match['participants'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($match);

// Get winner details
$stmt = $pdo->prepare("SELECT name FROM racers WHERE id = ?");
$stmt->execute([$tournament['winner_id']]);
$winnerName = $stmt->fetchColumn();

// 3. BUILD CONTEXT
$userNotes = trim($_POST['notes'] ?? '');
$customLength = null;

// Parse length
if (preg_match('/(\d+)\s*words?/i', $userNotes, $matches)) {
    $customLength = (int)$matches[1];
} elseif (preg_match('/\b(short|brief)\b/i', $userNotes)) {
    $customLength = 200;
} elseif (preg_match('/\b(long|detailed|extended)\b/i', $userNotes)) {
    $customLength = 600;
}

$targetLength = $customLength ?? 400;

$formatLabels = [
    'single_elim' => 'Single Elimination',
    'double_elim' => 'Double Elimination',
    'gauntlet' => 'Gauntlet',
    'team_relay' => 'Team Relay'
];

$dataContext = "*** TOURNAMENT INFORMATION ***\n";
$dataContext .= "Tournament Name: {$tournament['name']}\n";
$dataContext .= "Format: " . ($formatLabels[$tournament['format']] ?? $tournament['format']) . "\n";
$dataContext .= "Participants: " . count($participants) . " racers\n";
$dataContext .= "Champion: {$winnerName}\n";
$dataContext .= "Start Date: " . date('M j, Y', strtotime($tournament['start_date'])) . "\n";
$dataContext .= "End Date: " . date('M j, Y', strtotime($tournament['end_date'])) . "\n\n";

// Add participant seeding
$dataContext .= "*** TOURNAMENT SEEDING (by ELO) ***\n";
foreach ($participants as $p) {
    $dataContext .= "Seed #{$p['seed']}: {$p['name']} (ELO: {$p['elo_at_registration']})\n";
}
$dataContext .= "\n";

// Add match results by round
$dataContext .= "*** TOURNAMENT RESULTS (Round by Round) ***\n\n";
$currentRound = null;
foreach ($matches as $match) {
    if ($currentRound !== $match['round']) {
        $currentRound = $match['round'];
        $roundNames = [
            'R1' => 'Round 1',
            'R2' => 'Round 2',
            'R3' => 'Round 3',
            'QF' => 'Quarter Finals',
            'SF' => 'Semi Finals',
            'F' => 'Finals',
            'GF' => 'Grand Finals'
        ];
        $roundName = $roundNames[$match['round']] ?? $match['round'];
        $dataContext .= "=== {$roundName} ===\n";
    }

    $dataContext .= "Match #{$match['match_number']}";
    if (!empty($match['cup_name'])) {
        $dataContext .= " ({$match['cup_name']} Cup)";
    }
    $dataContext .= ":\n";

    foreach ($match['participants'] as $participant) {
        $winnerMark = $participant['is_winner'] ? " [ADVANCED]" : "";
        if ($participant['placement'] && $participant['points']) {
            $dataContext .= "  - {$participant['name']}: Placement {$participant['placement']}, {$participant['points']} pts";
            if (!empty($participant['character_used'])) {
                $dataContext .= " ({$participant['character_used']})";
            }
            $dataContext .= $winnerMark . "\n";
        } else {
            $dataContext .= "  - {$participant['name']}{$winnerMark}\n";
        }
    }
    $dataContext .= "\n";
}

// 4. PERSONA LOGIC
$pKey = $_POST['program'] ?? 'random';
if ($pKey === 'random') {
    $availableKeys = array_diff(array_keys($ecology_personas), ['random']);
    $pKey = $availableKeys[array_rand($availableKeys)];
}
$persona = $ecology_personas[$pKey] ?? $ecology_personas['core_team'];

// 5. DIRECTOR'S NOTES
$notesInstruction = "";
if (!empty($userNotes)) {
    $notesInstruction = "\n\n*** DIRECTOR'S PRIORITY INSTRUCTIONS ***\nYou MUST include these specific points/focus in your script:\n" . $userNotes . "\n******************************************\n";
}

// 6. CONSTRUCT PROMPT
$fullPrompt = "TASK: Write a broadcast script analyzing the tournament results.\n\n";
$fullPrompt .= "PERSONA: " . $persona['prompt'] . "\n\n";
$fullPrompt .= "LENGTH TARGET: Aim for approximately {$targetLength} words in the script body (excluding headline and quote).\n\n";
$fullPrompt .= "CRITICAL FORMATTING INSTRUCTIONS:\n";
$fullPrompt .= "1. The VERY FIRST line must be 'HEADLINE: [Insert 5-8 word punchy headline here]'\n";
$fullPrompt .= "2. The SECOND line must be 'QUOTE: [Insert a short, memorable quote from the script]'\n";
$fullPrompt .= "3. Use **Double Asterisks** around racer names (e.g. **Mario**).\n";
$fullPrompt .= "4. Leave a blank line, then start the actual script.\n\n";
$fullPrompt .= "TOURNAMENT DATA:\n" . $dataContext;
$fullPrompt .= $notesInstruction;

// 7. CALL GEMINI API
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$modelName:generateContent?key=" . $apiKey;
$payload = [
    "contents" => [["parts" => [["text" => $fullPrompt]]]],
    "safetySettings" => [
        ["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_ONLY_HIGH"],
        ["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_ONLY_HIGH"]
    ]
];

$jsonBody = json_encode($payload);
if ($jsonBody === false) {
    $jsonBody = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonBody === false) {
        die("Error: Failed to encode API payload as JSON — " . json_last_error_msg());
    }
}

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 8. HANDLE RESPONSE & SAVE
if ($httpCode === 200 && $response) {
    $json = json_decode($response, true);

    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        $rawText = $json['candidates'][0]['content']['parts'][0]['text'];

        $headline = "Tournament Complete: {$tournament['name']}";
        $quote = "{$winnerName} takes the championship!";
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

        // Prepare linked tournament ID
        $linkedTournamentId = "t{$tournamentId}";

        // Get current season
        $currentSeason = getCurrentSeasonNumber();

        // Save to DB
        $save = $pdo->prepare("
            INSERT INTO recap_archive
            (season_id, recap_text, headline, key_quote, program_key, linked_gpids)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $save->execute([$currentSeason, $bodyText, $headline, $quote, $pKey, $linkedTournamentId]);

        $newId = $pdo->lastInsertId();
        header("Location: /view-recap/$newId");
        exit;
    } else {
        echo "<h1>AI Generation Failed</h1>";
        echo "<pre>" . htmlspecialchars(print_r($json, true)) . "</pre>";
        exit;
    }
} else {
    $errorDetails = json_decode($response, true);
    $errorMsg = $errorDetails['error']['message'] ?? 'No details available';
    echo "<h1>API Connection Error ($httpCode)</h1>";
    echo "<p><strong>Details:</strong> " . htmlspecialchars($errorMsg) . "</p>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    exit;
}
