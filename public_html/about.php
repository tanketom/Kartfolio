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
                At its core, the league uses a custom-built <strong>GPScore™ algorithm</strong> to evaluate racer performance. Unlike simple placement-based scoring, GPScore uses average cup scores (to account for not everyone being able to race all the time), but adds points for attendance to incentivise playing.
            </p>
            <p>
                But numbers alone don't tell the full story. That's where the league's <strong>media ecology</strong> comes alive. Every week, the system's "Generate New Broadcast" feature synthesizes recent race data, current form rankings, and emerging rivalries into AI-generated commentary through various personas—from the analytical "Kart Core Team" to the philosophical musings of "The Situated Spectator." These broadcasts transform raw statistics into narrative arcs, tracking the rise of underdogs, the fall of favorites, and the bitter feuds that develop when racers meet too many times on Rainbow Road.
            </p>
            <p>
                The platform tracks lots of data: your signature character and kart combo, your personal best scores, your head-to-head records against specific rivals, even the dreaded <strong>Ludwig Obstruction</strong> moments when NPCs derail an otherwise perfect run. Historical power rankings chart each racer's trajectory across time, while the <strong>Nemesis Index</strong> identifies the tightest rivalries—those 50/50 matchups where neither racer can claim dominance.
            </p>
            <p>
                This isn't just a leaderboard. It is an archive trying to capture the drama, the statistics, and the stories that emerge when competitive racing meets serious data infrastructure. Every Grand Prix contributes to an expanding narrative universe where performance metrics and media commentary intertwine, creating a rich tapestry of racing history. The <?= htmlspecialchars($leagueName) ?> League proves that even a party game can become the foundation for compelling storytelling, and genuine competitive depth – one blue shell at a time.
            </p>
        </section>

        <section class="about-section">
            <h2>Key Features</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <h3>GPScore™ Algorithm</h3>
                    <p>Sophisticated performance scoring that accounts for field strength, attendance bonuses, and competitive context – not just finishing position.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📈</div>
                    <h3>Historical Power Rankings</h3>
                    <p>Track each racer's evolution across the season with dynamic charts showing form trends and performance trajectories.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">⚔️</div>
                    <h3>Nemesis Index</h3>
                    <p>Discover your closest rivals through head-to-head analytics. The system identifies tight matchups and tracks who owns who on the track.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📻</div>
                    <h3>AI-Generated Broadcasts</h3>
                    <p>Weekly AI commentary synthesizes race results, form rankings, and rivalries into narrative-driven broadcasts through multiple media personas.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🏆</div>
                    <h3>Cup Statistics</h3>
                    <p>Detailed breakdowns of performance by cup type – see who dominates the Mushroom Cup versus who thrives in Special Cup chaos.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">🎮</div>
                    <h3>Loadout Tracking</h3>
                    <p>Monitor character and kart preferences, discovering each racer's signature setup and meta choices.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon">📚</div>
                    <h3>Season Archives</h3>
                    <p>Complete historical records preserved in the Hall of Fame, immortalizing every champion and breakthrough performance.</p>
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
            <p>Built with:</p>
            <ul class="tech-stack">
                <li><strong>PHP & SQLite</strong> - Core backend and database architecture</li>
                <li><strong>Chart.js</strong> - Dynamic data visualizations and power rankings</li>
                <li><strong>Google Gemini AI</strong> - Natural language broadcast generation</li>
                <li><strong>Custom CSS</strong> - Nintendo-inspired design system with responsive layouts</li>
                <li><strong>Apache/Nginx</strong> - Production web server infrastructure</li>
            </ul>
        </section>

        <section class="about-section">
            <h2>The Media Ecology</h2>
            <p>
                The league's broadcast system features multiple distinct personas, each offering unique perspectives on race results:
            </p>
            <div class="personas-list">
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
