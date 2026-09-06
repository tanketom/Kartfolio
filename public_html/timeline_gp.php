<?php
/**
 * Single-GP detail page — /timeline/<gpid>
 *
 * One Grand Prix in full: results table, Elo deltas, cup link, MONSTER HUNT
 * role assignments (if applicable), MH Chronicles (if generated), and any
 * news broadcasts that reference this GPID in their linked_gpids field.
 *
 * Path: /cdnmk/public_html/timeline_gp.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$gpid = trim((string)($_GET['gp'] ?? ''));

if ($gpid === '' || !preg_match('/^s[0-9]+gp[0-9]+$/', $gpid)) {
    http_response_code(404);
    $pageTitle = "Unknown GP — Kartfolio";
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container"><h1>🏁 Unknown GP</h1>';
    echo '<p>No GP matches "<code>' . htmlspecialchars($gpid) . '</code>". ';
    echo '<a href="/timeline">Back to timeline</a>.</p></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

$seasonId = substr($gpid, 0, 3);

// ── Verify GP exists ────────────────────────────────────────────────────
$existsStmt = $pdo->prepare("SELECT COUNT(*) FROM results WHERE gpid = ?");
$existsStmt->execute([$gpid]);
if ((int)$existsStmt->fetchColumn() === 0) {
    http_response_code(404);
    $pageTitle = "Unknown GP — Kartfolio";
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container"><h1>🏁 No results for that GP</h1>';
    echo '<p>GPID <code>' . htmlspecialchars($gpid) . '</code> has no results recorded. ';
    echo '<a href="/timeline">Back to timeline</a>.</p></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

// ── 1. GP-level header info ────────────────────────────────────────────
$headerStmt = $pdo->prepare("
    SELECT cup_name, MIN(race_date) AS race_date, COUNT(*) AS racer_count,
           MAX(gp_points) AS top_score, SUM(is_lol) AS total_lols
    FROM results WHERE gpid = ?
");
$headerStmt->execute([$gpid]);
$gpHeader = $headerStmt->fetch(PDO::FETCH_ASSOC);

$seasonInfo = $pdo->prepare("SELECT season_name, scoring_system FROM season_meta WHERE season_id = ?");
$seasonInfo->execute([$seasonId]);
$season = $seasonInfo->fetch(PDO::FETCH_ASSOC) ?: ['season_name' => $seasonId, 'scoring_system' => 'average_attendance'];

$scoringDef = getScoringSystemDef($season['scoring_system']);
$cupEmoji   = $gpHeader['cup_name'] ? getMKCupEmoji($gpHeader['cup_name']) : '🏁';
$cupSlug    = $gpHeader['cup_name'] ? getMKCupSlug($gpHeader['cup_name']) : null;

// ── 2. Full results in finishing order ─────────────────────────────────
$resStmt = $pdo->prepare("
    SELECT res.rank, res.gp_points, res.character_used, res.kart_setup,
           res.is_lol, res.is_monster, r.id AS racer_id, r.name, r.nickname
    FROM results res
    JOIN racers r ON r.id = res.racer_id
    WHERE res.gpid = ?
    ORDER BY res.rank ASC
");
$resStmt->execute([$gpid]);
$results = $resStmt->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Elo deltas around this GP ───────────────────────────────────────
// Re-running the full Elo engine to extract per-GP deltas is heavy but
// already cached in elo_engine.php's static memoisation per request.
$eloData = calculateAllELORatings($pdo);
$gpChangelog = $eloData['gp_changelog'] ?? [];
$eloDeltas = $gpChangelog[$gpid] ?? [];

// ── 4. Adjacent GPs for prev/next navigation within the season ─────────
$navStmt = $pdo->prepare("
    SELECT DISTINCT gpid FROM results
    WHERE gpid LIKE ? ORDER BY race_date ASC, gpid ASC
");
$navStmt->execute([$seasonId . '%']);
$seasonGpids = $navStmt->fetchAll(PDO::FETCH_COLUMN);
$ix = array_search($gpid, $seasonGpids, true);
$prevGp = $ix > 0 ? $seasonGpids[$ix - 1] : null;
$nextGp = $ix !== false && $ix < count($seasonGpids) - 1 ? $seasonGpids[$ix + 1] : null;

// ── 5. MONSTER HUNT Chronicles (if generated for this GP) ──────────────
$storyStmt = $pdo->prepare("SELECT story_text, generated_at FROM gp_stories WHERE gpid = ?");
$storyStmt->execute([$gpid]);
$mhStory = $storyStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ── 6. News broadcasts that reference this GP ──────────────────────────
$newsStmt = $pdo->prepare("
    SELECT id, headline, key_quote, program_key, created_at
    FROM recap_archive
    WHERE status = 'published' AND (linked_gpids LIKE ? OR linked_gpids LIKE ? OR linked_gpids LIKE ? OR linked_gpids = ?)
    ORDER BY created_at DESC
");
$pat1 = $gpid;
$pat2 = $gpid . ',%';
$pat3 = '%,' . $gpid;
$pat4 = '%,' . $gpid . ',%';
$newsStmt->execute([$pat1, $pat2, $pat3, $pat4]);
$newsItems = $newsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = strtoupper($gpid) . " — " . htmlspecialchars($season['season_name']);
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/timeline?season=<?= htmlspecialchars($seasonId) ?>">Timeline</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current"><?= strtoupper(htmlspecialchars($gpid)) ?></span>
    </nav>

    <header class="gp-detail-header">
        <div class="gp-detail-cup"><?= $cupEmoji ?></div>
        <div class="gp-detail-info">
            <h1 class="gp-detail-title"><?= strtoupper(htmlspecialchars($gpid)) ?></h1>
            <div class="gp-detail-sub">
                <?php if ($gpHeader['cup_name']): ?>
                    <?php if ($cupSlug): ?>
                        <a href="/cup/<?= htmlspecialchars($cupSlug) ?>"><strong><?= htmlspecialchars($gpHeader['cup_name']) ?> Cup</strong></a>
                    <?php else: ?>
                        <strong><?= htmlspecialchars($gpHeader['cup_name']) ?> Cup</strong>
                    <?php endif; ?>
                    ·
                <?php endif; ?>
                <?= $gpHeader['race_date'] ? date('F j, Y', strtotime($gpHeader['race_date'])) : '' ?>
                · <?= (int)$gpHeader['racer_count'] ?> racers
                · Top <?= (int)$gpHeader['top_score'] ?>
                <?php if ((int)$gpHeader['total_lols'] > 0): ?>
                    · <?= (int)$gpHeader['total_lols'] ?> 😂 LOLs
                <?php endif; ?>
            </div>
            <div class="gp-detail-scoring">
                <?= $scoringDef['icon'] ?> <?= htmlspecialchars($season['season_name']) ?>
                · <?= htmlspecialchars(is_callable($scoringDef['name']) ? ($scoringDef['name'])([]) : $scoringDef['name']) ?>
            </div>
        </div>
        <div class="gp-detail-nav">
            <?php if ($prevGp): ?>
                <a href="/timeline/<?= htmlspecialchars($prevGp) ?>" class="gp-nav-btn">← <?= strtoupper(htmlspecialchars($prevGp)) ?></a>
            <?php endif; ?>
            <?php if ($nextGp): ?>
                <a href="/timeline/<?= htmlspecialchars($nextGp) ?>" class="gp-nav-btn"><?= strtoupper(htmlspecialchars($nextGp)) ?> →</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Results table -->
    <section class="gp-results-section">
        <h2 class="gp-section-h">🏁 Results</h2>
        <table class="gp-results-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Racer</th>
                    <th>Character / Setup</th>
                    <th class="num">Points</th>
                    <th class="num">Elo Δ</th>
                    <th>Flags</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $row):
                    $eloDelta = null;
                    foreach ($eloDeltas as $d) {
                        if ((int)($d['racer_id'] ?? 0) === (int)$row['racer_id']) { $eloDelta = $d; break; }
                    }
                    $rowClass = $row['rank'] === 1 ? 'gp-row-gold'
                        : ($row['rank'] === 2 ? 'gp-row-silver'
                        : ($row['rank'] === 3 ? 'gp-row-bronze' : ''));
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="gp-rank">#<?= (int)$row['rank'] ?></td>
                    <td>
                        <a href="/racer/<?= (int)$row['racer_id'] ?>" class="gp-racer-link">
                            <strong><?= htmlspecialchars($row['name']) ?></strong>
                            <?php if ($row['nickname']): ?>
                                <small>"<?= htmlspecialchars($row['nickname']) ?>"</small>
                            <?php endif; ?>
                        </a>
                    </td>
                    <td>
                        <?= htmlspecialchars($row['character_used'] ?: '—') ?>
                        <?php if ($row['kart_setup']): ?>
                            <small class="gp-setup"><?= htmlspecialchars($row['kart_setup']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="num"><strong><?= (int)$row['gp_points'] ?></strong></td>
                    <td class="num">
                        <?php if ($eloDelta && isset($eloDelta['delta'])):
                            $d = (float)$eloDelta['delta']; ?>
                            <span class="elo-delta <?= $d > 0 ? 'pos' : ($d < 0 ? 'neg' : 'zero') ?>">
                                <?= ($d > 0 ? '+' : '') . round($d, 1) ?>
                            </span>
                        <?php else: ?>
                            <span class="elo-delta zero">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="gp-flags">
                        <?php if ($row['is_monster']): ?><span class="flag flag-monster">👹 Monster</span><?php endif; ?>
                        <?php if ($row['is_lol']): ?><span class="flag flag-lol">😂 LOL</span><?php endif; ?>
                        <?php if ((int)$row['gp_points'] === MK_MAX_GP_POINTS): ?><span class="flag flag-perfect">💎 Perfect</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- MH Chronicles -->
    <?php if ($mhStory): ?>
    <section class="gp-story-section">
        <h2 class="gp-section-h">⚔️ MONSTER HUNT Chronicles</h2>
        <p class="gp-section-sub">Generated <?= date('M j, Y', strtotime($mhStory['generated_at'])) ?></p>
        <div class="gp-story-body"><?= nl2br(htmlspecialchars($mhStory['story_text'])) ?></div>
    </section>
    <?php endif; ?>

    <!-- News broadcasts referencing this GP -->
    <?php if (!empty($newsItems)): ?>
    <section class="gp-news-section">
        <h2 class="gp-section-h">📻 Broadcasts About This GP</h2>
        <div class="gp-news-list">
            <?php foreach ($newsItems as $n): ?>
                <a href="/view-recap/<?= (int)$n['id'] ?>" class="gp-news-item">
                    <div class="gp-news-headline"><?= htmlspecialchars($n['headline'] ?: 'Untitled broadcast') ?></div>
                    <?php if ($n['key_quote']): ?>
                        <div class="gp-news-quote">"<?= htmlspecialchars($n['key_quote']) ?>"</div>
                    <?php endif; ?>
                    <div class="gp-news-meta">
                        <?= htmlspecialchars($n['program_key']) ?>
                        · <?= date('M j, Y', strtotime($n['created_at'])) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>

<style>
.gp-detail-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 22px 26px;
    background: var(--gray-50);
    border: 2.5px solid var(--dark-bg);
    box-shadow: 4px 4px 0 var(--dark-bg);
    border-radius: 12px;
    margin-bottom: 24px;
    border-left: 6px solid #FFD700;
    flex-wrap: wrap;
}
.gp-detail-cup { font-size: 3.2rem; line-height: 1; }
.gp-detail-info { flex: 1; min-width: 200px; }
.gp-detail-title {
    margin: 0 0 4px 0;
    font-size: 2rem;
    font-weight: 900;
    color: var(--nintendo-red);
    letter-spacing: 1px;
}
.gp-detail-sub { color: var(--gray-700); font-size: 0.95rem; margin-bottom: 6px; }
.gp-detail-sub a { color: var(--nintendo-red); text-decoration: none; }
.gp-detail-sub a:hover { text-decoration: underline; }
.gp-detail-scoring { color: var(--gray-500); font-size: 0.85rem; }
.gp-detail-nav { display: flex; flex-direction: column; gap: 6px; }
.gp-nav-btn {
    background: var(--gray-200);
    color: var(--nintendo-red);
    padding: 6px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.85rem;
    border: 1px solid #333;
    white-space: nowrap;
}
.gp-nav-btn:hover { background: #2a2a2a; }

.gp-section-h {
    color: var(--nintendo-red);
    margin: 28px 0 4px 0;
    font-size: 1.3rem;
    font-weight: 900;
    text-transform: uppercase;
}
.gp-section-sub { color: var(--gray-500); font-size: 0.85rem; margin: 0 0 14px 0; font-style: italic; }

.gp-results-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
    background: var(--gray-50);
    border-radius: 8px;
    overflow: hidden;
    color: var(--gray-900);
}
.gp-results-table th, .gp-results-table td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid var(--gray-200);
}
.gp-results-table th {
    background: var(--gray-200);
    color: var(--nintendo-red);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.gp-results-table td.num, .gp-results-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.gp-results-table tr.gp-row-gold   { background: rgba(255, 215, 0, 0.08); }
.gp-results-table tr.gp-row-silver { background: rgba(192, 192, 192, 0.06); }
.gp-results-table tr.gp-row-bronze { background: rgba(205, 127, 50, 0.06); }
.gp-rank {
    font-weight: 900;
    font-style: italic;
    color: var(--nintendo-red);
    font-size: 1.05rem;
}
.gp-row-gold .gp-rank   { color: var(--nintendo-red); }
.gp-row-silver .gp-rank { color: #c0c0c0; }
.gp-row-bronze .gp-rank { color: #cd7f32; }
.gp-racer-link { color: var(--gray-900); text-decoration: none; }
.gp-racer-link:hover { color: var(--nintendo-red); }
.gp-racer-link small { color: var(--gray-500); font-style: italic; margin-left: 4px; }
.gp-setup { display: block; color: var(--gray-500); font-size: 0.75rem; }
.elo-delta { font-weight: 700; }
.elo-delta.pos  { color: #2ebd59; }
.elo-delta.neg  { color: var(--nintendo-red); }
.elo-delta.zero { color: var(--gray-600); }
.gp-flags { display: flex; gap: 4px; flex-wrap: wrap; }
.flag {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 4px;
    background: #2a2a2a;
    color: var(--gray-700);
    white-space: nowrap;
}
.flag-monster { background: #5a2a8a; color: #fff; }
.flag-lol     { background: #444; color: var(--nintendo-red); }
.flag-perfect { background: #2ebd59; color: #fff; }

.gp-story-section {
    background: var(--gray-50);
    border-left: 4px solid #8e44ad;
    border-radius: 8px;
    padding: 18px 22px;
    margin-top: 14px;
}
.gp-story-body { color: var(--gray-700); line-height: 1.6; white-space: pre-wrap; }

.gp-news-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 12px;
}
.gp-news-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-left: 3px solid #0066cc;
    border-radius: 8px;
    padding: 14px 18px;
    color: var(--gray-900);
    text-decoration: none;
}
.gp-news-item:hover { border-color: var(--nintendo-red); }
.gp-news-headline { font-weight: 900; color: var(--nintendo-red); font-size: 0.95rem; }
.gp-news-quote { color: var(--gray-700); font-size: 0.85rem; font-style: italic; margin-top: 4px; }
.gp-news-meta { color: var(--gray-500); font-size: 0.75rem; margin-top: 6px; }

@media (max-width: 700px) {
    .gp-detail-header { flex-direction: column; align-items: flex-start; }
    .gp-results-table { font-size: 0.85rem; }
    .gp-results-table th, .gp-results-table td { padding: 8px 10px; }
}
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
