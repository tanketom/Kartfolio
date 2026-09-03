<?php
/**
 * WALUIGI'S UNDERGROUND BETTING DEN
 * ⚠️ NOT OMK-SANCTIONED ⚠️
 * Path: /cdnmk/public_html/underground.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/assets.php';

// For now, hardcode wallet balance (Phase 2 will connect to database)
$walletBalance = 2450; // OMK

// Get current season for odds calculation (later)
$currentSeason = 's01';

// Randomly select 3 Fjord Apes from the collection of 12
$allApes = range(1, 12);
shuffle($allApes);
$selectedApes = array_slice($allApes, 0, 3);

// Ape traits (randomly assigned)
$apeTraits = [
    "The Visionary", "The Drifter", "The Oracle", "The Strategist",
    "The Maverick", "The Champion", "The Hustler", "The Prophet",
    "The Titan", "The Legend", "The Innovator", "The Conqueror"
];

// Random prices (Ethereum)
$apePrices = [0.42, 0.69, 1.33, 0.88, 1.11, 2.22, 3.33, 0.55, 0.77, 1.69, 4.20, 6.90];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚠️ UNAUTHORIZED ACCESS - RAINBOW ROAD 2.0</title>
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/underground.css') ?>">
    <link rel="icon" href="/assets/img/favicon.ico" type="image/x-icon">
</head>
<body class="underground">

<div class="underground-container">

    <!-- Header -->
    <div class="underground-header">
        <pre class="ascii-art ascii-art--header">
██████╗  █████╗ ██╗███╗   ██╗██████╗  ██████╗ ██╗    ██╗
██╔══██╗██╔══██╗██║████╗  ██║██╔══██╗██╔═══██╗██║    ██║
██████╔╝███████║██║██╔██╗ ██║██████╔╝██║   ██║██║ █╗ ██║
██╔══██╗██╔══██║██║██║╚██╗██║██╔══██╗██║   ██║██║███╗██║
██║  ██║██║  ██║██║██║ ╚████║██████╔╝╚██████╔╝╚███╔███╔╝
╚═╝  ╚═╝╚═╝  ╚═╝╚═╝╚═╝  ╚═══╝╚═════╝  ╚═════╝  ╚══╝╚══╝

██████╗  ██████╗  █████╗ ██████╗     ██████╗    ██████╗
██╔══██╗██╔═══██╗██╔══██╗██╔══██╗    ╚════██╗  ██╔═████╗
██████╔╝██║   ██║███████║██║  ██║     █████╔╝  ██║██╔██║
██╔══██╗██║   ██║██╔══██║██║  ██║    ██╔═══╝   ████╔╝██║
██║  ██║╚██████╔╝██║  ██║██████╔╝    ███████╗██╗╚██████╔╝
╚═╝  ╚═╝ ╚═════╝ ╚═╝  ╚═╝╚═════╝     ╚══════╝╚═╝ ╚═════╝</pre>
        <div class="header-meta">
            <span>👁️ PROPRIETOR: [REDACTED]</span>
            <span>📍 LOCATION: ████ CLASSIFIED</span>
            <span>⚠️ NOT OMK-SANCTIONED</span>
        </div>
    </div>

    <!-- Warning -->
    <div class="ug-section-gap">
        <pre class="footer-ascii">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ ⚠️  WARNING: ALL WAGERS ARE FINAL                   ░
░                                                      ░
░ THE ORGANISATION MONDIALE DU KARTING DOES NOT       ░
░ ENDORSE OR REGULATE THIS ESTABLISHMENT.             ░
░                                                      ░
░ PROCEED AT YOUR OWN RISK. WAH-HA-HA!                ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>
    </div>

    <!-- Bets Section -->
    <div class="ug-section-gap-sm">
        <pre class="footer-ascii">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ 🎲 PROP BETS - DEGENERATE SPECULATION               ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>
    </div>

    <div class="bet-card">
        <div class="bet-card-header">💎 PERFECT 60 WATCH</div>
        <div class="bet-card-description">
            WILL ANYONE HIT PERFECTION THIS WEEK?
        </div>
        <pre class="info-box">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ RARE EVENT ALERT:                  ░
░ Perfect scores are like blue       ░
░ shells - they happen when you      ░
░ least expect them. WAH!            ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>

        <div class="racer-bet-option">
            <span class="racer-name">YES - SOMEONE HITS 60</span>
            <span class="odds-display">8.0x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">NO - NO PERFECT SCORES</span>
            <span class="odds-display">1.15x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>
    </div>

    <div class="bet-card">
        <div class="bet-card-header">🎲 TOTAL GPS THIS WEEK</div>
        <div class="bet-card-description">
            HOW MANY GRAND PRIX WILL BE RACED?
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">OVER 18.5 GPS</span>
            <span class="odds-display">2.0x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">UNDER 18.5 GPS</span>
            <span class="odds-display">2.0x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>
    </div>

    <div class="bet-card">
        <div class="bet-card-header">📊 WINNING SCORE RANGE</div>
        <div class="bet-card-description">
            WHAT WILL THE WINNING SCORE BE?
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">55-60 POINTS (HIGH SCORER)</span>
            <span class="odds-display">3.0x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">50-54 POINTS (SOLID WIN)</span>
            <span class="odds-display">2.5x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">UNDER 50 (CHAOS GP)</span>
            <span class="odds-display">3.2x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>
    </div>

    <div class="bet-card">
        <div class="bet-card-header">💥 QUICK PROPS</div>
        <div class="bet-card-description">
            RAPID-FIRE BETTING OPTIONS
        </div>
        <pre class="info-box">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ HIGH RISK, HIGH REWARD:            ░
░ Chaos is profitable... sometimes.  ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>

        <div class="racer-bet-option">
            <span class="racer-name">WILL THERE BE A LOL?</span>
            <span class="odds-display">3.5x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">SOMEONE SCORES UNDER 20?</span>
            <span class="odds-display">2.8x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>

        <div class="racer-bet-option">
            <span class="racer-name">PERFECT 60 NEXT GP?</span>
            <span class="odds-display">25.0x</span>
            <button class="bet-button" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">PLACE BET</button>
        </div>
    </div>

    <!-- NFT Store Section -->
    <div class="ug-section-gap-lg">
        <pre class="footer-ascii">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░                                                      ░
░ "FORGET BETS. INVEST IN THE FUTURE."                ░
░                      - THE PROPRIETOR                ░
░                                                      ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>
    </div>

    <div class="ug-section-gap-sm">
        <pre class="footer-ascii">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ 🍄 NFT MARKETPLACE - NITRO FUNGI TOKENS             ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>
    </div>

    <div class="bet-card">
        <div class="bet-card-header">🦍 FJORD APES COLLECTION</div>
        <div class="bet-card-description">
            EXCLUSIVE LIMITED EDITION DIGITAL COLLECTIBLES
        </div>
        <pre class="info-box">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ INVESTMENT OPPORTUNITY:            ░
░ These will definitely appreciate   ░
░ in value. Trust me bro.            ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>

        <div class="ug-nft-grid">

            <?php foreach ($selectedApes as $index => $apeNum):
                $apeId = str_pad($apeNum, 3, '0', STR_PAD_LEFT);
                $trait = $apeTraits[$apeNum - 1];
                $price = $apePrices[$apeNum - 1];
            ?>
            <!-- Fjord Ape #<?= $apeId ?> -->
            <div class="ug-nft-card"
                 onmouseover="this.style.borderColor='#00ff00'; this.style.boxShadow='0 0 20px rgba(0, 255, 0, 0.3)'; this.style.transform='translateY(-5px)';"
                 onmouseout="this.style.borderColor='#9d4edd'; this.style.boxShadow='none'; this.style.transform='translateY(0)';">
                <img src="/assets/ape/ape<?= $apeId ?>.png" alt="Fjord Ape #<?= $apeId ?>" class="ug-nft-img" onerror="this.src='/assets/img/Mii.png'">
                <div class="ug-nft-name">FJORD APE #<?= $apeId ?></div>
                <div class="ug-nft-trait">"<?= strtoupper($trait) ?>"</div>
                <div class="ug-nft-price"><?= number_format($price, 2) ?> Ξ</div>
                <button class="bet-button bet-button--full" onclick="alert('Transfer 8 driftcoin to wallet 1RbowRd148mx8zNf89vK2pS5v9v8q8p7xW to open an account')">MINT NOW</button>
            </div>
            <?php endforeach; ?>

        </div>

        <div class="ug-nft-disclaimer">
            <pre class="info-box info-box--danger">
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
░ ⚠️  LEGAL DISCLAIMER: NOT FINANCIAL ADVICE          ░
░                                                      ░
░ The Proprietor makes no guarantees regarding the    ░
░ future value of these digital assets. All sales     ░
░ are final. No refunds. Caveat emptor. WAH!          ░
░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>
        </div>
    </div>

    <!-- Footer -->
    <div class="underground-footer">
        <pre class="footer-ascii">
    ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░
    ░ REMEMBER: THE HOUSE ALWAYS   ░
    ░ WINS... EXCEPT WHEN IT       ░
    ░ DOESN'T. WAH-HA-HA!          ░
    ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░</pre>
        <div class="ug-footer-return">
            <a href="/" class="return-link">← RETURN TO SURFACE</a>
        </div>
    </div>

</div>

</body>
</html>
