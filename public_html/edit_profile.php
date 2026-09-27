<?php
/**
 * A racer edits their own nickname and catchphrase — /edit-profile/<id>.
 *
 * Authorised by the racer's personal profile code (issued on /admin/racers,
 * stored only as a hash). POST + CSRF, and throttled per IP like every other
 * public write, so the code cannot be guessed by a script. The commissioner
 * keeps full control: they can edit the same fields, and reissuing a code
 * cancels the old one.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/throttle.php';
require_once __DIR__ . '/../private/includes/profile_codes.php';

$racerId = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT id, name, nickname, catchphrase FROM racers WHERE id = ?");
$st->execute([$racerId]);
$racer = $st->fetch(PDO::FETCH_ASSOC);
if (!$racer) {
    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$hasCode = profileHasCode($pdo, $racerId);
$error = ''; $saved = false;
$nickname    = (string)($racer['nickname'] ?? '');
$catchphrase = (string)($racer['catchphrase'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // Keep what was typed, so a wrong code does not throw the edit away.
    $nickname    = profileCleanText((string)($_POST['nickname'] ?? ''), PROFILE_NICKNAME_MAX);
    $catchphrase = profileCleanText((string)($_POST['catchphrase'] ?? ''), PROFILE_CATCHPHRASE_MAX);

    if (!$hasCode) {
        $error = 'There is no profile code for ' . $racer['name'] . ' yet. Ask the commissioner for one.';
    } elseif (!throttleAllow($pdo, 'profile_edit', 10, 10)) {
        $error = 'Too many attempts from this connection. Wait ten minutes and try again.';
    } elseif (!profileCodeCheck($pdo, $racerId, (string)($_POST['code'] ?? ''))) {
        $error = "That code doesn't match. Check it with the commissioner.";
    } else {
        $pdo->prepare("UPDATE racers SET nickname = ?, catchphrase = ? WHERE id = ?")
            ->execute([$nickname !== '' ? $nickname : null, $catchphrase !== '' ? $catchphrase : null, $racerId]);
        $saved = true;
    }
}

$pageTitle = 'Edit profile — ' . $racer['name'];
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/racer/<?= (int)$racerId ?>">← <?= htmlspecialchars($racer['name']) ?></a>
    </nav>

    <div class="card profile-edit">
        <h1 class="card-header">Edit my profile</h1>

        <?php if ($saved): ?>
            <div class="alert alert-success">
                Saved. <a href="/racer/<?= (int)$racerId ?>">See your profile →</a>
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$hasCode): ?>
            <p class="profile-edit-note">
                <?= htmlspecialchars($racer['name']) ?> doesn't have a profile code yet. The commissioner can issue one
                on the racers page; bring it back here to set your nickname and catchphrase.
            </p>
        <?php else: ?>
        <form method="POST" class="profile-edit-form" autocomplete="off">
            <?= csrf_field() ?>

            <label class="profile-edit-field">
                <span>Nickname <small>(up to <?= PROFILE_NICKNAME_MAX ?> characters)</small></span>
                <input type="text" name="nickname" maxlength="<?= PROFILE_NICKNAME_MAX ?>"
                       value="<?= htmlspecialchars($nickname) ?>" placeholder="The Knock Knock">
            </label>

            <label class="profile-edit-field">
                <span>Catchphrase <small>(up to <?= PROFILE_CATCHPHRASE_MAX ?> characters)</small></span>
                <input type="text" name="catchphrase" maxlength="<?= PROFILE_CATCHPHRASE_MAX ?>"
                       value="<?= htmlspecialchars($catchphrase) ?>" placeholder="Orange you glad I didn't throw banana?">
            </label>

            <label class="profile-edit-field profile-edit-field--code">
                <span>Your profile code</span>
                <input type="text" name="code" required inputmode="text" autocapitalize="characters"
                       spellcheck="false" placeholder="ABC-234">
            </label>

            <p class="profile-edit-note">
                These show on your profile and trading card, and the broadcasts use your nickname.
                Leave a field empty to clear it.
            </p>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
