<?php
/**
 * Trading Cards Collection Page
 * Displays all racer cards with season filter and PDF export
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/badges.php';
require_once __DIR__ . '/../private/includes/card_rendering.php';

$pageTitle = 'Trading Cards';

// Get all seasons from gpid patterns (e.g., s01gp01, s02gp01)
$seasonsStmt = $pdo->query("SELECT DISTINCT SUBSTR(gpid, 1, 3) as season FROM results ORDER BY season DESC");
$seasons = $seasonsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get selected season (default to most recent)
$selectedSeason = $_GET['season'] ?? ($seasons[0] ?? 's01');
$currentSeason = $selectedSeason;

// Get season metadata
$seasonMetaStmt = $pdo->prepare("SELECT season_name, academic_year FROM season_meta WHERE season_id = ?");
$seasonMetaStmt->execute([$selectedSeason]);
$seasonMeta = $seasonMetaStmt->fetch(PDO::FETCH_ASSOC);

// Format season display
$seasonNumber = str_pad(substr($selectedSeason, 1), 2, '0', STR_PAD_LEFT);
$seasonTitle = $seasonMeta['season_name'] ?? 'Season ' . $seasonNumber;
$seasonYear = substr($seasonMeta['academic_year'] ?? '2026', -2);

// Get all racers who have participated in the selected season
$stmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name, r.nickname, r.catchphrase
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
    ORDER BY r.name ASC
");
$stmt->execute([$selectedSeason . '%']);
$racers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css"><link rel="stylesheet" href="/assets/css/card.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="container">
    <div class="cards-page-header">
        <h1 class="cards-page-title">🎴 Trading Cards Collection</h1>

        <div class="cards-controls">
            <!-- Season Filter -->
            <select id="seasonFilter" onchange="window.location.href='/cards?season=' + this.value" class="cards-season-select">
                <?php foreach ($seasons as $season): ?>
                    <option value="<?= htmlspecialchars($season) ?>" <?= $season === $selectedSeason ? 'selected' : '' ?>>
                        <?= strtoupper($season) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Download PDF Button -->
            <button onclick="downloadPDF()" id="pdfButton" class="cards-pdf-btn">
                📄 Download PDF
            </button>
        </div>
    </div>

    <!-- Cards Grid (3 columns) -->
    <div id="cardsGrid" class="cards-grid">
        <?php foreach ($racers as $racer): ?>
        <!-- Racer Card -->
        <div class="racer-card">
            <?= renderRacerCard($pdo, $racer['id'], $currentSeason, 1.5) ?>
        </div>
        <?php endforeach; ?>

        <!-- The set's backing: one design, printed on the reverse of every card -->
        <?php $backing = renderCardBacking($pdo, $currentSeason, 1.5); foreach ($racers as $racer): ?>
        <div class="card-backing"><?= $backing ?></div>
        <?php endforeach; ?>
    </div>

    <div class="cards-tier-legend">
        <span class="cards-tier-legend-title">Foil tiers</span>
        <?php foreach (cardTierLadder() as [$key, $label, $how]): ?>
            <span class="cards-tier-chip cards-tier-chip--<?= $key ?>" title="<?= htmlspecialchars($how) ?>"><?= $label ?> <small><?= htmlspecialchars($how) ?></small></span>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" integrity="sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" integrity="sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk" crossorigin="anonymous"></script>

<script>
async function downloadPDF() {
    const button = document.getElementById('pdfButton');
    button.textContent = '⏳ Generating PDF...';
    button.disabled = true;

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF('portrait', 'mm', 'a4');

    const racerCards = document.querySelectorAll('.racer-card');
    const backingCards = document.querySelectorAll('.card-backing');

    // A4 dimensions: 210mm x 297mm
    // Card actual size: 238px × 332px (base) = 63mm × 88mm at 96 DPI.
    // Cards are captured at 3× (≈290 dpi on paper) and stored as JPEG so the
    // PDF stays a few MB instead of tens.
    const capture = { scale: 3, backgroundColor: '#ffffff', logging: false, useCORS: true };
    const pdfCardWidth = 63; // mm
    const pdfCardHeight = 88; // mm
    const margin = 7; // mm

    // 3 columns layout
    const cols = 3;
    const rows = 3;
    const cardsPerPage = cols * rows; // 9 cards per page

    let isFirstPage = true;

    // Render racer cards pages
    for (let i = 0; i < racerCards.length; i++) {
        if (i > 0 && i % cardsPerPage === 0) {
            pdf.addPage();
        } else if (!isFirstPage && i % cardsPerPage === 0) {
            pdf.addPage();
        }
        isFirstPage = false;

        const card = racerCards[i];
        const canvas = await html2canvas(card, capture);

        const imgData = canvas.toDataURL('image/jpeg', 0.9);

        const col = i % cols;
        const row = Math.floor((i % cardsPerPage) / cols);

        const x = margin + (col * (pdfCardWidth + margin));
        const y = margin + (row * (pdfCardHeight + margin));

        pdf.addImage(imgData, 'JPEG', x, y, pdfCardWidth, pdfCardHeight);
    }

    // Render card backing pages (same number of pages as fronts)
    const numBackingPages = Math.ceil(racerCards.length / cardsPerPage);

    for (let page = 0; page < numBackingPages; page++) {
        pdf.addPage();

        const startIdx = page * cardsPerPage;
        const endIdx = Math.min(startIdx + cardsPerPage, backingCards.length);

        for (let i = startIdx; i < endIdx; i++) {
            const backingCard = backingCards[i];
            const canvas = await html2canvas(backingCard, capture);

            const imgData = canvas.toDataURL('image/jpeg', 0.9);

            const localIdx = i - startIdx;
            const col = localIdx % cols;
            const row = Math.floor(localIdx / cols);

            const x = margin + (col * (pdfCardWidth + margin));
            const y = margin + (row * (pdfCardHeight + margin));

            pdf.addImage(imgData, 'JPEG', x, y, pdfCardWidth, pdfCardHeight);
        }
    }

    pdf.save('OMK_Trading_Cards_<?= strtoupper($selectedSeason) ?>.pdf');

    button.textContent = '📄 Download PDF';
    button.disabled = false;
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
