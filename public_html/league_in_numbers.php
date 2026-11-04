<?php
/**
 * League in numbers — /league-in-numbers
 *
 * How much has this league actually raced, and when: the totals, a calendar of
 * every race day, GPs and racers per month. About the league itself rather than
 * anyone's standing in it, which is the page to send someone who asks what this
 * is, and where next year's annual report gets its figures.
 *
 * Cost: one pass over the season results, plus the shared CPU count. Tournament
 * results (t…) are not GPs and stay out, as everywhere.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/settings.php';

$rows = $pdo->query("SELECT gpid, racer_id, gp_points, date(race_date) AS d FROM results WHERE gpid LIKE 's%' ORDER BY d ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$gpDay = []; $gpSize = []; $racers = []; $points = 0; $perfect = 0; $seasons = [];
$monthRacers = []; $firstMonth = [];
foreach ($rows as $r) {
    $g = $r['gpid']; $rid = (int)$r['racer_id']; $m = substr($r['d'], 0, 7);
    $gpDay[$g] ??= $r['d'];                 // a GP belongs to the day it started
    $gpSize[$g] = ($gpSize[$g] ?? 0) + 1;
    $racers[$rid] = true;
    $seasons[substr($g, 0, 3)] = true;
    $points += (int)$r['gp_points'];
    if ((int)$r['gp_points'] === 60) $perfect++;
    $monthRacers[$m][$rid] = true;
    $firstMonth[$rid] ??= $m;
}
$dayGps = array_count_values($gpDay);
ksort($dayGps);
$newInMonth = array_count_values($firstMonth);
$monthGps = [];
foreach ($gpDay as $d) { $m = substr($d, 0, 7); $monthGps[$m] = ($monthGps[$m] ?? 0) + 1; }

// Every month from the first race to now, the ones nobody raced in included.
$months = [];
if ($dayGps) {
    $to = date('Y-m');
    for ($m = substr(array_key_first($dayGps), 0, 7); $m <= $to; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $active = count($monthRacers[$m] ?? []);
        $new = $newInMonth[$m] ?? 0;
        $months[$m] = ['gps' => $monthGps[$m] ?? 0, 'active' => $active, 'new' => $new, 'back' => $active - $new];
    }
}
$maxGps = $months ? max(1, max(array_column($months, 'gps'))) : 1;
$maxActive = $months ? max(1, max(array_column($months, 'active'))) : 1;
$peakGps = $months ? array_search(max(array_column($months, 'gps')), array_column($months, 'gps'), true) : 0;
$peakActive = $months ? array_search(max(array_column($months, 'active')), array_column($months, 'active'), true) : 0;

// Race days by weekday, Monday first.
$weekdays = array_fill(1, 7, 0);
foreach (array_keys($dayGps) as $d) $weekdays[(int)date('N', strtotime($d))]++;
$maxWeekday = max(1, max($weekdays));
$dayNames = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// The quieter facts.
$facts = [];
if ($dayGps) {
    $bigDay = array_keys($dayGps, max($dayGps))[0];
    $facts[] = ['Busiest day', max($dayGps) . ' GPs', date('l j F Y', strtotime($bigDay))];
    $bigMonth = array_keys($monthGps, max($monthGps))[0];
    $facts[] = ['Busiest month', max($monthGps) . ' GPs', date('F Y', strtotime($bigMonth . '-01'))];
    $days = array_keys($dayGps); $gap = 0; $gapEnd = null;
    for ($i = 1; $i < count($days); $i++) {
        $n = (int)((strtotime($days[$i]) - strtotime($days[$i - 1])) / 86400);
        if ($n > $gap) { $gap = $n; $gapEnd = $days[$i]; }
    }
    if ($gapEnd) $facts[] = ['Longest break', $gap . ' days', 'ended ' . date('j F Y', strtotime($gapEnd))];
    $facts[] = ['Fullest GP', max($gpSize) . ' racers', count(array_keys($gpSize, max($gpSize))) . ' GP' . (count(array_keys($gpSize, max($gpSize))) === 1 ? '' : 's') . ' that size'];
    $cpu = cpuAheadStats($pdo);
    $cpuGps = array_sum(array_column($cpu['racers'], 'gps'));
    if ($cpuGps) {
        $facts[] = ['Ahead of every CPU', round(100 * array_sum(array_column($cpu['racers'], 'clean')) / $cpuGps) . '%', "of racers' finishes" . ($cpu['skipped'] ? ', leaving out ' . strtoupper(implode(', ', $cpu['skipped'])) : '')];
    }
}

// The calendar: one column per week, Monday at the top. The steps are chosen
// from the data's own shape: most race days run 1 to 5 GPs, a few reach 14.
$heatStep = fn(int $n) => $n === 0 ? 0 : ($n === 1 ? 1 : ($n <= 3 ? 2 : ($n <= 6 ? 3 : 4)));
$heatKey = [1 => '1 GP', 2 => '2–3', 3 => '4–6', 4 => '7+'];
$calWeeks = 0; $calCells = ''; $calMonths = ''; $monthStarts = [];
if ($dayGps) {
    $first = strtotime(rollCallWeekOf(array_key_first($dayGps)));
    $today = strtotime(date('Y-m-d'));
    $lastMonth = '';
    for ($t = $first, $i = 0; $t <= $today; $t = strtotime('+1 day', $t), $i++) {
        $col = (int)floor($i / 7) + 2;     // column 1 holds the weekday labels
        $rowN = ($i % 7) + 2;              // row 1 holds the month labels
        $d = date('Y-m-d', $t);
        $n = $dayGps[$d] ?? 0;
        $tip = date('D j M Y', $t) . ': ' . ($n ? $n . ' GP' . ($n === 1 ? '' : 's') : 'no racing');
        $calCells .= '<span class="ln-cell ln-h' . $heatStep($n) . '" style="grid-column:' . $col . ';grid-row:' . $rowN . '" data-tip="' . htmlspecialchars($tip) . '"></span>';
        $mKey = date('Y-m', $t);
        if ($i % 7 === 0 && $mKey !== $lastMonth) { $monthStarts[] = [$col, $t]; $lastMonth = $mKey; }
        $calWeeks = $col - 1;
    }
    // A month that gets under three columns (the league began on 19 November)
    // gives its label up to the next one, or the two print on top of each other.
    $keep = [];
    foreach ($monthStarts as $n => [$col, $t]) {
        if (isset($monthStarts[$n + 1]) && $monthStarts[$n + 1][0] - $col < 3) continue;
        $keep[] = [$col, $t];
    }
    foreach ($keep as $n => [$col, $t]) {
        $label = ($n === 0 || date('n', $t) === '1') ? date('M y', $t) : date('M', $t);
        $calMonths .= '<span class="ln-cal-month" style="grid-column:' . $col . ' / span 3;grid-row:1">' . htmlspecialchars($label) . '</span>';
    }
}

/** A month's label on a chart axis: the year only where it changes, and thinned once there are a lot of months. */
function lnMonthLabel(string $m, int $i, int $total): string {
    $every = $total > 24 ? 3 : ($total > 14 ? 2 : 1);
    if ($i % $every !== 0) return '';
    $ts = strtotime($m . '-01');
    return ($i === 0 || date('n', $ts) === '1') ? date('M y', $ts) : date('M', $ts);
}

$leagueName = getSetting($pdo, 'league_name', 'Kartfolio');
$since = $dayGps ? date('F Y', strtotime(array_key_first($dayGps))) : '';
$pageTitle = 'League in numbers';
$ogTitle = "$leagueName in numbers";
$ogDescription = $dayGps
    ? number_format(count($gpDay)) . ' Grands Prix on ' . count($dayGps) . ' race days, with ' . count($racers) . " racers, since $since."
    : 'The league has yet to race.';
$extraCss = '<link rel="stylesheet" href="/assets/css/league_numbers.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container ln-root">
    <nav class="breadcrumb">
        <a href="/">← Home</a><span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">League in numbers</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">📊 League in numbers</h1>
        <p class="page-subtitle"><?= $since !== '' ? 'RACING SINCE ' . htmlspecialchars(mb_strtoupper($since)) : 'NOTHING RACED YET' ?></p>
    </header>

    <?php if (!$dayGps): ?>
        <p class="ln-note">The numbers start with the first Grand Prix.</p>
    <?php else: ?>

    <div class="ln-tiles">
        <?php foreach ([
            ['Grands Prix', number_format(count($gpDay))],
            ['Race days', number_format(count($dayGps))],
            ['Racers', number_format(count($racers))],
            ['Seasons', number_format(count($seasons))],
            ['Points scored', number_format($points)],
            ['Perfect 60s', number_format($perfect)],
        ] as [$label, $value]): ?>
        <div class="ln-tile"><div class="ln-tile-value"><?= $value ?></div><div class="ln-tile-label"><?= htmlspecialchars($label) ?></div></div>
        <?php endforeach; ?>
    </div>

    <section class="ln-card">
        <h2 class="ln-h">Every race day</h2>
        <div class="ln-scroll" id="ln-cal-scroll" tabindex="0" role="img" aria-label="Calendar of race days, one square per day. The table below has the totals per month.">
            <div class="ln-cal" style="grid-template-columns: 34px repeat(<?= $calWeeks ?>, 13px);">
                <?= $calMonths ?>
                <?php foreach ([0 => 'Mon', 2 => 'Wed', 4 => 'Fri'] as $i => $label): ?><span class="ln-cal-day" style="grid-column:1;grid-row:<?= $i + 2 ?>"><?= $label ?></span><?php endforeach; ?>
                <?= $calCells ?>
            </div>
        </div>
        <div class="ln-legend" aria-hidden="true">
            <span>GPs that day:</span>
            <?php foreach ($heatKey as $step => $label): ?><span class="ln-key"><span class="ln-cell ln-h<?= $step ?>"></span><?= $label ?></span><?php endforeach; ?>
        </div>
    </section>

    <div class="ln-two">
        <section class="ln-card">
            <h2 class="ln-h">Grands Prix per month</h2>
            <div class="ln-scroll" role="img" aria-label="Bar chart of Grands Prix per month. The table below has the same numbers.">
                <div class="ln-bars" style="--ln-cols: <?= count($months) ?>;">
                    <?php $i = 0; foreach ($months as $m => $x): ?>
                    <div class="ln-col" tabindex="0" data-tip="<?= htmlspecialchars(date('F Y', strtotime($m . '-01')) . ': ' . $x['gps'] . ' GP' . ($x['gps'] === 1 ? '' : 's')) ?>">
                        <div class="ln-plot">
                            <?php if ($i === $peakGps): ?><span class="ln-peak"><?= (int)$x['gps'] ?></span><?php endif; ?>
                            <?php if ($x['gps']): ?><span class="ln-bar ln-s1" style="height:<?= round(100 * $x['gps'] / $maxGps, 1) ?>%"></span><?php endif; ?>
                        </div>
                        <div class="ln-x<?= $i % 2 ? ' ln-x-odd' : '' ?>"><?= htmlspecialchars(lnMonthLabel($m, $i, count($months))) ?></div>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
        </section>

        <section class="ln-card">
            <h2 class="ln-h">Racers per month</h2>
            <div class="ln-scroll" role="img" aria-label="Stacked bar chart of racers per month, split into returning and first-time racers. The table below has the same numbers.">
                <div class="ln-bars" style="--ln-cols: <?= count($months) ?>;">
                    <?php $i = 0; foreach ($months as $m => $x): ?>
                    <div class="ln-col" tabindex="0" data-tip="<?= htmlspecialchars(date('F Y', strtotime($m . '-01')) . ': ' . $x['active'] . ' racing, ' . $x['new'] . ' for the first time') ?>">
                        <div class="ln-plot">
                            <?php if ($i === $peakActive): ?><span class="ln-peak"><?= (int)$x['active'] ?></span><?php endif; ?>
                            <?php if ($x['active']): ?>
                            <div class="ln-stack" style="height:<?= round(100 * $x['active'] / $maxActive, 1) ?>%">
                                <?php if ($x['new']): ?><span class="ln-seg ln-s2" style="flex:<?= (int)$x['new'] ?> 0 0"></span><?php endif; ?>
                                <?php if ($x['back']): ?><span class="ln-seg ln-s1" style="flex:<?= (int)$x['back'] ?> 0 0"></span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="ln-x<?= $i % 2 ? ' ln-x-odd' : '' ?>"><?= htmlspecialchars(lnMonthLabel($m, $i, count($months))) ?></div>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
            <div class="ln-legend">
                <span class="ln-key"><span class="ln-swatch ln-s1"></span>Returning</span>
                <span class="ln-key"><span class="ln-swatch ln-s2"></span>First time</span>
            </div>
        </section>
    </div>

    <div class="ln-two">
        <section class="ln-card">
            <h2 class="ln-h">Race days by weekday</h2>
            <div class="ln-weekdays">
                <?php foreach ($weekdays as $n => $count): ?>
                <div class="ln-wd" data-tip="<?= htmlspecialchars($dayNames[$n] . ': ' . $count . ' race day' . ($count === 1 ? '' : 's')) ?>">
                    <span class="ln-wd-label"><?= htmlspecialchars($dayNames[$n]) ?></span>
                    <span class="ln-wd-track"><?php if ($count): ?><span class="ln-wd-bar ln-s1" style="width:<?= round(100 * $count / $maxWeekday, 1) ?>%"></span><?php endif; ?></span>
                    <span class="ln-wd-value"><?= (int)$count ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="ln-card">
            <h2 class="ln-h">The quieter facts</h2>
            <dl class="ln-facts">
                <?php foreach ($facts as [$label, $value, $detail]): ?>
                <div><dt><?= htmlspecialchars($label) ?></dt><dd><strong><?= htmlspecialchars($value) ?></strong> <span><?= htmlspecialchars($detail) ?></span></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>
    </div>

    <details class="ln-card ln-table-wrap">
        <summary class="ln-h">Every month as a table</summary>
        <table class="clean-table">
            <thead><tr><th>Month</th><th class="txt-right">GPs</th><th class="txt-right">Racers</th><th class="txt-right">First time</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($months, true) as $m => $x): ?>
                <tr><td><?= date('F Y', strtotime($m . '-01')) ?></td><td class="txt-right"><?= (int)$x['gps'] ?></td><td class="txt-right"><?= (int)$x['active'] ?></td><td class="txt-right"><?= (int)$x['new'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php endif; ?>
</div>

<div class="ln-tip" id="ln-tip" role="status" hidden></div>
<script>
(function () {
    const tip = document.getElementById('ln-tip');
    function show(el, x, y) {
        tip.textContent = el.dataset.tip;
        tip.hidden = false;
        const w = tip.offsetWidth;
        tip.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, x - w / 2)) + 'px';
        tip.style.top = Math.max(8, y - tip.offsetHeight - 12) + 'px';
    }
    document.addEventListener('pointermove', function (e) {
        const el = e.target.closest && e.target.closest('.ln-root [data-tip]');
        if (el) show(el, e.clientX, e.clientY); else tip.hidden = true;
    });
    document.addEventListener('focusin', function (e) {
        if (!e.target.matches || !e.target.matches('.ln-root [data-tip]')) return;
        const r = e.target.getBoundingClientRect();
        show(e.target, r.left + r.width / 2, r.top);
    });
    document.addEventListener('focusout', function () { tip.hidden = true; });
    // Open the calendar on the latest weeks, which on a phone are off to the right.
    const cal = document.getElementById('ln-cal-scroll');
    if (cal) cal.scrollLeft = cal.scrollWidth;
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
