<?php
/**
 * Wrapped personas — Aura, Club, and Racing Personality catalogs + pickers.
 *
 * Each picker evaluates its catalog in priority order against a $stats array
 * (built in wrapped.php) and returns the first match, falling back to a
 * guaranteed default at the end of the list. Keeping this out of wrapped.php
 * so the catalogs are easy to tune and unit-test in isolation.
 *
 * $stats keys used:
 *   gps, wins, podiums, avg, points, win_rate, podium_rate, std_dev,
 *   best_pts, has_perfect, peak_elo, elo_delta, lols, lol_rate,
 *   distinct_chars, cups_raced, cup_concentration, longest_podium_streak,
 *   comeback, attendance_rank, second_half_jump, top_group, group_pct,
 *   points_percentile
 *
 * Path: /cdnmk/private/includes/wrapped_personas.php
 */

/** Pick the first catalog entry whose 'match' closure returns true. */
function wrappedPick(array $catalog, array $stats): array {
    foreach ($catalog as $entry) {
        if (($entry['match'])($stats)) return $entry;
    }
    return end($catalog); // catalogs always end with an unconditional default
}

/** Minimum GPs before a racer is judged on "performance" auras/clubs.
 *  Below this they get character-identity or a low-GP catch-all instead, so a
 *  one-night racer isn't branded a "Fading Star" or "Specialist" off one GP. */
const WRAPPED_MIN_GPS = 6;

/**
 * ~22 Auras, in priority order. Performance auras are gated behind
 * WRAPPED_MIN_GPS and front-loaded by distinctiveness so the field spreads
 * out (the biggest winner takes Pure Gold, the biggest climber Ascendant,
 * etc.) instead of everyone collapsing onto Ice Cold. Character-identity
 * auras are ungated (your main says something from race one), and low-GP
 * racers fall to Rookie Fire / Fresh Tracks.
 */
function wrappedAuras(): array {
    $vol = fn($s) => $s['gps'] >= WRAPPED_MIN_GPS; // "enough volume to judge"
    return [
        // ── Performance: selective traits first so the strong field spreads ──
        ['key' => 'frontrunner',  'label' => 'Pure Gold',     'grad' => ['#f7b733', '#fc4a1a'], 'meaning' => 'The front of the pack is the only view you recognise. This season had a glow to it.',          'match' => fn($s) => $vol($s) && $s['win_rate'] >= 0.35],
        ['key' => 'ascendant',    'label' => 'Ascendant',     'grad' => ['#56ab2f', '#a8e063'], 'meaning' => 'Everything about this season pointed upward — you are not where you started, and that is the point.', 'match' => fn($s) => $vol($s) && $s['elo_delta'] >= 120],
        ['key' => 'explosive',    'label' => 'Explosive',     'grad' => ['#f7971e', '#ff0080'], 'meaning' => 'When it went off, the whole room felt it. Containment was never the plan.',                  'match' => fn($s) => $vol($s) && $s['best_pts'] >= 55 && $s['std_dev'] >= 13],
        ['key' => 'clinical',     'label' => 'Clinical',      'grad' => ['#11998e', '#38ef7d'], 'meaning' => 'Precision over flash — the kind of year that wins by simply never slipping.',                'match' => fn($s) => $vol($s) && $s['avg'] >= 45 && $s['std_dev'] < 8],
        ['key' => 'cursed',       'label' => 'Cursed',        'grad' => ['#3f5efb', '#1d976c'], 'meaning' => 'The shells found you — the blue ones especially. Some seasons are just haunted.',            'match' => fn($s) => $vol($s) && $s['lol_rate'] >= 0.25],
        ['key' => 'chaotic',      'label' => 'Chaotic',       'grad' => ['#fc466b', '#ffe53b'], 'meaning' => 'Order is for other people. Your season ran on pure entropy and somehow it worked.',           'match' => fn($s) => $vol($s) && $s['lols'] >= 6],
        ['key' => 'underdog',     'label' => 'Underdog Grit', 'grad' => ['#4568dc', '#b06ab3'], 'meaning' => 'Counted out more than once — and you kept showing up to prove the maths wrong.',               'match' => fn($s) => $vol($s) && $s['points_percentile'] <= 40 && $s['wins'] >= 1],
        ['key' => 'slow_burn',    'label' => 'Slow Burn',     'grad' => ['#cb2d3e', '#ef473a'], 'meaning' => 'You took your time. By the end, nobody wanted to see your name on the sheet.',                 'match' => fn($s) => $vol($s) && $s['second_half_jump'] >= 8],
        ['key' => 'fading_star',  'label' => 'Fading Star',   'grad' => ['#42275a', '#734b6d'], 'meaning' => 'Burned bright early and asked hard questions late. The light is still there.',               'match' => fn($s) => $vol($s) && $s['elo_delta'] <= -120],
        // ── Consistency: catch-alls for the steady, after the standouts ──
        ['key' => 'relentless',   'label' => 'Relentless',    'grad' => ['#ff512f', '#dd2476'], 'meaning' => 'No peaks, no valleys — just a tide that only ever came in.',                                  'match' => fn($s) => $vol($s) && $s['longest_podium_streak'] >= 8],
        ['key' => 'ice_cold',     'label' => 'Ice Cold',      'grad' => ['#7ee8fa', '#1f6feb'], 'meaning' => 'Cool to the touch and lethal under pressure — nothing rattled a season this composed.',     'match' => fn($s) => $vol($s) && $s['has_perfect'] && $s['std_dev'] < 9],
        ['key' => 'wildcard',     'label' => 'Wildcard',      'grad' => ['#8a2be2', '#00c9ff'], 'meaning' => 'Impossible to predict, impossible to ignore — your season kept everyone guessing.',           'match' => fn($s) => $vol($s) && $s['std_dev'] >= 14],
        ['key' => 'ironclad',     'label' => 'Ironclad',      'grad' => ['#485563', '#29323c'], 'meaning' => 'Through every race night, every cup, every shell — you simply never left the grid.',          'match' => fn($s) => $s['attendance_rank'] === 1 && $s['gps'] >= 15],
        ['key' => 'workhorse',    'label' => 'Workhorse',     'grad' => ['#603813', '#b29f94'], 'meaning' => 'No spotlight, no fireworks — just a season of quietly putting in the laps.',                   'match' => fn($s) => $s['gps'] >= 15 && $s['podium_rate'] < 0.40],
        // ── Character identity (valid from race one) ──
        ['key' => 'featherweight','label' => 'Featherlight',  'grad' => ['#89f7fe', '#66a6ff'], 'meaning' => 'Quick, weightless, hard to pin down — your season slipped through the field like air.',        'match' => fn($s) => $s['top_group'] === 'babies'],
        ['key' => 'heavyweight',  'label' => 'Heavy Metal',   'grad' => ['#870000', '#190a05'], 'meaning' => 'Built like a wall and twice as hard to move. You won on sheer presence.',                      'match' => fn($s) => $s['top_group'] === 'heavies'],
        ['key' => 'spectral',     'label' => 'Spectral',      'grad' => ['#41295a', '#2f0743'], 'meaning' => 'There is a chill to this season — you raced with the dead, and they raced well.',             'match' => fn($s) => $s['top_group'] === 'spooky'],
        ['key' => 'regal',        'label' => 'Regal',         'grad' => ['#ee9ca7', '#ffdde1'], 'meaning' => 'You carried yourself like the crown was already yours. Some seasons just have poise.',       'match' => fn($s) => $s['top_group'] === 'royals'],
        ['key' => 'feral',        'label' => 'Feral',         'grad' => ['#134e5e', '#71b280'], 'meaning' => 'Untamed and a little dangerous — you ran on instinct and teeth all year.',                    'match' => fn($s) => in_array($s['top_group'], ['furry', 'reptiles'], true)],
        // ── Low-GP catch-alls ──
        ['key' => 'rookie_fire',  'label' => 'Rookie Fire',   'grad' => ['#ff4e50', '#f9d423'], 'meaning' => 'You did not race often, but every time you did the field noticed. A spark with range.',      'match' => fn($s) => $s['gps'] < WRAPPED_MIN_GPS && $s['avg'] >= 38],
        ['key' => 'fresh_tracks', 'label' => 'Fresh Tracks',  'grad' => ['#36d1dc', '#5b86e5'], 'meaning' => 'Only a handful of starts so far — the season is still wide open for you.',                    'match' => fn($s) => $s['gps'] < WRAPPED_MIN_GPS],
        ['key' => 'steady',       'label' => 'Steady Hand',   'grad' => ['#5b6f8c', '#2c3e50'], 'meaning' => 'No drama, no collapse — a season that simply, reliably, got the job done.',                   'match' => fn($s) => true], // default
    ];
}

/**
 * ~23 Clubs in priority order. The specific achievement clubs come first, then
 * character identity, then the broad ones — and "The Perfectionists" (any 60)
 * is pushed DOWN so it stops swallowing every high-volume racer. Specialists
 * is gated behind WRAPPED_MIN_GPS (one GP = one cup ≠ a specialist), and
 * genuinely low-GP racers land in The Newcomers.
 */
function wrappedClubs(): array {
    $vol = fn($s) => $s['gps'] >= WRAPPED_MIN_GPS;
    return [
        // ── Rare / standout achievements first ──
        ['key' => 'comeback',       'name' => 'The Comeback Club',   'blurb' => 'Down is never out. You answer last place with a win.',           'match' => fn($s) => $vol($s) && $s['comeback']],
        ['key' => 'climbers',       'name' => 'The Climbers',        'blurb' => 'You spent the year going up. The only direction you know.',       'match' => fn($s) => $vol($s) && $s['elo_delta'] >= 120],
        ['key' => 'chaos',          'name' => 'The Chaos Cartel',    'blurb' => 'Where you go, blue shells follow. Beautiful disaster.',           'match' => fn($s) => $s['lols'] >= 6],
        ['key' => 'podium',         'name' => 'The Podium Regulars', 'blurb' => 'Top three is your natural habitat.',                              'match' => fn($s) => $vol($s) && $s['podium_rate'] >= 0.65],
        ['key' => 'variety',        'name' => 'The Variety Pack',    'blurb' => 'A different racer every night. Commitment? Never heard of it.',   'match' => fn($s) => $s['distinct_chars'] >= 10],
        // ── Character identity — the everyday spread ──
        ['key' => 'spook',          'name' => 'The Spook Squad',     'blurb' => 'Boo, Dry Bones, King Boo — you race with the dead.',              'match' => fn($s) => $s['top_group'] === 'spooky'],
        ['key' => 'royals',         'name' => 'The Royal Court',     'blurb' => 'Peach, Daisy, Rosalina. You ride with the crown.',                'match' => fn($s) => $s['top_group'] === 'royals'],
        ['key' => 'reptiles',       'name' => 'The Reptile House',   'blurb' => 'Cold-blooded and quick. Scales over fur.',                        'match' => fn($s) => $s['top_group'] === 'reptiles'],
        ['key' => 'fungi',          'name' => 'The Fungi',           'blurb' => 'Toad, Toadette, Peachette. A real fun guy.',                      'match' => fn($s) => $s['top_group'] === 'fungi'],
        ['key' => 'heavies',        'name' => 'The Heavies',         'blurb' => 'Bowser, DK, Wario. You win on sheer mass.',                       'match' => fn($s) => $s['top_group'] === 'heavies'],
        ['key' => 'feathers',       'name' => 'The Featherweights',  'blurb' => 'Light, nimble, untouchable on the corners.',                      'match' => fn($s) => $s['top_group'] === 'babies'],
        ['key' => 'koopa',          'name' => 'The Koopa Klan',      'blurb' => 'Bowser and the brood. The dark side has cookies.',                'match' => fn($s) => $s['top_group'] === 'koopa_clan'],
        ['key' => 'og_stars',       'name' => 'The OG Stars',        'blurb' => 'Mario, Luigi, Peach, Daisy. Old reliable.',                       'match' => fn($s) => $s['top_group'] === 'og_stars'],
        ['key' => 'persons',        'name' => "The Just-A-Persons",  'blurb' => 'Mii, Inkling, Villager. Keeping it refreshingly human.',          'match' => fn($s) => $s['top_group'] === 'humans'],
        ['key' => 'furries',        'name' => 'The Furries',         'blurb' => 'Tanooki Mario, Cat Peach. Suspiciously fluffy.',                  'match' => fn($s) => $s['top_group'] === 'furry'],
        // ── Effort / playstyle, then broad fallbacks ──
        ['key' => 'grinders',       'name' => 'The Grinders',        'blurb' => 'You showed up more than almost anyone. The league runs on you.',  'match' => fn($s) => $s['attendance_rank'] <= 2 && $s['gps'] >= 15],
        ['key' => 'wildcards',      'name' => 'The Wildcards',       'blurb' => 'Nobody can predict your night. Including you.',                   'match' => fn($s) => $vol($s) && $s['std_dev'] >= 14],
        ['key' => 'specialists',    'name' => 'The Specialists',     'blurb' => 'One cup is basically your second home.',                          'match' => fn($s) => $vol($s) && $s['cup_concentration'] >= 0.40],
        ['key' => 'completionists', 'name' => 'The Completionists',  'blurb' => 'Every cup, every circuit. You leave no track unraced.',           'match' => fn($s) => $s['cups_raced'] >= 22],
        ['key' => 'perfectionists', 'name' => 'The Perfectionists', 'blurb' => 'You found the flawless 60 — and clearly liked the taste.',         'match' => fn($s) => $s['has_perfect']],
        ['key' => 'iron',           'name' => 'The Iron Riders',     'blurb' => 'Season after season, GP after GP. Built to last.',                'match' => fn($s) => $s['gps'] >= 20],
        ['key' => 'newcomers',      'name' => 'The Newcomers',       'blurb' => 'New to the grid this season — the field is still learning your name.', 'match' => fn($s) => $s['gps'] < WRAPPED_MIN_GPS],
        ['key' => 'backmarkers',    'name' => "The Backmarkers' Union", 'blurb' => 'Results be damned — you turn up and you race. Respect.',       'match' => fn($s) => true], // default
    ];
}

/** ~9 Racing Personalities (the "Listening Personality" analog). */
function wrappedPersonalities(): array {
    return [
        ['key' => 'frontrunner', 'label' => 'The Frontrunner', 'blurb' => 'When you race, you race to win — and you usually do.',          'match' => fn($s) => $s['win_rate'] >= 0.35],
        ['key' => 'specialist',  'label' => 'The Specialist',  'blurb' => 'Give you the right cup and the league is in trouble.',          'match' => fn($s) => $s['cup_concentration'] >= 0.40 && $s['wins'] >= 1],
        ['key' => 'comebackkid', 'label' => 'The Comeback Kid','blurb' => 'You are at your most dangerous when written off.',             'match' => fn($s) => $s['comeback']],
        ['key' => 'climber',     'label' => 'The Climber',     'blurb' => 'Every month a little better. The graph only points up.',       'match' => fn($s) => $s['elo_delta'] >= 100],
        ['key' => 'wildcard',    'label' => 'The Wildcard',    'blurb' => 'Brilliant one night, baffling the next. Never boring.',        'match' => fn($s) => $s['std_dev'] >= 14],
        ['key' => 'grinder',     'label' => 'The Grinder',     'blurb' => 'The most reliable seat on the grid. You never miss.',          'match' => fn($s) => $s['attendance_rank'] <= 2],
        ['key' => 'tortoise',    'label' => 'The Tortoise',    'blurb' => 'Slow and steady — and somehow still stealing wins.',           'match' => fn($s) => $s['avg'] < 38 && $s['wins'] >= 1],
        ['key' => 'ironheart',   'label' => 'The Ironheart',   'blurb' => 'Mid-pack and proud, the heartbeat of every race night.',       'match' => fn($s) => $s['gps'] >= 12],
        ['key' => 'steady',      'label' => 'The Steady Hand', 'blurb' => 'No drama, no fuss — just clean, consistent karting.',          'match' => fn($s) => true], // default
    ];
}
