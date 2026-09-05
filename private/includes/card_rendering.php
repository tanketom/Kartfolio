<?php
/**
 * Trading card rendering — the one renderer behind /cards (the printable
 * season set) and the racer profile.
 *
 * Markup is classed, not inline-styled: assets/css/card.css sizes everything
 * off a --s scale variable so the profile (1.5×) and the print set (1.5× on
 * screen, 3× into the PDF) share one stylesheet. html2canvas reads computed
 * styles, so calc() and CSS variables survive the trip to PNG/PDF; conic
 * gradients, border-image and backdrop filters do not, so none are used.
 *
 * Card size: 238×332 px at scale 1 = 63×88 mm, a real trading card.
 */

require_once __DIR__ . '/mk_data.php';
require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/badges.php';
require_once __DIR__ . '/settings.php';

/**
 * Foil tier from career: the border finish on the card. Highest match wins.
 * Returns ['key', 'label', 'blurb'].
 */
function cardTier(int $gps, int $titles, int $podiums): array {
    $tiers = [
        ['holo',     'Holographic', 'Three or more season titles',           fn() => $titles >= 3],
        ['diamond',  'Diamond',     'Two season titles',                     fn() => $titles >= 2],
        ['platinum', 'Platinum',    'A season title, or 250 career GPs',     fn() => $titles >= 1 || $gps >= 250],
        ['gold',     'Gold',        'A season podium, or 150 career GPs',    fn() => $podiums >= 1 || $gps >= 150],
        ['silver',   'Silver',      '75 career GPs',                         fn() => $gps >= 75],
        ['bronze',   'Bronze',      '25 career GPs',                         fn() => $gps >= 25],
        ['base',     'Base',        'Everyone starts here',                  fn() => true],
    ];
    foreach ($tiers as [$key, $label, $blurb, $test]) if ($test()) return ['key' => $key, 'label' => $label, 'blurb' => $blurb];
    return ['key' => 'base', 'label' => 'Base', 'blurb' => ''];
}

/** All tiers, lowest first — for the legend on /cards. */
function cardTierLadder(): array {
    return [
        ['base', 'Base', 'everyone'], ['bronze', 'Bronze', '25 GPs'], ['silver', 'Silver', '75 GPs'],
        ['gold', 'Gold', 'a season podium or 150 GPs'], ['platinum', 'Platinum', 'a season title or 250 GPs'],
        ['diamond', 'Diamond', 'two titles'], ['holo', 'Holographic', 'three titles'],
    ];
}

/** The season's card set: racer ids in set order (by name), once per request. */
function cardSetOrder(PDO $pdo, string $season_id): array {
    static $cache = [];
    if (isset($cache[$season_id])) return $cache[$season_id];
    $st = $pdo->prepare("SELECT DISTINCT r.id FROM racers r JOIN results res ON res.racer_id = r.id WHERE res.gpid LIKE ? ORDER BY r.name ASC");
    $st->execute([$season_id . '%']);
    return $cache[$season_id] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Sunburst rays as an SVG data URI — white at low alpha over the cup gradient. */
function cardSunburst(): string {
    static $uri = null;
    if ($uri !== null) return $uri;
    $rays = '';
    for ($i = 0; $i < 24; $i += 2) {
        $a1 = deg2rad($i * 15); $a2 = deg2rad(($i + 1) * 15);
        $rays .= sprintf('<path d="M50 50 L%.1f %.1f L%.1f %.1f Z"/>', 50 + 90 * cos($a1), 50 + 90 * sin($a1), 50 + 90 * cos($a2), 50 + 90 * sin($a2));
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid slice"><g fill="#fff" fill-opacity="0.16">' . $rays . '</g></svg>';
    return $uri = 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

/** The OMK crest (the favicon) as inline SVG markup, sized by CSS. */
function cardCrestSvg(): string {
    static $svg = null;
    if ($svg !== null) return $svg;
    $file = __DIR__ . '/../../public_html/assets/img/favicon.svg';
    $raw = is_file($file) ? (string)file_get_contents($file) : '';
    $raw = preg_replace('/<\?xml[^>]*\?>/', '', $raw);
    $raw = preg_replace('/<rect[^>]*rx="6"[^>]*><\/rect>/', '', $raw, 1);   // drop the red tile; the backing is the field
    return $svg = trim((string)$raw);
}

/**
 * Render a racer's trading card for a season set.
 *
 * @param float $scale 1.0 = 238×332 px (63×88 mm). The profile and /cards use 1.5.
 */
function renderRacerCard($pdo, $racerId, $currentSeason, $scale = 1.0) {
    $racerId = (int)$racerId;
    $stmt = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
    $stmt->execute([$racerId]);
    $racer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$racer) return '<div class="tc-missing">Racer not found</div>';

    // A racer who hasn't raced the requested season yet gets their card from
    // the latest season they did race, so the profile never shows a blank
    // score or a made-up set number. /cards only asks for racers in the set.
    if (!getRacerSeasonRows($pdo, $racerId, $currentSeason)) {
        $st = $pdo->prepare("SELECT MAX(SUBSTR(gpid, 1, 3)) FROM results WHERE racer_id = ? AND gpid LIKE 's%'");
        $st->execute([$racerId]);
        if ($last = $st->fetchColumn()) $currentSeason = (string)$last;
    }

    // Career — season GPs only, like the profile. Tournament heats stay out.
    $stmt = $pdo->prepare("
        SELECT SUM(gp_points) AS total_points, COUNT(*) AS total_gps,
               SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) AS wins,
               MAX(gp_points) AS personal_best, AVG(gp_points) AS avg_points,
               MIN(SUBSTR(gpid, 1, 3)) AS first_season
        FROM results WHERE racer_id = ? AND gpid LIKE 's%'");
    $stmt->execute([$racerId]);
    $career = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $career = array_merge(['total_points' => 0, 'total_gps' => 0, 'wins' => 0, 'personal_best' => 0, 'avg_points' => 0, 'first_season' => null], array_filter($career, fn($v) => $v !== null));

    // Most used character (career), for the portrait.
    $stmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND character_used IS NOT NULL AND gpid LIKE 's%' GROUP BY character_used ORDER BY COUNT(*) DESC, character_used ASC LIMIT 1");
    $stmt->execute([$racerId]);
    $mainChar = $stmt->fetchColumn() ?: 'Mii';

    // Honour: a unique badge first, else the rarest badge held this season.
    $featuredBadge = null;
    $uniqueBadges = getUniqueBadges($pdo, $racerId, $currentSeason);
    $seasonBadges = getRacerBadges($pdo, $racerId, $currentSeason);
    if (!empty($uniqueBadges)) { $featuredBadge = $uniqueBadges[0]; $featuredBadge['type'] = 'unique'; }
    elseif (!empty($seasonBadges)) { $featuredBadge = sortBadgesByRarity($seasonBadges, badgeHolderCounts($pdo, $currentSeason))[0]; $featuredBadge['type'] = 'season'; }

    // The season's score, labelled with the season's own system (never "GPScore™" by default).
    $seasonInfo  = getScoringSystemInfo($pdo, $currentSeason);
    $seasonScore = calculateGPScore($pdo, $racerId, $currentSeason);
    $seasonGps   = count(getRacerSeasonRows($pdo, $racerId, $currentSeason));
    $scoreLabel  = ($seasonInfo['name'] ?? 'Score') . ' · ' . strtoupper($currentSeason);

    // Portrait background: the racer's two best cups by average, three GPs
    // minimum so one lucky run doesn't recolour the card (any cups if none qualify).
    $stmt = $pdo->prepare("SELECT cup_name, AVG(gp_points) AS avg_points, COUNT(*) AS n FROM results WHERE racer_id = ? AND gpid LIKE 's%' AND cup_name IS NOT NULL GROUP BY cup_name HAVING n >= 3 ORDER BY avg_points DESC, cup_name ASC LIMIT 2");
    $stmt->execute([$racerId]);
    $topCups = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($topCups) < 2) {
        $stmt = $pdo->prepare("SELECT cup_name FROM results WHERE racer_id = ? AND gpid LIKE 's%' AND cup_name IS NOT NULL GROUP BY cup_name ORDER BY AVG(gp_points) DESC, cup_name ASC LIMIT 2");
        $stmt->execute([$racerId]);
        $topCups = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $cup1 = $topCups[0] ?? 'Mushroom'; $cup2 = $topCups[1] ?? $cup1;
    $portraitBackground = sprintf('linear-gradient(180deg, %s 0%%, %s 100%%)', getMKCupColor($cup1)[0], getMKCupColor($cup2)[1]);

    // Season set: number in the set, season stamps, foil tier.
    $set = cardSetOrder($pdo, $currentSeason);
    $setPos = array_search($racerId, $set, true);
    $setLabel = $setPos === false ? null : sprintf('No. %d / %d', $setPos + 1, count($set));

    $titles = 0; $podiums = 0; $seasonPlace = null;
    foreach (archivedSeasonPlacements($pdo)[$racerId] ?? [] as [$sid, $place, $field]) {
        if ($place === 1) $titles++;
        if ($place <= 3) $podiums++;
        if ($sid === $currentSeason) $seasonPlace = $place;
    }
    $isRookie = ($career['first_season'] ?? null) === $currentSeason;
    $tier = cardTier((int)$career['total_gps'], $titles, $podiums);
    $stamp = $seasonPlace === 1 ? ['champion', '🥇 Champion'] : ($seasonPlace === 2 ? ['runner', '🥈 Runner-up'] : ($seasonPlace === 3 ? ['third', '🥉 Podium'] : null));

    $leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
    $seasonNum  = str_pad(ltrim(substr($currentSeason, 1), '0') ?: '0', 2, '0', STR_PAD_LEFT);

    // Portrait art: custom card art → card character art → character head → Mii.
    $racerName = htmlspecialchars($racer['name']);
    $imageSrc = "/assets/img/CARD_" . $racerName . ".png";
    $onError = "this.onerror=null; this.src='/assets/img/CARD_" . htmlspecialchars($mainChar) . ".png'; this.onerror=function(){ this.onerror=null; this.src='/assets/img/" . htmlspecialchars($mainChar) . ".png'; this.onerror=function(){ this.onerror=null; this.src='/assets/img/Mii.png'; }; };";

    ob_start();
    ?>
    <div class="tc tc--<?= $tier['key'] ?>" style="--s: <?= $scale ?>;" title="<?= htmlspecialchars($tier['label']) ?> tier — <?= htmlspecialchars($tier['blurb']) ?>">
      <div class="tc-inner">
        <div class="tc-banner tc-banner--name"><div class="tc-banner-text"><?= $racerName ?></div></div>
        <?php if (!empty($racer['nickname'])): ?>
        <div class="tc-banner tc-banner--nick"><div class="tc-banner-text"><?= htmlspecialchars($racer['nickname']) ?></div></div>
        <?php endif; ?>

        <div class="tc-portrait<?= !empty($racer['nickname']) ? ' tc-portrait--nick' : '' ?>" style="background-image: url('<?= cardSunburst() ?>'), <?= $portraitBackground ?>;">
          <div class="tc-floor"></div>
          <img src="<?= $imageSrc ?>" onerror="<?= $onError ?>" alt="<?= htmlspecialchars($mainChar) ?>" class="tc-art">
          <?php if ($stamp): ?><div class="tc-stamp tc-stamp--<?= $stamp[0] ?>"><?= $stamp[1] ?></div><?php endif; ?>
          <?php if ($isRookie): ?><div class="tc-stamp tc-stamp--rookie">Rookie</div><?php endif; ?>
        </div>

        <div class="tc-stats">
          <div class="tc-stats-top">
            <div class="tc-stat"><div class="tc-stat-label">Career Points</div><div class="tc-stat-value tc-red"><?= number_format((int)$career['total_points']) ?></div></div>
            <div class="tc-stat"><div class="tc-stat-label"><?= htmlspecialchars($scoreLabel) ?></div><div class="tc-stat-value"><?= $seasonGps ? htmlspecialchars(scoreNum($seasonScore)) : '—' ?></div></div>
          </div>
          <div class="tc-stats-row">
            <div class="tc-stat"><div class="tc-stat-label">GPs</div><div class="tc-stat-value"><?= (int)$career['total_gps'] ?></div></div>
            <div class="tc-stat"><div class="tc-stat-label">Wins</div><div class="tc-stat-value tc-red"><?= (int)$career['wins'] ?></div></div>
            <div class="tc-stat"><div class="tc-stat-label">Best</div><div class="tc-stat-value"><?= (int)$career['personal_best'] ?></div></div>
            <div class="tc-stat"><div class="tc-stat-label">Avg</div><div class="tc-stat-value"><?= number_format((float)$career['avg_points'], 1) ?></div></div>
          </div>
        </div>

        <?php if (!empty($racer['catchphrase'])): ?>
        <div class="tc-quote">"<?= htmlspecialchars($racer['catchphrase']) ?>"</div>
        <?php endif; ?>

        <div class="tc-banner tc-banner--foot"><div class="tc-banner-text"><?= htmlspecialchars($leagueName) ?></div></div>

        <?php if ($featuredBadge): ?>
        <div class="tc-honour">
          <?php if ($featuredBadge['type'] === 'unique'): ?>
            <img src="<?= htmlspecialchars($featuredBadge['img']) ?>" alt="<?= htmlspecialchars($featuredBadge['title']) ?>" class="tc-honour-img">
          <?php else: ?>
            <div class="tc-honour-icon"><?= $featuredBadge['icon'] ?></div>
          <?php endif; ?>
          <div class="tc-honour-label">Honors</div>
        </div>
        <?php endif; ?>

        <div class="tc-number"><span class="tc-series">S<?= $seasonNum ?></span> <?= $setLabel ? htmlspecialchars($setLabel) : '#' . str_pad((string)$racerId, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="tc-tier"><?= htmlspecialchars($tier['label']) ?></div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * The set's backing — one design for every card in the season, printed on
 * the reverse page. League name from settings; the crest is the site's own.
 */
function renderCardBacking($pdo, string $season_id, float $scale = 1.0): string {
    $st = $pdo->prepare("SELECT season_name, academic_year FROM season_meta WHERE season_id = ?");
    $st->execute([$season_id]);
    $meta = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $seasonNum  = str_pad(ltrim(substr($season_id, 1), '0') ?: '0', 2, '0', STR_PAD_LEFT);
    $seasonName = trim((string)($meta['season_name'] ?? '')) ?: 'Season ' . $seasonNum;
    $year       = substr((string)($meta['academic_year'] ?? date('Y')), -2);
    $leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
    $setSize    = count(cardSetOrder($pdo, $season_id));
    ob_start();
    ?>
    <div class="tc tc-back" style="--s: <?= $scale ?>;">
      <div class="tc-back-field">
        <div class="tc-back-frame">
          <div class="tc-back-corner tc-back-corner--tl"></div><div class="tc-back-corner tc-back-corner--tr"></div>
          <div class="tc-back-corner tc-back-corner--bl"></div><div class="tc-back-corner tc-back-corner--br"></div>
          <div class="tc-back-org">Organisation Mondial du Karting</div>
          <div class="tc-back-crest"><?= cardCrestSvg() ?></div>
          <div class="tc-back-omk">OMK</div>
          <div class="tc-back-rule"></div>
          <div class="tc-back-league"><?= htmlspecialchars($leagueName) ?></div>
          <div class="tc-back-season">Season <?= $seasonNum ?></div>
          <div class="tc-back-name"><?= htmlspecialchars($seasonName) ?></div>
          <div class="tc-back-year">'<?= htmlspecialchars($year) ?></div>
          <div class="tc-back-fine">Official league card · set of <?= $setSize ?> · 63 × 88 mm</div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
