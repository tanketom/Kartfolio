<?php
/**
 * The Vault — curiosities, outliers, and records that the main leaderboard
 * doesn't surface. Every entry is a self-contained query over the results
 * table; if a query returns nothing (e.g. brand-new league), the card just
 * goes empty rather than crashing.
 *
 * Path: /cdnmk/public_html/vault.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

/** Fetch a single row safely; returns null if no result. */
function vaultQuery(PDO $pdo, string $sql, array $args = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// ── 1. Highest single-GP score ever ────────────────────────────────────
$highest = vaultQuery($pdo, "
    SELECT res.gp_points, res.gpid, res.cup_name, r.name
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%'
    ORDER BY res.gp_points DESC, res.race_date ASC LIMIT 1
");

// ── 2. Lowest winning score (lowest gp_points that still finished 1st) ──
$lowestWin = vaultQuery($pdo, "
    SELECT res.gp_points, res.gpid, res.cup_name, r.name
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%' AND res.rank = 1
    ORDER BY res.gp_points ASC, res.race_date DESC LIMIT 1
");

// ── 3. Biggest blowout (gap between rank 1 and rank 2 in a single GP) ───
$blowout = vaultQuery($pdo, "
    SELECT a.gpid, a.cup_name,
           a.gp_points AS winner_pts, ra.name AS winner_name,
           b.gp_points AS second_pts, rb.name AS second_name,
           (a.gp_points - b.gp_points) AS gap
    FROM results a
    JOIN racers ra ON ra.id = a.racer_id
    JOIN results b ON b.gpid = a.gpid AND b.rank = 2
    JOIN racers rb ON rb.id = b.racer_id
    WHERE a.gpid LIKE 's%' AND a.rank = 1
    ORDER BY gap DESC LIMIT 1
");

// ── 4. Closest GP win (smallest 1st–2nd gap, ties broken to most recent) ─
$closest = vaultQuery($pdo, "
    SELECT a.gpid, a.cup_name,
           a.gp_points AS winner_pts, ra.name AS winner_name,
           b.gp_points AS second_pts, rb.name AS second_name,
           (a.gp_points - b.gp_points) AS gap
    FROM results a
    JOIN racers ra ON ra.id = a.racer_id
    JOIN results b ON b.gpid = a.gpid AND b.rank = 2
    JOIN racers rb ON rb.id = b.racer_id
    WHERE a.gpid LIKE 's%' AND a.rank = 1 AND a.gp_points >= b.gp_points
    ORDER BY gap ASC, a.race_date DESC LIMIT 1
");

// ── 5. Most LOLs in a single GP (racer + GP) ───────────────────────────
$mostLolsOneGp = vaultQuery($pdo, "
    SELECT res.gpid, res.cup_name, r.name,
           SUM(res.is_lol) AS lols
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.gpid, res.racer_id
    HAVING lols > 0
    ORDER BY lols DESC, res.race_date DESC LIMIT 1
");

// ── 6. Most LOLs in a season (racer) ───────────────────────────────────
$mostLolsSeason = vaultQuery($pdo, "
    SELECT r.name, SUBSTR(res.gpid, 1, 3) AS season,
           SUM(res.is_lol) AS lols
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.racer_id, season
    HAVING lols > 0
    ORDER BY lols DESC LIMIT 1
");

// ── 7. Most loyal character user (racer + character + count) ───────────
$loyal = vaultQuery($pdo, "
    SELECT r.name, res.character_used, COUNT(*) AS plays
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%' AND res.character_used IS NOT NULL AND res.character_used != ''
    GROUP BY res.racer_id, res.character_used
    ORDER BY plays DESC LIMIT 1
");

// ── 8. Most adventurous (racer with most distinct characters tried) ────
$variety = vaultQuery($pdo, "
    SELECT r.name, COUNT(DISTINCT res.character_used) AS variety
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%' AND res.character_used IS NOT NULL AND res.character_used != ''
    GROUP BY res.racer_id
    ORDER BY variety DESC, r.name ASC LIMIT 1
");

// ── 9. The career grinder (most GPs raced career) ──────────────────────
$grinder = vaultQuery($pdo, "
    SELECT r.name, COUNT(DISTINCT res.gpid) AS gps
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.racer_id
    ORDER BY gps DESC, r.name ASC LIMIT 1
");

// ── 10. Most consistent (lowest stddev in gp_points, min 5 GPs) ────────
// SQLite lacks STDDEV; compute via variance ourselves.
$consistencyRows = $pdo->query("
    SELECT res.racer_id, r.name, AVG(res.gp_points) AS mean,
           AVG(res.gp_points * res.gp_points) - AVG(res.gp_points) * AVG(res.gp_points) AS variance,
           COUNT(*) AS gps
    FROM results res JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid LIKE 's%'
    GROUP BY res.racer_id
    HAVING gps >= 5
    ORDER BY variance ASC LIMIT 1
")->fetch(PDO::FETCH_ASSOC) ?: null;
$consistent = $consistencyRows ? [
    'name'  => $consistencyRows['name'],
    'stdev' => sqrt(max(0, (float)$consistencyRows['variance'])),
    'mean'  => (float)$consistencyRows['mean'],
    'gps'   => (int)$consistencyRows['gps'],
] : null;

$pageTitle = "The Vault — Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Vault</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🗄️ The Vault</h1>
        <p class="page-subtitle">CURIOSITIES, OUTLIERS, AND RECORDS THE LEADERBOARD WON'T SHOW YOU</p>
    </header>

    <div class="vault-grid">

        <?php if ($highest): ?>
        <article class="vault-card vault-record">
            <div class="vault-icon">🚀</div>
            <h3 class="vault-h">Highest Single-GP Score</h3>
            <div class="vault-big"><?= (int)$highest['gp_points'] ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($highest['name']) ?></strong> on
                <strong><?= htmlspecialchars($highest['cup_name'] ?? 'Unknown') ?> Cup</strong>,
                <a href="/timeline/<?= htmlspecialchars($highest['gpid']) ?>"><?= strtoupper(htmlspecialchars($highest['gpid'])) ?></a>.
                <?php if ((int)$highest['gp_points'] === MK_MAX_GP_POINTS): ?><em>A perfect 60.</em><?php endif; ?>
            </p>
        </article>
        <?php endif; ?>

        <?php if ($lowestWin): ?>
        <article class="vault-card">
            <div class="vault-icon">🐌</div>
            <h3 class="vault-h">Lowest Winning Score</h3>
            <div class="vault-big"><?= (int)$lowestWin['gp_points'] ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($lowestWin['name']) ?></strong> took 1st on
                <strong><?= htmlspecialchars($lowestWin['cup_name'] ?? 'Unknown') ?> Cup</strong>
                with <?= (int)$lowestWin['gp_points'] ?> points in
                <a href="/timeline/<?= htmlspecialchars($lowestWin['gpid']) ?>"><?= strtoupper(htmlspecialchars($lowestWin['gpid'])) ?></a>.
                A win is a win.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($blowout): ?>
        <article class="vault-card">
            <div class="vault-icon">💥</div>
            <h3 class="vault-h">Biggest Blowout</h3>
            <div class="vault-big"><?= (int)$blowout['gap'] ?> pts</div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($blowout['winner_name']) ?></strong>
                (<?= (int)$blowout['winner_pts'] ?>) over
                <strong><?= htmlspecialchars($blowout['second_name']) ?></strong>
                (<?= (int)$blowout['second_pts'] ?>) on
                <strong><?= htmlspecialchars($blowout['cup_name'] ?? 'Unknown') ?> Cup</strong>,
                <a href="/timeline/<?= htmlspecialchars($blowout['gpid']) ?>"><?= strtoupper(htmlspecialchars($blowout['gpid'])) ?></a>.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($closest): ?>
        <article class="vault-card">
            <div class="vault-icon">📏</div>
            <h3 class="vault-h">Closest GP Win</h3>
            <div class="vault-big"><?= (int)$closest['gap'] ?> pt<?= (int)$closest['gap'] === 1 ? '' : 's' ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($closest['winner_name']) ?></strong>
                edged <strong><?= htmlspecialchars($closest['second_name']) ?></strong>
                <?= (int)$closest['winner_pts'] ?>–<?= (int)$closest['second_pts'] ?>
                on <strong><?= htmlspecialchars($closest['cup_name'] ?? 'Unknown') ?> Cup</strong>,
                <a href="/timeline/<?= htmlspecialchars($closest['gpid']) ?>"><?= strtoupper(htmlspecialchars($closest['gpid'])) ?></a>.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($mostLolsOneGp): ?>
        <article class="vault-card">
            <div class="vault-icon">😂</div>
            <h3 class="vault-h">Most LOLs, One Night</h3>
            <div class="vault-big"><?= (int)$mostLolsOneGp['lols'] ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($mostLolsOneGp['name']) ?></strong> collected
                <?= (int)$mostLolsOneGp['lols'] ?> Ludwig Obstruction<?= (int)$mostLolsOneGp['lols'] === 1 ? '' : 's' ?>
                on <strong><?= htmlspecialchars($mostLolsOneGp['cup_name'] ?? 'Unknown') ?> Cup</strong>,
                <a href="/timeline/<?= htmlspecialchars($mostLolsOneGp['gpid']) ?>"><?= strtoupper(htmlspecialchars($mostLolsOneGp['gpid'])) ?></a>.
                Cursed evening.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($mostLolsSeason): ?>
        <article class="vault-card">
            <div class="vault-icon">🎭</div>
            <h3 class="vault-h">Season LOL Champion</h3>
            <div class="vault-big"><?= (int)$mostLolsSeason['lols'] ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($mostLolsSeason['name']) ?></strong> set the season record
                with <?= (int)$mostLolsSeason['lols'] ?> Ludwig Obstructions in
                <strong><?= strtoupper(htmlspecialchars($mostLolsSeason['season'])) ?></strong>.
                A career in being in the wrong place.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($loyal): ?>
        <article class="vault-card">
            <div class="vault-icon">💍</div>
            <h3 class="vault-h">The Loyalist</h3>
            <div class="vault-big"><?= (int)$loyal['plays'] ?>×</div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($loyal['name']) ?></strong> has raced
                <strong><?= htmlspecialchars($loyal['character_used']) ?></strong>
                <?= (int)$loyal['plays'] ?> times. A marriage, basically.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($variety): ?>
        <article class="vault-card">
            <div class="vault-icon">🎨</div>
            <h3 class="vault-h">The Adventurer</h3>
            <div class="vault-big"><?= (int)$variety['variety'] ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($variety['name']) ?></strong> has tried
                <?= (int)$variety['variety'] ?> different characters.
                The Mario Kart equivalent of ordering something new every visit.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($grinder): ?>
        <article class="vault-card">
            <div class="vault-icon">⛏️</div>
            <h3 class="vault-h">The Grinder</h3>
            <div class="vault-big"><?= (int)$grinder['gps'] ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($grinder['name']) ?></strong> has raced in
                <?= (int)$grinder['gps'] ?> GPs across the league's history.
                Built different.
            </p>
        </article>
        <?php endif; ?>

        <?php if ($consistent): ?>
        <article class="vault-card">
            <div class="vault-icon">🎯</div>
            <h3 class="vault-h">Most Consistent</h3>
            <div class="vault-big">±<?= number_format($consistent['stdev'], 1) ?></div>
            <p class="vault-body">
                <strong><?= htmlspecialchars($consistent['name']) ?></strong> scores
                <?= number_format($consistent['mean'], 1) ?> ± <?= number_format($consistent['stdev'], 1) ?>
                across <?= (int)$consistent['gps'] ?> GPs. Boring, in the best way.
            </p>
        </article>
        <?php endif; ?>

    </div>
</div>

<style>
.vault-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 14px;
    margin-top: 16px;
}
.vault-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-left: 4px solid #FFD700;
    border-radius: 8px;
    padding: 18px 22px;
    color: var(--gray-900);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.vault-card.vault-record { border-left-color: #2EBD59; }
.vault-icon { font-size: 2rem; line-height: 1; }
.vault-h {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 900;
    text-transform: uppercase;
    color: var(--nintendo-red);
    letter-spacing: 0.5px;
}
.vault-big {
    font-size: 2.4rem;
    font-weight: 900;
    color: var(--gray-900);
    line-height: 1.05;
    font-variant-numeric: tabular-nums;
}
.vault-body {
    color: var(--gray-700);
    line-height: 1.5;
    font-size: 0.9rem;
    margin: 0;
}
.vault-body strong { color: var(--gray-900); }
.vault-body a { color: var(--nintendo-red); text-decoration: none; }
.vault-body a:hover { text-decoration: underline; }
.vault-body em { color: #2EBD59; font-style: normal; font-weight: 700; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
