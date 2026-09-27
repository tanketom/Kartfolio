<?php
/**
 * Reading a photo of the Mario Kart 8 Deluxe results screen.
 *
 * The first attempt at this (api/ocr_gemini.php, deleted in d20447a) asked a
 * model to do everything at once — find the humans, read their names, identify
 * each character from its portrait, read rank and points — in one unchecked
 * free-text answer, and it was never trusted enough to be used. This splits the
 * job the other way round:
 *
 *   - the MODEL only transcribes what is printed: for every visible row, the
 *     character name, whether the row has a coloured player bar, and the total;
 *   - the CODE decides everything else, and checks it.
 *
 * What the code knows that the model doesn't (proven on 20 photos of real GPs):
 *   - A four-race GP hands out exactly 82 points per race, so the twelve totals
 *     always add up to 328. One misread digit breaks the sum.
 *   - The position numbers on screen cannot be trusted: a photo taken while the
 *     totals are still animating shows that race's finishing order next to the
 *     GP totals. Rank is derived from the points instead (ties share a place,
 *     as the game does) — it matched the stored rank for all 74 human rows.
 *   - The humans are the rows with a coloured bar (red/pink, blue, green,
 *     yellow). Every name on the screen is a character name.
 */

require_once __DIR__ . '/gemini_client.php';

const SCAN_GP_TOTAL   = 328;   // (15+12+10+9+8+7+6+5+4+3+2+1) × 4 races
const SCAN_RACE_TOTAL = 82;
const SCAN_FIELD      = 12;

/** Gemini's structured-output schema: one entry per visible row, top to bottom. */
function resultScanSchema(): array {
    return [
        'type' => 'OBJECT',
        'properties' => [
            'rows' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'character' => ['type' => 'STRING'],
                        'bar'       => ['type' => 'STRING', 'enum' => ['none', 'red', 'blue', 'green', 'yellow']],
                        'points'    => ['type' => 'INTEGER'],
                        'readable'  => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['character', 'bar', 'points', 'readable'],
                ],
            ],
            'cut_off' => ['type' => 'BOOLEAN'],
        ],
        'required' => ['rows', 'cut_off'],
    ];
}

function resultScanPrompt(): string {
    return <<<TXT
This is a phone photo of a TV showing a Mario Kart 8 Deluxe standings table: twelve rows, one per racer, sorted or being sorted by total points. Transcribe the table. Do not interpret it.

For EVERY row you can see, from the top of the table to the bottom, give:
- character: the name printed in the row, exactly as printed (e.g. "Orange Yoshi", "Birdo (Black)", "Pink Gold Peach", "Bowser Jr.").
- bar: the colour of the row's bar. Human players have a solid, saturated player bar: "red" (includes pink/salmon), "blue" (includes cyan/light blue), "green" or "yellow". Computer players have a dark, grey or see-through bar — answer "none" for those, even when the scenery behind the TV image tints them.
- points: the total printed at the right-hand end of the row. Read each digit carefully; the font is a seven-segment style where 1/7, 3/8, 5/6 and 0/8 are easy to confuse.
- readable: false if glare, confetti, an overlapping kart or the photo's edge hides the number and you are guessing.

Judge the bar colour from the wide bar behind the character's NAME, not from the small icons at the far left: the orange up-arrows, blue down-arrows and green dashes there are position-change markers and appear on computer rows too. A computer row with a blue arrow is still "none".
Ignore the position numbers and the arrows at the left; they are not needed.
Set cut_off to true if any of the twelve rows is missing, hidden or only partly visible.
Never invent a row you cannot see.
TXT;
}

/**
 * Screen name → the name the league stores. The screen writes colour variants
 * two different ways ("Orange Yoshi", "Birdo (Black)"); the results table
 * stores Yoshi colours as "Yoshi (Orange)" and Birdo plain.
 */
function scanCanonicalCharacter(string $screen): string {
    $s = trim(preg_replace('/\s+/', ' ', $screen));
    if (preg_match('/^(Red|Orange|Yellow|Light Blue|Blue|Pink|Black|White)\s+Yoshi$/i', $s, $m)) {
        return 'Yoshi (' . ucwords(strtolower($m[1])) . ')';
    }
    if (preg_match('/^Birdo\s*\(.+\)$/i', $s)) return 'Birdo';
    if (preg_match('/^Villager/i', $s)) return 'Villager';
    return $s;
}

/** Family for matching racers: every Yoshi is a Yoshi, every Birdo a Birdo. */
function scanCharacterFamily(string $name): string {
    return preg_replace('/^(Yoshi|Birdo)\s*\(.+\)$/u', '$1', scanCanonicalCharacter($name));
}

/**
 * Ask the model to transcribe one photo.
 * @return array{rows: array, cut_off: bool, model: string, error: string}
 */
function resultScanRead(array $modelChain, string $apiKey, string $imageBytes, string $mime = 'image/jpeg'): array {
    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => resultScanPrompt()],
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($imageBytes)]],
            ],
        ]],
        'generationConfig' => [
            'temperature'      => 0,
            'responseMimeType' => 'application/json',
            'responseSchema'   => resultScanSchema(),
            'maxOutputTokens'  => 8192,
        ],
    ];
    [$body, $code, $err, $model] = callGeminiWithRetry($modelChain, $apiKey, $payload);
    if ($body === null) return ['rows' => [], 'cut_off' => true, 'model' => '', 'error' => $err ?: "HTTP $code"];

    $json = json_decode($body, true);
    $text = '';
    foreach ($json['candidates'][0]['content']['parts'] ?? [] as $p) {
        if (empty($p['thought'])) $text .= $p['text'] ?? '';
    }
    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
        return ['rows' => [], 'cut_off' => true, 'model' => (string)$model, 'error' => 'Unreadable model answer'];
    }
    $rows = [];
    foreach ($data['rows'] as $r) {
        $rows[] = [
            'character' => trim((string)($r['character'] ?? '')),
            'bar'       => in_array($r['bar'] ?? '', ['red', 'blue', 'green', 'yellow'], true) ? $r['bar'] : 'none',
            'points'    => (int)($r['points'] ?? -1),
            'readable'  => (bool)($r['readable'] ?? false),
        ];
    }
    return ['rows' => $rows, 'cut_off' => (bool)($data['cut_off'] ?? false), 'model' => (string)$model, 'error' => ''];
}

/**
 * Everything the code can conclude from a transcription, and every reason not
 * to trust it. A scan with problems still fills the form — the problems are
 * shown next to it — but a field it could not establish is left empty.
 *
 * @return array{humans: array, complete: bool, sum: int, checksum: ?bool, problems: string[]}
 */
function resultScanCheck(array $read): array {
    $rows = $read['rows'];
    $problems = [];
    $n = count($rows);
    $complete = $n === SCAN_FIELD && !$read['cut_off'];
    $pts = array_column($rows, 'points');
    $sum = array_sum($pts);

    foreach ($rows as $i => $r) {
        if ($r['points'] < 0 || $r['points'] > 60) $problems[] = "Row " . ($i + 1) . " reads {$r['points']} points, which a GP cannot give.";
        if (!$r['readable']) $problems[] = $r['character'] . "'s total was hard to read.";
    }
    $checksum = null;
    if ($complete) {
        $checksum = $sum === SCAN_GP_TOTAL;
        if (!$checksum) {
            $problems[] = $sum % SCAN_RACE_TOTAL === 0 && $sum < SCAN_GP_TOTAL
                ? 'The table adds up to ' . $sum . ', which is ' . ($sum / SCAN_RACE_TOTAL) . ' races — take the photo after the last race.'
                : "The twelve totals add up to $sum, not " . SCAN_GP_TOTAL . ' — at least one number is misread.';
        }
    } else {
        $problems[] = "Only $n of " . SCAN_FIELD . ' rows are visible, so the totals cannot be checked against each other.';
    }

    $desc = $pts;
    rsort($desc);
    $sorted = $pts === $desc;
    $humans = [];
    foreach ($rows as $r) {
        if ($r['bar'] === 'none') continue;
        // Rank from points. With rows missing, a hidden row could only outrank
        // someone if the table is still unsorted (mid-animation).
        $rank = 1 + count(array_filter($pts, fn($p) => $p > $r['points']));
        $rankSure = $complete || $sorted;
        $humans[] = [
            'screen'    => $r['character'],
            'character' => scanCanonicalCharacter($r['character']),
            'family'    => scanCharacterFamily($r['character']),
            'bar'       => $r['bar'],
            'points'    => $r['points'],
            'rank'      => $rankSure ? $rank : null,
        ];
    }
    if (!$complete && !$sorted) $problems[] = 'The table is still being re-sorted and rows are missing, so places cannot be worked out.';
    if (count($humans) === 0) $problems[] = 'No player bars were found.';
    if (count($humans) > 4) $problems[] = count($humans) . ' player bars found; a local GP has at most four.';

    return ['humans' => $humans, 'complete' => $complete, 'sum' => $sum, 'checksum' => $checksum, 'problems' => $problems];
}
