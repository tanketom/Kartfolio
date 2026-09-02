<?php
/**
 * Central Trading Card Rendering Functions
 * Ensures cards.php and racer.php use identical rendering
 */

/**
 * Render a racer trading card
 *
 * @param PDO $pdo Database connection
 * @param int $racerId Racer ID
 * @param string $currentSeason Current season (e.g., 's01')
 * @param float $scale Scale multiplier (1.0 for cards.php, 1.5 for racer.php)
 * @return string HTML output for the card
 */
function renderRacerCard($pdo, $racerId, $currentSeason, $scale = 1.0) {
    // Base dimensions (cards.php size)
    $baseWidth = 238;
    $baseHeight = 332;

    // Scaled dimensions
    $width = $baseWidth * $scale;
    $height = $baseHeight * $scale;

    // Scale helper function
    $s = function($value) use ($scale) {
        return $value * $scale;
    };

    // Get racer data
    $stmt = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
    $stmt->execute([$racerId]);
    $racer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$racer) {
        return '<div style="color: red;">Racer not found</div>';
    }

    // Get career stats
    $careerStats = [
        'total_points' => 0,
        'total_gps' => 0,
        'wins' => 0,
        'personal_best' => 0,
        'avg_points' => 0
    ];

    $stmt = $pdo->prepare("
        SELECT
            SUM(gp_points) as total_points,
            COUNT(*) as total_gps,
            SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
            MAX(gp_points) as personal_best,
            AVG(gp_points) as avg_points
        FROM results
        WHERE racer_id = ?
    ");
    $stmt->execute([$racerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats) {
        $careerStats = $stats;
    }

    // Get most used character
    $stmt = $pdo->prepare("
        SELECT character_used, COUNT(*) as usage_count
        FROM results
        WHERE racer_id = ? AND character_used IS NOT NULL
        GROUP BY character_used
        ORDER BY usage_count DESC
        LIMIT 1
    ");
    $stmt->execute([$racerId]);
    $characters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $mainChar = $characters[0]['character_used'] ?? 'Mii';

    // Get badges
    require_once __DIR__ . '/badges.php';
    $uniqueBadges = getUniqueBadges($pdo, $racerId, $currentSeason);
    $currentSeasonBadges = getRacerBadges($pdo, $racerId, $currentSeason);

    // Featured honour: a one-off unique badge if they have one, otherwise the
    // RAREST badge they hold this season (fewest holders), not the first emitted.
    $featuredBadge = null;
    if (!empty($uniqueBadges)) {
        $featuredBadge = $uniqueBadges[0];
        $featuredBadge['type'] = 'unique';
    } elseif (!empty($currentSeasonBadges)) {
        $featuredBadge = sortBadgesByRarity($currentSeasonBadges, badgeHolderCounts($pdo, $currentSeason))[0];
        $featuredBadge['type'] = 'season';
    }

    // Calculate current GPScore
    require_once __DIR__ . '/gp_logic.php';
    $currentGPScore = calculateGPScore($pdo, $racerId, $currentSeason);

    // Get top 2 best cups by average score
    $stmt = $pdo->prepare("
        SELECT cup_name, AVG(gp_points) as avg_points
        FROM results
        WHERE racer_id = ?
        GROUP BY cup_name
        ORDER BY avg_points DESC
        LIMIT 2
    ");
    $stmt->execute([$racerId]);
    $topCups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bestCup1 = $topCups[0]['cup_name'] ?? 'Mushroom';
    $bestCup2 = $topCups[1]['cup_name'] ?? $bestCup1;

    // Map cups to gradient colors (matching racer.php milestone boxes)
    $cupGradients = [
        'Mushroom' => ['#f093fb', '#f5576c'],   // Pink to Red (First Win gradient)
        'Flower' => ['#fa709a', '#fee140'],     // Pink to Yellow (First Podium gradient)
        'Star' => ['#43e97b', '#38f9d7'],       // Green to Cyan (Best Score gradient)
        'Special' => ['#4facfe', '#00f2fe'],    // Blue to Cyan (First Perfect gradient)
        'Shell' => ['#667eea', '#764ba2'],      // Purple gradient (First GP gradient)
        'Banana' => ['#ffeaa7', '#fdcb6e'],     // Yellow gradient
        'Leaf' => ['#00b894', '#00cec9'],       // Teal gradient
        'Lightning' => ['#a29bfe', '#6c5ce7'],  // Purple gradient
    ];

    // Get gradient colors for both cups
    $gradient1 = $cupGradients[$bestCup1] ?? ['#c0c0c0', '#a0a0a0'];
    $gradient2 = $cupGradients[$bestCup2] ?? ['#a0a0a0', '#808080'];

    // Create blended gradient: start color from best cup, end color from second-best cup
    $portraitBackground = "linear-gradient(180deg, {$gradient1[0]} 0%, {$gradient2[1]} 100%)";

    // Build image fallback paths
    $racerName = htmlspecialchars($racer['name']);
    $cardRacerPath = "/assets/img/CARD_" . $racerName . ".png";
    $cardCharPath = "/assets/img/CARD_" . htmlspecialchars($mainChar) . ".png";
    $regularCharPath = "/assets/img/" . htmlspecialchars($mainChar) . ".png";
    $fallbackPath = "/assets/img/Mii.png";

    $imageSrc = $cardRacerPath;
    $onError = "this.onerror=null; this.src='{$cardCharPath}'; this.onerror=function(){ this.onerror=null; this.src='{$regularCharPath}'; this.onerror=function(){ this.onerror=null; this.src='{$fallbackPath}'; }; };";

    // Calculate portrait height
    $portraitHeight = $s(200);

    // Start output buffering
    ob_start();
    ?>
    <div style="width: <?= $width ?>px; height: <?= $height ?>px; background: linear-gradient(180deg, #e8e8e8 0%, #d0d0d0 100%); border-radius: <?= $s(6) ?>px; overflow: hidden; box-shadow: 0 <?= $s(4) ?>px <?= $s(12) ?>px rgba(0,0,0,0.2); position: relative; border: <?= $s(4) ?>px solid white;">

        <!-- Diagonal Name Banner (Top) -->
        <div style="position: absolute; top: <?= $s(12) ?>px; left: <?= $s(-24) ?>px; right: <?= $s(-24) ?>px; transform: rotate(-3deg); background: linear-gradient(135deg, var(--nintendo-red) 0%, #c00010 100%); padding: <?= $s(6) ?>px <?= $s(32) ?>px; z-index: 10; box-shadow: 0 <?= $s(2) ?>px <?= $s(6) ?>px rgba(0,0,0,0.4);">
            <div style="font-size: <?= $s(0.8) ?>rem; font-weight: 900; color: white; text-transform: uppercase; font-style: italic; letter-spacing: <?= $s(0.5) ?>px; text-align: center; text-shadow: 0 <?= $s(1) ?>px <?= $s(2) ?>px rgba(0,0,0,0.5);">
                <?= htmlspecialchars($racer['name']) ?>
            </div>
        </div>

        <!-- Nickname Banner (if exists) -->
        <?php if (!empty($racer['nickname'])): ?>
        <div style="position: absolute; top: <?= $s(36) ?>px; left: <?= $s(-24) ?>px; right: <?= $s(-24) ?>px; transform: rotate(-3deg); background: linear-gradient(135deg, #ffffff 0%, #f0f0f0 100%); padding: <?= $s(4) ?>px <?= $s(32) ?>px; z-index: 10; box-shadow: 0 <?= $s(1.5) ?>px <?= $s(5) ?>px rgba(0,0,0,0.3);">
            <div style="font-size: <?= $s(0.5) ?>rem; font-weight: 800; color: var(--nintendo-red); text-transform: uppercase; font-style: italic; letter-spacing: <?= $s(0.5) ?>px; text-align: center; text-shadow: 0 <?= $s(0.5) ?>px <?= $s(1) ?>px rgba(0,0,0,0.1);">
                <?= htmlspecialchars($racer['nickname']) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Character Portrait -->
        <div style="background: <?= $portraitBackground ?>; padding: <?= !empty($racer['nickname']) ? $s(60) : $s(44) ?>px 0 <?= $s(8) ?>px; text-align: center; position: relative; height: <?= $portraitHeight ?>px; display: flex; align-items: center; justify-content: center;">
            <img src="<?= $imageSrc ?>"
                 onerror="<?= $onError ?>"
                 style="max-width: <?= $s(190) ?>px; max-height: <?= $s(190) ?>px; object-fit: contain; filter: drop-shadow(0 <?= $s(5) ?>px <?= $s(15) ?>px rgba(0,0,0,0.3));"
                 alt="<?= htmlspecialchars($mainChar) ?>">
        </div>

        <!-- Stats Section -->
        <div style="background: white; padding: <?= $s(6) ?>px 0 <?= $s(5) ?>px; border-top: <?= $s(1.5) ?>px solid var(--nintendo-red);">
            <!-- Career Points and GPScore -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: <?= $s(6) ?>px; margin-bottom: <?= $s(5) ?>px; padding-bottom: <?= $s(4) ?>px; border-bottom: <?= $s(1) ?>px solid #e0e0e0;">
                <div style="text-align: center;">
                    <div style="font-size: <?= $s(0.26) ?>rem; color: #888; text-transform: uppercase; font-weight: 700; letter-spacing: <?= $s(0.25) ?>px; margin-bottom: <?= $s(1.5) ?>px;">Career Points</div>
                    <div style="font-size: <?= $s(0.65) ?>rem; font-weight: 900; color: var(--nintendo-red);"><?= number_format($careerStats['total_points']) ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: <?= $s(0.26) ?>rem; color: #888; text-transform: uppercase; font-weight: 700; letter-spacing: <?= $s(0.25) ?>px; margin-bottom: <?= $s(1.5) ?>px;">Current GPScore™</div>
                    <div style="font-size: <?= $s(0.65) ?>rem; font-weight: 900; color: #333;"><?= number_format($currentGPScore, 2) ?></div>
                </div>
            </div>

            <!-- Compact Stats Grid -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: <?= $s(5) ?>px; margin-bottom: <?= $s(3) ?>px;">
                <div style="text-align: center;">
                    <div style="font-size: <?= $s(0.28) ?>rem; color: #666; text-transform: uppercase; font-weight: 700; letter-spacing: <?= $s(0.25) ?>px; margin-bottom: <?= $s(1.5) ?>px;">GPs</div>
                    <div style="font-size: <?= $s(0.7) ?>rem; font-weight: 900; color: #333;"><?= $careerStats['total_gps'] ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: <?= $s(0.28) ?>rem; color: #666; text-transform: uppercase; font-weight: 700; letter-spacing: <?= $s(0.25) ?>px; margin-bottom: <?= $s(1.5) ?>px;">WINS</div>
                    <div style="font-size: <?= $s(0.7) ?>rem; font-weight: 900; color: var(--nintendo-red);"><?= $careerStats['wins'] ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: <?= $s(0.28) ?>rem; color: #666; text-transform: uppercase; font-weight: 700; letter-spacing: <?= $s(0.25) ?>px; margin-bottom: <?= $s(1.5) ?>px;">BEST</div>
                    <div style="font-size: <?= $s(0.7) ?>rem; font-weight: 900; color: #333;"><?= $careerStats['personal_best'] ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: <?= $s(0.28) ?>rem; color: #666; text-transform: uppercase; font-weight: 700; letter-spacing: <?= $s(0.25) ?>px; margin-bottom: <?= $s(1.5) ?>px;">AVG</div>
                    <div style="font-size: <?= $s(0.7) ?>rem; font-weight: 900; color: #333;"><?= number_format($careerStats['avg_points'], 1) ?></div>
                </div>
            </div>
        </div>

        <!-- Catchphrase Area -->
        <?php if (!empty($racer['catchphrase'])): ?>
        <div style="background: white; padding: <?= $s(3) ?>px 0 <?= $s(7) ?>px; position: relative; z-index: 5;">
            <div style="font-size: <?= $s(0.3) ?>rem; font-style: italic; color: #666; text-align: center; line-height: 1.3;">
                "<?= htmlspecialchars($racer['catchphrase']) ?>"
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Number (Bottom Right) -->
        <div style="position: absolute; bottom: <?= $s(6) ?>px; right: <?= $s(8) ?>px; background: var(--nintendo-red); color: white; padding: <?= $s(3) ?>px <?= $s(6.5) ?>px; border-radius: <?= $s(2.5) ?>px; font-weight: 900; font-size: <?= $s(0.45) ?>rem; box-shadow: 0 <?= $s(1) ?>px <?= $s(4) ?>px rgba(0,0,0,0.3); z-index: 11;">
            #<?= str_pad($racerId, 2, '0', STR_PAD_LEFT) ?>
        </div>

        <!-- Diagonal Banner (Bottom) -->
        <div style="position: absolute; bottom: <?= $s(16) ?>px; left: <?= $s(-16) ?>px; right: <?= $s(-32) ?>px; transform: rotate(-3deg); background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%); padding: <?= $s(5) ?>px <?= $s(40) ?>px <?= $s(5) ?>px <?= $s(24) ?>px; z-index: 10; box-shadow: 0 <?= $s(-2) ?>px <?= $s(6) ?>px rgba(0,0,0,0.4);">
            <div style="font-size: <?= $s(0.5) ?>rem; font-weight: 900; color: white; text-transform: uppercase; letter-spacing: <?= $s(1) ?>px; text-align: right; font-style: italic; padding-right: <?= $s(8) ?>px;">
                DRIFTSMIDLER LEAGUE
            </div>
        </div>

        <!-- Featured Badge (Bottom Left) -->
        <?php if ($featuredBadge): ?>
        <div style="position: absolute; bottom: <?= $s(6) ?>px; left: <?= $s(8) ?>px; background: white; padding: <?= $s(5) ?>px; border-radius: <?= $s(4) ?>px; box-shadow: 0 <?= $s(2) ?>px <?= $s(6) ?>px rgba(0,0,0,0.3); z-index: 11; min-width: <?= $s(40) ?>px; border: <?= $s(1) ?>px solid #FFD700;">
            <div style="text-align: center;">
                <?php if ($featuredBadge['type'] === 'unique'): ?>
                    <img src="<?= htmlspecialchars($featuredBadge['img']) ?>" alt="<?= htmlspecialchars($featuredBadge['title']) ?>" style="width: <?= $s(24) ?>px; height: <?= $s(24) ?>px; object-fit: contain; margin-bottom: <?= $s(2.5) ?>px;">
                <?php else: ?>
                    <div style="font-size: <?= $s(1.2) ?>rem; line-height: 1; margin-bottom: <?= $s(2.5) ?>px;"><?= $featuredBadge['icon'] ?></div>
                <?php endif; ?>
                <div style="font-size: <?= $s(0.26) ?>rem; font-weight: 900; color: #333; text-transform: uppercase; line-height: 1.2;">
                    HONORS
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}
