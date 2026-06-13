<?php
/**
 * Add Result - The Complete Edition
 * Features: Split Cup List, Auto-GPID, Smart Auto-Fill
 * Path: /cdnmk/public_html/add_result.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/mk_data.php';

// 0. Inline migration — add is_monster column if it doesn't exist yet
try { $pdo->exec("ALTER TABLE results ADD COLUMN is_monster BOOLEAN DEFAULT 0"); }
catch (PDOException $e) {}

// 1. Fetch Racers (with favorites for auto-fill)
$racerQuery = $pdo->query("
    SELECT r.id, r.name, r.nickname,
    (SELECT character_used FROM results WHERE racer_id = r.id ORDER BY race_date DESC, id DESC LIMIT 1) as fav_char,
    (SELECT kart_setup FROM results WHERE racer_id = r.id ORDER BY race_date DESC, id DESC LIMIT 1) as fav_kart
    FROM racers r ORDER BY r.name ASC
")->fetchAll();

// 2. Cups grouped by source for the dropdown, with emoji prefixes.
$mk8Cups = [];
foreach (getMKCupsByGroup() as $group => $cups) {
    $labelled = [];
    foreach ($cups as $cup) {
        $labelled[$cup] = getMKCupEmoji($cup) . ' ' . $cup;
    }
    ksort($labelled);
    $mk8Cups[$group] = $labelled;
}

$mk8Characters = getMKCharacters();

// Pre-fill from the What Cup? modal: ?cup=X&r1=ID&r2=ID&r3=ID&r4=ID&monster=ID
$prefillCup = trim($_GET['cup'] ?? '');
$prefillRacers = [];
for ($i = 1; $i <= 4; $i++) {
    $val = (int)($_GET["r$i"] ?? 0);
    $prefillRacers[$i] = $val > 0 ? $val : null;
}
$prefillMonsterId = (int)($_GET['monster'] ?? 0) ?: null;

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
    // Verify wall code — throttled to 10 wrong codes per IP per 10 minutes
    // so the 4-digit space can't be brute-forced by a script.
    $expectedCode = getSetting($pdo, 'wall_code', '1234');
    $submittedCode = trim($_POST['wall_code'] ?? '');
    $submitterIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $wcCount = $pdo->prepare("
        SELECT COUNT(*) FROM auth_throttle
        WHERE ip = ? AND action = 'wall_code' AND attempted_at > datetime('now', '-10 minutes')
    ");
    $wcCount->execute([$submitterIp]);
    $wallCodeLocked = (int)$wcCount->fetchColumn() >= 10;
    $wallCodeOk = !$wallCodeLocked && hash_equals($expectedCode, $submittedCode);

    // Count how many racer slots are actually filled in. A GP needs at
    // least 3 racers — anything less isn't really a Grand Prix.
    $filledRacers = 0;
    for ($i = 1; $i <= 4; $i++) {
        if (!empty($_POST["racer_$i"])) $filledRacers++;
    }

    if ($wallCodeLocked) {
        $message = "Too many wrong codes. Wait 10 minutes and try again.";
    } elseif (!$wallCodeOk) {
        $pdo->prepare("INSERT INTO auth_throttle (ip, action) VALUES (?, 'wall_code')")->execute([$submitterIp]);
        $message = "Wrong wall code. Check the Gameslab wall and try again.";
    } elseif ($filledRacers < 3) {
        $message = "A Grand Prix needs at least 3 racers. You filled in $filledRacers.";
    } else {
    $pdo->beginTransaction();
    try {
        $packRacers = [];
        for ($i = 1; $i <= 4; $i++) {
            if (!empty($_POST["racer_$i"])) {
                $stmt = $pdo->prepare("INSERT INTO results (gpid, racer_id, gp_points, rank, character_used, kart_setup, cup_name, is_lol, is_monster, race_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['gpid'],
                    $_POST["racer_$i"],
                    $_POST["points_$i"],
                    $_POST["rank_$i"],
                    $_POST["char_$i"],
                    $_POST["kart_$i"],
                    $_POST['cup_name'],
                    isset($_POST["lol_$i"])     ? 1 : 0,
                    isset($_POST["monster_$i"]) ? 1 : 0,
                    $_POST['race_date']
                ]);
                $packRacers[] = (int)$_POST["racer_$i"];
            }
        }
        // Sticker packs: one per racer per GP (no-op before the stickers epoch).
        require_once __DIR__ . '/../private/includes/stickers.php';
        grantGpPacks($pdo, $_POST['gpid'], $packRacers);
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
                                <option value="<?= $val ?>" <?= $prefillCup === $val ? 'selected' : '' ?>><?= $label ?> Cup</option>
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
                        <th width="60">👹 Monster</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for($i=1;$i<=4;$i++):
                        $prefRid = $prefillRacers[$i] ?? null;
                        $rowClass = $prefRid ? 'active-row' : 'inactive-row';
                    ?>
                    <tr id="row_<?= $i ?>" class="input-row <?= $rowClass ?>">
                        <td data-label="Racer">
                            <select name="racer_<?= $i ?>" id="r_<?= $i ?>" onchange="activateRow(<?= $i ?>); autoFill(<?= $i ?>)" class="racer-select">
                                <option value="">- Empty Slot -</option>
                                <?php foreach($racerQuery as $r): ?>
                                    <option value="<?= $r['id'] ?>"
                                            data-name="<?= htmlspecialchars($r['name']) ?>"
                                            data-nick="<?= htmlspecialchars($r['nickname']) ?>"
                                            data-char="<?= htmlspecialchars($r['fav_char']) ?>"
                                            data-kart="<?= htmlspecialchars($r['fav_kart']) ?>"
                                            <?= ($prefRid !== null && (int)$r['id'] === $prefRid) ? 'selected' : '' ?>>
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
                        <td data-label="Monster" class="add-result-lol-cell"><input type="checkbox" name="monster_<?= $i ?>" class="add-result-monster-checkbox" onchange="setMonster(<?= $i ?>)" <?= ($prefillMonsterId !== null && $prefRid === $prefillMonsterId) ? 'checked' : '' ?>></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="wall-code-group">
            <label class="wall-code-label">Gameslab wall code</label>
            <input type="text" name="wall_code" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="····" required class="wall-code-input" autocomplete="off">
        </div>

        <button type="submit" class="btn-generate" id="gp-submit-btn" disabled>🚀 Submit Race Results</button>
        <p id="gp-submit-hint" class="add-result-submit-hint" style="margin-top:8px; font-size:0.85rem; color:#888;">
            Fill in at least 3 racers to submit.
        </p>
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
    refreshSubmitGate();
}

// Enable/disable the submit button based on how many racers are filled in.
// A GP requires at least 3 racers — this mirrors the server-side check so
// the user gets immediate feedback.
function refreshSubmitGate() {
    let filled = 0;
    for (let i = 1; i <= 4; i++) {
        const sel = document.getElementById('r_' + i);
        if (sel && sel.value) filled++;
    }
    const btn  = document.getElementById('gp-submit-btn');
    const hint = document.getElementById('gp-submit-hint');
    if (!btn) return;
    if (filled >= 3) {
        btn.disabled = false;
        if (hint) { hint.textContent = filled + ' racers ready. Submit when scores are in.'; hint.style.color = '#2EBD59'; }
    } else {
        btn.disabled = true;
        const needed = 3 - filled;
        if (hint) {
            hint.textContent = 'Fill in at least ' + needed + ' more racer' + (needed !== 1 ? 's' : '') + ' to submit.';
            hint.style.color = '#888';
        }
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

// 3. Monster checkbox — only one racer can be the Monster per GP
function setMonster(rowId) {
    for (let i = 1; i <= 4; i++) {
        if (i !== rowId) {
            const cb = document.querySelector('input[name="monster_' + i + '"]');
            if (cb) cb.checked = false;
        }
    }
}

// 4. Update Portrait Image — swap character portrait on select
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

// On page load: if a row was server-side pre-filled (from the What Cup?
// shortcut), trigger the same autoFill chain that runs when a user picks
// a racer manually. That populates character + kart from the data
// attributes and renders the portrait.
document.addEventListener('DOMContentLoaded', function () {
    for (let i = 1; i <= 4; i++) {
        const sel = document.getElementById('r_' + i);
        if (sel && sel.value) {
            activateRow(i);
            autoFill(i);
        }
    }
    // Always evaluate the gate on load — covers both prefill and empty form.
    refreshSubmitGate();
});

</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>