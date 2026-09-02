<?php
/**
 * MONSTER HUNT Season Dashboard
 * Live standings, hunt log, CR tier, and season stats for MH seasons.
 * Path: /cdnmk/public_html/mh_dashboard.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$currentSeason = getCurrentSeasonNumber();

// ── Fetch all MONSTER HUNT seasons ────────────────────────────────────────
$mhSeasonsStmt = $pdo->query("
    SELECT season_id, season_name, status,
           mh_slay_xp, mh_survive_xp, mh_party_bonus_xp,
           mh_monster_win_xp, mh_monster_partial_xp, mh_monster_loss_xp,
           mh_min_gps, mh_best_x
    FROM season_meta
    WHERE scoring_system = 'monster_hunt'
    ORDER BY season_id DESC
");
$mhSeasons = $mhSeasonsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "MONSTER HUNT Dashboard";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

if (empty($mhSeasons)) {
    echo '<div class="stats-container" style="text-align:center;padding:80px 20px;color:var(--gray-600);">
        <div style="font-size:4rem;margin-bottom:20px;">👹</div>
        <h2 style="color:var(--gray-800);">No MONSTER HUNT Seasons Found</h2>
        <p>This dashboard activates when a season uses the MONSTER HUNT scoring system.</p>
    </div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

// ── Select season ─────────────────────────────────────────────────────────
$defaultSeason = $mhSeasons[0]['season_id'];
foreach ($mhSeasons as $s) {
    if ($s['season_id'] === $currentSeason) { $defaultSeason = $currentSeason; break; }
}
$selectedSeason = $_GET['season'] ?? $defaultSeason;
$rules = null;
foreach ($mhSeasons as $s) {
    if ($s['season_id'] === $selectedSeason) { $rules = $s; break; }
}
if (!$rules) { $selectedSeason = $defaultSeason; $rules = $mhSeasons[0]; }

$seasonLabel = htmlspecialchars($rules['season_name'] ?: strtoupper($selectedSeason));

// ── XP rules ─────────────────────────────────────────────────────────────
$slay_xp        = (int)($rules['mh_slay_xp']           ?? 100);
$survive_xp     = (int)($rules['mh_survive_xp']         ?? 20);
$party_bonus_xp = (int)($rules['mh_party_bonus_xp']     ?? 50);
$monster_win_xp = (int)($rules['mh_monster_win_xp']     ?? 80);
$monster_part   = (int)($rules['mh_monster_partial_xp'] ?? 30);
$monster_loss   = (int)($rules['mh_monster_loss_xp']    ?? -40);
$bestX          = (int)($rules['mh_best_x']             ?? 20);

// ── Load Elo data ─────────────────────────────────────────────────────────
$changelog     = getMonsterHuntEloChangelog($pdo);
$eloResult     = calculateAllELORatings($pdo);
$currentRatings = $eloResult['ratings'];

// ── GPs in selected season ────────────────────────────────────────────────
$gpStmt = $pdo->prepare("
    SELECT DISTINCT gpid, MIN(race_date) AS gp_date, MAX(cup_name) AS cup_name
    FROM results
    WHERE gpid LIKE ?
    GROUP BY gpid
    ORDER BY gp_date ASC, gpid ASC
");
$gpStmt->execute([$selectedSeason . '%']);
$seasonGPs = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Helper: CR tier → [tier, mult, epithet, colour] (tier/mult from the engine) ──
function crTierFromGap(float $gap): array {
    [$tier, $mult] = mhCrTier($gap);
    $look = [1 => ['the Rival', '#7a5f00'], 2 => ['the Beast', '#7a3a00'], 3 => ['the Fearsome One', '#7a1010'], 4 => ['the Dragon', '#4a0020']];
    return [$tier, $mult, $look[$tier][0], $look[$tier][1]];
}

// ── Build hunt log — every hunt comes from mhSeasonHunts(), the one engine ──
$huntLog       = [];
$racerXpLog    = []; // name => [xp, xp, ...]
$slayCount     = []; // name => int
$monsterCount  = []; // name => int
$tpkCount      = 0; // Monster beat everyone
$fullSlayCount = 0; // Everyone beat the Monster
$totalGPs      = 0;

foreach (mhSeasonHunts($pdo, $selectedSeason, $rules) as $h) {
    if ($h['solo']) continue;
    $totalGPs++;
    [, , $crEpithet, $crColor] = crTierFromGap((float)$h['gap']);

    foreach ($h['xp'] as $name => $xp) $racerXpLog[$name][] = $xp;

    if ($h['tpk'])       $tpkCount++;
    if ($h['full_slay']) $fullSlayCount++;
    foreach ($h['slayers'] as $s) $slayCount[$s] = ($slayCount[$s] ?? 0) + 1;
    $monsterCount[$h['monster']] = ($monsterCount[$h['monster']] ?? 0) + 1;

    $huntLog[] = [
        'gpid'        => $h['gpid'],
        'date'        => $h['date'],
        'cup'         => $h['cup'],
        'monster'     => $h['monster'],
        'monster_elo' => $h['monster_elo'],
        'cr_tier'     => $h['cr_tier'],
        'cr_epithet'  => $crEpithet,
        'cr_color'    => $crColor,
        'slayers'     => $h['slayers'],
        'survivors'  => $h['survivors'],
        'full_slay'  => $h['full_slay'],
        'tpk'        => $h['tpk'],
        'xp'          => $h['xp'],
    ];
}

// ── XP standings ──────────────────────────────────────────────────────────
$standings = [];
foreach ($racerXpLog as $name => $xpList) {
    rsort($xpList);
    $bestN    = array_slice($xpList, 0, $bestX);
    $totalXP  = array_sum($bestN);
    $standings[] = [
        'name'      => $name,
        'total_xp'  => $totalXP,
        'gp_count'  => count($xpList),
        'avg_xp'    => count($xpList) > 0 ? round($totalXP / count($xpList), 1) : 0,
        'elo'       => (int)round($currentRatings[$name] ?? 1000),
        'slays'     => $slayCount[$name]    ?? 0,
        'as_monster'=> $monsterCount[$name] ?? 0,
    ];
}
usort($standings, fn($a, $b) => $b['total_xp'] <=> $a['total_xp']);

// ── Current Monster ───────────────────────────────────────────────────────
$currentMonster   = null;
$currentCrTier    = 1;
$currentCrEpithet = 'the Rival';
$currentCrColor   = '#7a5f00';

if (!empty($standings)) {
    $byElo = $standings;
    usort($byElo, fn($a, $b) => $b['elo'] <=> $a['elo']);
    $currentMonster = $byElo[0];

    $advElosNow = array_column(array_slice($byElo, 1), 'elo');
    $avgAdvNow  = count($advElosNow) > 0 ? array_sum($advElosNow) / count($advElosNow) : $currentMonster['elo'];
    $gapNow     = max(0, $currentMonster['elo'] - $avgAdvNow);
    [$currentCrTier, , $currentCrEpithet, $currentCrColor] = crTierFromGap($gapNow);
}

// Top slayer + most-monster racer
arsort($slayCount);
$topSlayer    = !empty($slayCount)    ? array_key_first($slayCount)    : null;
$topSlayN     = $topSlayer            ? $slayCount[$topSlayer]          : 0;
arsort($monsterCount);
$topMonster   = !empty($monsterCount) ? array_key_first($monsterCount) : null;
$topMonsterN  = $topMonster           ? $monsterCount[$topMonster]      : 0;

$mixedGPs = $totalGPs - $tpkCount - $fullSlayCount;
?>

<div class="stats-container">

    <!-- Breadcrumb + Season selector -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;">
        <nav class="breadcrumb" style="margin-bottom:0;">
            <a href="/">← Home</a>
            <span class="breadcrumb-separator">/</span>
            <span class="breadcrumb-current">MONSTER HUNT Dashboard</span>
        </nav>
        <?php if (count($mhSeasons) > 1): ?>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:0.8rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:1px;">Season</label>
            <select name="season" onchange="this.form.submit()" style="padding:6px 12px;border-radius:6px;border:1px solid var(--gray-300);background:var(--gray-50);color:var(--gray-900);font-weight:700;">
                <?php foreach ($mhSeasons as $s): ?>
                    <option value="<?= htmlspecialchars($s['season_id']) ?>" <?= $s['season_id'] === $selectedSeason ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['season_name'] ?: strtoupper($s['season_id'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>

    <h1 class="section-title">👹 MONSTER HUNT — <?= $seasonLabel ?></h1>

    <?php if ($totalGPs === 0): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--gray-600);">
            <p>No MONSTER HUNT GPs recorded for this season yet.</p>
        </div>
    <?php else: ?>

    <!-- ── STAT PILLS ──────────────────────────────────────────────── -->
    <div class="mhd-stat-pills">
        <div class="mhd-pill">
            <div class="mhd-pill-num"><?= $totalGPs ?></div>
            <div class="mhd-pill-label">Hunts Completed</div>
        </div>
        <div class="mhd-pill mhd-pill--red">
            <div class="mhd-pill-num"><?= $tpkCount ?></div>
            <div class="mhd-pill-label">Total Party Kills</div>
        </div>
        <div class="mhd-pill mhd-pill--green">
            <div class="mhd-pill-num"><?= $fullSlayCount ?></div>
            <div class="mhd-pill-label">Full Slays</div>
        </div>
        <div class="mhd-pill">
            <div class="mhd-pill-num"><?= $mixedGPs ?></div>
            <div class="mhd-pill-label">Mixed Outcomes</div>
        </div>
        <?php if ($totalGPs > 0): ?>
        <div class="mhd-pill mhd-pill--gold">
            <div class="mhd-pill-num"><?= round(($tpkCount / $totalGPs) * 100) ?>%</div>
            <div class="mhd-pill-label">TPK Rate</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── TWO-COLUMN: CURRENT MONSTER + TOP STATS ─────────────────── -->
    <div class="mhd-top-grid">

        <!-- Current Monster Card -->
        <?php if ($currentMonster): ?>
        <div class="mhd-monster-card" style="border-color:<?= htmlspecialchars($currentCrColor) ?>;">
            <div class="mhd-monster-label">CURRENT MONSTER</div>
            <div class="mhd-monster-name">👹 <?= htmlspecialchars($currentMonster['name']) ?></div>
            <div class="mhd-cr-badge" style="background:<?= htmlspecialchars($currentCrColor) ?>;">
                CR<?= $currentCrTier ?> — <?= htmlspecialchars($currentCrEpithet) ?>
            </div>
            <div class="mhd-monster-stats">
                <div class="mhd-ms-row"><span>ELO</span><strong><?= $currentMonster['elo'] ?></strong></div>
                <div class="mhd-ms-row"><span>Total XP</span><strong><?= number_format($currentMonster['total_xp']) ?></strong></div>
                <div class="mhd-ms-row"><span>Hunts as Monster</span><strong><?= $currentMonster['as_monster'] ?></strong></div>
                <div class="mhd-ms-row"><span>Slays</span><strong><?= $currentMonster['slays'] ?></strong></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notable Stats -->
        <div class="mhd-notable-grid">
            <?php if ($topSlayer): ?>
            <div class="mhd-notable-card">
                <div class="mhd-notable-icon">🗡️</div>
                <div class="mhd-notable-title">Top Slayer</div>
                <div class="mhd-notable-name"><?= htmlspecialchars($topSlayer) ?></div>
                <div class="mhd-notable-sub"><?= $topSlayN ?> slay<?= $topSlayN !== 1 ? 's' : '' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($topMonster): ?>
            <div class="mhd-notable-card">
                <div class="mhd-notable-icon">👑</div>
                <div class="mhd-notable-title">Most Hunted</div>
                <div class="mhd-notable-name"><?= htmlspecialchars($topMonster) ?></div>
                <div class="mhd-notable-sub"><?= $topMonsterN ?> time<?= $topMonsterN !== 1 ? 's' : '' ?> as Monster</div>
            </div>
            <?php endif; ?>
            <?php
            // Longest Full Slay streak
            $wipeStreak = 0; $curStreak = 0;
            foreach ($huntLog as $h) {
                if ($h['full_slay']) $curStreak++;
                else $curStreak = 0;
                $wipeStreak = max($wipeStreak, $curStreak);
            }
            if ($wipeStreak > 1):
            ?>
            <div class="mhd-notable-card">
                <div class="mhd-notable-icon">🔥</div>
                <div class="mhd-notable-title">Full Slay Streak</div>
                <div class="mhd-notable-name"><?= $wipeStreak ?> in a row</div>
                <div class="mhd-notable-sub">Consecutive full slays</div>
            </div>
            <?php endif; ?>
            <?php
            // XP leader
            if (!empty($standings)) {
                $leader = $standings[0];
            ?>
            <div class="mhd-notable-card">
                <div class="mhd-notable-icon">⚡</div>
                <div class="mhd-notable-title">XP Leader</div>
                <div class="mhd-notable-name"><?= htmlspecialchars($leader['name']) ?></div>
                <div class="mhd-notable-sub"><?= number_format($leader['total_xp']) ?> XP</div>
            </div>
            <?php } ?>
        </div>
    </div>

    <!-- ── XP STANDINGS ────────────────────────────────────────────── -->
    <section class="mhd-section">
        <h2 class="mhd-section-title">⚡ XP Standings</h2>
        <p style="color:var(--gray-600);font-size:0.82rem;margin-bottom:16px;">
            Best <?= $bestX ?> hunts counted. 👹 = currently the Monster.
        </p>
        <div class="mhd-standings-table-wrap">
            <table class="mhd-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Racer</th>
                        <th>Total XP</th>
                        <th>GPs</th>
                        <th>Avg XP</th>
                        <th>Slays</th>
                        <th>As Monster</th>
                        <th>ELO</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($standings as $i => $s): ?>
                    <tr class="<?= $i === 0 ? 'mhd-row-leader' : '' ?>">
                        <td class="mhd-rank"><?= $i + 1 ?></td>
                        <td class="mhd-racer-cell">
                            <?php if ($currentMonster && $s['name'] === $currentMonster['name']): ?>
                                <span class="mhd-monster-indicator" data-tooltip="Current Monster">👹</span>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($s['name']) ?></strong>
                        </td>
                        <td class="mhd-xp-val"><?= number_format($s['total_xp']) ?></td>
                        <td><?= $s['gp_count'] ?></td>
                        <td><?= $s['avg_xp'] ?></td>
                        <td><?= $s['slays'] ?></td>
                        <td><?= $s['as_monster'] ?></td>
                        <td><span class="mhd-elo-badge"><?= $s['elo'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ── HUNT LOG ─────────────────────────────────────────────────── -->
    <section class="mhd-section">
        <h2 class="mhd-section-title">📜 Hunt Log</h2>
        <div class="mhd-hunt-log">
            <?php foreach (array_reverse($huntLog) as $h): ?>
            <div class="mhd-hunt-row">
                <!-- GP Identity -->
                <div class="mhd-hunt-id">
                    <span class="mhd-gpid"><?= strtoupper(htmlspecialchars($h['gpid'])) ?></span>
                    <span class="mhd-gp-date"><?= date('M j', strtotime($h['date'])) ?></span>
                    <span class="mhd-gp-cup"><?= htmlspecialchars($h['cup'] ?? '—') ?> Cup</span>
                </div>

                <!-- Monster + CR -->
                <div class="mhd-hunt-monster">
                    <span class="mhd-hunt-monster-name">👹 <?= htmlspecialchars($h['monster']) ?></span>
                    <span class="mhd-hunt-cr-badge" style="background:<?= htmlspecialchars($h['cr_color']) ?>;">
                        CR<?= $h['cr_tier'] ?> <?= htmlspecialchars($h['cr_epithet']) ?>
                    </span>
                </div>

                <!-- Outcome -->
                <div class="mhd-hunt-outcome">
                    <?php if ($h['full_slay']): ?>
                        <span class="mhd-outcome-pill mhd-outcome-wipe">🎉 Full Slay</span>
                    <?php elseif ($h['tpk']): ?>
                        <span class="mhd-outcome-pill mhd-outcome-monster">💀 TPK</span>
                    <?php else: ?>
                        <span class="mhd-outcome-pill mhd-outcome-mixed">⚔️ Mixed</span>
                    <?php endif; ?>
                    <?php if (!empty($h['slayers'])): ?>
                        <span class="mhd-slayers">🗡️ <?= htmlspecialchars(implode(', ', $h['slayers'])) ?></span>
                    <?php endif; ?>
                </div>

                <!-- XP summary -->
                <div class="mhd-hunt-xp">
                    <?php foreach ($h['xp'] as $name => $xp): ?>
                        <span class="mhd-xp-chip <?= $xp > 0 ? 'mhd-xp-pos' : 'mhd-xp-neg' ?>">
                            <?= htmlspecialchars($name) ?> <?= $xp > 0 ? '+' : '' ?><?= $xp ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php endif; // totalGPs > 0 ?>
</div>

<style>
/* ── MONSTER HUNT Dashboard ───────────────────────────────────────────── */
.mhd-stat-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 30px;
}

.mhd-pill {
    background: var(--gray-50);
    border: 2.5px solid var(--ink);
    border-radius: 14px;
    box-shadow: 4px 4px 0 var(--ink);
    padding: 14px 22px;
    text-align: center;
    min-width: 120px;
    flex: 1;
}
.mhd-pill--red   { background: #fdecec; }
.mhd-pill--green { background: #e6f6ec; }
.mhd-pill--gold  { background: #fff6dc; }

.mhd-pill-num {
    font-size: 2rem;
    font-weight: 900;
    color: var(--nintendo-red);
}
.mhd-pill--green .mhd-pill-num { color: #157347; }
.mhd-pill--gold  .mhd-pill-num { color: #9a7b00; }

.mhd-pill-label {
    font-size: 0.72rem;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}

/* Two-column top grid */
.mhd-top-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 20px;
    margin-bottom: 40px;
}

@media (max-width: 700px) { .mhd-top-grid { grid-template-columns: 1fr; } }

/* Monster card */
.mhd-monster-card {
    background: #fdecec;
    border: 2.5px solid var(--ink);
    box-shadow: 4px 4px 0 var(--ink);
    border-radius: 16px;
    padding: 24px 20px;
    text-align: center;
}

.mhd-monster-label {
    font-size: 0.68rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--gray-600);
    margin-bottom: 10px;
}

.mhd-monster-name {
    font-size: 1.5rem;
    font-weight: 900;
    color: var(--nintendo-red);
    margin-bottom: 12px;
}

.mhd-cr-badge {
    display: inline-block;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 900;
    color: #fff;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
}

.mhd-monster-stats { width: 100%; }
.mhd-ms-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    border-bottom: 1px solid var(--gray-200);
    font-size: 0.83rem;
}
.mhd-ms-row span { color: var(--gray-600); }
.mhd-ms-row strong { color: var(--gray-900); }

/* Notable grid */
.mhd-notable-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 14px;
    align-content: start;
}

.mhd-notable-card {
    background: var(--gray-50);
    border: 2.5px solid var(--ink);
    border-radius: 14px;
    box-shadow: 4px 4px 0 var(--ink);
    padding: 16px 14px;
    text-align: center;
}

.mhd-notable-icon  { font-size: 1.6rem; margin-bottom: 8px; }
.mhd-notable-title { font-size: 0.68rem; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: var(--gray-600); margin-bottom: 6px; }
.mhd-notable-name  { font-size: 1rem; font-weight: 900; color: var(--gray-900); }
.mhd-notable-sub   { font-size: 0.75rem; color: var(--gray-600); margin-top: 3px; }

/* Section headers */
.mhd-section { margin-bottom: 48px; }
.mhd-section-title {
    font-family: var(--font-display);
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--gray-900);
    letter-spacing: -0.01em;
    border-bottom: 3px solid var(--ink);
    padding-bottom: 10px;
    margin-bottom: 18px;
}

/* Standings table */
.mhd-standings-table-wrap { overflow-x: auto; }
.mhd-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.mhd-table th {
    background: var(--gray-100);
    color: var(--gray-600);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 10px 14px;
    text-align: left;
    border-bottom: 2px solid var(--gray-200);
}
.mhd-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-600);
}
.mhd-row-leader td { background: rgba(230,0,18,0.06); }
.mhd-rank { font-weight: 900; color: var(--gray-600); width: 30px; }
.mhd-racer-cell { font-weight: 700; color: var(--gray-900); }
.mhd-monster-indicator { margin-right: 6px; }
.mhd-xp-val { font-weight: 900; color: #9a7b00; }
.mhd-elo-badge {
    background: #e2f4fc;
    color: #0066aa;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 700;
    font-family: monospace;
}

/* Hunt Log */
.mhd-hunt-log {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.mhd-hunt-row {
    background: var(--gray-50);
    border: 2px solid var(--ink);
    border-radius: 12px;
    box-shadow: 3px 3px 0 var(--ink);
    padding: 14px 18px;
    display: grid;
    grid-template-columns: 160px 220px auto 1fr;
    gap: 16px;
    align-items: center;
}

@media (max-width: 900px) {
    .mhd-hunt-row { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
    .mhd-hunt-row { grid-template-columns: 1fr; }
}

.mhd-hunt-id {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.mhd-gpid   { font-size: 0.72rem; font-weight: 900; color: var(--gray-600); letter-spacing: 1px; font-family: monospace; }
.mhd-gp-date { font-size: 0.8rem; font-weight: 700; color: var(--gray-500); }
.mhd-gp-cup  { font-size: 0.78rem; color: var(--gray-600); }

.mhd-hunt-monster {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.mhd-hunt-monster-name { font-size: 0.92rem; font-weight: 900; color: var(--nintendo-red); }
.mhd-hunt-cr-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 900;
    color: #fff;
    letter-spacing: 0.5px;
    align-self: flex-start;
}

.mhd-hunt-outcome {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.mhd-outcome-pill {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 800;
    align-self: flex-start;
}
.mhd-outcome-wipe    { background: #e6f6ec; color: #157347; }
.mhd-outcome-monster { background: #fdecec; color: #b3261e; }
.mhd-outcome-mixed   { background: #fff6dc; color: #9a7b00; }
.mhd-slayers { font-size: 0.78rem; color: #157347; }

.mhd-hunt-xp {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.mhd-xp-chip {
    padding: 3px 9px;
    border-radius: 8px;
    font-size: 0.74rem;
    font-weight: 700;
    font-family: monospace;
}
.mhd-xp-pos { background: #e6f6ec; color: #157347; }
.mhd-xp-neg { background: #fdecec; color: #b3261e; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
