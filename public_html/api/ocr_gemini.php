<?php
/**
 * Gemini OCR - Mario Kart Result Scanner
 * Path: /cdnmk/public_html/api/ocr_gemini.php
 */
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin(); // Protect this endpoint

header('Content-Type: application/json');

// 1. Load Config
$config = require __DIR__ . '/../../private/config/config.php';
if (empty($config['gemini_api_key'])) {
    echo json_encode(['error' => 'API Key missing']);
    exit;
}

// 2. Validate Upload
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Image upload failed']);
    exit;
}

// 3. Prepare Image for Gemini
$mimeType = $_FILES['image']['type'];
$imagePath = $_FILES['image']['tmp_name'];
$imageData = base64_encode(file_get_contents($imagePath));

// 4. Construct Prompt
// We ask for strict JSON to make parsing easy in JavaScript
$promptText = "This is a Mario Kart 8 Deluxe Grand Prix results screen showing the final standings of 12 racers after 4 races.

CRITICAL: Look through ALL 12 positions and identify the HUMAN PLAYERS (local players). You can identify them by their COLORED BACKGROUNDS:
- Human players have BRIGHT COLORED backgrounds: YELLOW, GREEN, PINK, or BLUE
- CPU/computer players have GRAY, DARK GRAY, or BLACK backgrounds

There will be 2-4 human players somewhere in the 12 positions. They could be in ANY position (1st through 12th).

For each human player you find, extract:
1. Player name (text displayed above/near the character)
2. Character name (the Mario Kart character they're playing as - look at the character image)
3. Rank (their position: 1-12)
4. Points (total GP points shown)

Return ONLY a raw JSON array in this exact format:
[{\"name\": \"PlayerName\", \"character\": \"CharacterName\", \"rank\": 1, \"points\": 15}, ...]

Do not include markdown formatting like ```json.
Return the human players in order from best rank to worst rank (so rank 1 first, rank 12 last).
Make sure to check all 12 positions - humans can be anywhere!";

$payload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $promptText],
                [
                    "inline_data" => [
                        "mime_type" => $mimeType,
                        "data" => $imageData
                    ]
                ]
            ]
        ]
    ]
];

// 5. Send to Gemini
$apiKey = $config['gemini_api_key'];
$modelName = $config['model_name'] ?? 'gemini-1.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key={$apiKey}";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 6. Handle Response
if ($httpCode === 200) {
    $json = json_decode($response, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Clean up any Markdown backticks if Gemini adds them despite instructions
    $text = str_replace(['```json', '```'], '', $text);

    echo $text; // Return the raw JSON array to the frontend
} else {
    $errorDetails = json_decode($response, true);
    $errorMsg = $errorDetails['error']['message'] ?? 'Unknown error';
    echo json_encode(['error' => "Gemini API Error ($httpCode): $errorMsg"]);
}
?>