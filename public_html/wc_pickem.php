<?php
/**
 * World Cup Bracket Pick'em — public prediction game for a world_cup
 * tournament, fronted by Kartificial the mascot.
 *
 * Before the first group match is recorded, anyone can lock in one bracket
 * per name: the two qualifiers from each group + an overall champion. Once
 * results start landing, picks are locked and this page becomes the
 * leaderboard.
 *
 * Scoring (computed live, never stored):
 *   +2 per correctly picked qualifier (scored once the group stage is done)
 *   +1 bonus if the qualifier you picked actually WON their group
 *   +10 for the correct champion (scored when the tournament completes)
 *
 * URL: /wc-pickem/<tournamentId>
 * Path: /cdnmk/public_html/wc_pickem.php
 */

require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/worldcup_tournament.php';

$tournamentId = (int)($_GET['id'] ?? 0);

$tStmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? AND format = 'world_cup'");
$tStmt->execute([$tournamentId]);
$tournament = $tStmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $pageTitle = "Pick'em — not found";
    include __DIR__ . '/../private/templates/header.php';
    echo '<div class="stats-container"><h1>🌍 No World Cup here</h1><p>That tournament doesn\'t exist (or isn\'t a World Cup). <a href="/tournaments-hall-of-fame">Tournaments</a>.</p></div>';
    include __DIR__ . '/../private/templates/footer.php';
    exit;
}

$groups = worldCupGroups($pdo, $tournamentId);
$tables = worldCupGroupTables($pdo, $tournamentId);
$death  = worldCupGroupOfDeath($pdo, $tournamentId);
$letters = range('A', 'Z');

// Picks lock as soon as any group match has been recorded.
$lockStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ? AND bracket = 'wc_group' AND status = 'completed'");
$lockStmt->execute([$tournamentId]);
$picksLocked = (int)$lockStmt->fetchColumn() > 0 || $tournament['status'] === 'completed';

$groupStageDone = worldCupGroupStageComplete($pdo, $tournamentId);
$championId     = worldCupWinnerId($pdo, $tournamentId);

$message = ''; $error = '';

// ── Submit a bracket ────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    if ($picksLocked) {
        $error = 'Picks are locked — the racing has started!';
    } else {
        $name = trim((string)($_POST['predictor_name'] ?? ''));
        $champion = (int)($_POST['champion'] ?? 0);
        $ip = clientIp();

        // Throttle: 5 submissions per IP per 10 minutes (same table as login/wall code).
        $thr = $pdo->prepare("SELECT COUNT(*) FROM auth_throttle WHERE ip = ? AND action = 'wc_pickem' AND attempted_at > datetime('now','-10 minutes')");
        $thr->execute([$ip]);
        $throttled = (int)$thr->fetchColumn() >= 5;

        // Validate: name, exactly 2 picks per group from that group, champion in field.
        $allIds = [];
        foreach ($groups as $members) foreach ($members as $m) $allIds[$m['racer_id']] = true;

        $picks = [];
        $valid = !$throttled && $name !== '' && mb_strlen($name) <= 40 && isset($allIds[$champion]);
        foreach ($groups as $gNum => $members) {
            $sel = array_map('intval', (array)($_POST['group'][$gNum] ?? []));
            $sel = array_values(array_unique($sel));
            $inGroup = array_column($members, 'racer_id');
            if (count($sel) !== 2 || array_diff($sel, $inGroup)) { $valid = false; break; }
            $picks[$gNum] = $sel;
        }

        if ($throttled) {
            $error = 'Too many submissions from your connection — try again in a few minutes.';
        } elseif (!$valid) {
            $error = 'Pick exactly two qualifiers per group, a champion, and give us a name (max 40 chars).';
        } else {
            try {
                $pdo->prepare("INSERT INTO wc_predictions (tournament_id, predictor_name, picks_json) VALUES (?, ?, ?)")
                    ->execute([$tournamentId, $name, json_encode(['groups' => $picks, 'champion' => $champion])]);
                $pdo->prepare("INSERT INTO auth_throttle (ip, action) VALUES (?, 'wc_pickem')")->execute([$ip]);
                $message = "Bracket locked in, $name. Kartificial wishes you luck. 🍄";
            } catch (PDOException $e) {
                $error = 'That name has already submitted a bracket for this tournament.';
            }
        }
    }
}

// ── Score everyone (live) ───────────────────────────────────────────────
// Scoring lives in worldcup_tournament.php so /wc-pickem and the Pick'em
// Oracle badge can never drift apart.
$board = worldCupPickemBoard($pdo, $tournamentId);

$pageTitle = htmlspecialchars($tournament['name']) . " — Bracket Pick'em";
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a><span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Bracket Pick'em</span>
    </nav>

    <header class="pk-hero">
        <img src="/assets/img/kartificial.png" class="pk-mascot" alt="Kartificial" onerror="this.style.display='none'">
        <div>
            <h1 class="pk-title">🌍 <?= htmlspecialchars($tournament['name']) ?></h1>
            <p class="pk-sub">
                BRACKET PICK'EM · pick two qualifiers per group + your champion ·
                hosted by <strong>Kartificial</strong>
            </p>
            <p class="pk-rules">+2 per correct qualifier · +1 if they top the group · +10 for the champion</p>
        </div>
    </header>

    <?php if ($message): ?><div class="pk-flash pk-flash--good"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="pk-flash pk-flash--bad"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$picksLocked): ?>
    <form method="POST" class="pk-form">
        <?= csrf_field() ?>
        <div class="pk-groups">
            <?php foreach ($groups as $gNum => $members):
                $letter = $letters[$gNum - 1] ?? $gNum;
                $isDeath = $death && $death[0] === $gNum; ?>
            <fieldset class="pk-group <?= $isDeath ? 'pk-group--death' : '' ?>">
                <legend>Group <?= $letter ?><?= $isDeath ? ' 💀' : '' ?> <small>pick 2</small></legend>
                <?php foreach ($members as $m): ?>
                    <label class="pk-pick">
                        <input type="checkbox" name="group[<?= $gNum ?>][]" value="<?= $m['racer_id'] ?>" data-group="<?= $gNum ?>">
                        <span><?= htmlspecialchars($m['name']) ?></span>
                        <small>seed #<?= $m['seed'] ?></small>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <?php endforeach; ?>
        </div>

        <div class="pk-finalrow">
            <label class="pk-field">
                <span>🏆 Your champion</span>
                <select name="champion" required>
                    <option value="">— pick a winner —</option>
                    <?php foreach ($groups as $gNum => $members): ?>
                        <optgroup label="Group <?= $letters[$gNum - 1] ?? $gNum ?>">
                            <?php foreach ($members as $m): ?>
                                <option value="<?= $m['racer_id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="pk-field">
                <span>Your name</span>
                <input type="text" name="predictor_name" maxlength="40" required placeholder="One bracket per name">
            </label>
            <button type="submit" class="btn btn-primary">🔒 Lock in my bracket</button>
        </div>
    </form>
    <?php else: ?>
        <div class="pk-locked">🔒 Picks are locked — the karts are on track. Scores update as the groups finish<?= $championId === null ? ' and the champion is crowned' : '' ?>.</div>
    <?php endif; ?>

    <section class="pk-board">
        <h2>📋 Predictor Leaderboard <small><?= count($board) ?> bracket<?= count($board) === 1 ? '' : 's' ?></small></h2>
        <?php if (empty($board)): ?>
            <p class="pk-empty">No brackets yet. <?= $picksLocked ? '' : 'Be the first — Kartificial believes in you.' ?></p>
        <?php else: ?>
            <table class="pk-table">
                <thead><tr><th>#</th><th>Predictor</th><th>Champion pick</th><th>Points</th></tr></thead>
                <tbody>
                <?php foreach ($board as $i => $b): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                        <td><?= htmlspecialchars($b['champion']) ?></td>
                        <td class="pk-pts"><?= $b['points'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$groupStageDone): ?><p class="pk-note">Group-stage points land once every matchday is recorded.</p><?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<style>
.pk-hero { display:flex; align-items:center; gap:20px; background:#0a2342; border-radius:12px; padding:22px 26px; color:#fff; margin-bottom:18px; }
.pk-mascot { width:110px; height:110px; object-fit:contain; filter:drop-shadow(0 6px 14px rgba(0,0,0,.4)); }
.pk-title { margin:0; font-size:1.7rem; font-weight:900; }
.pk-sub { margin:6px 0 0; color:var(--gray-600); font-size:.85rem; letter-spacing:1px; text-transform:uppercase; }
.pk-rules { margin:6px 0 0; color:var(--gray-600); font-size:.82rem; }
.pk-flash { border-radius:8px; padding:10px 16px; margin-bottom:14px; font-weight:700; }
.pk-flash--good { background:#e6f6ec; color:#1e7e44; border:1px solid #bfe8cd; }
.pk-flash--bad  { background:#fdecec; color:#b03a2e; border:1px solid #f0c4be; }
.pk-groups { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px; }
.pk-group { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:10px; padding:12px 16px; }
.pk-group--death { border-color:#c0392b; }
.pk-group legend { font-weight:900; padding:0 6px; }
.pk-group legend small { color:#999; font-weight:600; }
.pk-pick { display:flex; align-items:center; gap:10px; padding:7px 4px; border-bottom:1px solid #f0f0f2; cursor:pointer; }
.pk-pick span { flex:1; font-weight:600; }
.pk-pick small { color:var(--gray-500); }
.pk-finalrow { display:flex; gap:18px; align-items:flex-end; flex-wrap:wrap; background:var(--gray-50); border:1px solid var(--gray-200); border-radius:10px; padding:16px 20px; margin-top:14px; }
.pk-field { display:flex; flex-direction:column; gap:6px; font-weight:700; font-size:.85rem; }
.pk-field select, .pk-field input { padding:8px 10px; border:1px solid var(--gray-400); border-radius:6px; font:inherit; min-width:220px; }
.pk-locked { background:#fff6dc; border:1px solid #ffd54f; color:#ffd58a; border-radius:8px; padding:12px 18px; font-weight:700; margin-bottom:16px; }
.pk-board { margin-top:26px; }
.pk-board h2 { font-size:1.2rem; } .pk-board h2 small { color:#999; font-weight:600; }
.pk-table { width:100%; border-collapse:collapse; background:var(--gray-50); border-radius:8px; overflow:hidden; }
.pk-table th { text-align:left; background:var(--gray-100); padding:8px 12px; font-size:.75rem; text-transform:uppercase; color:#777; }
.pk-table td { padding:9px 12px; border-top:1px solid #f0f0f2; }
.pk-pts { font-weight:900; color:var(--nintendo-red,var(--nintendo-red)); }
.pk-empty, .pk-note { color:#888; font-size:.88rem; }
</style>

<script>
// Enforce exactly 2 picks per group client-side (server re-validates).
document.querySelectorAll('.pk-group').forEach(group => {
    group.addEventListener('change', () => {
        const boxes = group.querySelectorAll('input[type=checkbox]');
        const checked = group.querySelectorAll('input[type=checkbox]:checked').length;
        boxes.forEach(b => { if (!b.checked) b.disabled = checked >= 2; });
    });
});
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
