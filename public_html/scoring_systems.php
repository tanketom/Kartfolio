<?php
/**
 * Scoring Systems reference page.
 *
 * A descriptive catalog of every scoring system the platform ships with —
 * pulled from getScoringSystemRegistry() so the copy here can never drift
 * from the homepage tooltip / admin dropdown / /scoring breakdown.
 *
 * Mikkoliiga gets its own section since it's a parallel internal scoring
 * scheme rather than a season-wide system.
 *
 * Path: /cdnmk/public_html/scoring_systems.php
 * URL:  /scoring-systems
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$registry = getScoringSystemRegistry();

/**
 * Resolve a callable-or-string field to a string, using the registry's
 * default rules so configurable systems show a sensible example.
 */
function ss_resolve($val, array $rules) {
    if (is_callable($val)) return $val($rules);
    return (string)$val;
}

// Defaults used for configurable name/description templates so the
// examples on this page show a representative value rather than a 0.
$exampleRules = newSeasonDefaults('average_attendance') + [
    'pm_ante'            => 100,
    'h2h_npc_weight'     => 0.25,
    'bs_rate'            => 0.10,
    'bs_cap'             => 2.0,
    'hm_cap'             => 2.0,
    'form_window'        => 8,
    'bg_line_pts'        => 100,
    'bg_card_pts'        => 500,
    'pir_target'         => 'median',
    'pir_best_n'         => 15,
    'eq_mode'            => 'season',
    'cc_gp_cost'         => 5,
    'cc_final_cost'      => 50,
];

// Grouped presentation. Order within each group mirrors the registry.
$groups = [
    [
        'title' => 'Foundational',
        'blurb' => 'The everyday systems — built around averages and attendance, with sensible knobs for season tuning.',
        'keys'  => ['average_attendance', 'preseason'],
    ],
    [
        'title' => 'Cup-Based & Best-Of',
        'blurb' => 'Reward depth and consistency by counting only the strongest performances or specific cup sets.',
        'keys'  => ['cup_based', 'best_n_gps', 'drop_worst', 'perfect_hunt', 'top_12_unique', 'random_cup_draw', 'territory', 'hard_mode'],
    ],
    [
        'title' => 'Experimental',
        'blurb' => 'For when the leaderboard should feel less predictable.',
        'keys'  => ['black_box'],
    ],
    [
        'title' => 'RPG & Combat',
        'blurb' => 'Elo-aware modes that turn the strongest racers into bounties or boss fights.',
        'keys'  => ['monster_hunt', 'bounty_hunter'],
    ],
    [
        'title' => 'Wagering',
        'blurb' => 'Zero-sum formats where every GP redistributes points among the participants.',
        'keys'  => ['pari_mutuel'],
    ],
    [
        'title' => 'Relative / Position-Based',
        'blurb' => 'Score where you finish, not how many points you scored — so a win always feels like a win, regardless of margin. Built to stay fair when racers attend wildly different numbers of GPs.',
        'keys'  => ['positional_points', 'head_to_head'],
    ],
    [
        'title' => 'Balance & Form',
        'blurb' => 'Systems that level the field or track current form instead of season totals — a catch-up multiplier, a median that ignores volume, and a rolling window that forgets the spring.',
        'keys'  => ['blue_shell', 'median', 'form'],
    ],
    [
        'title' => 'The Weird Ones',
        'blurb' => 'Systems that break the rules on purpose — what counts, when you find out, or whether being good is even the goal. Seeded bingo cards, a hidden target you must not exceed, a season won by being average, and a crown nobody wants.',
        'keys'  => ['kart_bingo', 'price_is_right', 'equaliser', 'cursed_crown'],
    ],
];

// Safety net: any registry system not placed in a group above still gets
// listed. This page used to drop unlisted systems silently — five shipped and
// none appeared here — so the catalogue can no longer disagree with the code.
$covered  = array_merge(...array_column($groups, 'keys'));
$leftover = array_values(array_diff(array_keys($registry), $covered));
if (!empty($leftover)) {
    $groups[] = [
        'title' => 'Also available',
        'blurb' => 'Systems in the registry that have not been given a home above yet.',
        'keys'  => $leftover,
    ];
}

$pageTitle = "Scoring Systems - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Scoring Systems</span>
    </nav>

    <header class="page-header about-page-header">
        <h1 class="page-title about-page-title">Scoring Systems</h1>
        <p class="page-subtitle">EVERY WAY THE LEAGUE KEEPS SCORE</p>
        <p class="page-subtitle"><a href="/multiverse">🌌 Who would have won each season under each of them? →</a></p>
    </header>

    <div class="about-content">
        <section class="about-section">
            <p>
                Every season picks <strong>one</strong> scoring system from the catalog below. The system
                decides how raw GP results turn into season standings, what counts as qualifying,
                and which tiebreakers apply. Some systems expose knobs an admin can tune per
                season — drop counts, multipliers, cup requirements, ante sizes — without touching
                the engine itself.
            </p>
            <p>
                The descriptions on this page are the same ones used everywhere else: the
                homepage tooltip, the admin season dropdown, and the <a href="/scoring">/scoring</a>
                breakdown for the current season. Change them in one place
                (<code>getScoringSystemRegistry()</code>), and they update everywhere.
            </p>
        </section>

        <?php foreach ($groups as $g): ?>
            <section class="about-section">
                <h2><?= htmlspecialchars($g['title']) ?></h2>
                <p><?= htmlspecialchars($g['blurb']) ?></p>
                <div class="ss-grid">
                    <?php foreach ($g['keys'] as $key):
                        if (!isset($registry[$key])) continue;
                        $def = $registry[$key];
                        $name = ss_resolve($def['name'], $exampleRules);
                        $oneLiner = ss_resolve($def['description'], $exampleRules);
                        $long = $def['long_description'] ?? '';
                        // Black Box is admin-eyes-only by design — the public catalog
                        // shouldn't leak the formula even if the registry knows it.
                        if ($key === 'black_box') {
                            $long = '[redacted]';
                        }
                    ?>
                        <article class="ss-card">
                            <div class="ss-card-head">
                                <span class="ss-card-icon"><?= $def['icon'] ?></span>
                                <h3 class="ss-card-name"><?= htmlspecialchars($name) ?></h3>
                            </div>
                            <p class="ss-card-oneliner"><?= htmlspecialchars($oneLiner) ?></p>
                            <?php if ($long): ?>
                                <p class="ss-card-long"><?= htmlspecialchars($long) ?></p>
                            <?php endif; ?>
                            <?php if (is_callable($def['description']) || is_callable($def['name'])): ?>
                                <p class="ss-card-config">⚙️ Configurable per season</p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="about-section">
            <h2>🌟 Mikkoliiga (parallel sub-league)</h2>
            <p>
                Mikkoliiga is not a season-wide scoring system — it runs <em>inside</em> whichever
                main-league system the season uses. Opted-in members race in the same GPs as
                everyone else but score <strong>internally</strong>: in each GP, only Mikkoliiga
                members are considered, re-ranked among themselves by their actual GP points, and
                awarded the canonical Mario Kart 12-position scale —
                <strong>15 / 12 / 10 / 9 / 8 / 7 / 6 / 5 / 4 / 3 / 2 / 1</strong>.
            </p>
            <p>
                A member's season total is the sum of their <strong>best <?= MIKKOLIIGA_BEST_X ?></strong>
                internal scores. Membership is snapshotted at season close so historical
                standings can't shift retroactively, and admins can re-snapshot from the season
                admin page if a correction is needed.
            </p>
            <div class="about-cta-buttons">
                <a href="/mikkoliiga" class="btn btn-secondary about-cta-btn">View Current Standings</a>
            </div>
        </section>

        <section class="about-section">
            <h2>🏆 Tournament Formats (one-off events)</h2>
            <p>
                Separate from season scoring, the league can run <strong>tournaments</strong> — bounded
                events with their own bracket or board. The setup screen recommends the formats that
                best fit your racer count, and any live tournament shows a public board linked from the
                top of the front page.
            </p>
            <div class="ss-grid">
                <article class="ss-card">
                    <div class="ss-card-head"><span class="ss-card-icon">🐍</span><h3 class="ss-card-name">Snakes &amp; Ladders</h3></div>
                    <p class="ss-card-oneliner">Climb a drawn board in rotating heats of four.</p>
                    <p class="ss-card-long">Each round the field splits into heats; your finish is your roll (1st = +4 … 4th = +1). Ladders climb, snakes slide, and the first token to land <em>exactly</em> on the final square is champion. Board length and chaos are configurable.</p>
                    <p class="ss-card-config">⚙️ Configurable per tournament</p>
                </article>
                <article class="ss-card">
                    <div class="ss-card-head"><span class="ss-card-icon">🌍</span><h3 class="ss-card-name">World Cup</h3></div>
                    <p class="ss-card-oneliner">Group stage of fours → knockout, hosted by Kartificial.</p>
                    <p class="ss-card-long">A pot-seeded draw into groups; top two per group plus the best third-placers advance to a head-to-head knockout. Bracket Pick'em opens automatically.</p>
                </article>
                <article class="ss-card">
                    <div class="ss-card-head"><span class="ss-card-icon">💀</span><h3 class="ss-card-name">Survivor</h3></div>
                    <p class="ss-card-oneliner">One big race each round; the bottom finisher is out.</p>
                    <p class="ss-card-long">Pure attrition with a deathboard view. Eliminations-per-round is configurable for larger fields.</p>
                </article>
                <article class="ss-card">
                    <div class="ss-card-head"><span class="ss-card-icon">🤝</span><h3 class="ss-card-name">Team Scramble</h3></div>
                    <p class="ss-card-oneliner">Snake-drafted into balanced teams, one GP, most combined points wins.</p>
                </article>
                <article class="ss-card">
                    <div class="ss-card-head"><span class="ss-card-icon">⚔️</span><h3 class="ss-card-name">Single / Double Elimination</h3></div>
                    <p class="ss-card-oneliner">Classic knockout brackets — lose once (or twice) and you're out.</p>
                </article>
                <article class="ss-card">
                    <div class="ss-card-head"><span class="ss-card-icon">👑</span><h3 class="ss-card-name">Gauntlet &amp; Team Relay</h3></div>
                    <p class="ss-card-oneliner">A Boss defending against all challengers, or team-leg relays.</p>
                </article>
            </div>
        </section>

        <section class="about-section about-cta-section">
            <h2>See It In Action</h2>
            <p>
                The current season uses one of the systems above. Visit
                <a href="/scoring">/scoring</a> for a live, season-specific breakdown of how
                today's standings were computed.
            </p>
            <div class="about-cta-buttons">
                <a href="/scoring" class="btn btn-primary about-cta-btn">Current Season Breakdown</a>
                <a href="/" class="btn btn-secondary about-cta-btn">Back to Standings</a>
            </div>
        </section>
    </div>
</div>

<style>
.ss-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
    margin-top: 16px;
}
.ss-card {
    background: var(--gray-50);
    border: 1px solid #2a2a2a;
    border-left: 4px solid #FFD700;
    border-radius: 8px;
    padding: 18px 20px;
    color: var(--gray-900);
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ss-card-head {
    display: flex;
    align-items: center;
    gap: 12px;
}
.ss-card-icon {
    font-size: 2rem;
    line-height: 1;
}
.ss-card-name {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 900;
    text-transform: uppercase;
    color: var(--nintendo-red);
    letter-spacing: 0.5px;
}
.about-section .ss-card p.ss-card-oneliner {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1.4;
}
.about-section .ss-card p.ss-card-long {
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.55;
    color: var(--gray-700);
}
.about-section .ss-card p.ss-card-config {
    margin: 0;
    font-size: 0.78rem;
    color: var(--nintendo-red);
    font-style: italic;
    border-top: 1px dashed #333;
    padding-top: 10px;
    line-height: 1.3;
}

@media (max-width: 600px) {
    .ss-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
