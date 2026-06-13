<?php
/**
 * Commissioner's Desk — admin-only AI briefing on the current season.
 *
 * Renders fast with a Generate/Regenerate button; the button fetch()es the
 * /api/commish-digest endpoint (which does the slow Gemini call behind a
 * raised time limit). The latest digest per season is cached in
 * commish_digests and shown on load.
 *
 * Path: /cdnmk/public_html/admin/commissioner_desk.php
 * Route: /admin/commissioner-desk
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/csrf.php';
require_admin();

$currentSeason  = getCurrentSeasonNumber();
$selectedSeason = $_GET['season'] ?? $currentSeason;

// Available seasons for the picker.
$seasons = $pdo->query("SELECT DISTINCT SUBSTR(gpid,1,3) AS s FROM results WHERE gpid LIKE 's%' ORDER BY s DESC")->fetchAll(PDO::FETCH_COLUMN);

// Latest cached digest for the selected season.
$dStmt = $pdo->prepare("SELECT body, model_used, generated_at FROM commish_digests WHERE season_id = ?");
$dStmt->execute([$selectedSeason]);
$digest = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$pageTitle = "Commissioner's Desk - Admin";
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Commissioner's Desk</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🗒️ Commissioner's Desk</h1>
        <p class="page-subtitle">PRIVATE AI BRIEFING · ADMIN ONLY · NOT PUBLISHED ANYWHERE</p>
    </header>

    <form method="GET" class="cd-filter">
        <label>Season
            <select name="season" onchange="this.form.submit()">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $s === $selectedSeason ? 'selected' : '' ?>>
                        <?= strtoupper(htmlspecialchars($s)) ?><?= $s === $currentSeason ? ' (current)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <section class="cd-desk"
             data-season="<?= htmlspecialchars($selectedSeason) ?>"
             data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
        <header class="cd-head">
            <h2 class="cd-title">Season <?= strtoupper(htmlspecialchars($selectedSeason)) ?> briefing</h2>
            <?php if ($digest): ?>
                <span class="cd-meta">
                    Generated <?= date('M j, Y · H:i', strtotime($digest['generated_at'])) ?>
                    <?php if (!empty($digest['model_used'])): ?> · <?= htmlspecialchars($digest['model_used']) ?><?php endif; ?>
                </span>
            <?php endif; ?>
        </header>

        <div class="cd-body" id="cd-body">
            <?php if ($digest): ?>
                <?= nl2br(htmlspecialchars($digest['body'])) ?>
            <?php else: ?>
                <p class="cd-empty"><em>No briefing generated for this season yet. Hit the button below.</em></p>
            <?php endif; ?>
        </div>

        <div class="cd-status" id="cd-status" hidden></div>

        <div class="cd-actions">
            <button type="button" id="cd-generate-btn" class="btn btn-primary">
                <?= $digest ? '🔄 Regenerate briefing' : '🗒️ Generate briefing' ?>
            </button>
            <small class="cd-note">Pulls live standings, Elo swings, streaks &amp; attendance. ~20–60s.</small>
        </div>
    </section>
</div>

<style>
.cd-filter {
    background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: 8px;
    padding: 12px 18px; margin-bottom: 16px; color: var(--gray-600);
}
.cd-filter label { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }
.cd-filter select {
    background: var(--gray-200); color: #fff; border: 1px solid #333;
    padding: 6px 10px; border-radius: 4px; font: inherit; margin-left: 8px;
}
.cd-desk {
    background: var(--gray-50);
    border: 1px solid var(--dark-bg); border-left: 5px solid #0066cc;
    border-radius: 10px; padding: 22px 26px;
}
.cd-head {
    display: flex; justify-content: space-between; align-items: baseline;
    flex-wrap: wrap; gap: 8px; margin-bottom: 14px;
}
.cd-title { margin: 0; font-size: 1.3rem; font-weight: 900; color: #0066cc; }
.cd-meta { font-size: 0.8rem; color: var(--gray-500); font-style: italic; }
.cd-body { color: var(--gray-600); line-height: 1.65; font-size: 1rem; white-space: pre-wrap; }
.cd-empty { color: var(--gray-500); }
.cd-status { margin-top: 12px; font-size: 0.9rem; }
.cd-actions { margin-top: 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.cd-note { color: var(--gray-500); font-size: 0.78rem; }
</style>

<script>
(function () {
    const desk   = document.querySelector('.cd-desk');
    const btn    = document.getElementById('cd-generate-btn');
    const body   = document.getElementById('cd-body');
    const status = document.getElementById('cd-status');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ Briefing the desk…';
        status.hidden = false;
        status.style.color = '#888';
        status.textContent = 'Reading the season — standings, Elo, streaks…';

        try {
            const res = await fetch('/api/commish-digest', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    season_id:  desk.dataset.season,
                    csrf_token: desk.dataset.csrf,
                }).toString(),
            });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); }
            catch (e) {
                status.style.color = '#c0392b';
                status.textContent = 'Bad response (HTTP ' + res.status + '): ' + text.slice(0, 240);
                btn.disabled = false; btn.innerHTML = original;
                return;
            }

            if (data.success) {
                body.textContent = data.body;
                status.style.color = '#2EBD59';
                status.textContent = 'Done — ' + data.generated_at + ' (model: ' + data.model_used + ').';
                btn.innerHTML = '🔄 Regenerate briefing';
                btn.disabled = false;
            } else {
                status.style.color = '#c0392b';
                status.textContent = 'Error: ' + (data.error || 'Unknown error');
                btn.disabled = false; btn.innerHTML = original;
            }
        } catch (e) {
            status.style.color = '#c0392b';
            status.textContent = 'Network error: ' + e.message;
            btn.disabled = false; btn.innerHTML = original;
        }
    });
})();
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
