<?php
/**
 * Multiverse Champions — every archived season re-scored under every scoring
 * system in the registry. Which titles were robust, which were an artefact of
 * the system the league happened to pick that year.
 *
 * Cheap enough to compute live (all seasons × all systems is well under a
 * second on the season cache), so nothing is cached beyond the request.
 *
 * Path: /cdnmk/public_html/multiverse.php   (clean URL: /multiverse)
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$registry = getScoringSystemRegistry();
/** Registry names/descriptions may be closures of the rules ("Best 15 GPs"). */
function mvName(array $def, array $rules): string { return is_callable($def['name']) ? (string)($def['name'])($rules) : (string)$def['name']; }
$names    = racerNamesMap($pdo);
$seasons  = $pdo->query("SELECT season_id, scoring_system, champion_name FROM season_meta WHERE status = 'archived' ORDER BY season_id ASC")->fetchAll(PDO::FETCH_ASSOC);

/** Top three under one system for one season: [['id','name','score'], …] + field size. */
function multiverseTop(PDO $pdo, string $season_id, string $system): array {
    $def   = getScoringSystemDef($system);
    $rules = getSeasonRules($pdo, $season_id) ?: ['min_races_threshold' => 3];
    $rules['scoring_system'] = $system;
    $names = racerNamesMap($pdo);
    $rows  = [];
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rid => $rrows) {
        if (!racerQualifies(count($rrows), $rules)) continue;
        $rows[] = ['id' => (int)$rid, 'name' => (string)($names[$rid] ?? ''), 'score' => round((float)($def['calculate'])($pdo, (int)$rid, $season_id, $rules), 2)];
    }
    sortStandingsByScoring($rows, $system, $pdo, $season_id);
    $top = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'score' => $r['score']], array_slice($rows, 0, 3));
    return ['top' => $top, 'field' => count($rows)];
}

$universe = [];   // season => system => ['top'=>…, 'field'=>n]
$wins     = [];   // name => [season => count]
$perSeason = [];  // season => ['real'=>…, 'counts'=>name=>n, 'champions'=>n distinct]
foreach ($seasons as $s) {
    $sid = $s['season_id'];
    if (!getSeasonResultsByRacer($pdo, $sid)) continue;
    $counts = [];
    foreach ($registry as $key => $def) {
        $r = multiverseTop($pdo, $sid, $key);
        $universe[$sid][$key] = $r;
        if ($r['top']) { $n = $r['top'][0]['name']; $counts[$n] = ($counts[$n] ?? 0) + 1; $wins[$n][$sid] = ($wins[$n][$sid] ?? 0) + 1; }
    }
    arsort($counts);
    $perSeason[$sid] = ['real_system' => $s['scoring_system'], 'real_champion' => trim((string)$s['champion_name']), 'counts' => $counts];
}
$seasonIds = array_keys($universe);
$totalUniverses = count($registry) * count($seasonIds);
uasort($wins, fn($a, $b) => array_sum($b) <=> array_sum($a));

$mostContested = null; $mostUnanimous = null;
foreach ($perSeason as $sid => $p) {
    $distinct = count($p['counts']); $lead = $p['counts'] ? max($p['counts']) : 0;
    if ($mostContested === null || $distinct > $perSeason[$mostContested]['distinct']) { $perSeason[$sid]['distinct'] = $distinct; $mostContested = $sid; }
    $perSeason[$sid]['distinct'] = $distinct; $perSeason[$sid]['lead'] = $lead;
    if ($mostUnanimous === null || $lead > $perSeason[$mostUnanimous]['lead']) $mostUnanimous = $sid;
}
$colours = [];
foreach (array_keys($wins) as $i => $n) $colours[$n] = ['#e63946', '#2a9d8f', '#f4a261', '#457b9d', '#8d5bd6', '#d4a017', '#1d9a6c', '#c95d8f', '#6b7280', '#0ea5e9'][$i % 10];

$pageTitle = "Multiverse Champions — Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>
<div class="container">
    <nav class="breadcrumb">
        <a href="/">Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/scoring-systems">Scoring Systems</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Multiverse</span>
    </nav>

    <header class="page-header about-page-header">
        <h1 class="page-title about-page-title">🌌 Multiverse Champions</h1>
        <p class="page-subtitle">EVERY ARCHIVED SEASON, RE-SCORED UNDER ALL <?= count($registry) ?> SYSTEMS</p>
    </header>

    <?php if (!$seasonIds): ?>
        <div class="empty-state"><p>No archived seasons with results yet. The multiverse opens when the first season closes.</p></div>
    <?php else: ?>

    <section class="about-content">
        <p class="mv-intro">Same races, same scores, <?= count($registry) ?> different rulebooks. Each archived season is scored again under every system in the <a href="/scoring-systems">catalogue</a> with that season's own settings, and the winner of each universe is counted. A title that holds up across most rulebooks was earned on the track; one that only exists under the system the league happened to pick that year was earned in the committee room.</p>
    </section>

    <section class="mv-section">
        <h2 class="section-title">🏆 Universes won</h2>
        <p class="section-subtitle"><?= $totalUniverses ?> universes across <?= count($seasonIds) ?> season<?= count($seasonIds) === 1 ? '' : 's' ?></p>
        <div class="mv-bars">
            <?php $max = max(array_map('array_sum', $wins)); foreach ($wins as $n => $bySeason): $tot = array_sum($bySeason); ?>
                <div class="mv-bar-row">
                    <span class="mv-bar-name"><?= htmlspecialchars($n) ?></span>
                    <span class="mv-bar-track"><span class="mv-bar-fill" style="width:<?= round(100 * $tot / max(1, $max)) ?>%;background:<?= $colours[$n] ?>"></span></span>
                    <span class="mv-bar-num"><?= $tot ?></span>
                    <span class="mv-bar-seasons"><?php foreach ($seasonIds as $sid): ?><span class="mv-chip<?= empty($bySeason[$sid]) ? ' mv-chip--none' : '' ?>" title="<?= strtoupper($sid) ?>: <?= (int)($bySeason[$sid] ?? 0) ?> universe(s)"><?= strtoupper($sid) ?> <?= (int)($bySeason[$sid] ?? 0) ?></span><?php endforeach; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="mv-facts">
            <?php if ($mostContested !== null): ?><div class="mv-fact"><strong>Most contested:</strong> <?= strtoupper($mostContested) ?> — <?= $perSeason[$mostContested]['distinct'] ?> different champions across <?= count($registry) ?> universes.</div><?php endif; ?>
            <?php if ($mostUnanimous !== null): ?><div class="mv-fact"><strong>Most unanimous:</strong> <?= strtoupper($mostUnanimous) ?> — <?= htmlspecialchars(array_key_first($perSeason[$mostUnanimous]['counts'])) ?> wins <?= $perSeason[$mostUnanimous]['lead'] ?> of <?= count($registry) ?>.</div><?php endif; ?>
        </div>
    </section>

    <?php foreach ($seasonIds as $sid): $p = $perSeason[$sid]; $consensus = array_key_first($p['counts']); $realDef = isset($registry[$p['real_system']]) ? $registry[$p['real_system']] : null; $sRules = getSeasonRules($pdo, $sid) ?: []; ?>
    <section class="mv-section">
        <h2 class="section-title"><?= strtoupper($sid) ?></h2>
        <p class="section-subtitle">
            Actually scored on <?= $realDef ? $realDef['icon'] . ' ' . htmlspecialchars(mvName($realDef, $sRules)) : htmlspecialchars($p['real_system']) ?><?= $p['real_champion'] !== '' ? ' · champion <strong>' . htmlspecialchars($p['real_champion']) . '</strong>' : '' ?>
            · consensus champion <strong><?= htmlspecialchars($consensus) ?></strong> (<?= $p['counts'][$consensus] ?> of <?= count($registry) ?>)
            <?php if ($p['real_champion'] !== '' && $p['real_champion'] !== $consensus): ?><span class="mv-upset">the multiverse disagrees</span><?php endif; ?>
        </p>
        <div class="mv-summary">
            <?php foreach ($p['counts'] as $n => $c): ?><span class="mv-chip mv-chip--big" style="border-color:<?= $colours[$n] ?>"><span class="mv-dot" style="background:<?= $colours[$n] ?>"></span><?= htmlspecialchars($n) ?> <b><?= $c ?></b></span><?php endforeach; ?>
        </div>
        <div class="mv-grid">
            <?php foreach ($registry as $key => $def): $u = $universe[$sid][$key]; $w = $u['top'][0] ?? null; ?>
                <div class="mv-cell<?= $key === $p['real_system'] ? ' mv-cell--real' : '' ?>" title="<?= htmlspecialchars(implode(' · ', array_map(fn($t, $i) => ($i + 1) . '. ' . $t['name'] . ' ' . scoreNum($t['score']), $u['top'], array_keys($u['top'])))) ?>">
                    <span class="mv-cell-sys"><?= $def['icon'] ?> <?= htmlspecialchars(mvName($def, $sRules)) ?><?= $key === $p['real_system'] ? ' <em>real</em>' : '' ?></span>
                    <?php if ($w): ?>
                        <span class="mv-cell-win"><span class="mv-dot" style="background:<?= $colours[$w['name']] ?? '#999' ?>"></span><?= htmlspecialchars($w['name']) ?> <small><?= scoreNum($w['score']) ?></small></span>
                        <span class="mv-cell-podium"><?php foreach (array_slice($u['top'], 1) as $i => $t): ?><span><?= $i === 0 ? '🥈' : '🥉' ?> <?= htmlspecialchars($t['name']) ?></span><?php endforeach; ?></span>
                    <?php else: ?>
                        <span class="mv-cell-win mv-cell-win--none">nobody qualifies</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../private/templates/footer.php'; ?>
