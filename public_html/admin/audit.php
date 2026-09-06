<?php
/**
 * Data audit — every anomaly the pages would otherwise trip over, with a
 * link to the place that fixes it. Read-only; nothing here writes.
 *
 * Path: /cdnmk/public_html/admin/audit.php   (clean URL: /admin/audit)
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';
require_once __DIR__ . '/../../private/includes/settings.php';
require_admin();

$q = fn(string $sql, array $args = []) => (function () use ($pdo, $sql, $args) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(PDO::FETCH_ASSOC); })();
$names = racerNamesMap($pdo);
$sections = [];   // [title, severity, rows[[label, detail, link, linkText]], blurb]
$add = function (string $title, string $severity, array $rows, string $blurb) use (&$sections) { $sections[] = compact('title', 'severity', 'rows', 'blurb'); };

// ── Results ────────────────────────────────────────────────────────────────
$rows = [];
foreach ($q("SELECT gpid, COUNT(*) AS n, MIN(race_date) AS d FROM results WHERE gpid LIKE 's%' GROUP BY gpid HAVING n < 2 ORDER BY gpid") as $r)
    $rows[] = [$r['gpid'], "only {$r['n']} human on {$r['d']}", "/admin/results?search=" . urlencode($r['gpid']), 'Results'];
$add('GPs with fewer than two humans', 'warn', $rows, 'A Grand Prix needs at least two humans for head-to-head systems, Elo and rivalries to mean anything. Usually a half-entered night.');

$rows = [];
foreach ($q("SELECT gpid, racer_id, COUNT(*) AS n FROM results WHERE gpid LIKE 's%' GROUP BY gpid, racer_id HAVING n > 1 ORDER BY gpid") as $r)
    $rows[] = [$r['gpid'], ($names[$r['racer_id']] ?? "racer {$r['racer_id']}") . " entered {$r['n']} times", "/admin/results?search=" . urlencode($r['gpid']), 'Results'];
$add('A racer entered twice in one GP', 'bad', $rows, 'Double entries inflate points and GP counts for that racer. Delete the duplicate row.');

$rows = [];
foreach ($q("SELECT gpid, racer_id, gp_points, rank FROM results WHERE gpid LIKE 's%' AND (gp_points < 0 OR gp_points > ? OR rank IS NULL OR rank < 1 OR rank > 12) ORDER BY gpid", [MK_MAX_GP_POINTS]) as $r)
    $rows[] = [$r['gpid'], ($names[$r['racer_id']] ?? '?') . ": {$r['gp_points']} pts, rank " . ($r['rank'] ?? 'none'), "/admin/results?search=" . urlencode($r['gpid']), 'Results'];
// A shared rank with equal points is a tie and fine; with different points it is a typo.
foreach ($q("SELECT gpid, rank, COUNT(*) AS n, GROUP_CONCAT(gp_points) AS pts FROM results WHERE gpid LIKE 's%' AND rank IS NOT NULL GROUP BY gpid, rank HAVING n > 1 AND COUNT(DISTINCT gp_points) > 1 ORDER BY gpid") as $r)
    $rows[] = [$r['gpid'], "{$r['n']} racers share rank {$r['rank']} on different points ({$r['pts']})", "/admin/results?search=" . urlencode($r['gpid']), 'Results'];
$add('Impossible points or ranks', 'bad', $rows, 'Points must be 0–' . MK_MAX_GP_POINTS . ' and ranks 1–12. Two racers may share a rank only as a tie on equal points; a shared rank on different points is a typo that skews podium counts.');

$rows = [];
foreach ($q("SELECT gpid, COUNT(DISTINCT race_date) AS d FROM results WHERE gpid LIKE 's%' GROUP BY gpid HAVING d > 1") as $r)
    $rows[] = [$r['gpid'], "{$r['d']} different dates on one GP", "/admin/results?search=" . urlencode($r['gpid']), 'Results'];
foreach ($q("SELECT DISTINCT gpid, race_date FROM results WHERE gpid LIKE 's%' AND race_date > date('now', '+1 day') ORDER BY race_date") as $r)
    $rows[] = [$r['gpid'], "dated in the future: {$r['race_date']}", "/admin/results?search=" . urlencode($r['gpid']), 'Results'];
$add('Dates that cannot be right', 'warn', $rows, 'A GP on two dates, or a date in the future, breaks streaks, "The Return", and the timeline ordering.');

$rows = [];
foreach ($q("SELECT racer_id, COUNT(*) AS n FROM results WHERE gpid LIKE 's%' AND (character_used IS NULL OR character_used = '') GROUP BY racer_id ORDER BY n DESC") as $r)
    $rows[] = [$names[$r['racer_id']] ?? "racer {$r['racer_id']}", "{$r['n']} GPs without a character", "/admin/results?search=" . urlencode($names[$r['racer_id']] ?? ''), 'Results'];
$add('Results with no character', 'info', $rows, 'Cards, Wrapped and the character stats fall back to Mii for these. Cosmetic, but easy to fix while the night is fresh.');

// ── Seasons ────────────────────────────────────────────────────────────────
$rows = [];
foreach ($q("SELECT DISTINCT SUBSTR(gpid, 1, 3) AS s FROM results WHERE gpid LIKE 's%' AND SUBSTR(gpid, 1, 3) NOT IN (SELECT season_id FROM season_meta)") as $r)
    $rows[] = [strtoupper($r['s']), 'has results but no season row', '/admin/seasons', 'Seasons'];
foreach ($q("SELECT season_id FROM season_meta WHERE status = 'archived' AND (champion_name IS NULL OR TRIM(champion_name) = '')") as $r)
    $rows[] = [strtoupper($r['season_id']), 'archived without a champion name', '/admin/seasons', 'Seasons'];
foreach ($q("SELECT m.season_id FROM season_meta m WHERE m.status = 'archived' AND m.season_id NOT IN (SELECT DISTINCT season_id FROM season_placements) AND EXISTS (SELECT 1 FROM results WHERE gpid LIKE m.season_id || '%')") as $r)
    $rows[] = [strtoupper($r['season_id']), 'archived without a placements snapshot', '/admin/close-season', 'Close Season'];
foreach ($q("SELECT season_id FROM season_meta WHERE status = 'archived' AND scoring_system = 'territory' AND season_id NOT IN (SELECT season_id FROM season_maps)") as $r)
    $rows[] = [strtoupper($r['season_id']), 'Territory season archived without a frozen map', '/admin/close-season', 'Close Season'];
foreach ($q("SELECT m.season_id, m.champion_name FROM season_meta m WHERE m.status = 'archived' AND m.champion_name IS NOT NULL AND TRIM(m.champion_name) != '' AND TRIM(m.champion_name) NOT IN (SELECT name FROM racers)") as $r)
    $rows[] = [strtoupper($r['season_id']), "champion \"{$r['champion_name']}\" is not a racer name", '/admin/seasons', 'Seasons'];
$active = $q("SELECT season_id FROM season_meta WHERE status = 'active'");
if (count($active) > 1) $rows[] = [implode(', ', array_map('strtoupper', array_column($active, 'season_id'))), 'more than one season is active', '/admin/seasons', 'Seasons'];
if (count($active) === 0) {
    $up = $q("SELECT m.season_id, (SELECT COUNT(*) FROM results WHERE gpid LIKE m.season_id || '%') AS n FROM season_meta m WHERE m.status = 'upcoming' ORDER BY m.season_id DESC LIMIT 1");
    $rows[] = ['—', $up && (int)$up[0]['n'] > 0 ? 'no season is active — ' . strtoupper($up[0]['season_id']) . " is still 'upcoming' with {$up[0]['n']} results; set it active" : 'no season is active', '/admin/seasons', 'Seasons'];
}
$add('Seasons', 'bad', $rows, 'Snapshots feed the placement ledger, career arcs and accolades; a missing one is recomputed live and can drift. Champion names must match a racer for titles and card tiers to count.');

// ── Racers ─────────────────────────────────────────────────────────────────
$rows = [];
foreach ($q("SELECT LOWER(TRIM(name)) AS k, COUNT(*) AS n, GROUP_CONCAT(id) AS ids FROM racers GROUP BY k HAVING n > 1") as $r)
    $rows[] = [$r['k'], "{$r['n']} racers share this name (ids {$r['ids']})", '/admin/racers', 'Racers'];
foreach ($q("SELECT id, name FROM racers WHERE name != TRIM(name) OR name LIKE '%  %'") as $r)
    $rows[] = [$r['name'], 'name has stray spaces', '/admin/racers', 'Racers'];
foreach ($q("SELECT r.id, r.name FROM racers r WHERE COALESCE(r.is_retired, 0) = 0 AND NOT EXISTS (SELECT 1 FROM results WHERE racer_id = r.id) ORDER BY r.name") as $r)
    $rows[] = [$r['name'], 'active racer with no results', '/admin/racers', 'Racers'];
$add('Racers', 'warn', $rows, 'Duplicates split one person\'s career across two profiles. Racers with no results are usually roster typos.');

// ── Tournaments ────────────────────────────────────────────────────────────
$rows = [];
foreach ($q("SELECT id, name FROM tournaments WHERE status = 'completed' AND winner_id IS NULL") as $r)
    $rows[] = [$r['name'], 'completed without a winner', "/admin/tournament_bracket.php?id={$r['id']}", 'Bracket'];
foreach ($q("SELECT t.id, t.name, COUNT(m.id) AS n FROM tournaments t JOIN tournament_matches m ON m.tournament_id = t.id WHERE m.status = 'completed' AND (m.gpid IS NULL OR m.gpid = '') GROUP BY t.id") as $r)
    $rows[] = [$r['name'], "{$r['n']} completed matches with no result rows", "/admin/tournament_bracket.php?id={$r['id']}", 'Bracket'];
foreach ($q("SELECT id, name, created_at FROM tournaments WHERE status NOT IN ('completed', 'cancelled') AND created_at < datetime('now', '-60 days')") as $r)
    $rows[] = [$r['name'], 'still open after 60 days (since ' . substr($r['created_at'], 0, 10) . ')', "/admin/tournament_bracket.php?id={$r['id']}", 'Bracket'];
$add('Tournaments', 'info', $rows, 'Half-finished brackets keep showing as live on the hub and never award their badges.');

// ── Configuration ──────────────────────────────────────────────────────────
$rows = [];
$cfg = kartfolioConfig();
if (!empty($cfg['_missing'])) $rows[] = ['config.php', 'missing — running on config.example.php, admin login disabled', '/admin/settings', 'Settings'];
if (trim((string)getSetting($pdo, 'wall_code', '')) === '') $rows[] = ['Wall code', 'empty — result entry is locked', '/admin/settings', 'Settings'];
if (trim((string)($cfg['gemini_api_key'] ?? '')) === '') $rows[] = ['Gemini API key', 'empty — broadcasts, musings and reports cannot generate', '/admin/settings', 'Settings'];
$pw = (string)($cfg['admin_password'] ?? '');
if ($pw !== '' && !preg_match('/^\$(2y|argon2)/', $pw)) $rows[] = ['Admin password', 'stored as plain text in config.php — replace it with a bcrypt hash', '/admin/settings', 'Settings'];
foreach ($q("SELECT ip, action, COUNT(*) AS n FROM auth_throttle WHERE attempted_at > datetime('now', '-1 day') GROUP BY ip, action HAVING n >= 5 ORDER BY n DESC LIMIT 10") as $r)
    $rows[] = [$r['ip'], "{$r['n']} × {$r['action']} in the last day", '', ''];
$add('Configuration and access', 'warn', $rows, 'Things that stop features working, plus any address hammering the login or wall code in the last day.');

$problems = array_sum(array_map(fn($s) => count($s['rows']), $sections));

$pageTitle = 'Data audit — Admin';
$extraCss  = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>
<div class="container">
    <div class="admin-page-header">
        <h1>🔎 Data audit</h1>
        <p class="admin-page-sub"><?= $problems === 0 ? 'All clear — nothing found across ' . count($sections) . ' checks.' : $problems . ' thing' . ($problems === 1 ? '' : 's') . ' to look at across ' . count($sections) . ' checks. Each row links to where it is fixed.' ?></p>
    </div>

    <?php foreach ($sections as $s): $n = count($s['rows']); ?>
    <section class="audit-section audit-section--<?= $n ? $s['severity'] : 'ok' ?>">
        <div class="audit-head">
            <h2><?= htmlspecialchars($s['title']) ?></h2>
            <span class="audit-count"><?= $n === 0 ? '✓ clear' : $n ?></span>
        </div>
        <p class="audit-blurb"><?= htmlspecialchars($s['blurb']) ?></p>
        <?php if ($n): ?>
        <table class="admin-table audit-table">
            <tbody>
            <?php foreach ($s['rows'] as [$label, $detail, $link, $linkText]): ?>
                <tr>
                    <td class="audit-label"><?= htmlspecialchars($label) ?></td>
                    <td><?= htmlspecialchars($detail) ?></td>
                    <td class="audit-fix"><?php if ($link): ?><a href="<?= htmlspecialchars($link) ?>" class="btn-secondary btn-sm"><?= htmlspecialchars($linkText) ?> →</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
