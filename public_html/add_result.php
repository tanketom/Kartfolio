<?php
/**
 * Add Result - The Complete Edition
 * Features: AI OCR, Split Cup List, Auto-GPID, Smart Auto-Fill
 * Path: /cdnmk/public_html/add_result.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/csrf.php';

// 1. Fetch Racers (with favorites for auto-fill)
$racerQuery = $pdo->query("
    SELECT r.id, r.name, r.nickname,
    (SELECT character_used FROM results WHERE racer_id = r.id ORDER BY race_date DESC, id DESC LIMIT 1) as fav_char,
    (SELECT kart_setup FROM results WHERE racer_id = r.id ORDER BY race_date DESC, id DESC LIMIT 1) as fav_kart
    FROM racers r ORDER BY r.name ASC
")->fetchAll();

// 2. Organized Cup List (Base vs Booster)
$baseCups = [
    "Banana"    => "🍌 Banana",
    "Bell"      => "🔔 Bell",
    "Crossing"  => "🍃 Crossing",
    "Egg"       => "🥚 Egg",
    "Flower"    => "🌺 Flower",
    "Leaf"      => "🍃 Leaf",
    "Lightning" => "⚡ Lightning",
    "Mushroom"  => "🍄 Mushroom",
    "Shell"     => "🐢 Shell",
    "Special"   => "👑 Special",
    "Star"      => "⭐ Star",
    "Triforce"  => "▲ Triforce"
];

$boosterCups = [
    "Acorn"       => "🌰 Acorn",
    "Boomerang"   => "🪃 Boomerang",
    "Cherry"      => "🍒 Cherry",
    "Feather"     => "🪶 Feather",
    "Fruit"       => "🍓 Fruit",
    "Golden Dash" => "🍄 Golden Dash",
    "Lucky Cat"   => "🐱 Lucky Cat",
    "Moon"        => "🌙 Moon",
    "Propeller"   => "🔴 Propeller",
    "Rock"        => "🪨 Rock",
    "Spiny"       => "🔵 Spiny",
    "Turnip"      => "🌱 Turnip"
];

ksort($baseCups);
ksort($boosterCups);

$mk8Cups = [
    "Base Game" => $baseCups,
    "Booster Course Pass" => $boosterCups
];

$mk8Characters = ["Mario", "Luigi", "Peach", "Daisy", "Rosalina", "Tanooki Mario", "Cat Peach", "Yoshi", "Toad", "Toadette", "Koopa Troopa", "Lakitu", "Shy Guy", "Baby Mario", "Baby Luigi", "Baby Peach", "Baby Daisy", "Baby Rosalina", "Metal Mario", "Pink Gold Peach", "Wario", "Waluigi", "Donkey Kong", "Bowser", "Dry Bones", "Bowser Jr.", "Dry Bowser", "King Boo", "Lemmy", "Larry", "Wendy", "Ludwig", "Iggy", "Roy", "Morton", "Inkling Girl", "Inkling Boy", "Link", "Villager", "Isabelle", "Mii", "Birdo", "Petey Piranha", "Wiggler", "Kamek", "Diddy Kong", "Funky Kong", "Pauline", "Peachette"];
sort($mk8Characters);

// 3. Auto-Increment GPID Logic (sXXgpXX)
$currentSeason = getCurrentSeasonNumber();
$gpidStmt = $pdo->prepare("SELECT gpid FROM results WHERE gpid LIKE ? ORDER BY LENGTH(gpid) DESC, gpid DESC LIMIT 1");
$gpidStmt->execute([$currentSeason . "%"]);
$lastGPID = $gpidStmt->fetchColumn();

if ($lastGPID && preg_match('/gp(\d+)$/', $lastGPID, $matches)) {
    $nextNum = intval($matches[1]) + 1;
    $nextGPID = $currentSeason . 'gp' . str_pad($nextNum, 2, '0', STR_PAD_LEFT);
} else {
    $nextGPID = $currentSeason . 'gp01';
}

$message = "";

// 4. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Verify wall code
    $expectedCode = getSetting($pdo, 'wall_code', '1234');
    $submittedCode = trim($_POST['wall_code'] ?? '');
    if ($submittedCode !== $expectedCode) {
        $message = "Wrong wall code. Check the Gameslab wall and try again.";
    } else {
    $pdo->beginTransaction();
    try {
        for ($i = 1; $i <= 4; $i++) {
            if (!empty($_POST["racer_$i"])) {
                $stmt = $pdo->prepare("INSERT INTO results (gpid, racer_id, gp_points, rank, character_used, kart_setup, cup_name, is_lol, race_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['gpid'], 
                    $_POST["racer_$i"], 
                    $_POST["points_$i"], 
                    $_POST["rank_$i"], 
                    $_POST["char_$i"], 
                    $_POST["kart_$i"], 
                    $_POST['cup_name'], 
                    isset($_POST["lol_$i"]) ? 1 : 0, 
                    $_POST['race_date']
                ]);
            }
        }
        $pdo->commit();
        header("Location: index.php?success=1"); 
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Error: " . $e->getMessage();
    }
    } // end wall code check
}

$pageTitle = "Log Results - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <h1 class="add-result-title">Log Grand Prix</h1>

    <?php if($message): ?><div class="badge add-result-error"><?= $message ?></div><?php endif; ?>

    <form method="POST" id="gp-form">
        <?= csrf_field() ?>
        <div class="gp-meta-grid">
            <div class="meta-item">
                <label>GPID (Auto-filled)</label>
                <input type="text" name="gpid" value="<?= $nextGPID ?>" required class="add-result-gpid-input">
            </div>
            
            <div class="meta-item">
                <label>Cup Selection</label>
                <select name="cup_name" required class="add-result-cup-select">
                    <option value="">- Select Cup -</option>
                    <?php foreach($mk8Cups as $group => $cups): ?>
                        <optgroup label="<?= $group ?>">
                            <?php foreach($cups as $val => $label): ?>
                                <option value="<?= $val ?>"><?= $label ?> Cup</option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="meta-item">
                <label>Race Date</label>
                <input type="date" name="race_date" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="add-result-table-wrap">
            <table class="admin-table" style="min-width: 800px; margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Racer</th>
                        <th style="width: 90px;">Pts</th>
                        <th style="width: 70px;">Rank</th>
                        <th style="width: 25%;">Character</th>
                        <th>Kart Setup</th>
                        <th width="50">LOL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for($i=1;$i<=4;$i++): ?>
                    <tr id="row_<?= $i ?>" class="input-row inactive-row">
                        <td data-label="Racer">
                            <select name="racer_<?= $i ?>" id="r_<?= $i ?>" onchange="activateRow(<?= $i ?>); autoFill(<?= $i ?>)" class="racer-select">
                                <option value="">- Empty Slot -</option>
                                <?php foreach($racerQuery as $r): ?>
                                    <option value="<?= $r['id'] ?>"
                                            data-name="<?= htmlspecialchars($r['name']) ?>"
                                            data-nick="<?= htmlspecialchars($r['nickname']) ?>"
                                            data-char="<?= htmlspecialchars($r['fav_char']) ?>"
                                            data-kart="<?= htmlspecialchars($r['fav_kart']) ?>">
                                        <?= htmlspecialchars($r['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td data-label="Points">
                            <input type="number" name="points_<?= $i ?>" id="pts_<?= $i ?>" class="pts-input" min="0" max="60" placeholder="0">
                        </td>
                        <td data-label="Rank">
                            <input type="number" name="rank_<?= $i ?>" id="rank_<?= $i ?>" min="1" max="12" placeholder="-" class="add-result-rank-input">
                        </td>
                        <td data-label="Character">
                            <div class="char-input-group">
                                <div class="portrait-preview">
                                    <img id="p_<?= $i ?>" src="" style="display:none;" class="portrait-img">
                                </div>
                                <select name="char_<?= $i ?>" id="c_<?= $i ?>" onchange="updatePortrait(<?= $i ?>)" id="char_<?= $i ?>">
                                    <option value="">- Char -</option>
                                    <?php foreach($mk8Characters as $char): ?>
                                        <option value="<?= $char ?>"><?= $char ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </td>
                        <td data-label="Kart Setup"><input type="text" name="kart_<?= $i ?>" id="k_<?= $i ?>" placeholder="Kart Setup"></td>
                        <td data-label="Ludwig Obstruction Law" class="add-result-lol-cell"><input type="checkbox" name="lol_<?= $i ?>" class="add-result-lol-checkbox"></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="add-result-scanner-wrap">
            <input type="file" id="ocr_file" accept="image/*" capture="environment" style="display:none;" onchange="uploadImage()">

            <button type="button" id="scan_btn" onclick="document.getElementById('ocr_file').click()" class="btn-scanner">
                <span id="scan_icon">📸</span> <span id="scan_text">BETA functionality: Scan Scoreboard with AI</span>
            </button>

            <div id="scan_status" class="add-result-scan-status">
                <span class="spinner">⏳</span> Analyzing image... please wait...
            </div>
        </div>

        <div class="wall-code-group">
            <label class="wall-code-label">Gameslab wall code</label>
            <input type="text" name="wall_code" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="····" required class="wall-code-input" autocomplete="off">
        </div>

        <button type="submit" class="btn-generate">🚀 Submit Race Results</button>
    </form>
</div>


<script>
// --- CORE FORM LOGIC ---

// 1. Highlight Row Logic
function activateRow(id) {
    const row = document.getElementById('row_' + id);
    const val = document.getElementById('r_' + id).value;
    if (val) {
        row.classList.remove('inactive-row');
        row.classList.add('active-row');
    } else {
        row.classList.add('inactive-row');
        row.classList.remove('active-row');
    }
}

// 2. Auto-Fill Character/Kart from Data Attributes
function autoFill(rowId) {
    const racerSelect = document.getElementById('r_' + rowId);
    const selectedOption = racerSelect.options[racerSelect.selectedIndex];
    const charField = document.getElementById('c_' + rowId);
    const kartField = document.getElementById('k_' + rowId);

    if (selectedOption.dataset.char) {
        charField.value = selectedOption.dataset.char;
        updatePortrait(rowId);
    }
    if (selectedOption.dataset.kart) {
        kartField.value = selectedOption.dataset.kart;
    }
}

// 3. Update Portrait Image
function updatePortrait(rowId) {
    const charSelect = document.getElementById('c_' + rowId);
    const img = document.getElementById('p_' + rowId);
    if (charSelect.value) {
        img.src = '/assets/img/' + charSelect.value + '.png';
        img.style.display = 'block';
    } else {
        img.style.display = 'none';
    }
}

// --- AI SCANNER LOGIC ---

function uploadImage() {
    const input = document.getElementById('ocr_file');
    if (input.files.length === 0) return;

    const file = input.files[0];
    const formData = new FormData();
    formData.append('image', file);

    // UI State: Loading
    document.getElementById('scan_btn').style.display = 'none';
    document.getElementById('scan_status').style.display = 'block';

    fetch('/api/ocr_gemini.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            // Check if it's an overload error
            if (data.error.includes('overloaded') || data.error.includes('503')) {
                alert("⏳ AI Service is busy right now.\n\nPlease wait 10-30 seconds and try again.");
            } else {
                alert("Scan Failed: " + data.error);
            }
        } else {
            populateFormFromOCR(data);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("Network Error during scan. Check console for details.");
    })
    .finally(() => {
        // Reset UI
        document.getElementById('scan_btn').style.display = 'flex';
        document.getElementById('scan_status').style.display = 'none';
        input.value = ''; // Clear for next scan
    });
}

function populateFormFromOCR(results) {
    // Results should already be in order (1st-4th human player) from the API
    // No need to sort - the colored backgrounds guarantee correct order

    // We only care about the top 4 rows max (since we only log 4 players)
    const limit = Math.min(results.length, 4);

    for (let i = 0; i < limit; i++) {
        const entry = results[i];
        const rowId = i + 1; // 1 to 4

        // A. Set Points & Rank
        document.getElementById('pts_' + rowId).value = entry.points;
        document.getElementById('rank_' + rowId).value = entry.rank;

        // B. Try to Match Name (and optionally use character as a hint)
        const select = document.getElementById('r_' + rowId);
        const bestMatch = findBestMatch(entry.name, entry.character, select);

        if (bestMatch) {
            select.value = bestMatch;
            activateRow(rowId); // Highlight row
            autoFill(rowId);    // Fill Fav Character/Kart
        } else {
            // If no match found, still activate the row and populate character if available
            activateRow(rowId);
            if (entry.character) {
                // Try to set the character dropdown if we can
                const charSelect = document.getElementById('char_' + rowId);
                if (charSelect) {
                    // Try to find matching character option
                    const charOptions = charSelect.options;
                    for (let j = 0; j < charOptions.length; j++) {
                        const optText = charOptions[j].text.toLowerCase();
                        const scannedChar = entry.character.toLowerCase();
                        if (optText.includes(scannedChar) || scannedChar.includes(optText)) {
                            charSelect.value = charOptions[j].value;
                            break;
                        }
                    }
                }
            }
        }
    }

    // Smooth scroll to top to check GPID/Date
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function findBestMatch(scannedName, scannedCharacter, selectElement) {
    // Basic fuzzy matching: removes spaces/symbols and compares lowercase
    const search = scannedName.toLowerCase().replace(/[^a-z0-9]/g, '');
    const searchChar = scannedCharacter ? scannedCharacter.toLowerCase().replace(/[^a-z0-9]/g, '') : '';
    let bestOption = null;
    let highestScore = 0;

    for (let i = 1; i < selectElement.options.length; i++) { // Skip "Empty Slot"
        const opt = selectElement.options[i];
        const dbName = opt.dataset.name.toLowerCase().replace(/[^a-z0-9]/g, '');
        const dbNick = opt.dataset.nick ? opt.dataset.nick.toLowerCase().replace(/[^a-z0-9]/g, '') : '';
        const dbChar = opt.dataset.char ? opt.dataset.char.toLowerCase().replace(/[^a-z0-9]/g, '') : '';

        // 1. Exact Match on Name
        if (dbName === search || dbNick === search) return opt.value;

        // 2. Partial Match Scoring
        let score = 0;
        if (dbName.includes(search)) score += 10;
        if (search.includes(dbName)) score += 10;

        // Bonus for length similarity (avoids matching "Tom" to "Tomato" too easily)
        if (Math.abs(dbName.length - search.length) < 3) score += 5;

        // 3. Character matching bonus (helps disambiguate similar names)
        if (searchChar && dbChar) {
            if (dbChar === searchChar) score += 8; // Strong bonus for exact character match
            else if (dbChar.includes(searchChar) || searchChar.includes(dbChar)) score += 4;
        }

        if (score > highestScore) {
            highestScore = score;
            bestOption = opt.value;
        }
    }

    // Only return if confidence is decent
    return (highestScore >= 10) ? bestOption : null;
}
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>