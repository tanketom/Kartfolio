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
$exampleRules = [
    'cups_required'      => 12,
    'best_n_count'       => 15,
    'drop_worst_count'   => 2,
    'perfect_multiplier' => 2.0,
    'pm_ante'            => 100,
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
        'keys'  => ['cup_based', 'best_n_gps', 'drop_worst', 'perfect_hunt', 'top_12_unique', 'random_cup_draw'],
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
];

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
