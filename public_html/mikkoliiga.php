<?php
/**
 * Mikkoliiga — casual sub-league standings page.
 *
 * Shows the full Mikkoliiga internal-scoring standings for the current
 * season (or a season passed via ?season=sNN). Internal scoring uses the
 * canonical Mario Kart 12-position scale; each member's season total is
 * the sum of their best MIKKOLIIGA_BEST_X internal scores.
 *
 * Path: /cdnmk/public_html/mikkoliiga.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/settings.php';
requireModule($pdo, 'mikkoliiga');   // Admin → Modules
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();

// Elo for tiebreak.
$eloByName = [];
try {
    $eloData = calculateAllELORatings($pdo);
    $eloByName = $eloData['final'] ?? $eloData['ratings'] ?? [];
} catch (Throwable $e) {
    // Non-fatal — fall back to name tiebreak.
}

$standings = getMikkoliigaStandings($pdo, $seasonId, $eloByName);

// Sister-page data: total Mikkoliiga members (incl. zero-GP), GPs in season.
$memberCount = (int)$pdo->query("SELECT COUNT(*) FROM racers WHERE in_mikkoliiga = 1")->fetchColumn();
$gpStmt = $pdo->prepare("SELECT COUNT(DISTINCT gpid) FROM results WHERE gpid LIKE ?");
$gpStmt->execute([$seasonId . '%']);
$seasonGPCount = (int)$gpStmt->fetchColumn();

$pageTitle = "Mikkoliiga — Casual Sub-League";
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Mikkoliiga</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🌟 Mikkoliiga</h1>
        <p class="page-subtitle">SEASON <?= strtoupper($seasonId) ?> · CASUAL SUB-LEAGUE · BEST <?= MIKKOLIIGA_BEST_X ?> GPs COUNTED</p>
    </header>

    <div class="mikko-intro">
        <p>
            Mikkoliiga members race in the same Grand Prix as the main league, but score
            <strong>internally</strong>: in each GP, only Mikkoliiga members are considered,
            re-ranked among themselves by their actual GP points, and awarded the canonical
            Mario Kart scale — <strong>15/12/10/9/8/7/6/5/4/3/2/1</strong>. The season total is
            the sum of each member's <strong>best <?= MIKKOLIIGA_BEST_X ?></strong> internal scores.
        </p>
        <p class="mikko-qualifier">
            ⚖️ <strong>The qualifier:</strong> a GP only counts toward Mikkoliiga if
            <strong>two or more</strong> members raced it. It's a head-to-head among members —
            being the only Mikkoligan in a race is no contest, so it scores nothing. The
            "GPs counted" figures below reflect these <em>qualifying</em> races, not every
            night a member showed up.
        </p>
        <p class="mikko-intro-meta">
            <strong><?= $memberCount ?></strong> members · <strong><?= $seasonGPCount ?></strong> season GPs so far
            <?php if (!empty($eloByName)): ?> · ties broken by Elo<?php endif; ?>
        </p>
    </div>

    <?php if (empty($standings)): ?>
        <div class="mikko-empty">
            <div style="font-size:3rem;">🌟</div>
            <h3>No Mikkoliiga members yet.</h3>
            <p>An admin can mark racers as Mikkoliiga members on the <a href="/admin/racers">Racer Management</a> page.</p>
        </div>
    <?php else: ?>

    <div class="mikko-standings">
        <?php foreach ($standings as $idx => $m):
            $rank = $idx + 1;
            $rankClass = $rank === 1 ? 'mikko-gold' : ($rank === 2 ? 'mikko-silver' : ($rank === 3 ? 'mikko-bronze' : ''));
            $mainChar = getMostUsedCharacter($pdo, $m['id'], $seasonId);
            $elo = $eloByName[$m['name']] ?? null;
        ?>
        <a href="/racer/<?= $m['id'] ?>" class="mikko-row <?= $rankClass ?>">
            <div class="mikko-rank">#<?= $rank ?></div>
            <img src="/assets/img/<?= htmlspecialchars($mainChar) ?>.png" class="mikko-row-portrait" onerror="this.src='/assets/img/Mii.png'">
            <div class="mikko-row-info">
                <div class="mikko-row-name"><?= htmlspecialchars($m['name']) ?></div>
                <?php if ($m['nickname']): ?>
                    <div class="mikko-row-nick"><?= htmlspecialchars($m['nickname']) ?></div>
                <?php endif; ?>
                <div class="mikko-row-meta">
                    <?= $m['gps_counted'] ?> of best <?= MIKKOLIIGA_BEST_X ?> counted
                    <?php if ($m['total_gps'] > $m['gps_counted']): ?>
                        · <?= $m['total_gps'] ?> total GPs raced
                    <?php endif; ?>
                    <?php if ($elo !== null): ?>
                        · Elo <?= (int)$elo ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mikko-row-score"><?= $m['score'] ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<style>
.mikko-intro {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-left: 4px solid #FFD700;
    border-radius: 8px;
    padding: 18px 24px;
    margin-bottom: 24px;
    font-size: 0.95rem;
    line-height: 1.5;
    color: var(--gray-600);
}
.mikko-intro p { margin: 0 0 8px 0; }
.mikko-intro p:last-child { margin-bottom: 0; }
.mikko-intro strong { color: var(--nintendo-red); }
.mikko-qualifier {
    background: #fff6dc;
    border-left: 3px solid #FFD700;
    border-radius: 6px;
    padding: 10px 14px;
    font-size: 0.9rem;
    color: var(--gray-600);
}
.mikko-qualifier strong { color: #9a7b00; }
.mikko-intro-meta {
    font-size: 0.85rem;
    color: var(--gray-500);
    font-style: italic;
}

.mikko-standings {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.mikko-row {
    display: flex;
    align-items: center;
    gap: 18px;
    background: var(--gray-50);
    color: var(--gray-900);
    padding: 16px 22px;
    border: 2.5px solid var(--dark-bg);
    border-radius: 12px;
    text-decoration: none;
    box-shadow: 4px 4px 0 var(--dark-bg);
    transition: transform 0.15s ease;
}
.mikko-row:hover { transform: translateX(4px); }
.mikko-row.mikko-gold   { background: #fff6dc; }
.mikko-row.mikko-silver { background: #f4f4f2; }
.mikko-row.mikko-bronze { background: #fdf0e8; }
.mikko-rank {
    font-size: 1.8rem;
    font-weight: 900;
    font-style: italic;
    color: var(--gray-600);
    min-width: 60px;
}
.mikko-row.mikko-gold .mikko-rank   { color: #FFD700; }
.mikko-row.mikko-silver .mikko-rank { color: var(--gray-500); }
.mikko-row.mikko-bronze .mikko-rank { color: #CD7F32; }
.mikko-row-portrait {
    width: 64px;
    height: 64px;
    object-fit: contain;
}
.mikko-row-info { flex: 1; min-width: 0; }
.mikko-row-name {
    font-size: 1.3rem;
    font-weight: 900;
    text-transform: uppercase;
    line-height: 1.1;
}
.mikko-row-nick {
    font-size: 0.85rem;
    color: var(--gray-600);
    font-style: italic;
    margin-top: 2px;
}
.mikko-row-meta {
    font-size: 0.8rem;
    color: var(--gray-500);
    margin-top: 4px;
}
.mikko-row-score {
    font-size: 2rem;
    font-weight: 900;
    color: var(--nintendo-red);
    min-width: 80px;
    text-align: right;
}

.mikko-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--gray-500);
}
.mikko-empty h3 { color: var(--gray-600); margin: 12px 0; }
.mikko-empty a { color: #FFD700; }
</style>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
