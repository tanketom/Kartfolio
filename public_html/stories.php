<?php
require_once __DIR__ . '/../private/includes/session.php';
/**
 * MONSTER HUNT Chronicles — GP Story Archive
 * Path: /cdnmk/public_html/stories.php
 *
 * Lists every GP in a MONSTER HUNT season with an AI-generated
 * chronicle entry. Admins can generate or regenerate each story.
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

// ── Ensure gp_stories table exists (self-migrating) ───────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS gp_stories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        gpid TEXT NOT NULL UNIQUE,
        season_id TEXT NOT NULL,
        story_text TEXT NOT NULL,
        story_data TEXT DEFAULT NULL,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// ── Admin check ────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) kartfolioSessionStart();
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

if ($isAdmin) {
    require_once __DIR__ . '/../private/includes/csrf.php';
    $csrfToken = csrf_token();
}

// ── Fetch all MONSTER HUNT seasons ─────────────────────────────────────────
$mhSeasons = $pdo->query("
    SELECT season_id, season_name, status
    FROM season_meta
    WHERE scoring_system = 'monster_hunt'
    ORDER BY season_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Season selection ───────────────────────────────────────────────────────
$selectedSeason = $_GET['season'] ?? null;

// Default: first active MH season, then first MH season at all
if (!$selectedSeason) {
    foreach ($mhSeasons as $s) {
        if ($s['status'] === 'active') { $selectedSeason = $s['season_id']; break; }
    }
    if (!$selectedSeason && !empty($mhSeasons)) {
        $selectedSeason = $mhSeasons[0]['season_id'];
    }
}

// Validate
$currentSeasonMeta = null;
if ($selectedSeason) {
    foreach ($mhSeasons as $s) {
        if ($s['season_id'] === $selectedSeason) { $currentSeasonMeta = $s; break; }
    }
}

// ── Data for selected season ───────────────────────────────────────────────
$gpList   = [];
$stories  = [];
$gpMeta   = []; // gpid => {cup_name, race_date, monster_name, cr_tier, epithet}

if ($currentSeasonMeta) {
    // All GPs in this season (chronological)
    $gpStmt = $pdo->prepare("
        SELECT gpid, MIN(race_date) AS race_date, MAX(cup_name) AS cup_name
        FROM results
        WHERE gpid LIKE ?
        GROUP BY gpid
        ORDER BY race_date ASC, gpid ASC
    ");
    $gpStmt->execute([$selectedSeason . '%']);
    $gpList = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

    // Existing stories
    if (!empty($gpList)) {
        $gpids = array_column($gpList, 'gpid');
        $placeholders = implode(',', array_fill(0, count($gpids), '?'));
        $storiesStmt = $pdo->prepare("
            SELECT gpid, story_text, story_data, generated_at
            FROM gp_stories
            WHERE gpid IN ($placeholders)
        ");
        $storiesStmt->execute($gpids);
        foreach ($storiesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stories[$row['gpid']] = $row;
        }
    }

    // Monster metadata from Elo changelog (for display)
    if (!empty($gpList)) {
        try {
            $changelog = getMonsterHuntEloChangelog($pdo);

            $cupLocations = [
                'Mushroom'    => 'Mushroom Kingdom',
                'Flower'      => 'Flower Fields',
                'Star'        => 'Star Heights',
                'Special'     => 'Special Circuits',
                'Shell'       => 'Shell Coast',
                'Banana'      => 'Banana Groves',
                'Leaf'        => 'Leaf Forest',
                'Lightning'   => 'Lightning Circuits',
                'Egg'         => 'Egg Canyon',
                'Triforce'    => 'Triforce Temple',
                'Crossing'    => 'Crossing Village',
                'Bell'        => 'Bell Spires',
                'Golden Dash' => 'Golden Dash Arena',
                'Lucky Cat'   => 'Lucky Cat Shrine',
                'Turnip'      => 'Turnip Fields',
                'Propeller'   => 'Propeller Heights',
                'Rock'        => 'Rock Canyon',
                'Moon'        => 'Moon Circuit',
                'Fruit'       => 'Fruit Forest',
                'Boomerang'   => 'Boomerang Ruins',
                'Feather'     => 'Feather Peaks',
                'Cherry'      => 'Cherry Blossom Festival',
                'Acorn'       => 'Acorn Forest',
                'Spiny'       => 'Spiny Wastes',
            ];

            foreach ($gpList as $gp) {
                $gpid   = $gp['gpid'];
                $gpData = $changelog[$gpid] ?? [];
                if (empty($gpData)) {
                    $gpMeta[$gpid] = null;
                    continue;
                }

                // Find Monster
                [$monsterName, $monsterElo] = pickMonster($gpid, $gpData, $pdo);

                // CR
                $advElos = [];
                foreach ($gpData as $n => $d) {
                    if ($n !== $monsterName) $advElos[] = $d['old_elo'];
                }
                $avgAdv  = count($advElos) > 0 ? array_sum($advElos) / count($advElos) : $monsterElo;
                $eloGap  = max(0, $monsterElo - $avgAdv);

                if      ($eloGap < 50)  { $crTier = 1; $epithet = 'the Rival'; $crIcon = '⚔️'; }
                elseif  ($eloGap < 150) { $crTier = 2; $epithet = 'the Beast'; $crIcon = '🐗'; }
                elseif  ($eloGap < 300) { $crTier = 3; $epithet = 'the Fearsome One'; $crIcon = '🐉'; }
                else                    { $crTier = 4; $epithet = 'the Dragon'; $crIcon = '🔥'; }

                $cupName  = $gp['cup_name'] ?? '';
                $location = $cupLocations[$cupName] ?? str_replace(' Cup', '', $cupName);

                $gpMeta[$gpid] = [
                    'monster_name' => $monsterName,
                    'monster_elo'  => $monsterElo,
                    'epithet'      => $epithet,
                    'cr_tier'      => $crTier,
                    'cr_icon'      => $crIcon,
                    'location'     => $location,
                    'elo_gap'      => round($eloGap),
                ];
            }
        } catch (Exception $e) {
            // Elo data unavailable — degrade gracefully
        }
    }
}

// ── Page meta ──────────────────────────────────────────────────────────────
$seasonLabel = $currentSeasonMeta
    ? ($currentSeasonMeta['season_name'] ?: strtoupper($currentSeasonMeta['season_id']))
    : 'MONSTER HUNT';

$pageTitle = "MONSTER HUNT Chronicles — {$seasonLabel} · Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';

include __DIR__ . '/../private/templates/header.php';
?>

<div class="container">

    <!-- ── Page Header ───────────────────────────────────────────────── -->
    <div class="page-header" style="text-align:center; margin-bottom: 2rem;">
        <h1 style="font-size:2rem; margin-bottom:0.25rem;">👹 MONSTER HUNT Chronicles</h1>
        <p style="color:var(--text-muted); margin:0;">
            A season told GP by GP — through the eyes of a medieval scribe.
        </p>
    </div>

    <?php if (empty($mhSeasons)): ?>
    <!-- ── No MONSTER HUNT seasons ───────────────────────────────────── -->
    <div class="card" style="text-align:center; padding:3rem;">
        <p style="font-size:1.5rem; margin-bottom:0.5rem;">🗺️ No MONSTER HUNT seasons found.</p>
        <p style="color:var(--text-muted);">
            Set a season's scoring system to <strong>MONSTER HUNT</strong> in the
            <?php if ($isAdmin): ?>
                <a href="/admin/seasons">Season Admin</a>
            <?php else: ?>
                admin panel
            <?php endif; ?>
            to begin the chronicles.
        </p>
    </div>
    <?php else: ?>

    <!-- ── Season Selector ───────────────────────────────────────────── -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:2rem;">
        <div>
            <label for="seasonSelect" style="font-weight:600; margin-right:0.5rem;">Season:</label>
            <select id="seasonSelect" onchange="window.location.href='/stories?season='+this.value"
                    style="padding:0.4rem 0.75rem; border-radius:6px; border:1px solid var(--border); background:var(--card-bg); color:var(--text); font-size:0.95rem;">
                <?php foreach ($mhSeasons as $s):
                    $label = $s['season_name'] ?: strtoupper($s['season_id']);
                    $badge = $s['status'] === 'active' ? ' ★' : '';
                    $sel   = ($s['season_id'] === $selectedSeason) ? ' selected' : '';
                ?>
                    <option value="<?= htmlspecialchars($s['season_id']) ?>"<?= $sel ?>>
                        <?= htmlspecialchars($label . $badge) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($isAdmin && !empty($gpList)): ?>
        <div style="display:flex; gap:0.5rem;">
            <button id="btnGenerateAll"
                    onclick="generateAll()"
                    style="padding:0.45rem 1rem; border-radius:6px; background:var(--nintendo-red); color:#fff; border:none; cursor:pointer; font-size:0.9rem; font-weight:600;">
                ✨ Generate Missing Stories
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Story Count ───────────────────────────────────────────────── -->
    <?php
    $totalGPs      = count($gpList);
    $totalStories  = count($stories);
    $pendingGPs    = $totalGPs - $totalStories;
    ?>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:1.5rem; text-align:center;">
        <?= $totalStories ?> of <?= $totalGPs ?> GPs chronicled
        <?php if ($pendingGPs > 0 && $isAdmin): ?>
            · <em><?= $pendingGPs ?> awaiting generation</em>
        <?php endif; ?>
    </p>

    <!-- ── GP Cards ──────────────────────────────────────────────────── -->
    <?php if (empty($gpList)): ?>
    <div class="card" style="text-align:center; padding:2rem;">
        <p style="color:var(--text-muted);">No GPs recorded for this season yet.</p>
    </div>
    <?php else: ?>

    <div class="stories-grid">
    <?php foreach ($gpList as $gp):
        $gpid     = $gp['gpid'];
        $cupName  = $gp['cup_name'] ?? '—';
        $raceDateRaw = $gp['race_date'] ?? '';
        $dateFmt  = $raceDateRaw ? date('j M Y', strtotime($raceDateRaw)) : '';
        $meta     = $gpMeta[$gpid]   ?? null;
        $story    = $stories[$gpid]  ?? null;
        $hasStory = $story !== null;

        // GP number display (e.g. s01g04 → GP 4)
        preg_match('/g(\d+)$/i', $gpid, $gpMatch);
        $gpNum = isset($gpMatch[1]) ? (int)$gpMatch[1] : '?';

        $crTier   = $meta['cr_tier']   ?? 0;
        $crIcon   = $meta['cr_icon']   ?? '⚔️';
        $epithet  = $meta['epithet']   ?? '';
        $monName  = $meta['monster_name'] ?? '';
        $location = $meta['location']  ?? $cupName;

        // CR badge class
        $crClasses = ['', 'cr-1', 'cr-2', 'cr-3', 'cr-4'];
        $crClass   = $crClasses[$crTier] ?? '';

        $genAt = $hasStory ? date('j M Y, H:i', strtotime($story['generated_at'])) : null;
    ?>
    <article class="story-card <?= $hasStory ? 'has-story' : 'no-story' ?>"
             id="card-<?= htmlspecialchars($gpid) ?>"
             data-gpid="<?= htmlspecialchars($gpid) ?>"
             data-season="<?= htmlspecialchars($selectedSeason) ?>">

        <!-- Card Header -->
        <header class="story-card-header">
            <div class="story-card-meta">
                <span class="story-gp-badge">GP <?= $gpNum ?></span>
                <span class="story-cup"><?= htmlspecialchars($cupName) ?></span>
                <?php if ($dateFmt): ?>
                <span class="story-date"><?= htmlspecialchars($dateFmt) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($meta): ?>
            <div class="story-monster-badge <?= $crClass ?>">
                <span class="cr-icon"><?= $crIcon ?></span>
                <span class="monster-name"><?= htmlspecialchars($monName) ?></span>
                <span class="monster-epithet"><?= htmlspecialchars($epithet) ?></span>
                <span class="cr-label">CR <?= $crTier ?></span>
            </div>
            <?php endif; ?>
        </header>

        <!-- Story Body -->
        <div class="story-body" id="body-<?= htmlspecialchars($gpid) ?>">
            <?php if ($hasStory): ?>
                <?php
                $storyHtml = htmlspecialchars($story['story_text']);
                $storyHtml = preg_replace('/\*\*(.*?)\*\*/', '<strong class="highlight-name">$1</strong>', $storyHtml);
                // Style the bardic signature line (starts with —)
                $storyHtml = preg_replace('/(\n— .+)$/', '<span class="story-signature">$1</span>', $storyHtml);
                $storyHtml = nl2br($storyHtml);
                ?>
                <p class="story-text"><?= $storyHtml ?></p>
                <?php
                // Decode story_data for extra badge (party wipe, monster won)
                $sd = $story['story_data'] ? json_decode($story['story_data'], true) : null;
                if ($sd): ?>
                <div class="story-badges">
                    <?php if (!empty($sd['full_slay']) || !empty($sd['party_wipe'])): ?>
                        <span class="story-badge badge-wipe">🎉 Full Slay!</span>
                    <?php elseif (!empty($sd['tpk']) || !empty($sd['monster_won'])): ?>
                        <span class="story-badge badge-monster">💀 TPK</span>
                    <?php endif; ?>
                    <?php if (!empty($sd['slayers'])): ?>
                        <span class="story-badge badge-slayers">🗡️ <?= htmlspecialchars(implode(', ', $sd['slayers'])) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($genAt): ?>
                <p class="story-generated-at">Chronicled <?= htmlspecialchars($genAt) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <div class="story-placeholder">
                    <span class="placeholder-icon">📜</span>
                    <p>This chapter has not yet been written.</p>
                    <?php if (!$isAdmin): ?>
                    <p style="font-size:0.85rem; color:var(--text-muted);">The chronicler's quill rests.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Admin Controls -->
        <?php if ($isAdmin): ?>
        <footer class="story-card-footer">
            <button class="btn-generate"
                    onclick="generateStory('<?= htmlspecialchars($gpid) ?>', '<?= htmlspecialchars($selectedSeason) ?>')"
                    id="btn-<?= htmlspecialchars($gpid) ?>">
                <?= $hasStory ? '🔄 Regenerate' : '✨ Generate Story' ?>
            </button>
        </footer>
        <?php endif; ?>

    </article>
    <?php endforeach; ?>
    </div>

    <?php endif; // empty gpList ?>
    <?php endif; // empty mhSeasons ?>

</div><!-- /.container -->

<?php include __DIR__ . '/../private/templates/footer.php'; ?>

<!-- ── Story Page Styles ──────────────────────────────────────────────────── -->
<style>
.stories-grid {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    max-width: 780px;
    margin: 0 auto;
}

/* ── Story Card ── */
.story-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    transition: box-shadow 0.2s ease;
}
.story-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
}
.story-card.has-story {
    border-left: 4px solid var(--nintendo-red);
}
.story-card.no-story {
    opacity: 0.75;
}

/* ── Card Header ── */
.story-card-header {
    padding: 1rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.story-card-meta {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.story-gp-badge {
    background: var(--nintendo-red);
    color: #fff;
    font-weight: 700;
    font-size: 0.78rem;
    padding: 0.2rem 0.55rem;
    border-radius: 4px;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}
.story-cup {
    font-weight: 600;
    font-size: 0.95rem;
}
.story-date {
    font-size: 0.82rem;
    color: var(--text-muted);
}

/* ── Monster Badge ── */
.story-monster-badge {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.82rem;
    background: rgba(0,0,0,0.06);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 0.2rem 0.65rem;
}
.cr-icon { font-size: 1rem; }
.monster-name { font-weight: 700; }
.monster-epithet { color: var(--text-muted); font-style: italic; }
.cr-label {
    font-size: 0.7rem;
    font-weight: 700;
    background: rgba(0,0,0,0.1);
    padding: 0.1rem 0.35rem;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* CR tier colouring */
.cr-1 .cr-label { background: #dbeafe; color: #1e40af; }
.cr-2 .cr-label { background: #dcfce7; color: #166534; }
.cr-3 .cr-label { background: #fef9c3; color: #92400e; }
.cr-4 .cr-label { background: #fee2e2; color: #991b1b; }

/* ── Story Body ── */
.story-body {
    padding: 1.25rem 1.5rem;
}
.story-text {
    font-size: 1.0rem;
    line-height: 1.75;
    color: var(--text);
    font-style: italic;
    margin: 0 0 0.75rem;
}
.story-generated-at {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin: 0.5rem 0 0;
    text-align: right;
}
.story-signature {
    display: block;
    margin-top: 0.6rem;
    font-size: 0.85rem;
    color: var(--text-muted);
    font-style: normal;
    letter-spacing: 0.01em;
}

/* ── Story Badges ── */
.story-badges {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 0.5rem;
}
.story-badge {
    font-size: 0.78rem;
    padding: 0.2rem 0.6rem;
    border-radius: 12px;
    font-weight: 600;
}
.badge-wipe    { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
.badge-monster { background: #fff1f2; color: #881337; border: 1px solid #fda4af; }
.badge-slayers { background: #fefce8; color: #713f12; border: 1px solid #fde047; }

/* ── Placeholder ── */
.story-placeholder {
    text-align: center;
    padding: 1.5rem 1rem;
    color: var(--text-muted);
}
.placeholder-icon {
    font-size: 2rem;
    display: block;
    margin-bottom: 0.5rem;
}
.story-placeholder p { margin: 0.25rem 0; }

/* ── Admin Footer ── */
.story-card-footer {
    padding: 0.65rem 1.25rem;
    border-top: 1px solid var(--border);
    background: rgba(0,0,0,0.03);
    display: flex;
    justify-content: flex-end;
}
.btn-generate {
    padding: 0.4rem 1rem;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--card-bg);
    color: var(--text);
    cursor: pointer;
    font-size: 0.875rem;
    font-weight: 600;
    transition: background 0.15s, color 0.15s;
}
.btn-generate:hover {
    background: var(--nintendo-red);
    color: #fff;
    border-color: var(--nintendo-red);
}
.btn-generate:disabled {
    opacity: 0.5;
    cursor: wait;
}
</style>

<?php if ($isAdmin): ?>
<!-- ── Admin JS ───────────────────────────────────────────────────────────── -->
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

async function generateStory(gpid, seasonId) {
    const btn   = document.getElementById('btn-' + gpid);
    const body  = document.getElementById('body-' + gpid);
    const card  = document.getElementById('card-' + gpid);

    btn.disabled = true;
    btn.textContent = '⏳ Generating…';
    body.innerHTML = '<div class="story-placeholder"><span class="placeholder-icon">⚗️</span><p>The chronicler is writing…</p></div>';

    try {
        const res = await fetch('/api/gp-story', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
            },
            body: JSON.stringify({ gpid, season_id: seasonId }),
        });
        const data = await res.json();

        if (data.error) {
            body.innerHTML = `<div class="story-placeholder"><span class="placeholder-icon">❌</span><p><strong>Error:</strong> ${escHtml(data.error)}</p></div>`;
            btn.disabled = false;
            btn.textContent = '🔄 Retry';
            return;
        }

        // Build badges
        let badges = '';
        if (data.full_slay)        badges += '<span class="story-badge badge-wipe">🎉 Full Slay!</span>';
        if (data.slayers && data.slayers.length > 0 && !data.full_slay) {
            badges += `<span class="story-badge badge-slayers">🗡️ ${escHtml(data.slayers.join(', '))}</span>`;
        }

        const now = new Date().toLocaleString('en-GB', {day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});

        body.innerHTML = `
            <p class="story-text">${formatStory(data.story_text)}</p>
            ${badges ? '<div class="story-badges">' + badges + '</div>' : ''}
            <p class="story-generated-at">Chronicled ${now}</p>
        `;

        card.classList.remove('no-story');
        card.classList.add('has-story');
        btn.textContent = '🔄 Regenerate';
        btn.disabled = false;

    } catch (err) {
        body.innerHTML = `<div class="story-placeholder"><span class="placeholder-icon">❌</span><p>Network error. Please try again.</p></div>`;
        btn.disabled = false;
        btn.textContent = '🔄 Retry';
    }
}

async function generateAll() {
    const allCards = document.querySelectorAll('.story-card.no-story');
    if (allCards.length === 0) {
        alert('All stories already generated!');
        return;
    }

    const btn = document.getElementById('btnGenerateAll');
    btn.disabled = true;
    btn.textContent = `⏳ Generating 0/${allCards.length}…`;

    let done = 0;
    for (const card of allCards) {
        const gpid   = card.dataset.gpid;
        const season = card.dataset.season;
        await generateStory(gpid, season);
        done++;
        btn.textContent = `⏳ Generating ${done}/${allCards.length}…`;
        // Small delay between requests to avoid hammering the API
        await new Promise(r => setTimeout(r, 800));
    }

    btn.textContent = '✅ All Done!';
    setTimeout(() => {
        btn.disabled = false;
        btn.textContent = '✨ Generate Missing Stories';
    }, 3000);
}

function formatStory(str) {
    let s = escHtml(str);
    // **name** → bold
    s = s.replace(/\*\*(.*?)\*\*/g, '<strong class="highlight-name">$1</strong>');
    // bardic signature line (starts with —)
    s = s.replace(/(\n— .+)$/, '<span class="story-signature">$1</span>');
    // newlines → <br>
    s = s.replace(/\n/g, '<br>');
    return s;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}
</script>
<?php endif; ?>
