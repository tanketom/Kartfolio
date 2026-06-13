<?php
/**
 * Season Awards - Fully Automatic Generation (Display Page)
 *
 * Generation runs out-of-band via /api/generate_season_awards.php (AJAX).
 * This page only renders existing awards from the DB and triggers the API.
 *
 * Path: /cdnmk/public_html/admin/season_awards.php
 */
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/season_awards_logic.php';

require_admin();

$seasonId        = $_GET['season'] ?? getCurrentSeasonNumber();
$fixedCategories = seasonAwardFixedCategories();

$racers     = seasonAwardRacers($pdo, $seasonId);
$racerNames = array_column($racers, 'name');
$racerCount = count($racers);

// We still need per-racer stats for the portraits below (which character to show).
$racerStats = $racerCount > 0 ? gatherRacerStats($pdo, $racers, $seasonId) : [];

// Fetch saved awards from DB (this is now the only state we display).
$awardsStmt = $pdo->prepare("SELECT * FROM season_awards WHERE season_id = ? ORDER BY id ASC");
$awardsStmt->execute([$seasonId]);
$existingAwards = $awardsStmt->fetchAll(PDO::FETCH_ASSOC);

// Per-session reason cache populated by the AJAX endpoint via redirect-back.
$awardDetails = $_SESSION['award_details'] ?? ['fixed' => [], 'ai' => []];

$displayAwards = [];
foreach ($existingAwards as $award) {
    $cat     = $award['award_category'];
    $isFixed = isset($fixedCategories[$cat]);
    $icon    = '🌟';
    $reason  = '';

    if ($isFixed) {
        $icon   = $fixedCategories[$cat]['icon'];
        $reason = $awardDetails['fixed'][$cat]['reason'] ?? '';
    } else {
        foreach ($awardDetails['ai'] as $ga) {
            if (($ga['category'] ?? '') === $cat) {
                $icon   = $ga['icon']   ?? '🌟';
                $reason = $ga['reason'] ?? '';
                break;
            }
        }
    }

    $displayAwards[] = [
        'category' => $cat,
        'winner'   => $award['winner_name'],
        'icon'     => $icon,
        'reason'   => $reason,
        'is_fixed' => $isFixed,
    ];
}

$awardedNames  = array_column($displayAwards, 'winner');
$uniqueAwarded = array_unique(array_filter($awardedNames, fn($n) => !str_contains($n, ' vs ')));
$missingRacers = array_diff($racerNames, $uniqueAwarded);

$pageTitle = "Season Awards - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <div class="admin-awards-header">
        <h1 class="admin-awards-title">Season Awards</h1>
        <p class="admin-awards-subtitle">
            Season <?= strtoupper($seasonId) ?> &mdash; <?= $racerCount ?> racers
        </p>
    </div>

    <div id="awards-status" class="awards-status" style="display:none;"></div>

    <!-- Generate Button -->
    <div class="awards-generate-form">
        <button type="button" id="awards-generate-btn" class="btn btn-primary awards-generate-btn"
                data-season="<?= htmlspecialchars($seasonId) ?>"
                data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
            🤖 <?= empty($existingAwards) ? 'Generate All Awards Automatically' : 'Regenerate All Awards' ?>
        </button>
        <p class="awards-generate-hint">
            Automatically determines 6 core awards from stats, then generates <?= max(0, $racerCount - 6) ?> personalized awards with AI — one per racer.
            <br><em>Generation usually takes 30–90 seconds. If Gemini is overloaded the system retries with backoff and falls back to older flash models, so it can take longer.</em>
        </p>
    </div>

    <!-- Awards Display -->
    <?php if (!empty($displayAwards)): ?>

        <?php if (!empty($missingRacers)): ?>
        <div class="admin-alert-error-awards awards-missing-alert">
            ⚠️ Missing awards for: <?= htmlspecialchars(implode(', ', $missingRacers)) ?>
        </div>
        <?php endif; ?>

        <div class="awards-section">
            <h3 class="awards-section-title">Season Awards</h3>
            <div class="awards-ceremony-grid">
                <?php foreach ($displayAwards as $award):
                    $charImg = 'Mii';
                    foreach ($racerStats as $rs) {
                        if ($rs['name'] === $award['winner']) { $charImg = $rs['main_char']; break; }
                    }
                    $cardClass = $award['is_fixed'] ? 'award-card-core' : 'award-card-ai';
                ?>
                <div class="award-card <?= $cardClass ?>">
                    <div class="award-card-icon"><?= $award['icon'] ?></div>
                    <h4 class="award-card-category"><?= htmlspecialchars($award['category']) ?></h4>
                    <div class="award-card-portrait">
                        <img src="/assets/img/<?= htmlspecialchars($charImg) ?>.png" onerror="this.src='/assets/img/Mii.png'" alt="">
                    </div>
                    <div class="award-card-winner"><?= htmlspecialchars($award['winner']) ?></div>
                    <?php if ($award['reason']): ?>
                    <p class="award-card-reason"><?= htmlspecialchars($award['reason']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="admin-awards-form-footer">
            <a href="/view-season-report?season=<?= $seasonId ?>" class="btn-secondary admin-btn-back">
                ← Back to Report
            </a>
        </div>

    <?php else: ?>
        <div class="awards-empty-state">
            <div class="awards-empty-icon">🏅</div>
            <h3>No Awards Yet</h3>
            <p>Click the button above to automatically generate all season awards.</p>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const btn    = document.getElementById('awards-generate-btn');
    const status = document.getElementById('awards-status');
    if (!btn) return;

    btn.addEventListener('click', async function () {
        const season = btn.dataset.season;
        const csrf   = btn.dataset.csrf;

        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML  = '🔄 Generating… (30–90 seconds)';

        status.style.display = 'block';
        status.style.color   = '#888';
        status.textContent   = 'Crunching stats and calling Gemini — please wait, don’t close the tab.';

        try {
            const body = new URLSearchParams({ season: season, csrf_token: csrf });
            const res  = await fetch('/api/generate_season_awards.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
            });

            const text = await res.text();
            let data;
            try { data = JSON.parse(text); }
            catch (e) {
                status.style.color = 'var(--nintendo-red)';
                status.textContent = 'Bad response from server (HTTP ' + res.status + '): ' + text.slice(0, 300);
                btn.disabled = false;
                btn.innerHTML = original;
                return;
            }

            if (data.success) {
                status.style.color = '#2EBD59';
                status.textContent = 'Awards generated. Reloading…';
                setTimeout(() => window.location.reload(), 700);
            } else {
                status.style.color = 'var(--nintendo-red)';
                status.textContent = 'Error: ' + (data.error || 'Unknown error');
                btn.disabled = false;
                btn.innerHTML = original;
            }
        } catch (e) {
            status.style.color = 'var(--nintendo-red)';
            status.textContent = 'Network error: ' + e.message;
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
