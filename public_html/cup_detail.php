<?php
/**
 * Cup Detail Page — per-track encyclopedia for one cup.
 *
 * Sections, top to bottom:
 *   1. Header (cup icon + name + source badge + fan-fave Elo)
 *   2. Cup-level stats strip (races run, avg / high score, LOL count)
 *   3. Four track sections, each with: name, retro era, preference Elo,
 *      vote count, Mac's Mushroom Musings, and (for admins) a
 *      Generate/Regenerate button.
 *   4. Champions Wall — every racer who's ever taken rank=1 on this cup.
 *   5. Recent GPs ribbon — last 5 GPs played on this cup.
 *
 * URL: /cup/<slug>     (e.g. /cup/mushroom, /cup/golden-dash)
 *
 * Path: /cdnmk/public_html/cup_detail.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/mk_data.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/track_ranking.php';

$slug = trim((string)($_GET['cup'] ?? ''));
$cup  = $slug !== '' ? getMKCupFromSlug($slug) : null;

if ($cup === null) {
    http_response_code(404);
    $pageTitle = "Unknown Cup — Kartfolio";
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container"><h1>🏆 Unknown Cup</h1>';
    echo '<p>No cup matches "<code>' . htmlspecialchars($slug) . '</code>". ';
    echo '<a href="/cup-stats">Browse all cups</a>.</p></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

$tracks       = getMKTracksByCup()[$cup] ?? [];
$isBoosterCup = in_array($cup, MK_BOOSTER_CUPS, true);
$cupEmoji     = getMKCupEmoji($cup);
$isAdmin      = !empty($_SESSION['is_admin']);

// ── 1. Track preference Elo for this cup ────────────────────────────────
$allRankings = trackRankings($pdo);
$cupTrackData = [];
$cupEloSum  = 0;
$cupVoteSum = 0;
foreach ($tracks as $t) {
    $r = $allRankings[$t] ?? ['elo' => 1500, 'votes_total' => 0, 'wins' => 0, 'losses' => 0, 'win_pct' => null];
    $cupTrackData[$t] = $r;
    $cupEloSum  += $r['elo'];
    $cupVoteSum += $r['votes_total'];
}
$cupAvgElo = count($tracks) > 0 ? (int)round($cupEloSum / count($tracks)) : 1500;

// ── 2. Cup-level stats (all-time) ──────────────────────────────────────
$statStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT gpid) AS races_run,
           AVG(gp_points)       AS avg_score,
           MAX(gp_points)       AS high_score,
           SUM(is_lol)          AS total_lols
    FROM results
    WHERE cup_name = ? AND gpid LIKE 's%'
");
$statStmt->execute([$cup]);
$cupStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'races_run'  => 0,
    'avg_score'  => null,
    'high_score' => null,
    'total_lols' => 0,
];

// ── 3. Mac's Musings for each track (may be missing) ───────────────────
$musings = [];
if (!empty($tracks)) {
    $placeholders = implode(',', array_fill(0, count($tracks), '?'));
    $mStmt = $pdo->prepare("SELECT track_name, body, model_used, generated_at FROM track_musings WHERE track_name IN ($placeholders)");
    $mStmt->execute($tracks);
    foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $musings[$row['track_name']] = $row;
    }
}

// ── 4. Champions Wall — distinct racers who've taken rank=1 on this cup ─
$champStmt = $pdo->prepare("
    SELECT r.id, r.name, COUNT(*) AS wins
    FROM results res
    JOIN racers r ON r.id = res.racer_id
    WHERE res.cup_name = ? AND res.rank = 1 AND res.gpid LIKE 's%'
    GROUP BY r.id
    ORDER BY wins DESC, r.name ASC
");
$champStmt->execute([$cup]);
$champions = $champStmt->fetchAll(PDO::FETCH_ASSOC);
// Resolve each champion's main character. getMostUsedCharacter is backed by
// per-request caches (season results + a batched career-character map), so
// this loop costs a fixed couple of queries regardless of champion count.
$curSeason = getCurrentSeasonNumber();
foreach ($champions as &$c) {
    $c['character'] = getMostUsedCharacter($pdo, (int)$c['id'], $curSeason);
}
unset($c);

// ── 5. Recent GPs ribbon — last 5 GPs played on this cup ────────────────
// Champion-of-GP is resolved in a second pass below (simpler than a
// correlated subquery; the row count is bounded at 5).
$recentStmt = $pdo->prepare("
    SELECT res.gpid,
           res.race_date,
           MAX(res.gp_points) AS top_score
    FROM results res
    WHERE res.cup_name = ? AND res.gpid LIKE 's%'
    GROUP BY res.gpid
    ORDER BY res.race_date DESC, res.gpid DESC
    LIMIT 5
");
$recentStmt->execute([$cup]);
$recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Resolve all champion-of-GP names in one query instead of one per GP.
$recentGpids = array_column($recent, 'gpid');
$champByGp = [];
if (!empty($recentGpids)) {
    $ph = implode(',', array_fill(0, count($recentGpids), '?'));
    $cStmt = $pdo->prepare("
        SELECT res.gpid, r.name
        FROM results res
        JOIN racers r ON r.id = res.racer_id
        WHERE res.gpid IN ($ph) AND res.rank = 1
    ");
    $cStmt->execute($recentGpids);
    foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // First rank-1 row per gpid wins — matches the old LIMIT 1 per GP.
        if (!isset($champByGp[$row['gpid']])) $champByGp[$row['gpid']] = $row['name'];
    }
}
foreach ($recent as &$g) {
    $g['champion'] = $champByGp[$g['gpid']] ?? '—';
}
unset($g);

$pageTitle = "$cup Cup — Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/cup-stats">Cups</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current"><?= htmlspecialchars($cup) ?></span>
    </nav>

    <header class="cup-detail-header">
        <div class="cup-detail-icon"><?= $cupEmoji ?></div>
        <div class="cup-detail-titlewrap">
            <h1 class="cup-detail-title"><?= htmlspecialchars($cup) ?> Cup</h1>
            <div class="cup-detail-badges">
                <span class="cup-badge cup-badge-source <?= $isBoosterCup ? 'booster' : 'base' ?>">
                    <?= $isBoosterCup ? 'Booster Course Pass' : 'Base Game' ?>
                </span>
                <span class="cup-badge cup-badge-elo">
                    Fan Favourite Elo · <?= $cupAvgElo ?>
                </span>
                <span class="cup-badge cup-badge-votes">
                    <?= number_format($cupVoteSum) ?> track votes
                </span>
            </div>
        </div>
    </header>

    <!-- Cup-level stats strip -->
    <section class="cup-stats-strip">
        <div class="cup-stat">
            <div class="cup-stat-num"><?= (int)$cupStats['races_run'] ?></div>
            <div class="cup-stat-lbl">GPs played</div>
        </div>
        <div class="cup-stat">
            <div class="cup-stat-num"><?= $cupStats['avg_score'] !== null ? number_format((float)$cupStats['avg_score'], 1) : '—' ?></div>
            <div class="cup-stat-lbl">avg score</div>
        </div>
        <div class="cup-stat">
            <div class="cup-stat-num"><?= $cupStats['high_score'] !== null ? (int)$cupStats['high_score'] : '—' ?></div>
            <div class="cup-stat-lbl">high score</div>
        </div>
        <div class="cup-stat">
            <div class="cup-stat-num"><?= (int)$cupStats['total_lols'] ?></div>
            <div class="cup-stat-lbl">😂 LOL moments</div>
        </div>
    </section>

    <!-- Per-track encyclopedia -->
    <section class="cup-tracks">
        <h2 class="cup-section-h">🏁 The Four Tracks</h2>
        <p class="cup-section-sub">Each track gets a strategy musing from Mac, the old Toad caddie who's worked every cup since the SNES days.</p>

        <?php foreach ($tracks as $i => $t):
            $era    = getMKTrackEra($t);
            $slugT  = getMKTrackImageSlug($t);
            $rank   = $cupTrackData[$t];
            $musing = $musings[$t] ?? null;
        ?>
            <article class="track-card" id="track-<?= htmlspecialchars($slugT) ?>"
                     data-track-name="<?= htmlspecialchars($t) ?>"
                     data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
                <header class="track-card-head">
                    <div class="track-card-num"><?= $i + 1 ?></div>
                    <div class="track-card-titles">
                        <h3 class="track-card-name">
                            <?php if ($era): ?><span class="track-era"><?= $era ?></span> <?php endif; ?>
                            <?= htmlspecialchars(preg_replace('/^(SNES|N64|GCN|GBA|DS|3DS|Wii|Tour) /', '', $t)) ?>
                        </h3>
                        <div class="track-card-meta">
                            Preference Elo <strong><?= $rank['elo'] ?></strong>
                            · <?= (int)$rank['votes_total'] ?> votes
                            <?php if ($rank['win_pct'] !== null): ?>
                                · <?= $rank['win_pct'] ?>% win rate
                            <?php endif; ?>
                        </div>
                    </div>
                    <a class="track-vote-cta" href="/track-favourites">Vote ▶</a>
                </header>

                <div class="track-musing">
                    <div class="track-musing-head">
                        <span class="track-musing-icon">🍄</span>
                        <span class="track-musing-label">Mac's Mushroom Musings</span>
                        <?php if ($musing): ?>
                            <span class="track-musing-meta">
                                <?= date('M j, Y', strtotime($musing['generated_at'])) ?>
                                <?php if (!empty($musing['model_used'])): ?> · <?= htmlspecialchars($musing['model_used']) ?><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="track-musing-body" data-empty="<?= $musing ? '0' : '1' ?>">
                        <?php if ($musing): ?>
                            <?= nl2br(htmlspecialchars($musing['body'])) ?>
                        <?php else: ?>
                            <em>No musings yet for this track.<?php if ($isAdmin): ?> Generate below.<?php endif; ?></em>
                        <?php endif; ?>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="track-musing-actions">
                            <button type="button" class="btn btn-secondary btn-sm track-musing-btn">
                                <?= $musing ? '🔄 Regenerate' : '🤖 Generate Musings' ?>
                            </button>
                            <span class="track-musing-status" hidden></span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <!-- Champions Wall -->
    <?php if (!empty($champions)): ?>
    <section class="cup-champions">
        <h2 class="cup-section-h">🏆 Champions Wall</h2>
        <p class="cup-section-sub">Every racer who's ever taken 1st on the <?= htmlspecialchars($cup) ?> Cup.</p>
        <div class="champion-grid">
            <?php foreach ($champions as $c):
                $char = !empty($c['character']) ? $c['character'] : 'Mii';
            ?>
                <a href="/racer/<?= (int)$c['id'] ?>" class="champion-tile">
                    <img src="/assets/img/<?= htmlspecialchars($char) ?>.png"
                         class="champion-portrait"
                         onerror="this.src='/assets/img/Mii.png'">
                    <div class="champion-name"><?= htmlspecialchars($c['name']) ?></div>
                    <div class="champion-wins"><?= (int)$c['wins'] ?>× 🥇</div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Recent GPs ribbon -->
    <?php if (!empty($recent)): ?>
    <section class="cup-recent">
        <h2 class="cup-section-h">📅 Recent GPs on this Cup</h2>
        <div class="recent-gps">
            <?php foreach ($recent as $g): ?>
                <div class="recent-gp">
                    <div class="recent-gp-id"><?= htmlspecialchars(strtoupper($g['gpid'])) ?></div>
                    <div class="recent-gp-date"><?= $g['race_date'] ? date('M j, Y', strtotime($g['race_date'])) : '—' ?></div>
                    <div class="recent-gp-champ">🥇 <?= htmlspecialchars($g['champion']) ?></div>
                    <div class="recent-gp-top">High: <strong><?= (int)$g['top_score'] ?></strong></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

</div>

<style>
.cup-detail-header {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 24px 28px;
    background: var(--gray-50);
    border: 2.5px solid var(--dark-bg);
    box-shadow: 4px 4px 0 var(--dark-bg);
    border-radius: 12px;
    margin-bottom: 24px;
    border-left: 6px solid #FFD700;
}
.cup-detail-icon {
    font-size: 4rem;
    line-height: 1;
}
.cup-detail-title {
    margin: 0 0 8px 0;
    font-size: 2.2rem;
    font-weight: 900;
    text-transform: uppercase;
    color: var(--gray-900);
    letter-spacing: 1px;
}
.cup-detail-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.cup-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.cup-badge-source.base    { background: #2c3e50; color: #fff; }
.cup-badge-source.booster { background: #8e44ad; color: #fff; }
.cup-badge-elo            { background: #FFD700; color: #3a2c00; }
.cup-badge-votes          { background: #333;    color: var(--gray-700); }

.cup-stats-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 28px;
}
.cup-stat {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 14px 12px;
    text-align: center;
    color: var(--gray-900);
}
.cup-stat-num {
    font-size: 1.6rem;
    font-weight: 900;
    color: var(--nintendo-red);
    line-height: 1.1;
}
.cup-stat-lbl {
    font-size: 0.78rem;
    text-transform: uppercase;
    color: var(--gray-500);
    margin-top: 4px;
    letter-spacing: 0.5px;
}

.cup-section-h {
    color: var(--nintendo-red);
    margin: 32px 0 4px 0;
    font-size: 1.4rem;
    font-weight: 900;
    text-transform: uppercase;
}
.cup-section-sub {
    color: var(--gray-500);
    font-size: 0.9rem;
    margin: 0 0 16px 0;
    font-style: italic;
}

.cup-tracks { display: flex; flex-direction: column; gap: 16px; }
.track-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-left: 4px solid #2EBD59;
    border-radius: 10px;
    padding: 18px 22px;
    color: var(--gray-900);
}
.track-card-head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 12px;
}
.track-card-num {
    font-size: 2rem;
    font-weight: 900;
    font-style: italic;
    color: #2EBD59;
    min-width: 40px;
}
.track-card-titles { flex: 1; min-width: 0; }
.track-card-name {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 900;
    text-transform: uppercase;
    color: var(--gray-900);
}
.track-era {
    display: inline-block;
    background: #444;
    color: var(--nintendo-red);
    font-size: 0.7rem;
    padding: 2px 7px;
    border-radius: 4px;
    vertical-align: middle;
    margin-right: 4px;
    letter-spacing: 0.5px;
}
.track-card-meta {
    margin-top: 4px;
    font-size: 0.85rem;
    color: var(--gray-500);
}
.track-card-meta strong { color: var(--gray-900); }
.track-vote-cta {
    background: #FFD700;
    color: #3a2c00;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 700;
    text-decoration: none;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}
.track-vote-cta:hover { background: #ffe34d; }

.track-musing {
    background: var(--gray-200);
    border-radius: 8px;
    padding: 14px 18px;
    border-left: 3px solid #FFD700;
}
.track-musing-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.track-musing-icon { font-size: 1.2rem; }
.track-musing-label {
    font-weight: 900;
    color: var(--nintendo-red);
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}
.track-musing-meta {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--gray-500);
    font-style: italic;
}
.track-musing-body {
    color: var(--gray-700);
    line-height: 1.55;
    font-size: 0.95rem;
}
.track-musing-body[data-empty="1"] { color: var(--gray-500); }
.track-musing-actions {
    margin-top: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn-sm { padding: 5px 12px; font-size: 0.85rem; }
.track-musing-status { font-size: 0.85rem; color: var(--gray-500); }

.champion-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 14px;
}
.champion-tile {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 12px 8px;
    text-align: center;
    color: var(--gray-900);
    text-decoration: none;
    transition: transform 0.15s;
}
.champion-tile:hover { transform: translateY(-2px); border-color: var(--nintendo-red); }
.champion-portrait { width: 60px; height: 60px; object-fit: contain; }
.champion-name {
    font-weight: 700;
    font-size: 0.85rem;
    margin-top: 4px;
    color: var(--gray-900);
}
.champion-wins {
    font-size: 0.75rem;
    color: var(--nintendo-red);
    margin-top: 2px;
}

.recent-gps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}
.recent-gp {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    padding: 12px 16px;
    color: var(--gray-900);
}
.recent-gp-id {
    font-weight: 900;
    color: var(--nintendo-red);
    font-size: 0.9rem;
    letter-spacing: 0.5px;
}
.recent-gp-date {
    font-size: 0.8rem;
    color: var(--gray-500);
    margin-top: 2px;
}
.recent-gp-champ {
    margin-top: 8px;
    font-size: 0.9rem;
}
.recent-gp-top {
    margin-top: 4px;
    font-size: 0.85rem;
    color: var(--gray-500);
}

@media (max-width: 600px) {
    .cup-stats-strip { grid-template-columns: repeat(2, 1fr); }
    .cup-detail-header { flex-direction: column; align-items: flex-start; }
    .cup-detail-icon { font-size: 3rem; }
    .cup-detail-title { font-size: 1.6rem; }
    .track-card-head { flex-wrap: wrap; }
}
</style>

<?php if ($isAdmin): ?>
<script>
(function () {
    document.querySelectorAll('.track-musing-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const card    = btn.closest('.track-card');
            const trackNm = card.dataset.trackName;
            const csrf    = card.dataset.csrf;
            const body    = card.querySelector('.track-musing-body');
            const status  = card.querySelector('.track-musing-status');
            const original = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '⏳ Generating…';
            status.hidden = false;
            status.style.color = '#aaa';
            status.textContent = 'Mac is loading his pipe…';

            try {
                const res = await fetch('/api/generate-track-musings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        track_name: trackNm,
                        csrf_token: csrf,
                    }).toString(),
                });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch (e) {
                    status.style.color = '#c0392b';
                    status.textContent = 'Bad response (HTTP ' + res.status + '): ' + text.slice(0, 200);
                    btn.disabled = false; btn.innerHTML = original;
                    return;
                }

                if (data.success) {
                    body.textContent = data.body;
                    body.dataset.empty = '0';
                    status.style.color = '#2EBD59';
                    status.textContent = 'Saved — model: ' + data.model_used;
                    btn.innerHTML = '🔄 Regenerate';
                    btn.disabled = false;
                } else {
                    status.style.color = '#c0392b';
                    status.textContent = 'Error: ' + (data.error || 'Unknown');
                    btn.disabled = false; btn.innerHTML = original;
                }
            } catch (e) {
                status.style.color = '#c0392b';
                status.textContent = 'Network error: ' + e.message;
                btn.disabled = false; btn.innerHTML = original;
            }
        });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
