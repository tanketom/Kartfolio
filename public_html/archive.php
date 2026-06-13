<?php
/**
 * Broadcast Archive - Media Ecology Feed
 * Path: /cdnmk/public_html/archive.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/programs.php';

$currentSeason = getCurrentSeasonNumber();
$pageTitle = "Broadcast Archive - Kartfolio";
$deleteMsg = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $deleteMsg = 'Broadcast deleted.';
}
include __DIR__ . '/../private/templates/header.php';

// 1. Fetch Archive List
$stmt = $pdo->prepare("SELECT * FROM recap_archive ORDER BY created_at DESC");
$stmt->execute();
$recaps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Program Definitions — pull from shared catalog (programs.php)
$programs   = getProgramsCatalog();
$aiPrograms = getAIProgramsCatalog(); // for the "generate broadcast" dropdown
?>

<div class="container stats-container">
    <header class="archive-header">
        <h1>Broadcast Archive</h1>
        <p>Mushroom Kingdom Media Ecology History</p>
    </header>

    <?php if ($deleteMsg): ?>
        <div class="archive-alert-deleted"><?= htmlspecialchars($deleteMsg) ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['is_admin'])): ?>
    <section class="admin-ecology-box">
        <div class="ecology-status">
            <div class="live-indicator"></div>
            <h2>Generate New Broadcast</h2>
        </div>
        <p>Analyzes last week's races, current form rankings, and rivalry data to generate AI commentary.</p>

        <form action="api/gemini_recap.php" method="POST" class="admin-gen-form">
            <?= csrf_field() ?>
            <div class="admin-input-group">
                <label>Select Program</label>
                <select name="program" class="admin-select">
                    <?php foreach ($aiPrograms as $val => $data): ?>
                        <option value="<?= $val ?>"><?= $data['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-input-group">
                <label>Director Notes (Optional)</label>
                <textarea name="notes" placeholder="Specify focus, time range, or length (e.g. 'Tom's dominance', 'last 3 days', '500 words', 'short', 'detailed')..." class="admin-textarea"></textarea>
            </div>
            <button type="submit" class="btn-generate">Broadcast Now</button>
        </form>
    </section>

    <section class="admin-ecology-box admin-press-box">
        <div class="ecology-status">
            <div class="press-indicator"></div>
            <h2>📰 OMK Press Office</h2>
        </div>
        <p>Publish a news item directly — no AI, no generation, no Director's Notes. What you type is what gets published.</p>

        <form action="/api/press-release" method="POST" class="admin-gen-form">
            <?= csrf_field() ?>
            <div class="admin-input-group">
                <label>Headline</label>
                <input type="text" name="headline" maxlength="200" required class="admin-select" placeholder="e.g. OMK confirms s03 finals schedule">
            </div>
            <div class="admin-input-group">
                <label>Key Quote (optional — shown as the pull quote on cards)</label>
                <input type="text" name="key_quote" maxlength="300" class="admin-select" placeholder="A short attention-grabbing line">
            </div>
            <div class="admin-input-group">
                <label>Body</label>
                <textarea name="body" required class="admin-textarea" placeholder="The full press release text. Plain text — line breaks are preserved."></textarea>
            </div>
            <div class="admin-input-group">
                <label>Linked GPIDs (optional, comma-separated — e.g. s03gp14, s03gp15)</label>
                <input type="text" name="linked_gpids" class="admin-select" placeholder="s03gp14, s03gp15">
            </div>
            <button type="submit" class="btn-generate btn-publish">📢 Publish</button>
        </form>
    </section>
    <?php endif; ?>

    <div class="archive-list">
        <?php if (empty($recaps)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📻</div>
                <h2 class="empty-state-title">The Airwaves Are Silent</h2>
                <p class="empty-state-message">No broadcasts have been recorded yet. Check back after the next Grand Prix!</p>
                <?php if (isset($_SESSION['is_admin'])): ?>
                    <a href="#" onclick="window.scrollTo({top: 0, behavior: 'smooth'}); return false;" class="btn btn-primary">Generate First Broadcast</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($recaps as $r): 
                // Determine Program Identity
                $pKey = $r['program_key'] ?? 'core_team'; // Fallback for old records
                $pInfo = getProgramInfo($pKey);
            ?>
                <div class="archive-card-wrapper">
                    <a href="/view-recap/<?= $r['id'] ?>" class="archive-card">
                        <div class="archive-meta-row">
                            <div class="program-identity">
                                <img src="/assets/img/<?= $pInfo['img'] ?>" alt="Program Logo" class="program-mini-icon" onerror="this.src='/assets/img/program_default.png'">
                                <span class="program-name"><?= htmlspecialchars($pInfo['label']) ?></span>
                            </div>
                            <span class="archive-date"><?= date('M j', strtotime($r['created_at'])) ?></span>
                        </div>

                        <h3 class="archive-headline"><?= htmlspecialchars($r['headline']) ?></h3>

                        <?php if (!empty($r['key_quote'])): ?>
                            <div class="archive-quote">"<?= htmlspecialchars($r['key_quote']) ?>"</div>
                        <?php endif; ?>

                        <div class="read-more-link">Read Transcript &rarr;</div>
                    </a>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true): ?>
                    <button class="archive-delete-btn" onclick="deleteBroadcast(<?= $r['id'] ?>)" title="Delete broadcast">&times;</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    .archive-header { margin-bottom: 40px; text-align: center; padding-top: 20px; }
    .archive-header h1 { font-size: 3rem; font-style: italic; font-weight: 900; text-transform: uppercase; margin: 0; color: var(--gray-900); }
    .archive-header p { color: #888; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; font-size: 0.8rem; }

    /* Admin Box */
    .admin-ecology-box { 
        background: var(--gray-50); padding: 40px; border-radius: 12px; margin-bottom: 50px; 
        box-shadow: var(--card-shadow); border-left: 10px solid var(--nintendo-red);
    }
    .ecology-status { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
    .live-indicator { width: 12px; height: 12px; background: #2ebd59; border-radius: 50%; box-shadow: 0 0 10px #2ebd59; animation: pulse 2s infinite; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
    .ecology-status h2 { margin: 0; font-size: 1.2rem; text-transform: uppercase; font-weight: 900; }
    .admin-gen-form { display: grid; gap: 20px; }
    .admin-input-group label { display: block; font-size: 0.75rem; font-weight: 900; text-transform: uppercase; color: #888; margin-bottom: 8px; }
    .admin-select, .admin-textarea { width: 100%; padding: 12px; border: 1px solid var(--gray-200); border-radius: 6px; font-family: inherit; font-size: 0.9rem; background: var(--gray-100); }
    .admin-textarea { height: 80px; resize: vertical; }
    .btn-generate { background: #111; color: white; border: none; padding: 15px; border-radius: 6px; font-weight: 900; text-transform: uppercase; cursor: pointer; transition: background 0.2s; }
    .btn-generate:hover { background: var(--nintendo-red); }

    /* OMK Press Office (hand-written news, no AI) */
    .admin-press-box { border-left-color: #0066CC; }
    .press-indicator { width: 12px; height: 12px; background: #0066CC; border-radius: 2px; box-shadow: 0 0 8px rgba(0, 102, 204, 0.6); }
    .btn-publish { background: #0066CC; }
    .btn-publish:hover { background: #004999; }
    .admin-press-box .admin-textarea { height: 140px; }

    /* Archive Grid */
    .archive-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 30px; }
    .archive-card { 
        background: var(--gray-50); padding: 30px; border-radius: 12px; text-decoration: none; color: inherit;
        box-shadow: var(--card-shadow); transition: transform 0.2s, box-shadow 0.2s; border: 1px solid var(--gray-200);
        display: flex; flex-direction: column;
    }
    .archive-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-lg); border-color: var(--nintendo-red); }

    /* New Byline Styles */
    .archive-meta-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #f5f5f5; padding-bottom: 10px; }
    .program-identity { display: flex; align-items: center; gap: 10px; }
    .program-mini-icon { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 2px solid var(--gray-200); }
    .program-name { font-size: 0.8rem; font-weight: 800; color: var(--gray-700); text-transform: uppercase; letter-spacing: 0.5px; }
    .archive-date { font-size: 0.75rem; color: var(--gray-500); font-weight: 600; }

    .archive-headline { font-size: 1.6rem; font-weight: 900; line-height: 1.2; margin: 0 0 15px 0; color: var(--gray-900); }
    .archive-quote { font-family: 'Georgia', serif; font-style: italic; color: var(--gray-700); font-size: 1.1rem; line-height: 1.5; border-left: 4px solid var(--gray-200); padding-left: 15px; margin-bottom: 20px; flex: 1; }
    .read-more-link { font-weight: 900; color: var(--nintendo-red); font-size: 0.8rem; text-transform: uppercase; align-self: flex-start; }
    
    @media (max-width: 768px) {
        .archive-list { grid-template-columns: 1fr; }
        .archive-headline { font-size: 1.4rem; }
    }

    /* iPhone Mini & Small Devices */
    @media (max-width: 390px) {
        .archive-card {
            padding: 15px;
        }
        .archive-headline {
            font-size: 1.1rem;
            line-height: 1.3;
        }
        .archive-meta {
            font-size: 0.7rem;
        }
    }

    /* Delete button */
    .archive-card-wrapper {
        position: relative;
    }
    .archive-delete-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: none;
        background: rgba(200, 0, 0, 0.8);
        color: white;
        font-size: 1.1rem;
        font-weight: 900;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.2s;
        z-index: 10;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .archive-card-wrapper:hover .archive-delete-btn {
        opacity: 1;
    }
    .archive-delete-btn:hover {
        background: var(--nintendo-red);
        transform: scale(1.1);
    }

    .archive-alert-deleted {
        background: #d4edda;
        color: #155724;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: 700;
        margin-bottom: 20px;
        text-align: center;
    }
</style>

<script>
function deleteBroadcast(id) {
    if (typeof showConfirm === 'function') {
        showConfirm({
            icon: '🗑️',
            title: 'Delete Broadcast?',
            message: 'This will permanently remove this broadcast from the archive.'
        }).then(ok => {
            if (ok) window.location.href = '/api/delete_recap.php?id=' + id;
        });
    } else if (confirm('Delete this broadcast? This cannot be undone.')) {
        window.location.href = '/api/delete_recap.php?id=' + id;
    }
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>