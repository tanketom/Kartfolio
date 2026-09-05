<?php
/**
 * Global Header Template - Responsive & Refined
 * Path: /cdnmk/private/templates/header.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
// "Tournament mode" — when on, the Tournaments hub is open to all players.
$tournamentsOn = (bool) getSetting($pdo, 'enable_tournaments', true);

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
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/forms.css') ?>">
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
                    <a href="/mikkoliiga">🌟 Mikkoliiga</a>

                </div>
            </div>

            <?php if ($tournamentsOn): ?>
                <a href="/tournaments">🏆 Tournaments</a>
            <?php endif; ?>

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
                            <a href="/admin/results">Results</a>
                            <a href="/admin/commissioner-desk">🗒️ Commissioner's Desk</a>
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