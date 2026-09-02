<?php
/**
 * Site Map — index of every open page on the site.
 *
 * Hidden URL (not linked from nav). Lists everything a non-admin can
 * browse to, grouped by purpose. Admin pages, API endpoints, and the
 * login/logout flow are deliberately omitted.
 *
 * Path: /cdnmk/public_html/map.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');

$pageTitle = 'Site Map - Kartfolio';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// Each entry: ['url', 'icon', 'name', 'desc'].
// Sections render in declaration order.
$sections = [
    'Standings & leagues' => [
        ['/',                  '🏁', 'Current season',         'Live leaderboard for the active season — scoring system + standings + last-GP card.'],
        ['/mikkoliiga',        '🌟', 'Mikkoliiga',              'Casual sub-league standings — best ' . MIKKOLIIGA_BEST_X . ' GPs by internal Mario Kart points.'],
        ['/teams',             '🤝', 'Teams',                   'Constructor-style team standings — best ' . TEAM_BEST_N . ' members per GP. Rosters set by an admin per season.'],
        ['/all-time',          '🏆', 'All-time records',        'Cross-season leaderboard tracking career totals and peak performances.'],
        ['/season-archives',   '📚', 'Hall of Fame',            'Every closed season with champion, awards, and a final report.'],
        ['/view-season-report?season=s02', '📜', 'Season report',  'Full archived season report — final standings, champion, season awards, AI narrative.'],
    ],

    'Statistics & analysis' => [
        ['/stats',             '📈', 'Trends',                  'Form, momentum, and rolling-window stats — who\'s heating up, who\'s cooling off.'],
        ['/power-rankings',    '🎙️', 'Power Rankings',          'AI-generated weekly take on each racer\'s trajectory, narrative, and vibes.'],
        ['/cup-stats',         '📊', 'Cup analysis',            'Difficulty index, Fan Favourite cups (from /track-favourites), per-cup podiums. Click any cup to drill in.'],
        ['/cup/mushroom',      '🍄', 'Cup detail page',         'Per-track encyclopedia for one cup — fan-fave Elo, Mac\'s Mushroom Musings strategy notes, champions wall, recent GPs. URL ends in the cup slug.'],
        ['/cup-mastery',       '🏆', 'Cup mastery grid',        'Visual 24-cup × all-racers completion matrix with best scores.'],
        ['/track-favourites',  '🏁', 'Track Favourites',        'Head-to-head track voting. Builds an Elo ranking of all 96 tracks.'],
        ['/rivalries',         '⚔️', 'Nemesis Index',           'Pairwise head-to-head records — the tightest 50/50 matchups across the league.'],
        ['/rivalry_web.php',   '🕸️', 'Rivalry web',             'Force-directed graph of every rivalry — visualises the social structure of the league.'],
        ['/timeline',          '🗓️', 'Timeline',                'Season-by-season timeline of races, results, and milestones.'],
        ['/timeline/s03gp01',  '🏁', 'Single GP detail',        'Full breakdown of one Grand Prix — results, Elo deltas, MH Chronicles, related broadcasts. URL ends in the GPID.'],
        ['/season-chart',      '📈', 'Season chart',            'F1-style spaghetti chart of standings rank by GP. Crossings show where rivalries flipped.'],
        ['/vault',             '🗄️', 'The Vault',               'Curiosities and outliers — closest wins, biggest blowouts, most loyal characters, season LOL champions.'],
        ['/records',           '📖', 'Record Book',             'Peak single-GP scores, longest streaks, perfect 60 counts, and other career bests.'],
        ['/badges-overview',   '🏅', 'Badges',                  'Every racer\'s career badge progress — what\'s unlocked, what\'s next.'],
        ['/elo-trends',        '📉', 'Elo trends',              'Elo trajectory charts — each racer\'s rating curve over the league\'s history.'],
        ['/predictions',       '🔮', 'Crystal Ball',            'Monte Carlo simulations of the current season — championship probabilities.'],
        ['/scoring-systems',   '🧮', 'Scoring systems catalog', 'Descriptive reference for every scoring system the platform ships with — what each one rewards and how it tunes.'],
        ['/scoring',           '🔢', 'Current-season scoring',  'Live breakdown of how the active season\'s standings are computed under its scoring system.'],
    ],

    'Per-racer profiles' => [
        ['/racer/1',           '👤', 'Racer profile',           'Per-racer dossier with career stats, badges, Elo history, and signature loadout. Replace the id with any racer\'s number.'],
    ],

    'News & broadcasts' => [
        ['/archive',           '📻', 'Broadcast archive',       'News feed — AI broadcasts in 7 program styles plus hand-written OMK Press Office releases.'],
        ['/view-recap/1',      '📰', 'Single broadcast',        'Full text of one broadcast or press release. Replace the id with any broadcast number.'],
        ['/stories',           '⚔️', 'MONSTER HUNT Chronicles', 'Medieval-bard-voiced post-GP stories for MONSTER HUNT seasons.'],
        ['/mh-dashboard',      '👹', 'MONSTER HUNT dashboard',  'Per-GP role assignments, CR tiers, XP totals, and the Monster Hunter title ladder.'],
    ],

    'Fantasy & predictions' => [
        ['/fantasy',           '🎲', 'Fantasy league',          'Weekly MVP picks, head-to-head matchups, prop bets — with the Light / Medium / Lock confidence picker.'],
    ],

    'Tournaments' => [
        ['/tournaments-hall-of-fame', '🥊', 'Tournament Hall of Fame', 'Every completed tournament: winner, bracket, format, recap.'],
        ['/view-tournament-report?id=1', '🏆', 'Tournament report',   'Detailed report for a single tournament. Replace the id.'],
        ['/wc-pickem/1',       '🌍', "Bracket Pick'em",         'World Cup prediction game — pick group qualifiers + a champion before racing starts. Replace the id with a World Cup tournament. Hosted by Kartificial.'],
    ],

    'Displays & overlays' => [
        ['/display/vertical',     '📺', 'Vertical signage',     '2048×2560 portrait standings for vertically-mounted lounge screens.'],
        ['/display/horizontal',   '🖥️', 'Horizontal signage',    '1920×1080 standings layout for TVs.'],
        ['/display/auto-vertical','🎞️', 'Auto-vertical',         'Rotating slides for the lounge screen — standings + ticker + recap cards.'],
        ['/overlay',              '📡', 'OBS overlay',           'Compact streaming overlay with 7 hotkey-switchable views (standings, last GP, rivalry, fantasy, Elo movers, cup-of-night, hide).'],
    ],

    'Tools & utilities' => [
        ['/add-result',        '🚀', 'Log a Grand Prix',        'Result-entry form for league members. Requires the four-digit Gameslab wall code.'],
        ['/cards',             '🃏', 'Trading cards',           'Browse every racer\'s trading card (auto-generated SVGs).'],
        ['/stickers/1',        '🩹', 'Sticker album',           'Panini-style collection — one pack per GP raced (from next season), anyone can tear them open. Replace the id with any racer\'s number.'],
        ['/rank-graphic',      '🖼️', 'Standings graphic',       'Exportable standings card — shareable image for Discord or social.'],
        ['/animate-season',    '🎬', 'Season animation',        'Animated season recap — rank changes round by round.'],
        ['/api/v1/standings',  '🔌', 'JSON API',                'Read-only public data feed (standings, racers, teams, mikkoliiga, seasons) for embeds & Discord. CORS-open.'],
    ],

    'About this site' => [
        ['/about',             'ℹ️', 'About',                    'What the league is and how the system works. The user-facing intro.'],
        ['/map',               '🗺️', 'Site map',                 'You\'re here.'],
        ['/uml',               '🗂️', 'System UML',               'Class diagram of every DB table + a data-flow view of which code modules read or write what.'],
        ['/lexicon',           '📖', 'Lexicon',                 'Every term, in-joke, and piece of jargon the league uses — GPScore™, Ludwig Obstruction, Mikkoligan, the lot.'],
    ],
];
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Site Map</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🗺️ Site Map</h1>
        <p class="page-subtitle">EVERY OPEN PAGE OF <?= strtoupper(htmlspecialchars($leagueName)) ?> · ORGANISED BY PURPOSE</p>
    </header>

    <div class="map-intro">
        <p>
            This page lists every open URL the site exposes — public reads only. Admin tools, API endpoints, and the login flow are deliberately omitted.
        </p>
        <p>
            URLs with dynamic IDs (racer profiles, broadcasts, tournament reports) link to a sample row; substitute any numeric id to navigate to a specific entry.
        </p>
    </div>

    <?php foreach ($sections as $title => $entries): ?>
    <section class="map-section">
        <h2 class="map-section-title"><?= htmlspecialchars($title) ?></h2>
        <div class="map-grid">
            <?php foreach ($entries as $entry):
                [$url, $icon, $name, $desc] = $entry;
            ?>
            <a class="map-card" href="<?= htmlspecialchars($url) ?>">
                <div class="map-card-row">
                    <span class="map-icon"><?= $icon ?></span>
                    <span class="map-name"><?= htmlspecialchars($name) ?></span>
                </div>
                <div class="map-url"><?= htmlspecialchars($url) ?></div>
                <div class="map-desc"><?= htmlspecialchars($desc) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>

<style>
.map-intro {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-left: 4px solid #FFD700;
    border-radius: 8px;
    padding: 16px 22px;
    margin-bottom: 28px;
    color: var(--gray-600);
    line-height: 1.55;
}
.map-intro p { margin: 0 0 6px; font-size: 0.92rem; }
.map-intro p:last-child { margin: 0; }

.map-section { margin-bottom: 36px; }
.map-section-title {
    font-size: 1rem;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: var(--gray-500);
    border-bottom: 1px solid var(--gray-200);
    padding-bottom: 6px;
    margin: 0 0 14px;
}
.map-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.map-card {
    background: var(--gray-200);
    border: 1px solid #1f1f1f;
    border-radius: 8px;
    padding: 14px 16px;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    gap: 4px;
    transition: transform 0.12s ease, border-color 0.12s ease, background 0.12s ease;
}
.map-card:hover {
    transform: translateY(-2px);
    border-color: #FFD700;
    background: #fff6dc;
}
.map-card-row { display: flex; align-items: center; gap: 10px; }
.map-icon { font-size: 1.5rem; line-height: 1; }
.map-name { font-weight: 800; color: var(--gray-900); font-size: 1rem; }
.map-url {
    font-family: ui-monospace, "SF Mono", Menlo, Monaco, "Courier New", monospace;
    font-size: 0.72rem;
    color: var(--boost);
    word-break: break-all;
}
.map-desc { color: var(--gray-500); font-size: 0.82rem; line-height: 1.4; margin-top: 2px; }
@media (max-width: 480px) { .map-grid { grid-template-columns: 1fr; } }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
