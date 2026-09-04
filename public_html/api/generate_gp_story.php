<?php
/**
 * GP Story Generator — MONSTER HUNT Chronicles
 * Path: /cdnmk/public_html/api/generate_gp_story.php
 *
 * Admin-only endpoint. Accepts POST { gpid, season_id }.
 * Gathers GP + Elo data, calls Gemini, stores in gp_stories.
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/elo_engine.php';
require_once __DIR__ . '/../../private/includes/gemini_client.php';

header('Content-Type: application/json');

require_admin();
verify_csrf();

// The fallback chain can stretch a single call past PHP's 30s default if
// the primary 503s and we have to walk through retries + alternates.
@set_time_limit(300);
ignore_user_abort(true);

// ── Ensure table exists (self-migrating for existing DBs) ──────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS gp_stories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gpid TEXT NOT NULL UNIQUE,
        season_id TEXT NOT NULL,
        story_text TEXT NOT NULL,
        story_data TEXT DEFAULT NULL,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// ── Parse input ────────────────────────────────────────────────────────────
$input    = json_decode(file_get_contents('php://input'), true);
$gpid     = trim($input['gpid']      ?? '');
$seasonId = trim($input['season_id'] ?? '');

if (!$gpid || !$seasonId) {
    echo json_encode(['error' => 'Missing gpid or season_id']);
    exit;
}

// ── Config ─────────────────────────────────────────────────────────────────
$config = kartfolioConfig();
$apiKey = $config['gemini_api_key'] ?? '';
$model  = $config['model_name']     ?? 'gemini-2.5-flash';

if (empty($apiKey)) {
    echo json_encode(['error' => 'Gemini API key not configured']);
    exit;
}

// ── Season rules ───────────────────────────────────────────────────────────
$rules = getSeasonRules($pdo, $seasonId);

if (!$rules || ($rules['scoring_system'] ?? '') !== 'monster_hunt') {
    echo json_encode(['error' => 'Not a MONSTER HUNT season']);
    exit;
}

// ── Elo changelog ──────────────────────────────────────────────────────────
$changelog = getMonsterHuntEloChangelog($pdo);
$gpData    = $changelog[$gpid] ?? null;

if (!$gpData || count($gpData) < 1) {
    echo json_encode(['error' => 'No Elo data found for GP: ' . $gpid]);
    exit;
}

// ── Results (character, cup, date) ─────────────────────────────────────────
$resStmt = $pdo->prepare("
    SELECT res.*, r.name AS racer_name
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid = ?
    ORDER BY res.rank ASC
");
$resStmt->execute([$gpid]);
$resultRows = $resStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($resultRows)) {
    echo json_encode(['error' => 'No results found for GP: ' . $gpid]);
    exit;
}

$cupName  = $resultRows[0]['cup_name']   ?? 'Unknown Cup';
$raceDate = $resultRows[0]['race_date']  ?? '';

$charLookup = [];
foreach ($resultRows as $row) {
    $charLookup[$row['racer_name']] = $row['character_used'] ?? 'Mii';
}

// ── Cup → evocative location ───────────────────────────────────────────────
$cupLocations = [
    'Mushroom'    => 'the Mushroom Kingdom meadows',
    'Flower'      => 'the sun-baked flower fields',
    'Star'        => 'the celestial Star Heights',
    'Special'     => 'the treacherous Special Circuits',
    'Shell'       => 'the coastal Shell Tracks',
    'Banana'      => 'the treacherous Banana Groves',
    'Leaf'        => 'the windswept Leaf Forest',
    'Lightning'   => 'the storm-swept Lightning Circuits',
    'Egg'         => 'the ancient Egg Canyon',
    'Triforce'    => 'the sacred Triforce Temple',
    'Crossing'    => 'the peaceful Crossing Village',
    'Bell'        => 'the twinkling Bell Spires',
    'Golden Dash' => 'the grand Golden Dash Arena',
    'Lucky Cat'   => 'the auspicious Lucky Cat Shrine',
    'Turnip'      => 'the rolling Turnip Fields',
    'Propeller'   => 'the sky-high Propeller Heights',
    'Rock'        => 'the jagged Rock Canyon',
    'Moon'        => 'the moonlit Lunar Circuit',
    'Fruit'       => 'the fragrant Fruit Forest',
    'Boomerang'   => 'the ancient Boomerang Ruins',
    'Feather'     => 'the breezy Feather Wind Peaks',
    'Cherry'      => 'the festive Cherry Blossom grounds',
    'Acorn'       => 'the towering Acorn Forest',
    'Spiny'       => 'the dangerous Spiny Wastes',
];
$location = $cupLocations[$cupName] ?? "the {$cupName} circuit";

// ── Identify Monster (top-2 pre-GP Elo, hashed per GP) ─────────────────────
[$monsterName, $monsterElo] = pickMonster($gpid, $gpData, $pdo);

// ── CR tier & epithet ──────────────────────────────────────────────────────
$adventurerElos = [];
foreach ($gpData as $name => $d) {
    if ($name !== $monsterName) $adventurerElos[] = $d['old_elo'];
}
$avgAdvElo = count($adventurerElos) > 0
    ? array_sum($adventurerElos) / count($adventurerElos)
    : $monsterElo;
$eloGap = max(0, $monsterElo - $avgAdvElo);

if      ($eloGap < 50)  { $crTier = 1; $crMult = 1.0;  $epithet = 'the Rival'; }
elseif  ($eloGap < 150) { $crTier = 2; $crMult = 1.25; $epithet = 'the Beast'; }
elseif  ($eloGap < 300) { $crTier = 3; $crMult = 1.5;  $epithet = 'the Fearsome One'; }
else                    { $crTier = 4; $crMult = 2.0;  $epithet = 'the Dragon'; }

$monsterRank = $gpData[$monsterName]['rank'];
$monsterPts  = $gpData[$monsterName]['gp_points'];

// ── Count outcomes ─────────────────────────────────────────────────────────
$advWon    = 0;
$advLost   = 0;
$slayers   = [];
$survivors = [];
foreach ($gpData as $name => $d) {
    if ($name === $monsterName) continue;
    if ($d['rank'] < $monsterRank) {
        $advWon++;
        $slayers[] = $name;
    } else {
        $advLost++;
        $survivors[] = $name;
    }
}
$fullSlay = ($advLost === 0 && $advWon > 0); // Every adventurer beat the Monster
$isTpk    = ($advWon === 0);               // Monster beat every adventurer (TPK)

// ── XP per racer ───────────────────────────────────────────────────────────
$slay_xp        = (int)($rules['mh_slay_xp']           ?? 100);
$survive_xp     = (int)($rules['mh_survive_xp']         ?? 20);
$party_bonus_xp = (int)($rules['mh_party_bonus_xp']     ?? 50);
$monster_win    = (int)($rules['mh_monster_win_xp']     ?? 80);
$monster_part   = (int)($rules['mh_monster_partial_xp'] ?? 30);
$monster_loss   = (int)($rules['mh_monster_loss_xp']    ?? -40);

$racerXP = [];
foreach ($gpData as $name => $d) {
    if ($name === $monsterName) {
        if      ($advWon === 0)  $xp = $monster_win;
        elseif  ($advLost === 0) $xp = $monster_loss;
        else                     $xp = $monster_part;
    } else {
        if ($d['rank'] < $monsterRank) {
            $xp = (int)round($slay_xp * $crMult);
            if ($fullSlay) $xp += $party_bonus_xp;
        } else {
            $xp = $survive_xp;
        }
    }
    $racerXP[$name] = $xp;
}

// ── Build Gemini prompt ────────────────────────────────────────────────────
$dateStr = $raceDate ? date('F j, Y', strtotime($raceDate)) : 'an unrecorded date';

$crRomanMap = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV'];
$crRoman = $crRomanMap[$crTier] ?? $crTier;

$prompt  = "You are a medieval chronicler recording the deeds of Monster Hunters in a grand kart racing league.\n";
$prompt .= "Write a dramatic 3-4 sentence chronicle entry about the following Grand Prix.\n\n";

$prompt .= "SETTING: GP {$gpid} — {$cupName}, held at {$location} on {$dateStr}.\n\n";

$prompt .= "THE MONSTER: {$monsterName}, {$epithet} (Challenge Rating {$crRoman}).\n";
$prompt .= "The Monster finished in position {$monsterRank}.\n\n";

$prompt .= "RACE RESULTS:\n";
// Sort by rank for the prompt
$sortedGpData = $gpData;
uasort($sortedGpData, fn($a, $b) => $a['rank'] <=> $b['rank']);
foreach ($sortedGpData as $name => $d) {
    $role = ($name === $monsterName) ? '⚔ MONSTER' :
            (in_array($name, $slayers) ? '🗡 Slayer' : '🛡 Survivor');
    $char = $charLookup[$name] ?? 'Mii';
    $prompt .= "  #{$d['rank']} {$name} ({$char}) [{$role}]\n";
}

if ($fullSlay) {
    $slayerList = implode(', ', $slayers);
    $prompt .= "\nNOTABLE: FULL SLAY — Every adventurer finished ahead of the Monster! Slayers: {$slayerList}.\n";
} elseif ($isTpk) {
    $prompt .= "\nNOTABLE: TOTAL PARTY KILL — The Monster finished ahead of every single adventurer.\n";
} elseif (!empty($slayers)) {
    $slayerList = implode(', ', $slayers);
    $prompt .= "\nSlayers who finished ahead of the Monster: {$slayerList}.\n";
}

$prompt .= "\nWrite in a dramatic, medieval chronicle voice — as if a scribe is recording a legendary hunt for posterity. ";
$prompt .= "Name specific racers and their characters. Reference the Monster's epithet and CR tier (I–IV) using flavourful language, not numbers. ";
$prompt .= "Do NOT mention Elo ratings, XP, or any numerical game statistics — these are recorded separately by the guild's accountants. ";
$prompt .= "Focus on the drama of who triumphed, who fell, and the glory or shame of the hunt's outcome. ";
$prompt .= "Use **double asterisks** around every racer's name each time it appears (e.g. **Knut**, **Trond**). ";
$prompt .= "Exactly 3-4 sentences of flowing prose — no bullet points, no headers, no other markdown. ";
$prompt .= "End with a new line signed: — [Bardic Name], Scribe of the Hunt. Invent a whimsical medieval bardic name each time.";

// ── Call Gemini (with retry-on-503 + model fallbacks) ─────────────────────
$payload = [
    'contents'       => [['parts' => [['text' => $prompt]]]],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',       'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',      'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT','threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT','threshold' => 'BLOCK_ONLY_HIGH'],
    ],
];

$modelChain = geminiDefaultModelChain($model);
[$response, $httpCode, $lastError, $modelUsed] = callGeminiWithRetry($modelChain, $apiKey, $payload);

if ($response === null) {
    echo json_encode(['error' => $lastError]);
    exit;
}

// ── Parse response ─────────────────────────────────────────────────────────
$result    = json_decode($response, true);
$storyText = trim($result['candidates'][0]['content']['parts'][0]['text'] ?? '');

if (empty($storyText)) {
    echo json_encode(['error' => 'Empty response from Gemini (' . $modelUsed . ')', 'raw' => $response]);
    exit;
}

// Strip any stray markdown
$storyText = preg_replace('/^```[a-z]*\n?/i', '', $storyText);
$storyText = preg_replace('/\n?```$/', '', $storyText);
$storyText = trim($storyText);

// ── Persist to DB ──────────────────────────────────────────────────────────
$storyData = [
    'gpid'            => $gpid,
    'season_id'       => $seasonId,
    'cup_name'        => $cupName,
    'location'        => $location,
    'race_date'       => $raceDate,
    'monster_name'    => $monsterName,
    'monster_epithet' => $epithet,
    'monster_elo'     => $monsterElo,
    'cr_tier'         => $crTier,
    'cr_mult'         => $crMult,
    'elo_gap'         => round($eloGap),
    'full_slay'       => $fullSlay,
    'tpk'             => $isTpk,
    'slayers'         => $slayers,
    'survivors'       => $survivors,
];

// DELETE first so we can cleanly re-insert (supports all SQLite versions)
$pdo->prepare("DELETE FROM gp_stories WHERE gpid = ?")->execute([$gpid]);

$saveStmt = $pdo->prepare("
    INSERT INTO gp_stories (gpid, season_id, story_text, story_data, generated_at)
    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
");
$saveStmt->execute([$gpid, $seasonId, $storyText, json_encode($storyData)]);

// ── Return ─────────────────────────────────────────────────────────────────
echo json_encode([
    'success'    => true,
    'gpid'       => $gpid,
    'story_text' => $storyText,
    'cr_tier'    => $crTier,
    'epithet'    => $epithet,
    'monster'    => $monsterName,
    'full_slay'  => $fullSlay,
    'slayers'    => $slayers,
]);
