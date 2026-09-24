<?php
/**
 * What each broadcast program is allowed to KNOW.
 *
 * Every program used to receive a byte-identical briefing, which put the
 * personas at war with their own data: Viberacing is told to "reject
 * math/legal scrutiny — zero analysis, just gas", and was handed title odds,
 * Elo indices and a standings table. Reef's Dispatch is told to "show disdain
 * for the metrics" and got the same table. A model quotes the numbers it is
 * given, so instructions alone cannot fix that — the fix is to not send them.
 *
 * A diet is a list of section names. gemini_recap.php builds the briefing as
 * named sections and assembles only the ones the program asks for, so a lean
 * show also gets a shorter, cheaper prompt.
 *
 * Sections: system · standings · odds · scenarios · form · results · nemesis
 *           · mikkoliiga · focus
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

/** Every section, for a program that should see the lot. */
const BROADCAST_ALL_SECTIONS = [
    'system', 'standings', 'odds', 'scenarios', 'form', 'results', 'nemesis', 'mikkoliiga',
];

/**
 * Which sections a program receives. An unknown program gets everything,
 * which is the safe default: too much context reads oddly, too little
 * invents.
 */
function broadcastDiet(string $programKey): array {
    $diets = [
        // Analytics for nerds — the only show that gets the lot, tie-breaks
        // and qualification rules included.
        'meta_report' => ['system', 'standings', 'odds', 'scenarios', 'form', 'results', 'nemesis', 'mikkoliiga'],

        // Generic sports broadcast: the table, the story, the odds.
        'core_team'   => ['system', 'standings', 'odds', 'form', 'results', 'nemesis', 'mikkoliiga'],

        // Personality and grudges. Movement and rivalry, no formulas.
        'the_rant'    => ['standings', 'results', 'nemesis', 'form'],

        // "Study the spectacle": the table as an instrument of control, plus
        // who is excluded by the threshold. No odds — this show does not
        // forecast, it interprets.
        'situated_spectator' => ['system', 'standings', 'results'],

        // One racer's story in depth, and the leader for contrast. No
        // league-wide tables: a documentary about an underdog does not open
        // with a spreadsheet.
        'ghost_racer' => ['focus', 'results'],

        // Disdain for metrics. Raw outcomes only — who beat whom.
        'reef_dispatch' => ['results'],

        // Zero analysis, just gas.
        'viberacing'  => ['results'],
    ];

    return $diets[$programKey] ?? BROADCAST_ALL_SECTIONS;
}

/**
 * The racer The Ghost Racer's Ascent follows — the same one every week,
 * because the show is a serialised documentary and swapping its subject each
 * broadcast would defeat the format.
 *
 * Stored in settings, so a commissioner can change it in Admin → Settings. On
 * a league that has never set it, one of the founding four racers is drawn at
 * random and WRITTEN BACK, so the choice is made once and then kept — it does
 * not re-roll every broadcast.
 *
 * @return array{id:int, name:string}|null
 */
function ghostRacerFocus(PDO $pdo): ?array {
    // Accepts a racer id OR a name, because the setting is edited by hand in
    // Admin → Settings and nobody knows their racers' row ids.
    $set = trim((string)getSetting($pdo, 'ghost_racer_focus', ''));
    if ($set !== '') {
        try {
            if (ctype_digit($set)) {
                $st = $pdo->prepare("SELECT id, name FROM racers WHERE id = ?");
                $st->execute([(int)$set]);
            } else {
                $st = $pdo->prepare("SELECT id, name FROM racers WHERE LOWER(name) = LOWER(?)");
                $st->execute([$set]);
            }
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        } catch (PDOException $e) { return null; }
        // Falls through when the named racer has been deleted or renamed.
    }

    try {
        // The founding four — the roster the league started with.
        $founders = $pdo->query("SELECT id, name FROM racers ORDER BY id ASC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return null; }
    if (!$founders) return null;

    $pick = $founders[random_int(0, count($founders) - 1)];
    updateSetting($pdo, 'ghost_racer_focus', (string)$pick['id']);
    return ['id' => (int)$pick['id'], 'name' => (string)$pick['name']];
}
