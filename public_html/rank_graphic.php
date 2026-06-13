<?php
/**
 * Rank Graphic - Downloadable Season Rankings Image
 * Path: /cdnmk/public_html/rank_graphic.php
 * URL: /rank-graphic
 *
 * Horizontal layout:
 *   Left column:  #1 (tall, spans full height of right column)
 *   Right column: #2, #3 on top row; #4, #5, #6 on bottom row
 *   Full width:   #7+ field rows
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/settings.php';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();

// Fetch available seasons for dropdown
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch season metadata
$meta = getSeasonRules($pdo, $seasonId);

$seasonNumber = strtoupper($seasonId);
$seasonName = $meta['season_name'] ?? '';
$scoringInfo = getScoringSystemInfo($pdo, $seasonId);

// Season dates for footer
$startDate = !empty($meta['start_date']) ? date('F j, Y', strtotime($meta['start_date'])) : null;
$endDate = !empty($meta['end_date']) ? date('F j, Y', strtotime($meta['end_date'])) : null;

// Compute standings (same pattern as index.php)
$racerStmt = $pdo->prepare("SELECT DISTINCT r.* FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
$racerStmt->execute([$seasonId . "%"]);
$activeRacers = $racerStmt->fetchAll();

$standings = [];
foreach ($activeRacers as $r) {
    $score = calculateGPScore($pdo, $r['id'], $seasonId);

    $charStmt = $pdo->prepare("SELECT character_used FROM results WHERE racer_id = ? AND gpid LIKE ? GROUP BY character_used ORDER BY COUNT(*) DESC LIMIT 1");
    $charStmt->execute([$r['id'], $seasonId . "%"]);
    $char = $charStmt->fetchColumn() ?: 'Mii';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $countStmt->execute([$r['id'], $seasonId . "%"]);
    $raceCount = (int)$countStmt->fetchColumn();

    $standings[] = [
        'id'        => $r['id'],
        'name'      => $r['name'],
        'score'     => $score,
        'char'      => $char,
        'badges'    => ($raceCount >= 3) ? getRacerBadges($pdo, $r['id'], $seasonId) : [],
        'unique'    => getUniqueBadges($pdo, $r['id'], $seasonId),
        'raceCount' => $raceCount,
        'eligible'  => racerQualifies($raceCount, $meta)
    ];
}

// Sort standings
if ($scoringInfo['system'] === 'top_12_unique') {
    foreach ($standings as &$s) {
        $s['tiebreaker'] = getTop12UniqueTiebreaker($pdo, $s['id'], $seasonId);
    }
    unset($s);
    usort($standings, function($a, $b) {
        if ($b['score'] != $a['score']) return $b['score'] <=> $a['score'];
        if ($b['tiebreaker'] != $a['tiebreaker']) return $b['tiebreaker'] <=> $a['tiebreaker'];
        return strcmp($a['name'], $b['name']);
    });
} else {
    usort($standings, fn($a, $b) => ($b['score'] == $a['score']) ? strcmp($a['name'], $b['name']) : $b['score'] <=> $a['score']);
}

// Filter out racers with 0 GPScore
$standings = array_values(array_filter($standings, fn($s) => $s['score'] > 0));

// Split into tiers
$champion   = array_slice($standings, 0, 1);
$podium     = array_slice($standings, 1, 2); // #2, #3
$contenders = array_slice($standings, 3, 3); // #4, #5, #6
$field      = array_slice($standings, 6);    // #7+

$pageTitle = "Rank Graphic - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

/**
 * Render hanging badges below a portrait.
 * Badges fan out from center with slight rotation, like medals on ribbons.
 * Left-of-center badges tilt left (negative), right-of-center tilt right (positive).
 */
function renderHangingBadges(array $badges, array $unique, string $sizeClass = ''): string {
    $allBadges = [];
    foreach ($badges as $b) {
        $allBadges[] = ['type' => 'emoji', 'icon' => $b['icon'], 'title' => $b['title']];
    }
    foreach ($unique as $u) {
        $allBadges[] = ['type' => 'img', 'img' => $u['img'], 'title' => $u['title']];
    }
    if (empty($allBadges)) return '';

    $count = count($allBadges);
    $html = '<div class="rg-hanging-badges ' . $sizeClass . '">';
    foreach ($allBadges as $i => $badge) {
        // Fan inward: left-of-center tilts right (+), right-of-center tilts left (-)
        $center = ($count - 1) / 2;
        $offset = $i - $center;
        $rotation = round($offset * -10, 1); // negative = lanyards point inward
        $html .= '<div class="rg-hanging-badge" data-rotation="' . $rotation . '">';
        $html .= '<div class="rg-badge-ribbon"></div>';
        if ($badge['type'] === 'emoji') {
            $html .= '<span class="rg-badge-medal" title="' . htmlspecialchars($badge['title']) . '">' . $badge['icon'] . '</span>';
        } else {
            $html .= '<img src="' . htmlspecialchars($badge['img']) . '" class="rg-badge-medal-img" alt="' . htmlspecialchars($badge['title']) . '" title="' . htmlspecialchars($badge['title']) . '">';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Rank Graphic</span>
    </nav>

    <!-- Controls (outside canvas — not captured) -->
    <div class="rg-controls">
        <form method="GET" action="/rank-graphic" class="rg-controls-form">
            <label for="rgSeasonSelect" class="rg-controls-label">Season:</label>
            <select name="season" id="rgSeasonSelect" onchange="this.form.submit()" class="rg-controls-select">
                <?php foreach ($availableSeasons as $season):
                    $label = 'Season ' . strtoupper($season['season_id']) . ($season['status'] === 'archived' ? ' (Archived)' : '');
                ?>
                    <option value="<?= htmlspecialchars($season['season_id']) ?>" <?= ($season['season_id'] === $seasonId) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <button onclick="downloadRankGraphic()" class="btn-primary rg-download-btn">
            📸 Download as Image
        </button>
    </div>

    <?php if (empty($standings)): ?>
        <div class="racer-card rg-empty">
            <div class="rg-empty-icon">🏁</div>
            <h2>No Rankings Available</h2>
            <p>No racers have competed in this season yet.</p>
        </div>
    <?php else: ?>

    <!-- Graphic Canvas (captured by html2canvas) -->
    <div id="rg-canvas" class="rg-canvas">

        <!-- Header: single line season + name, then scoring system -->
        <div class="rg-header">
            <div class="rg-header-league"><?= htmlspecialchars($leagueName) ?> Mario Kart League</div>
            <div class="rg-header-season">Season <?= htmlspecialchars($seasonNumber) ?><?php if (!empty($seasonName)): ?>: <?= htmlspecialchars($seasonName) ?><?php endif; ?></div>
            <div class="rg-header-scoring">Scoring system: <?= htmlspecialchars($scoringInfo['icon'] ?? '') ?> <?= htmlspecialchars($scoringInfo['name']) ?></div>
        </div>

        <!-- Main grid: #1 left (tall) | #2-3 top-right + #4-5-6 bottom-right -->
        <div class="rg-main-grid">
            <?php if (!empty($champion)):
                $c = $champion[0];
            ?>
            <!-- #1 Champion — tall, fills entire left column -->
            <div class="rg-card rg-card--champion<?= !$c['eligible'] ? ' rg-card--ineligible' : '' ?>">
                <div class="rg-rank rg-rank--gold">#1</div>
                <div class="rg-portrait rg-portrait--lg">
                    <img src="/assets/img/<?= htmlspecialchars($c['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="<?= htmlspecialchars($c['name']) ?>">
                    <span class="rg-medal rg-medal--lg">🥇</span>
                </div>
                <?= renderHangingBadges($c['badges'], $c['unique']) ?>
                <div class="rg-name rg-name--lg"><?= htmlspecialchars($c['name']) ?></div>
                <div class="rg-score rg-score--lg"><?= number_format($c['score'], 2) ?></div>
                <div class="rg-gp-count"><?= $c['raceCount'] ?> GP<?= $c['raceCount'] !== 1 ? 's' : '' ?></div>
            </div>
            <?php endif; ?>

            <!-- Right column: #2-3 on top, #4-5-6 below -->
            <div class="rg-right-col">
                <?php if (!empty($podium)): ?>
                <!-- #2, #3 side by side -->
                <div class="rg-podium-row">
                    <?php foreach ($podium as $i => $p):
                        $rank = $i + 2;
                        $medalEmoji = $rank === 2 ? '🥈' : '🥉';
                        $rankClass = $rank === 2 ? 'rg-rank--silver' : 'rg-rank--bronze';
                        $cardClass = $rank === 2 ? 'rg-card--silver' : 'rg-card--bronze';
                    ?>
                    <div class="rg-card <?= $cardClass ?><?= !$p['eligible'] ? ' rg-card--ineligible' : '' ?>">
                        <div class="rg-rank <?= $rankClass ?>">#<?= $rank ?></div>
                        <div class="rg-portrait rg-portrait--md">
                            <img src="/assets/img/<?= htmlspecialchars($p['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="<?= htmlspecialchars($p['name']) ?>">
                            <span class="rg-medal rg-medal--md"><?= $medalEmoji ?></span>
                        </div>
                        <?= renderHangingBadges($p['badges'], $p['unique'], 'rg-hanging-badges--sm') ?>
                        <div class="rg-name rg-name--md"><?= htmlspecialchars($p['name']) ?></div>
                        <div class="rg-score rg-score--md"><?= number_format($p['score'], 2) ?></div>
                        <div class="rg-gp-count"><?= $p['raceCount'] ?> GP<?= $p['raceCount'] !== 1 ? 's' : '' ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($contenders)): ?>
                <!-- #4, #5, #6 — three across -->
                <div class="rg-contenders-row">
                    <?php foreach ($contenders as $i => $ct):
                        $rank = $i + 4;
                    ?>
                    <div class="rg-card rg-card--contender<?= !$ct['eligible'] ? ' rg-card--ineligible' : '' ?>">
                        <div class="rg-rank">#<?= $rank ?></div>
                        <div class="rg-portrait rg-portrait--sm">
                            <img src="/assets/img/<?= htmlspecialchars($ct['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="<?= htmlspecialchars($ct['name']) ?>">
                        </div>
                        <?= renderHangingBadges($ct['badges'], $ct['unique'], 'rg-hanging-badges--xs') ?>
                        <div class="rg-name rg-name--sm"><?= htmlspecialchars($ct['name']) ?></div>
                        <div class="rg-score rg-score--sm"><?= number_format($ct['score'], 2) ?></div>
                        <div class="rg-gp-count"><?= $ct['raceCount'] ?> GP<?= $ct['raceCount'] !== 1 ? 's' : '' ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Field (#7+) — small square cards in a row -->
        <?php if (!empty($field)): ?>
        <div class="rg-row rg-row--field">
            <?php foreach ($field as $i => $f):
                $rank = $i + 7;
            ?>
            <div class="rg-card rg-card--field<?= !$f['eligible'] ? ' rg-card--ineligible' : '' ?>">
                <div class="rg-rank rg-rank--field">#<?= $rank ?></div>
                <div class="rg-portrait rg-portrait--xs">
                    <img src="/assets/img/<?= htmlspecialchars($f['char']) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="<?= htmlspecialchars($f['name']) ?>">
                </div>
                <div class="rg-name rg-name--xs"><?= htmlspecialchars($f['name']) ?></div>
                <div class="rg-score rg-score--field"><?= number_format($f['score'], 2) ?></div>
                <?php if (!empty($f['badges']) || !empty($f['unique'])): ?>
                <div class="rg-field-badges">
                    <?php foreach ($f['badges'] as $badge): ?>
                        <span title="<?= htmlspecialchars($badge['title']) ?>"><?= $badge['icon'] ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($f['unique'] as $ub): ?>
                        <img src="<?= htmlspecialchars($ub['img']) ?>" class="rg-field-badge-img" alt="<?= htmlspecialchars($ub['title']) ?>" title="<?= htmlspecialchars($ub['title']) ?>">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Footer: season dates -->
        <div class="rg-footer">
            <?php if ($startDate && $endDate): ?>
                <span><?= $startDate ?> — <?= $endDate ?></span>
            <?php elseif ($startDate): ?>
                <span><?= $startDate ?> — Present</span>
            <?php else: ?>
                <span><?= htmlspecialchars($leagueName) ?> Mario Kart League</span>
            <?php endif; ?>
        </div>

    </div><!-- /#rg-canvas -->

    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
<script>
// Apply rotation transforms to hanging badges via JS (html2canvas-safe)
document.querySelectorAll('.rg-hanging-badge').forEach(badge => {
    const rotation = badge.dataset.rotation || 0;
    badge.style.transform = 'rotate(' + rotation + 'deg)';
});

function downloadRankGraphic() {
    const canvas = document.getElementById('rg-canvas');
    const button = event.target;
    button.textContent = 'Generating...';
    button.disabled = true;

    html2canvas(canvas, {
        scale: 2,
        backgroundColor: null,
        logging: false,
        useCORS: true
    }).then(result => {
        const link = document.createElement('a');
        link.download = '<?= htmlspecialchars($leagueName) ?>_Rankings_Season_<?= htmlspecialchars($seasonNumber) ?>.png';
        link.href = result.toDataURL();
        link.click();

        button.textContent = '📸 Download as Image';
        button.disabled = false;
    }).catch(err => {
        console.error('Download failed:', err);
        button.textContent = '📸 Download as Image';
        button.disabled = false;
    });
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
