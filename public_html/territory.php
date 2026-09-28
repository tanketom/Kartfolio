<?php
/**
 * Territory — who holds each cup.
 *
 * The Territory engine (territorySeason) runs for every season anyway — the
 * Landlord / Usurper / Fortress badges read it — but the map only appears on
 * the homepage when a season is SCORED on Territory. This page shows the
 * holders for any season: official on a Territory season, "unofficial" on
 * the rest (who would hold each cup under Territory rules).
 *
 * Nothing here is computed differently: holders, points, decay and the event
 * log are territorySeason(); the map is territoryMapPayload() drawn by the
 * same overworld.js + territory_map.js the homepage uses.
 *
 * Path: /cdnmk/public_html/territory.php   Route: /territory[?season=sNN]
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';

// Seasons with season GPs (tournament t-rows excluded), newest first.
$availableSeasons = $pdo->query("SELECT DISTINCT SUBSTR(gpid, 1, INSTR(gpid, 'g') - 1) AS s FROM results WHERE gpid LIKE 's%' ORDER BY s DESC")->fetchAll(PDO::FETCH_COLUMN);
$currentSeason    = getCurrentSeasonNumber();
$seasonId = (string)($_GET['season'] ?? $currentSeason);
if (!in_array($seasonId, $availableSeasons, true)) $seasonId = in_array($currentSeason, $availableSeasons, true) ? $currentSeason : ($availableSeasons[0] ?? $currentSeason);

$rules       = getSeasonRules($pdo, $seasonId);
$scoringInfo = getScoringSystemInfo($pdo, $seasonId);
$isOfficial  = ($scoringInfo['system'] ?? '') === 'territory';
$seasonName  = trim((string)($rules['season_name'] ?? ''));

$t      = territorySeason($pdo, $seasonId);
$ttMap  = territoryMapPayload($pdo, $seasonId);
$names  = racerNamesMap($pdo);
$colors = $ttMap['colors'];
$decay  = (int)$t['decay_gps'];

// Per cup: how many times it changed hands, and the most recent change.
$flips = []; $lastChange = [];
foreach ($t['events'] as $ev) {
    if ($ev['from'] === null || $ev['from'] === $ev['to']) continue;
    $flips[$ev['cup']] = ($flips[$ev['cup']] ?? 0) + 1;
    $lastChange[$ev['cup']] = $ev;
}

// Holders ranked by cups held, then points across held cups, then name (§10).
$holders = [];
foreach ($t['by_racer'] as $rid => $cups) {
    $holders[] = ['id' => (int)$rid, 'name' => $names[$rid] ?? '?', 'cups' => count($cups), 'pts' => array_sum($cups)];
}
usort($holders, fn($a, $b) => ($b['cups'] <=> $a['cups']) ?: ($b['pts'] <=> $a['pts']) ?: strcmp($a['name'], $b['name']));

// Latest takeovers, newest first.
$typeLabel = ['beat' => 'beat', 'tie' => 'tied', 'decay' => 'took it undefended from'];
$takeovers = array_reverse(array_values(array_filter($t['events'], fn($e) => $e['from'] !== null && $e['from'] !== $e['to'])));

$pageTitle = 'Territory - Kartfolio';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

$dot = fn(int $rid) => '<span class="terr-dot" style="background:' . htmlspecialchars($colors[$rid] ?? '#999') . '"></span>';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Territory</span>
    </nav>

    <div class="terr-head">
        <div>
            <h1 class="section-title terr-title">TERRITORY</h1>
            <p class="terr-sub">Who holds each cup in <?= strtoupper(htmlspecialchars($seasonId)) ?><?= $seasonName !== '' ? ' · ' . htmlspecialchars($seasonName) : '' ?>.
                The best score on a cup holds it; an equal score takes it<?= $decay ? ', and a cup raced ' . $decay . '× without its holder goes to the best challenger' : '' ?>.</p>
        </div>
        <form method="GET" class="terr-season">
            <label for="terr-season">Season</label>
            <select id="terr-season" name="season" onchange="this.form.submit()">
                <?php foreach ($availableSeasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $seasonId ? 'selected' : '' ?>><?= strtoupper(htmlspecialchars($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="terr-banner <?= $isOfficial ? 'is-official' : '' ?>">
        <?php if ($isOfficial): ?>
            🏰 This season is scored on <strong>Territory</strong> — these holders are the standings.
        <?php else: ?>
            👀 <strong>Unofficial.</strong> <?= strtoupper(htmlspecialchars($seasonId)) ?> is scored on <?= htmlspecialchars(($scoringInfo['icon'] ?? '') . ' ' . ($scoringInfo['name'] ?? 'another system')) ?> — this is who <em>would</em> hold each cup under Territory rules.
        <?php endif; ?>
    </div>

    <?php if (!$t['hold']): ?>
        <div class="empty-state">
            <div class="empty-state-icon">🏳️</div>
            <h2 class="empty-state-title">No cups claimed yet</h2>
            <p class="empty-state-message">Nobody has raced this season. The first score on a cup plants the flag.</p>
        </div>
    <?php else: ?>

    <div class="tt-switch">
        <span class="tt-held"><?= (int)$ttMap['held'] ?> of <?= count($ttMap['cups']) ?> cups held · 🚩 = changed hands on the latest race night</span>
    </div>
    <div class="tt-map-card" id="tt-map-card">
        <canvas id="tt-map" data-layout="landscape" aria-label="Territory map: who holds each cup"></canvas>
        <div class="tt-overlay" id="tt-overlay"></div>
        <div class="tt-tip" id="tt-tip"></div>
    </div>
    <script id="tt-data" type="application/json"><?= json_encode($ttMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="<?= assetUrl('/assets/js/overworld.js') ?>"></script>
    <script src="<?= assetUrl('/assets/js/territory_map.js') ?>"></script>

    <section class="terr-holders">
        <?php foreach ($holders as $i => $h): ?>
            <a class="terr-chip" href="/racer/<?= $h['id'] ?>">
                <?= $dot($h['id']) ?>
                <strong><?= htmlspecialchars($h['name']) ?></strong>
                <span><?= $h['cups'] ?> cup<?= $h['cups'] === 1 ? '' : 's' ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="terr-grid">
        <section class="racer-card stats-section-card">
            <h2 class="stats-section-heading">🏆 Cup by cup</h2>
            <div class="terr-table-wrap">
                <table class="clean-table terr-table">
                    <thead>
                        <tr><th>Cup</th><th>Held by</th><th class="txt-right">To beat</th><th class="txt-center">Changed hands</th><?php if ($decay): ?><th class="txt-center" title="Nights the cup was raced without its holder. In red: one more and it goes to the best challenger.">Undefended</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ttMap['cups'] as $c):
                            $cup = $c['cup']; $h = $t['hold'][$cup] ?? null; ?>
                        <tr class="<?= $h ? '' : 'terr-open' ?>">
                            <td><a href="/cup/<?= getMKCupSlug($cup) ?>" class="terr-cup"><?= getMKCupEmoji($cup) ?> <?= htmlspecialchars($cup) ?></a><?= $c['flip'] ? ' <span title="Changed hands on the latest race night">🚩</span>' : '' ?></td>
                            <?php if ($h): ?>
                                <td><?= $dot($h['racer_id']) ?><a href="/racer/<?= $h['racer_id'] ?>"><?= htmlspecialchars($names[$h['racer_id']] ?? '?') ?></a></td>
                                <td class="txt-right terr-pts"><?= (int)$h['points'] ?><?= $h['points'] >= MK_MAX_GP_POINTS ? ' ✦' : '' ?></td>
                                <td class="txt-center"><?= $flips[$cup] ?? 0 ?></td>
                                <?php if ($decay): ?><td class="txt-center <?= $h['undefended'] >= $decay - 1 ? 'terr-danger' : '' ?>"><?= (int)$h['undefended'] ?> / <?= $decay ?></td><?php endif; ?>
                            <?php else: ?>
                                <td colspan="<?= $decay ? 4 : 3 ?>" class="terr-muted">Open — nobody has raced it</td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="racer-card stats-section-card">
            <h2 class="stats-section-heading">⚔️ Latest takeovers</h2>
            <?php if (!$takeovers): ?>
                <p class="terr-muted">No cup has changed hands yet — every holder is its first claimant.</p>
            <?php else: ?>
                <ol class="terr-log">
                    <?php foreach (array_slice($takeovers, 0, 15) as $ev): ?>
                    <li>
                        <a href="/timeline/<?= htmlspecialchars($ev['gpid']) ?>" class="terr-gp"><?= htmlspecialchars($ev['gpid']) ?></a>
                        <span><?= $dot($ev['to']) ?><strong><?= htmlspecialchars($names[$ev['to']] ?? '?') ?></strong>
                            <?= $typeLabel[$ev['type']] ?? $ev['type'] ?>
                            <?= htmlspecialchars($names[$ev['from']] ?? '?') ?> on
                            <?= getMKCupEmoji($ev['cup']) ?> <?= htmlspecialchars($ev['cup']) ?> (<?= (int)$ev['points'] ?>)</span>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (count($takeovers) > 15): ?><p class="terr-muted"><?= count($takeovers) - 15 ?> earlier takeovers not shown.</p><?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
