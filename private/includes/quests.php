<?php
/**
 * Side Quests — two optional season-long objectives per racer, drawn once
 * (at random) from a pack of ~20 and frozen in the racer_quests table.
 * Completion is evaluated live from racerSeasonStats().
 *
 * Each quest: key, icon, title, desc, and a check(fn($s)) over the stat bag.
 *
 * Path: /cdnmk/private/includes/quests.php
 */

require_once __DIR__ . '/gp_logic.php';

/** How many quests each racer carries per season. */
const QUESTS_PER_RACER = 2;

/** The pack. Checks read the racerSeasonStats() bag. */
function questPack(): array {
    return [
        ['key' => 'perfect_run',   'icon' => '💎', 'title' => 'Perfect Run',       'desc' => 'Score a perfect 60 in any GP.',                         'check' => fn($s) => $s['has_perfect']],
        ['key' => 'half_century',  'icon' => '🎯', 'title' => 'Half Century',      'desc' => 'Post a 50+ score in a single GP.',                      'check' => fn($s) => $s['best'] >= 50],
        ['key' => 'big_game',      'icon' => '🔥', 'title' => 'Big Game',          'desc' => 'Hit 55 or more in a single GP.',                        'check' => fn($s) => $s['best'] >= 55],
        ['key' => 'hat_trick',     'icon' => '🎩', 'title' => 'Hat Trick',         'desc' => 'Win three Grand Prix in a row.',                        'check' => fn($s) => $s['longest_win_streak'] >= 3],
        ['key' => 'back_to_back',  'icon' => '⚡', 'title' => 'Back-to-Back',      'desc' => 'Win two Grand Prix in a row.',                          'check' => fn($s) => $s['longest_win_streak'] >= 2],
        ['key' => 'podium_run',    'icon' => '🏆', 'title' => 'Podium Run',        'desc' => 'String together four straight podium finishes.',        'check' => fn($s) => $s['longest_podium_streak'] >= 4],
        ['key' => 'comeback_kid',  'icon' => '🎪', 'title' => 'Comeback Kid',      'desc' => 'Win a GP right after finishing 8th or worse.',          'check' => fn($s) => $s['comeback']],
        ['key' => 'iron_driver',   'icon' => '🛠️', 'title' => 'Iron Driver',       'desc' => 'Race in 15 or more Grand Prix this season.',            'check' => fn($s) => $s['gps'] >= 15],
        ['key' => 'marathon',      'icon' => '🏁', 'title' => 'Marathon',          'desc' => 'Race in 20 or more Grand Prix this season.',            'check' => fn($s) => $s['gps'] >= 20],
        ['key' => 'cup_tour',      'icon' => '🗺️', 'title' => 'Cup Tour',          'desc' => 'Race on 8 different cups.',                              'check' => fn($s) => $s['cups_raced'] >= 8],
        ['key' => 'base_dozen',    'icon' => '🏛️', 'title' => 'The Base Dozen',    'desc' => 'Race all 12 base-game cups this season.',               'check' => fn($s) => $s['base_cups_raced'] >= 12],
        ['key' => 'loyalist',      'icon' => '💍', 'title' => 'The Loyalist',      'desc' => 'Race the same character 10+ times.',                    'check' => fn($s) => $s['max_char_plays'] >= 10],
        ['key' => 'variety',       'icon' => '🎨', 'title' => 'Variety Pack',      'desc' => 'Use 6 or more different characters.',                   'check' => fn($s) => $s['distinct_chars'] >= 6],
        ['key' => 'steady',        'icon' => '🎯', 'title' => 'Steady Hand',       'desc' => 'Keep your score std-dev under 8 (min 5 GPs).',          'check' => fn($s) => $s['gps'] >= 5 && $s['stddev'] < 8],
        ['key' => 'podium_reg',    'icon' => '🥉', 'title' => 'Podium Regular',    'desc' => 'Finish on the podium in 60%+ of your GPs (min 5).',     'check' => fn($s) => $s['gps'] >= 5 && $s['podium_rate'] >= 0.6],
        ['key' => 'five_wins',     'icon' => '🌟', 'title' => 'High Five',         'desc' => 'Win five Grand Prix this season.',                      'check' => fn($s) => $s['wins'] >= 5],
        ['key' => 'climber',       'icon' => '🧗', 'title' => 'The Climber',       'desc' => 'Gain 100+ Elo over the season.',                        'check' => fn($s) => $s['elo_delta'] >= 100],
        ['key' => 'double_digits', 'icon' => '🔟', 'title' => 'Double Digits',     'desc' => 'Reach 10 podium finishes.',                             'check' => fn($s) => $s['podiums'] >= 10],
        ['key' => 'clean_sheet',   'icon' => '🧼', 'title' => 'Clean Sheet',       'desc' => 'Race 10+ GPs with zero Ludwig Obstructions.',           'check' => fn($s) => $s['gps'] >= 10 && $s['lols'] === 0],
        ['key' => 'dabbler',       'icon' => '🌱', 'title' => 'Getting Started',   'desc' => 'Race at least 5 Grand Prix this season.',               'check' => fn($s) => $s['gps'] >= 5],
        ['key' => 'first_win',     'icon' => '🚩', 'title' => 'On the Board',      'desc' => 'Win at least one Grand Prix.',                          'check' => fn($s) => $s['wins'] >= 1],
    ];
}

/** quest_key => definition. */
function questByKey(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (questPack() as $q) $map[$q['key']] = $q;
    }
    return $map;
}

/**
 * The racer's two quests for a season — assigning (and freezing) them on first
 * access. Deterministic seed so a racer always draws the same pair, even
 * before the row is written. Returns rows with completion evaluated live.
 */
function getRacerQuests($pdo, int $racer_id, string $season_id): array {
    // Already assigned?
    $sel = $pdo->prepare("SELECT quest_key FROM racer_quests WHERE season_id = ? AND racer_id = ? ORDER BY quest_key");
    $sel->execute([$season_id, $racer_id]);
    $keys = $sel->fetchAll(PDO::FETCH_COLUMN);

    if (empty($keys)) {
        // Deterministic draw: seed from racer+season so it's stable.
        $pack = array_keys(questByKey());
        mt_srand(crc32($season_id . ':' . $racer_id));
        shuffle($pack);
        mt_srand(); // restore entropy
        $keys = array_slice($pack, 0, QUESTS_PER_RACER);

        $ins = $pdo->prepare("INSERT OR IGNORE INTO racer_quests (season_id, racer_id, quest_key) VALUES (?, ?, ?)");
        foreach ($keys as $k) $ins->execute([$season_id, $racer_id, $k]);
    }

    // Evaluate completion once from the shared stat bag.
    $stats = racerSeasonStats($pdo, $racer_id, $season_id);
    $defs  = questByKey();
    $out = [];
    foreach ($keys as $k) {
        if (!isset($defs[$k])) continue;
        $q = $defs[$k];
        $out[] = [
            'key'       => $k,
            'icon'      => $q['icon'],
            'title'     => $q['title'],
            'desc'      => $q['desc'],
            'completed' => (bool)($q['check'])($stats),
        ];
    }
    return $out;
}
