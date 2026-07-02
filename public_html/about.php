<?php
/**
 * About Page
 * Path: /cdnmk/public_html/about.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$currentSeason = getCurrentSeasonNumber();
$pageTitle = "About - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <header class="page-header about-page-header">
        <h1 class="page-title about-page-title">About <?= htmlspecialchars($leagueName) ?> League</h1>
        <p class="page-subtitle">WHERE MARIO KART RACING MEETS DATA-DRIVEN STORYTELLING</p>
    </header>

    <div class="about-content">
        <section class="about-section">
            <h2>What Is This?</h2>
            <p>
                The <strong><?= htmlspecialchars($leagueName) ?> League</strong> is a competitive Mario Kart 8 Deluxe racing league that transforms casual gameplay into a sophisticated data ecosystem. What began as colleagues racing evolved into a comprehensive performance tracking platform that captures every drift, every item hit, and every triumph across the Mushroom Kingdom's circuits.
            </p>
            <p>
                At the core of every season sits a configurable <strong>scoring system</strong>. The original <strong>GPScore™</strong> blends average cup scores with attendance bonuses, but the platform now ships with a dozen-plus alternatives — from Best-N-GPs and Drop-Worst formats to the cup-collecting <strong>Top 12 Unique</strong>, the chaotic <strong>Random Cup Draw</strong>, the statistical <strong>Black Box</strong>, the RPG-flavoured <strong>MONSTER HUNT</strong> (where racers farm XP against Elo-driven monsters), the field-policing <strong>Bounty Hunter</strong> (every above-median Elo racer carries a bounty equal to their Elo gap), and the zero-sum <strong>Pari-Mutuel</strong> betting model. For leagues that care about <em>where</em> you finish rather than by how much, two relative systems score on position alone: <strong>Positional Points</strong> (a fixed Mario-Kart finish ladder — a win always banks the same) and <strong>Head-to-Head</strong> (your win rate across every pairwise matchup, margin-blind and fair across uneven attendance). Every season picks its own rules, grace periods, and finals weeks.
            </p>
            <p>
                For the slightly less avid players, <strong>Mikkoliiga</strong> runs as a parallel casual sub-league inside the same Grand Prix nights. Members race alongside everyone else but score on their own internal scale — the canonical Mario Kart 12-position points awarded among Mikkoliiga members only, with the best <?= MIKKOLIIGA_BEST_X ?> GPs counted. Membership is snapshotted at season close so the standings can't shift retroactively.
            </p>
            <p>
                Numbers alone don't tell the full story. That's where the league's <strong>media ecology</strong> comes alive. The broadcast feature synthesizes recent race data, current form rankings, and emerging rivalries into AI-generated commentary through eight distinct personas — from the analytical Kart Core Team to the philosophical musings of The Situated Spectator. When the news demands sober copy instead of vibes, the <strong>OMK Press Office</strong> provides a hand-written publishing path that bypasses AI entirely. Dedicated pipelines also produce weekly power rankings, MONSTER HUNT Chronicles (medieval-bard post-GP stories), tournament recaps, and end-of-season reports with personalized awards.
            </p>
            <p>
                The platform tracks everything that matters: signature character and kart combos, personal bests, head-to-head records against specific rivals, even the dreaded <strong>Ludwig Obstruction</strong> moments when NPCs derail an otherwise perfect run. <strong>Elo ratings</strong> chart each racer's trajectory in real numbers, the <strong>Nemesis Index</strong> surfaces the tightest rivalries, a <strong>badge system</strong> marks milestones as they happen, and a <strong>fantasy layer</strong> lets the audience pick weekly MVPs, head-to-head matchups, and prop bets — now with a <strong>confidence picker</strong> that multiplies both hits and misses.
            </p>
            <p>
                Beyond the web UI, the league runs on real hardware. Dedicated <strong>signage displays</strong> broadcast live standings to lounge screens in both vertical (2048×2560) and horizontal (1920×1080) formats, with an auto-rotating variant for longer attention spans, and an <strong>OBS overlay</strong> with seven hotkey-switchable views for streaming. Tournaments support eight formats — Single &amp; Double Elimination, Gauntlet, Team Relay, the attrition-driven <strong>Survivor</strong> (one big multi-player race per round, last finisher out), <strong>Team Scramble</strong>, the group-stage <strong>World Cup</strong>, and <strong>Snakes &amp; Ladders</strong> (rotating heats climb a drawn board; first token to land exactly on the final square wins). The setup screen recommends the formats that best fit your racer count, and any live tournament shows a public board linked from the top of the front page. Season winners get immortalized in the <strong>Hall of Fame</strong>, and any standing can be exported as a shareable graphic for Discord.
            </p>
            <p>
                This isn't just a leaderboard. It is an archive that captures the drama, the statistics, and the stories that emerge when competitive racing meets serious data infrastructure. Every Grand Prix contributes to an expanding narrative universe where performance metrics and media commentary intertwine. The <?= htmlspecialchars($leagueName) ?> League proves that even a party game can become the foundation for compelling storytelling and genuine competitive depth — one blue shell at a time.
            </p>
        </section>

        <section class="about-section">
            <h2>Key Features</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <h3>Twelve Scoring Systems</h3>
                    <p>From classic GPScore™ (average + attendance) to Best-N, Drop-Worst, Top 12 Unique Cups, Random Cup Draw, Black Box, Perfect Hunt, Preseason, the XP-driven MONSTER HUNT, the Elo-tracking Bounty Hunter, the zero-sum Pari-Mutuel, and the relative Positional Points and Head-to-Head systems. Each season picks its own rules.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🌟</div>
                    <h3>Mikkoliiga Sub-League</h3>
                    <p>An opt-in casual league for less-avid players. Members race in the same GPs but score internally with the canonical Mario Kart points scale, best <?= MIKKOLIIGA_BEST_X ?> GPs counted. Membership snapshots at season close so history stays stable.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📈</div>
                    <h3>Elo Ratings & Power Rankings</h3>
                    <p>Dynamic Elo ratings track each racer's real skill trajectory, while AI-generated power rankings capture form, narrative, and vibes week by week.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">⚔️</div>
                    <h3>Rivalries & Nemesis Index</h3>
                    <p>Head-to-head analytics identify the tightest 50/50 matchups, while the rivalry web visualizes the full social graph of who keeps beating whom.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📻</div>
                    <h3>AI Broadcasts</h3>
                    <p>Gemini-powered commentary in eight distinct program voices — sports desk, gonzo journalism, academic analysis, meta breakdowns — plus weekly power rankings and per-GP MONSTER HUNT Chronicles.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📰</div>
                    <h3>OMK Press Office</h3>
                    <p>A hand-written publishing channel that bypasses AI entirely. Type the headline, key quote, and body — what you write is what gets published, no generation, no Director's Notes.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🏅</div>
                    <h3>Badges & Milestones</h3>
                    <p>60+ badges auto-awarded — first podium, perfect cups, rivalry flips, streaks, cup completion, sticker collecting, tournament honours, and more — with unlock alerts on the racer page.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🎲</div>
                    <h3>Fantasy Predictions</h3>
                    <p>Weekly MVP picks, head-to-head matchups, and prop bets with a confidence picker (Light ×1 / Medium ×2 / 🔒 Lock ×3) that multiplies both hits and misses. Leaderboard shows accuracy %, locks-hit ratio, and total points.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🥊</div>
                    <h3>Tournament Formats</h3>
                    <p>Eight formats: Single &amp; Double Elimination, Gauntlet, Team Relay, Survivor, Team Scramble, World Cup, and Snakes &amp; Ladders. Setup recommends the best fit for your racer count; running tournaments get a public live board linked from the front page.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🏆</div>
                    <h3>Cup Statistics</h3>
                    <p>Detailed breakdowns of performance by cup — see who dominates Mushroom Cup versus who thrives in Special Cup chaos — plus the 24-cup × all-racers Cup Mastery grid.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🎮</div>
                    <h3>Racer Profiles & Loadouts</h3>
                    <p>Per-racer pages with career timelines, signature character and kart combos, cup mastery, record chasers, milestone alerts, and earned badges.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📺</div>
                    <h3>Lounge Displays</h3>
                    <p>Edge-to-edge signage for physical screens — vertical (2048×2560), horizontal (1920×1080), and auto-rotating slides with news tickers.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📡</div>
                    <h3>OBS Stream Overlay</h3>
                    <p>Compact streaming overlay with seven view modes — standings, last GP, rivalry spotlight, fantasy leaderboard, Elo movers, cup-of-the-night, hide — switchable via hotkeys 0-6, URL params for OBS scene-switching, and optional auto-rotation.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📚</div>
                    <h3>Season Archives & Awards</h3>
                    <p>Every closed season preserved in the Hall of Fame, with auto-determined core awards and AI-generated personalized awards for each racer. Mikkoliiga standings get their own archived sidebar.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h3>All-Time Records</h3>
                    <p>Cross-season leaderboards tracking peak performances, career totals, and legendary moments across league history.</p>
                </div>
            </div>
        </section>

        <section class="about-section">
            <h2>The Technology Stack</h2>
            <p>Built with no frameworks and no build step — just deploy and race:</p>
            <ul class="tech-stack">
                <li><strong>PHP 8 & SQLite</strong> — core backend and single-file database</li>
                <li><strong>Vanilla JavaScript & Chart.js</strong> — interactive dashboards and rating curves</li>
                <li><strong>Google Gemini</strong> — broadcasts, power rankings, tournament recaps, season reports, personalized awards</li>
                <li><strong>Custom CSS</strong> — Nintendo-inspired design system with responsive layouts and dedicated signage stylesheets</li>
                <li><strong>Apache with mod_rewrite</strong> — clean URLs and route mapping via .htaccess</li>
            </ul>
        </section>

        <section class="about-section">
            <h2>The Media Ecology</h2>
            <p>
                The league's broadcast system features multiple distinct programs, each offering a different perspective on race results. Seven AI personas write generated commentary; OMK Press Office publishes straight news without any generation.
            </p>
            <div class="personas-list">
                <div class="persona-item">
                    <strong>📰 OMK Press Office</strong> - Hand-written news straight from the governing body. No AI, no Director's Notes — what's written is what's published.
                </div>
                <div class="persona-item">
                    <strong>Kart Core Team</strong> - Analytical, data-driven commentary focusing on technical performance
                </div>
                <div class="persona-item">
                    <strong>Reef's Dispatch</strong> - Our very own Hunter S. Thompson
                </div>
                <div class="persona-item">
                    <strong>The Meta Report</strong> - Deep-dive analysis of strategic trends and character choices
                </div>
                <div class="persona-item">
                    <strong>The Rant</strong> - Passionate, opinionated hot takes on controversial moments
                </div>
                <div class="persona-item">
                    <strong>The Ghost Racer's Ascent</strong> - Youtube channel obsessed with one particular racer
                </div>
                <div class="persona-item">
                    <strong>The Situated Spectator</strong> - Academic, theoretical commentary on racing as performance
                </div>
                <div class="persona-item">
                    <strong>Viberacing</strong> - Vibes-based analysis that captures the intangible energy of competition
                </div>
            </div>
        </section>

        <section class="about-section about-cta-section">
            <h2>Ready to Race?</h2>
            <p>
                The current season is <strong><?= strtoupper($currentSeason) ?></strong>. Check out the latest standings and broadcasts.
            </p>
            <div class="about-cta-buttons">
                <a href="/" class="btn btn-primary about-cta-btn">View Leaderboard</a>
                <a href="/archive" class="btn btn-secondary about-cta-btn">Browse Broadcasts</a>
            </div>
        </section>
    </div>
</div>


<?php include __DIR__ . '/../private/templates/footer.php'; ?>
