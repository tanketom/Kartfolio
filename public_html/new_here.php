<?php
/**
 * New here? — /new-here
 *
 * The page to send a visitor to: what this league is, how the current season is
 * scored, what a race night looks like, the words people use, how to join, and
 * where everything is. Everything is read from the league itself (settings, the
 * current season, the registry, the lexicon), so it stays true and works for any
 * league running Kartfolio. /about is the longer tour of the platform.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';
require_once __DIR__ . '/../private/includes/wrapped_personas.php';

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio');
$bodyFull   = getSetting($pdo, 'governing_body_full', '');
$bodyShort  = getSetting($pdo, 'governing_body_short', '');
$joinText   = trim((string)getSetting($pdo, 'new_here_join', ''));

// The league in four numbers.
$firstRace = wrappedFirstRace($pdo);
$totals = $pdo->query("SELECT COUNT(DISTINCT gpid) AS gps, COUNT(DISTINCT racer_id) AS racers FROM results WHERE gpid LIKE 's%'")->fetch(PDO::FETCH_ASSOC) ?: ['gps' => 0, 'racers' => 0];
$seasonsRaced = (int)$pdo->query("SELECT COUNT(DISTINCT SUBSTR(gpid, 1, 3)) FROM results WHERE gpid LIKE 's%'")->fetchColumn();

// This season: its name, dates and the one-line version of how it is scored.
$seasonId = getCurrentSeasonNumber();
$st = $pdo->prepare("SELECT season_name, season_description, start_date, end_date FROM season_meta WHERE season_id = ?");
$st->execute([$seasonId]);
$season = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$scoring = getScoringSystemInfo($pdo, $seasonId);
$scoringDef = getScoringSystemDef($scoring['system'] ?? 'average_attendance');
$scoringLong = trim((string)($scoringDef['long_description'] ?? ''));
// One sentence: the rest is one click away on /scoring, and the later sentences get into knobs.
if ($scoringLong !== '' && preg_match('/^(.+?[.!?])(\s|$)/su', strip_tags($scoringLong), $m)) $scoringLong = $m[1];
$teams = isTeamSeason($pdo, $seasonId) ? getTeamStandings($pdo, $seasonId) : [];
$fmtDate = fn($d) => $d ? date('j F', strtotime($d)) : null;

// A few of the words you will hear, from the league's own lexicon: the ones a visitor
// meets on the first night, in this order. Terms a league has deleted just drop out.
$wordList = ['GP', 'Ludwig Obstruction', 'perfect 60', 'Elo', 'Nemesis Index', 'Wall Code', 'Mikkoliiga'];
$words = [];
try {
    $st = $pdo->prepare("SELECT term, slug, definition FROM lexicon_terms WHERE term IN (" . implode(',', array_fill(0, count($wordList), '?')) . ")");
    $st->execute($wordList);
    $found = array_column($st->fetchAll(PDO::FETCH_ASSOC), null, 'term');
    foreach ($wordList as $w) if (isset($found[$w]) && ($w !== 'Mikkoliiga' || moduleEnabled($pdo, 'mikkoliiga'))) $words[] = $found[$w];
} catch (Throwable $e) { error_log('new here lexicon: ' . $e->getMessage()); }
$firstSentence = fn(string $t) => preg_match('/^(.+?[.!?])(\s|$)/su', $t, $m) ? $m[1] : $t;

$pageTitle = 'New here? — ' . $leagueName;
$ogTitle = "New to the $leagueName?";
$ogDescription = 'What this league is, how this season is scored, and what a race night looks like.';
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container nh-root">
    <header class="page-header">
        <h1 class="page-title">New here?</h1>
        <p class="page-subtitle">THE LEAGUE IN FIVE MINUTES</p>
    </header>

    <section class="nh-card nh-lead">
        <p><strong><?= htmlspecialchars($leagueName) ?></strong> is a Mario Kart 8 Deluxe league. People race Grand Prix (four races, 150cc) whenever they get together, every result goes in here, and the site keeps the standings, the records and the stories<?= $bodyFull !== '' ? ', all overseen by the ' . htmlspecialchars($bodyFull) . ($bodyShort !== '' ? ' (' . htmlspecialchars($bodyShort) . '), which takes itself far too seriously' : '') : '' ?>.</p>
        <?php if ($firstRace): ?>
        <div class="nh-facts">
            <div><strong><?= date('j M Y', strtotime($firstRace)) ?></strong><span>First race</span></div>
            <div><strong><?= number_format((int)$totals['gps']) ?></strong><span>Grand Prix</span></div>
            <div><strong><?= number_format((int)$totals['racers']) ?></strong><span>Racers</span></div>
            <div><strong><?= $seasonsRaced ?></strong><span>Season<?= $seasonsRaced === 1 ? '' : 's' ?></span></div>
        </div>
        <?php endif; ?>
    </section>

    <section class="nh-card">
        <h2 class="nh-h">This season</h2>
        <p><strong><?= htmlspecialchars($season['season_name'] ?? strtoupper($seasonId)) ?></strong><?php if (!empty($season['start_date']) && !empty($season['end_date'])): ?>, <?= $fmtDate($season['start_date']) ?> to <?= $fmtDate($season['end_date']) ?><?php endif; ?>.
            <?= !empty($season['season_description']) ? htmlspecialchars($season['season_description']) : '' ?></p>
        <p>It is scored with <strong><?= htmlspecialchars(($scoring['icon'] ?? '') . ' ' . ($scoring['name'] ?? '')) ?></strong>. <?= htmlspecialchars($scoringLong) ?></p>
        <?php if ($teams): ?>
        <p>It is a team season: everyone races for one of <?= count($teams) ?> teams, and the top team's racers are all champions.</p>
        <ul class="nh-teams">
            <?php foreach ($teams as $tm): ?><li><span class="racer-team-dot" style="background:<?= htmlspecialchars($tm['color']) ?>"></span><?= htmlspecialchars($tm['name']) ?> <small><?= count($tm['members'] ?? []) ?> racers</small></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <p class="nh-links"><a href="/">See the standings →</a> <a href="/scoring">How the points work →</a><?php if ($teams): ?> <a href="/teams">The teams →</a><?php endif; ?></p>
    </section>

    <section class="nh-card">
        <h2 class="nh-h">A race night</h2>
        <ol class="nh-steps">
            <li><strong>Pick a cup.</strong> Press <em>🎲 What cup?</em> at the top of any page. Kartificial, the host, draws one, favouring cups the group has raced least this season.</li>
            <li><strong>Race a Grand Prix.</strong> Four races, 150cc, everyone on the same console. The CPU karts fill the grid to twelve.</li>
            <li><strong>Enter the scores.</strong> <em>Add GP scores</em> takes each racer's points and character. It asks for the league's wall code, which whoever runs the night can tell you.</li>
            <li><strong>Watch it land.</strong> The standings, records and badges update straight away<?= moduleEnabled($pdo, 'stickers') ? ', everyone who raced gets a sticker pack' : '' ?><?= moduleEnabled($pdo, 'broadcasts') ? ', and the news shows write it up afterwards' : '' ?>.</li>
        </ol>
    </section>

    <?php if ($words): ?>
    <section class="nh-card">
        <h2 class="nh-h">Words you will hear</h2>
        <dl class="nh-words">
            <?php foreach ($words as $w): ?>
            <div><dt><a href="/lexicon/<?= htmlspecialchars($w['slug']) ?>"><?= htmlspecialchars($w['term']) ?></a></dt><dd><?= htmlspecialchars($firstSentence((string)$w['definition'])) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="nh-links"><a href="/lexicon">The whole lexicon →</a></p>
    </section>
    <?php endif; ?>

    <section class="nh-card nh-join">
        <h2 class="nh-h">Joining in</h2>
        <p><?= nl2br(htmlspecialchars($joinText !== '' ? $joinText : 'Come to a race night and race. Your first Grand Prix puts you on the roster, and your profile, trading card and badges start from there. Nobody minds if you finish twelfth.')) ?></p>
    </section>

    <section class="nh-card">
        <h2 class="nh-h">Find your way</h2>
        <div class="nh-grid">
            <a href="/"><strong>🏁 Standings</strong><span>Who is winning this season</span></a>
            <a href="/racers"><strong>🏎️ Racers</strong><span>Every profile, card and badge</span></a>
            <a href="/archive"><strong>📰 News</strong><span>The league's broadcasts</span></a>
            <a href="/records"><strong>📚 Record Book</strong><span>Best ever, worst ever</span></a>
            <a href="/badges-overview"><strong>🏅 Badges</strong><span>What there is to earn</span></a>
            <a href="/season-archives"><strong>🏆 Hall of Fame</strong><span>Every past champion</span></a>
            <a href="/league-in-numbers"><strong>📊 League in numbers</strong><span>The whole history at a glance</span></a>
            <?php if (moduleEnabled($pdo, 'wrapped') && wrappedIsOpen($pdo)): ?><a href="/wrapped"><strong>🎁 Wrapped</strong><span>The year, racer by racer</span></a><?php endif; ?>
            <a href="/about"><strong>ℹ️ About</strong><span>The longer tour of the site</span></a>
        </div>
    </section>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
