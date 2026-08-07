<?php
/**
 * First-Run Setup — one page, one submit, league ready.
 * Path: /cdnmk/public_html/admin/setup.php
 *
 * A fresh install already builds its own schema (db.php migrations) and seeds
 * default settings, so a new commissioner gets a working but empty site called
 * "Kartfolio League". This closes that last gap: name the league, create the
 * first season, paste the roster.
 *
 * Only reachable while the league is empty (no racers AND no seasons) — once
 * there's data it redirects to /admin/seasons, so it never clutters a running
 * install.
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/roster.php';
require_admin();

initializeSettings($pdo);

// "Empty" means no racers and no results. Deliberately NOT keyed on
// season_meta: schema.sql seeds a placeholder 's01' on every fresh install, so
// a season row always exists before anyone has done anything.
$racerCount  = (int)$pdo->query("SELECT COUNT(*) FROM racers")->fetchColumn();
$resultCount = (int)$pdo->query("SELECT COUNT(*) FROM results")->fetchColumn();
$isEmpty     = ($racerCount === 0 && $resultCount === 0);
$done        = isset($_GET['done']);

// Nothing to set up — send them somewhere useful.
if (!$isEmpty && !$done) {
    header('Location: /admin/seasons');
    exit;
}

$error = '';

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $leagueName = trim((string)($_POST['league_name'] ?? ''));
    $bodyFull   = trim((string)($_POST['governing_body_full'] ?? ''));
    $bodyShort  = trim((string)($_POST['governing_body_short'] ?? ''));
    $tagline    = trim((string)($_POST['league_tagline'] ?? ''));

    $seasonId   = strtolower(trim((string)($_POST['season_id'] ?? '')));
    $seasonName = trim((string)($_POST['season_name'] ?? ''));
    $system     = (string)($_POST['scoring_system'] ?? 'average_attendance');
    $rosterRaw  = (string)($_POST['roster'] ?? '');

    $registry = getScoringSystemRegistry();

    if ($leagueName === '') {
        $error = 'Give the league a name.';
    } elseif (!preg_match('/^[a-z]{1,3}[0-9]{1,3}$/', $seasonId)) {
        // Matches the house GPID convention: s01, s04, ps01 …
        $error = 'Season ID must look like s01 or ps01 — letters then digits, no spaces.';
    } elseif (!isset($registry[$system])) {
        $error = 'Pick a scoring system from the list.';
    } else {
        $roster = parseRosterLines($rosterRaw);

        try {
            $pdo->beginTransaction();

            updateSetting($pdo, 'league_name', mb_substr($leagueName, 0, 80));
            if ($bodyFull !== '')  updateSetting($pdo, 'governing_body_full',  mb_substr($bodyFull, 0, 120));
            if ($bodyShort !== '') updateSetting($pdo, 'governing_body_short', mb_substr($bodyShort, 0, 20));
            if ($tagline !== '')   updateSetting($pdo, 'league_tagline',       mb_substr($tagline, 0, 140));

            // Same column set and defaults the season-create handler in
            // admin/seasons.php uses, so a setup season is indistinguishable
            // from a hand-made one. Knobs stay at their defaults; the admin
            // tunes them on /admin/seasons afterwards.
            //
            // UPSERT rather than INSERT: schema.sql already seeded a
            // placeholder 's01', so if the commissioner keeps that ID we must
            // fill it in, not collide with it.
            $label     = $seasonName !== '' ? mb_substr($seasonName, 0, 80) : strtoupper($seasonId);
            $attWeight = $system === 'average_attendance' ? 1.0 : 0.0;
            $dropRate  = $system === 'average_attendance' ? 10 : 0;

            $exists = $pdo->prepare("SELECT COUNT(*) FROM season_meta WHERE season_id = ?");
            $exists->execute([$seasonId]);

            if ((int)$exists->fetchColumn() > 0) {
                $stmt = $pdo->prepare("
                    UPDATE season_meta SET
                        status='active', scoring_system=?, academic_year=?, season_name=?,
                        cups_required=12, best_n_count=15, drop_worst_count=2, perfect_multiplier=2.0,
                        attendance_weight=?, weekly_bonus_cap=2, min_races_threshold=3, drop_rate=?
                    WHERE season_id = ?
                ");
                $stmt->execute([$system, date('Y'), $label, $attWeight, $dropRate, $seasonId]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO season_meta (
                        season_id, status, scoring_system, academic_year,
                        season_name, season_description,
                        cups_required, best_n_count, drop_worst_count, perfect_multiplier,
                        attendance_weight, weekly_bonus_cap, min_races_threshold, drop_rate
                    ) VALUES (?, 'active', ?, ?, ?, '', 12, 15, 2, 2.0, ?, 2, 3, ?)
                ");
                $stmt->execute([$seasonId, $system, date('Y'), $label, $attWeight, $dropRate]);

                // The commissioner named their season something other than the
                // seeded placeholder — drop the placeholder so the league isn't
                // left with a phantom empty season. Only ever removes a season
                // with no results attached.
                $ph = $pdo->prepare("
                    DELETE FROM season_meta
                    WHERE season_id = 's01' AND season_id <> ?
                      AND NOT EXISTS (SELECT 1 FROM results WHERE gpid LIKE 's01%')
                ");
                $ph->execute([$seasonId]);
            }

            [$inserted, $skipped] = insertRosterRows($pdo, $roster);

            $pdo->commit();
            header('Location: /admin/setup?done=1&racers=' . $inserted . '&season=' . urlencode($seasonId));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Setup failed: ' . $e->getMessage();
        }
    }
}

$scoringSystems = [];
foreach (getScoringSystemRegistry() as $key => $def) {
    if ($key === 'random_cup_draw') continue; // needs per-racer cup assignment first
    $scoringSystems[$key] = [
        'name'        => is_callable($def['name'])        ? ($def['name'])([])        : $def['name'],
        'description' => is_callable($def['description']) ? ($def['description'])([]) : $def['description'],
        'icon'        => $def['icon'],
    ];
}

$pageTitle = "Setup - Kartfolio";
$extraCss  = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="season-config">
<?php if ($done):
    $newRacers = (int)($_GET['racers'] ?? 0);
    $newSeason = strtoupper((string)($_GET['season'] ?? ''));
?>
    <div class="config-section">
        <h1 class="config-label">🏁 Your league is ready</h1>
        <div class="alert-success" style="margin:16px 0;">
            Season <strong><?= htmlspecialchars($newSeason) ?></strong> is active
            with <strong><?= $newRacers ?></strong> racer<?= $newRacers === 1 ? '' : 's' ?> on the roster.
        </div>
        <p>Next steps, in the order you'll want them:</p>
        <ul class="setup-next-steps">
            <li><a href="/add-result">Add GP scores</a> — log your first Grand Prix and the standings come alive.</li>
            <li><a href="/admin/seasons">Seasons</a> — tune the scoring knobs, or try other systems in the Scoring Simulator.</li>
            <li><a href="/admin/racers">Racers</a> — add nicknames, catchphrases, or more people.</li>
            <li><a href="/admin/settings">Settings</a> — brand colour, wall code, and feature toggles.</li>
            <li><a href="/">View your league</a></li>
        </ul>
    </div>
<?php else: ?>
    <div class="config-section">
        <h1 class="config-label">🏁 Welcome — let's set up your league</h1>
        <p class="setup-intro">
            The database built itself when you loaded this page. All that's left is
            naming the league, opening a season, and pasting in who races.
            Everything here can be changed later.
        </p>

        <?php if ($error): ?>
            <div class="alert-error" style="margin:16px 0;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/setup">
            <?= csrf_field() ?>

            <h2 class="config-label setup-step">1 · The league</h2>
            <div class="form-grid">
                <div class="form-field">
                    <label>League name <span class="setup-req">*</span></label>
                    <input type="text" name="league_name" class="form-input" required maxlength="80"
                           value="<?= htmlspecialchars($_POST['league_name'] ?? '') ?>"
                           placeholder="e.g. CDN Driftsmidler">
                </div>
                <div class="form-field">
                    <label>Tagline</label>
                    <input type="text" name="league_tagline" class="form-input" maxlength="140"
                           value="<?= htmlspecialchars($_POST['league_tagline'] ?? '') ?>"
                           placeholder="Premier Mario Kart Racing League">
                </div>
                <div class="form-field">
                    <label>Governing body</label>
                    <input type="text" name="governing_body_full" class="form-input" maxlength="120"
                           value="<?= htmlspecialchars($_POST['governing_body_full'] ?? '') ?>"
                           placeholder="Organisation Mondial du Karting">
                </div>
                <div class="form-field">
                    <label>Its acronym</label>
                    <input type="text" name="governing_body_short" class="form-input" maxlength="20"
                           value="<?= htmlspecialchars($_POST['governing_body_short'] ?? '') ?>"
                           placeholder="OMK">
                </div>
            </div>

            <h2 class="config-label setup-step">2 · The first season</h2>
            <div class="form-grid">
                <div class="form-field">
                    <label>Season ID <span class="setup-req">*</span></label>
                    <input type="text" name="season_id" class="form-input" required maxlength="6"
                           value="<?= htmlspecialchars($_POST['season_id'] ?? 's01') ?>"
                           pattern="[A-Za-z]{1,3}[0-9]{1,3}" placeholder="s01">
                    <small class="setup-hint">Letters then digits. Every GP is numbered from this — <code>s01gp01</code>.</small>
                </div>
                <div class="form-field">
                    <label>Season name</label>
                    <input type="text" name="season_name" class="form-input" maxlength="80"
                           value="<?= htmlspecialchars($_POST['season_name'] ?? '') ?>"
                           placeholder="The Inaugural Season">
                </div>
                <div class="form-field setup-field-wide">
                    <label>Scoring system <span class="setup-req">*</span></label>
                    <select name="scoring_system" class="form-input scoring-system-select">
                        <?php $chosen = $_POST['scoring_system'] ?? 'average_attendance'; ?>
                        <?php foreach ($scoringSystems as $key => $info): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $chosen === $key ? 'selected' : '' ?>>
                                <?= $info['icon'] ?> <?= htmlspecialchars($info['name']) ?> — <?= htmlspecialchars($info['description']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="setup-hint">Not sure? <strong>Average + Attendance</strong> is the classic. You can switch systems any time, and preview any of them against real results in the Scoring Simulator.</small>
                </div>
            </div>

            <h2 class="config-label setup-step">3 · Who races</h2>
            <div class="form-field">
                <label>Roster — one per line</label>
                <textarea name="roster" class="form-input setup-roster" rows="8"
                          placeholder="Hanna&#10;Tom, The Wall&#10;Andreas"><?= htmlspecialchars($_POST['roster'] ?? '') ?></textarea>
                <small class="setup-hint">
                    Paste straight from a spreadsheet. Add a nickname after a comma if you like.
                    Duplicates are ignored, and you can add more people later.
                </small>
            </div>

            <div class="setup-actions">
                <button type="submit" class="btn btn-primary">🏁 Create my league</button>
            </div>
        </form>
    </div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
