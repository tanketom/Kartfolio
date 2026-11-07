<?php
require_once __DIR__ . '/../includes/session.php';
/**
 * Global Header Template - Responsive & Refined
 * Path: /cdnmk/private/templates/header.php
 */
if (session_status() === PHP_SESSION_NONE) {
    kartfolioSessionStart();
}
require_once __DIR__ . '/../includes/gp_logic.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/assets.php';

// Get settings
global $pdo;
initializeSettings($pdo);
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio');
$primaryColor = getSetting($pdo, 'primary_color', '#E60012');
$currentSeasonTag = strtoupper(getCurrentSeasonNumber());

// A brand-new install has no racers and no results. Surface the first-run
// setup link (admins only) so /admin/setup is reachable without knowing the
// URL — it disappears the moment the league has any data.
// Must match the "empty" test in admin/setup.php: season_meta is deliberately
// NOT counted, because schema.sql seeds a placeholder season on every fresh
// install, which would make the league look already-configured.
$needsSetup = false;
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    $needsSetup = (int)$pdo->query(
        "SELECT (SELECT COUNT(*) FROM racers) + (SELECT COUNT(*) FROM results)"
    )->fetchColumn() === 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($leagueName) ?> League <?= $currentSeasonTag ?></title>
    <?php
    // What a link to this page looks like when pasted into a chat. A page can set
    // $ogTitle, $ogDescription and $ogImage (a site path) before including this
    // header; anything it leaves out falls back to the league's own.
    $ogTitleOut = trim((string)($ogTitle ?? html_entity_decode((string)($pageTitle ?? ''), ENT_QUOTES))) ?: $leagueName;
    $ogDescOut  = trim((string)($ogDescription ?? '')) ?: "$leagueName: standings, results and news from the league.";
    ?>
    <meta property="og:site_name" content="<?= htmlspecialchars($leagueName) ?>">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitleOut) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescOut) ?>">
    <meta property="og:url" content="<?= htmlspecialchars(siteUrl((string)($_SERVER['REQUEST_URI'] ?? '/'))) ?>">
    <meta property="og:image" content="<?= htmlspecialchars(siteUrl($ogImage ?? '/assets/img/omk-favicon-512.png')) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="description" content="<?= htmlspecialchars($ogDescOut) ?>">
    <?php if (moduleEnabled($pdo, 'broadcasts')): ?><link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($leagueName) ?> news" href="/feed"><?php endif; ?>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/omk-favicon-32.png">
    <link rel="icon" type="image/png" sizes="64x64" href="/assets/img/omk-favicon-64.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/img/omk-favicon-512.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/omk-favicon-180.png">
    <!-- KART POP type system: Fredoka (display) · Inter (body/UI) · DM Mono (numerals) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800;900&family=DM+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/global.css') ?>">
    <?php
    // Load admin CSS files if we're on an admin page
    $isAdminPage = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true && strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false;
    if ($isAdminPage):
    ?>
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/admin.css') ?>">
    <?php endif; ?>
    <?php if (!empty($extraCss)) echo versionAssetTags($extraCss); ?>
    <style>
        /* Dynamic primary colour (set by admin settings) */
        :root {
            --nintendo-red: <?= htmlspecialchars($primaryColor) ?>;
            --nintendo-red-dark: <?= htmlspecialchars($primaryColor) ?>dd;
        }
    </style>
</head>
<body>

<header class="main-header">
    <div class="container header-flex">
        <div class="logo-group">
            <a href="/">
                <span class="logo-text"><?= htmlspecialchars($leagueName) ?> League</span>
                <span class="logo-season"><?= $currentSeasonTag ?></span>
            </a>
        </div>

        <nav class="main-nav">
            <a href="/add-result">Add GP scores</a>
            <a href="#" id="cup-picker-btn">🎲 What cup?</a>
            <a href="/archive">News</a>
            <a href="/racers">Racers</a>

            <div class="dropdown">
                <span class="dropbtn">Statistics ▾</span>
                <div class="dropdown-content">
                    <a href="/stats">Trends</a>
                    <a href="/all-time">All-Time</a>
                    <a href="/cup-stats">Cups</a>
                    <a href="/timeline">Timeline</a>
                    <a href="/rivalries">Nemesis Index</a>
                    <a href="/records">Record Book</a>
                    <a href="/cup-mastery">Cup Mastery Grid</a>
                    <a href="/track-favourites">🏁 Track Favourites</a>
                    <?php if (moduleEnabled($pdo, 'mikkoliiga')): ?><a href="/mikkoliiga">🌟 Mikkoliiga</a><?php endif; ?>
                    <?php if (moduleEnabled($pdo, 'teams')): ?><a href="/teams">🤝 Teams</a><?php endif; ?>
                    <a href="/league-in-numbers">📊 League in numbers</a>
                    <?php if (moduleEnabled($pdo, 'omk')): ?><a href="/omk-exchange"><span class="omk-sign" role="img" aria-label="OMKredits">Ω</span> OMK Exchange</a><?php endif; ?>

                </div>
            </div>

            <a href="/season-archives">Hall of Fame</a>

            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true): ?>
                <div class="admin-pill">
                    <div class="dropdown">
                        <span class="dropbtn" style="color: #ffcc00 !important;">Admin ▾</span>
                        <div class="dropdown-content">
                            <?php if ($needsSetup): ?>
                                <a href="/admin/setup">🚀 First-time setup</a>
                            <?php endif; ?>
                            <a href="/admin/seasons">Seasons</a>
                            <a href="/admin/close-season">🏁 Close Season</a>
                            <a href="/admin/racers">Racers</a>
                            <a href="/admin/sticker-board">🩹 Sticker Board</a>
                            <?php if (moduleEnabled($pdo, 'omk')): ?><a href="/admin/omk">💱 OMKredits</a><?php endif; ?>
                            <a href="/admin/results">Results</a>
                            <a href="/admin/commissioner-desk">🗒️ Commissioner's Desk</a>
                            <a href="/admin/news">📰 News desk</a>
                            <a href="/admin/audit">🔎 Data audit<?php
                                // The only nudge a flagged result gets, so nobody has to remember to look.
                                $openFlags = 0;
                                try { $openFlags = (int)$pdo->query("SELECT COUNT(*) FROM result_flags WHERE status = 'open'")->fetchColumn(); }
                                catch (Throwable $e) { error_log('flag count: ' . $e->getMessage()); }
                                if ($openFlags > 0) echo ' <strong>(' . $openFlags . ')</strong>'; ?></a>
                            <a href="/admin/errors">🧯 Errors<?php
                                // Same nudge as the audit's flags: a count when something went wrong today.
                                $recentErrors = 0;
                                try { $recentErrors = function_exists('kartfolioRecentErrorCount') ? kartfolioRecentErrorCount() : 0; }
                                catch (Throwable $e) { /* the log reader must never break the menu */ }
                                if ($recentErrors > 0) echo ' <strong>(' . $recentErrors . ')</strong>'; ?></a>
                            <a href="/admin/modules">🧩 Modules</a>
                            <a href="/admin/uml">🗂️ System UML</a>
                            <a href="/admin/settings">⚙️ Settings</a>
                        </div>
                    </div>
                    <a href="/admin/tournaments">Tournaments</a>
                    <a href="/logout" class="logout-link">Exit</a>
                </div>
            <?php endif; ?>
        </nav>
    </div>
</header>

<div class="container main-content">