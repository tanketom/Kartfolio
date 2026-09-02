<?php
/**
 * Scoring Explainer - How GPScore is Calculated
 * Path: /cdnmk/public_html/scoring.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$pageTitle = "Scoring Explained - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

$seasonId = $_GET['season'] ?? getCurrentSeasonNumber();

// Fetch season meta
$rules = getSeasonRules($pdo, $seasonId);

$scoringSystem = $rules['scoring_system'] ?? 'average_attendance';
$scoringInfo = getScoringSystemInfo($pdo, $seasonId);

// Fetch all seasons for selector
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all racers with results this season
$racersStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'
    ORDER BY r.name
");
$racersStmt->execute([$seasonId . '%']);
$racers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

// Build per-racer breakdown
$racerBreakdowns = [];
foreach ($racers as $racer) {
    $rid = $racer['id'];

    // All GPs, points ascending (gp_points ASC, id ASC) — the same rows and
    // the same order the scoring engine drops from, off the season cache.
    $gps = getRacerSeasonRows($pdo, (int)$rid, $seasonId);

    $totalRaces = count($gps);
    $finalScore = calculateGPScore($pdo, $rid, $seasonId);

    $entry = [
        'name' => $racer['name'],
        'gps' => $gps,
        'total' => $totalRaces,
        'score' => $finalScore,
        'dropped' => [],
        'counted' => [],
    ];

    if ($scoringSystem === 'average_attendance') {
        // The one formula (gp_logic aaFromRows) — dropped/counted rows and the
        // two components the page explains.
        $aa = aaFromRows($gps, $rules);
        $entry['num_dropped']      = $aa['num_dropped'];
        $entry['dropped']          = $aa['dropped'];
        $entry['counted']          = $aa['counted'];
        $entry['average']          = round($aa['avg'], 2);
        $entry['attendance_bonus'] = round($aa['att'], 2);
    } elseif ($scoringSystem === 'preseason') {
        $ps = preseasonFromRows($gps);
        $entry['num_dropped'] = $ps['num_dropped'];
        $entry['dropped']     = $ps['dropped'];
        $entry['counted']     = $ps['counted'];
        $entry['average']     = round($ps['avg'], 2);
    } elseif ($scoringSystem === 'top_12_unique') {
        // Best score per cup, top 12
        // Best per cup off the season cache — this was 24 MAX() queries per racer.
        $cupBests = array_filter(getBestScorePerCup($pdo, (int)$rid, $seasonId, getMKAllCups()), fn($v) => $v !== null && $v > 0);
        arsort($cupBests);
        $entry['cup_bests'] = $cupBests;
        $top12 = array_slice($cupBests, 0, 12, true);
        $dropped = array_slice($cupBests, 12, null, true);
        $entry['top12'] = $top12;
        $entry['dropped_cups'] = $dropped;
        $entry['top12_total'] = array_sum($top12);
        $entry['bubble_score'] = count($cupBests) >= 12 ? min($top12) : null;
        $entry['perfects'] = count(array_filter($top12, fn($p) => $p === MK_MAX_GP_POINTS));
    } elseif ($scoringSystem === 'positional_points') {
        // Ladder points per GP, best-first, with the best-N cut line marked.
        $detail = positionalPointsDetail($pdo, (int)$rid, $seasonId, $rules);
        $entry['pos']          = $detail;
        $entry['pos_total']    = array_sum(array_column($detail['rows'], 'pts'));
        $entry['pos_counted']  = array_sum(array_column(
            array_filter($detail['rows'], fn($r) => $r['counted']), 'pts'));
        $entry['pos_wins']     = $detail['pos_counts'][1] ?? 0;
    } elseif ($scoringSystem === 'monster_hunt') {
        // Per-GP role + CR + outcome + XP, straight from the MONSTER HUNT
        // engine (mhSeasonHunts) — the same hunts the official score sums.
        $slay_xp         = (int)($rules['mh_slay_xp']           ?? 100);
        $survive_xp      = (int)($rules['mh_survive_xp']         ?? 20);
        $party_bonus_xp  = (int)($rules['mh_party_bonus_xp']     ?? 50);
        $monster_win_xp  = (int)($rules['mh_monster_win_xp']     ?? 80);
        $monster_part_xp = (int)($rules['mh_monster_partial_xp'] ?? 30);
        $monster_loss_xp = (int)($rules['mh_monster_loss_xp']    ?? -40);
        $best_x          = max(1, (int)($rules['mh_best_x']      ?? 20));
        $racerName       = $racer['name'];

        $hunts = []; // per-GP breakdown
        foreach (mhSeasonHunts($pdo, $seasonId, $rules) as $h) {
            if (!isset($h['xp'][$racerName])) continue;
            $xp = $h['xp'][$racerName];

            // Solo GP — straight survive XP.
            if ($h['solo']) {
                $hunts[] = [
                    'gpid' => $h['gpid'], 'cup' => $h['cup'], 'date' => $h['date'],
                    'role' => 'Lone Adventurer', 'monster' => null,
                    'cr_tier' => null, 'cr_mult' => null,
                    'outcome' => 'no monster', 'xp' => $xp,
                    'explanation' => "+$xp (no Monster — solo)",
                ];
                continue;
            }

            $cr = $h['cr_tier']; $crMult = $h['cr_mult'];
            $outcome = $h['tpk'] ? 'TPK' : ($h['full_slay'] ? 'Full Slay' : 'Partial');

            if ($racerName === $h['monster']) {
                $role = 'Monster';
                if ($h['tpk'])            $expl = "+$monster_win_xp (Monster Win — beat the whole field)";
                elseif ($h['full_slay'])  $expl = ($monster_loss_xp >= 0 ? '+' : '') . "$monster_loss_xp (Full Slay — every adventurer beat you)";
                else                      $expl = "+$monster_part_xp (Monster Partial — survived some)";
            } elseif (in_array($racerName, $h['slayers'], true)) {
                $role = 'Slayer';
                $base = (int)round($slay_xp * $crMult);
                $expl = "+$base ($slay_xp slay × {$crMult} CR{$cr})"
                      . ($h['full_slay'] ? " + $party_bonus_xp (Party Bonus)" : '');
            } else {
                $role = 'Survivor';
                $expl = "+$survive_xp (survived but didn't beat the Monster)";
            }

            $hunts[] = [
                'gpid' => $h['gpid'], 'cup' => $h['cup'], 'date' => $h['date'],
                'role' => $role, 'monster' => $h['monster'],
                'cr_tier' => $cr, 'cr_mult' => $crMult,
                'outcome' => $outcome, 'xp' => $xp,
                'explanation' => $expl,
            ];
        }

        // Pick the best-X hunts by ranking them and taking the top slice.
        //
        // This used to derive a cutoff XP and then accept hunts in
        // CHRONOLOGICAL order while any scored >= cutoff. With ties at the
        // cutoff, early tied nights ate the slots and a later, bigger haul got
        // excluded — flagging the wrong hunts as counted and under-reporting
        // the sum (Hanna, s03: 590 shown vs her real 705 score). Ordering is
        // explicit (xp desc, then date, then gpid) so the cut never wobbles.
        $order = array_keys($hunts);
        usort($order, function ($a, $b) use ($hunts) {
            if ($hunts[$a]['xp'] !== $hunts[$b]['xp']) return $hunts[$b]['xp'] <=> $hunts[$a]['xp'];
            if ($hunts[$a]['date'] !== $hunts[$b]['date']) return strcmp($hunts[$a]['date'], $hunts[$b]['date']);
            return strcmp($hunts[$a]['gpid'], $hunts[$b]['gpid']);
        });
        $countedKeys  = array_flip(array_slice($order, 0, $best_x));
        $countedCount = 0;
        $countedTotal = 0;
        foreach ($hunts as $k => &$h) {
            $h['counted'] = isset($countedKeys[$k]);
            if ($h['counted']) {
                $countedCount++;
                $countedTotal += $h['xp'];
            }
        }
        unset($h);

        // Chronological order in the UI so storylines read naturally.
        usort($hunts, fn($a, $b) => strcmp($a['date'] . $a['gpid'], $b['date'] . $b['gpid']));

        $entry['hunts'] = $hunts;
        $entry['best_x'] = $best_x;
        $entry['counted_total'] = $countedTotal;
        $mh = getMonsterHuntDisplayData($pdo, $rid, $seasonId, $rules);
        // Average XP per GP PLAYED — the canonical figure (gp_logic's
        // getMonsterHuntDisplayData), and the one the title is derived from.
        // This used to divide by the counted hunts only, producing a number
        // that matched nothing: 109.6 sat next to "Apex Predator", a title
        // whose band is 85-105, so the page contradicted itself.
        $entry['avg_xp']   = $mh['avg_xp'] ?? 0;
        $entry['mh_title'] = $mh['title'] ?? null;
        $entry['mh_level'] = $mh['level'] ?? null;
    }

    $racerBreakdowns[] = $entry;
}

// Sort by score descending
usort($racerBreakdowns, fn($a, $b) => $b['score'] <=> $a['score']);

$minThreshold = (int)($rules['min_races_threshold'] ?? 3);
?>

<div class="stats-container">
    <div class="racer-card scr-header-card">
        <div class="scr-top-row">
            <div>
                <h1 class="scr-title"><?= $scoringInfo['icon'] ?> Scoring Explained</h1>
                <p class="scr-subtitle"><?= htmlspecialchars(strtoupper($seasonId)) ?> &mdash; <?= htmlspecialchars($scoringInfo['name']) ?></p>
            </div>
            <form method="GET" action="/scoring" class="season-selector-form">
                <select name="season" onchange="this.form.submit()" class="season-selector-select">
                    <?php foreach ($availableSeasons as $s): ?>
                        <option value="<?= htmlspecialchars($s['season_id']) ?>" <?= ($s['season_id'] === $seasonId) ? 'selected' : '' ?>>
                            <?= 'Season ' . strtoupper($s['season_id']) . ($s['status'] === 'archived' ? ' (Archived)' : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="scr-formula">
            <?php if ($scoringSystem === 'average_attendance'): ?>
                <h2>How GPScore is calculated</h2>
                <div class="scr-formula-box">
                    <code>GPScore = Average(counted GPs) + Attendance Bonus</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">
                        <strong>Drop rate:</strong> 1 worst GP dropped per <?= (int)($rules['drop_rate'] ?? 10) ?> played
                    </div>
                    <div class="scr-rule">
                        <strong>Attendance bonus:</strong> +<?= $rules['attendance_weight'] ?? 1.0 ?> per GP, max <?= $rules['weekly_bonus_cap'] ?? 2 ?> per week
                    </div>
                    <div class="scr-rule">
                        <strong>Minimum races:</strong> <?= $minThreshold ?> GPs required to appear on leaderboard
                    </div>
                </div>

            <?php elseif ($scoringSystem === 'preseason'): ?>
                <h2>How the Pre-Season score is calculated</h2>
                <div class="scr-formula-box">
                    <code>Score = Average(counted GPs)</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">
                        <strong>Drop rate:</strong> 1 worst GP dropped per <?= (int)($rules['drop_rate'] ?? 10) ?> played
                    </div>
                </div>

            <?php elseif ($scoringSystem === 'top_12_unique'): ?>
                <h2>How Top 12 Unique scoring works</h2>
                <div class="scr-formula-box">
                    <code>Score = Sum of best score from each of your top 12 cups</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">
                        <strong>Per cup:</strong> Only your best GP in each cup counts
                    </div>
                    <div class="scr-rule">
                        <strong>Top 12:</strong> Your 12 highest-scoring cups are summed; the rest are dropped
                    </div>
                </div>

            <?php elseif ($scoringSystem === 'positional_points'):
                $posMode  = $rules['pos_mode'] ?? 'best_n';
                $posBestN = (int)($rules['best_n_count'] ?? 15);
                $formula  = $posMode === 'average'
                    ? 'Score = Average(ladder points per GP)'
                    : ($posMode === 'sum'
                        ? 'Score = Sum(ladder points from every GP)'
                        : "Score = Sum(ladder points from your best $posBestN GPs)");
            ?>
                <h2>🏁 How Positional Points works</h2>
                <div class="scr-formula-box">
                    <code><?= htmlspecialchars($formula) ?></code>
                </div>
                <p class="diff-description" style="margin-top:8px;">
                    Only <strong>where you finish</strong> matters — not by how much. Every GP pays out on a fixed Mario Kart ladder, so a win banks 15 points whether you led by a lap or a bumper.
                </p>
                <?php $posOrdinals = array_map('ordinal', range(1, count(MK_POINTS_SCALE))); ?>
                <div class="scr-pos-ladder">
                    <?php foreach (MK_POINTS_SCALE as $i => $pts): ?>
                        <div class="scr-pos-rung<?= $i === 0 ? ' scr-pos-rung--win' : '' ?>">
                            <span class="scr-pos-place"><?= $posOrdinals[$i] ?? ($i + 1) ?></span>
                            <span class="scr-pos-pts"><?= $pts ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="scr-pos-rung scr-pos-rung--none">
                        <span class="scr-pos-place">13th+</span>
                        <span class="scr-pos-pts">0</span>
                    </div>
                </div>
                <div class="scr-rules">
                    <?php if ($posMode === 'best_n'): ?>
                        <div class="scr-rule"><strong>Best <?= $posBestN ?>:</strong> your <?= $posBestN ?> highest-scoring GPs are summed; extra nights don't hurt you, they just have to beat one of your counted nights to matter</div>
                    <?php elseif ($posMode === 'average'): ?>
                        <div class="scr-rule"><strong>Average mode:</strong> every GP counts and the score is the per-GP average — one bad night lowers it, so consistency rules</div>
                    <?php else: ?>
                        <div class="scr-rule"><strong>Sum mode:</strong> every GP counts and adds up — showing up more is a real advantage</div>
                    <?php endif; ?>
                    <div class="scr-rule"><strong>Minimum GPs:</strong> <?= $minThreshold ?> required to appear on the leaderboard</div>
                    <div class="scr-rule"><strong>Ties break on:</strong> count-back (most 1sts, then 2nds, then 3rds…) → fewest GPs needed to reach the score → fewer GPs played → name A→Z</div>
                </div>

            <?php elseif ($scoringSystem === 'black_box'): ?>
                <h2>Black Box</h2>
                <div class="scr-formula-box">
                    <code>???</code>
                </div>
                <div class="scr-rules">
                    <div class="scr-rule">The Black Box scoring formula is classified.</div>
                </div>

            <?php elseif ($scoringSystem === 'monster_hunt'): ?>
                <h2>👹 How MONSTER HUNT scoring works</h2>
                <div class="scr-formula-box">
                    <code>Score = Sum of your <?= (int)($rules['mh_best_x'] ?? 20) ?> best XP hauls · Title = avg XP per GP</code>
                </div>
                <p class="diff-description" style="margin-top:8px;">
                    Each GP, the <strong>highest-Elo racer</strong> becomes the <strong>Monster</strong> (ties broken alphabetically). Everyone else is an <strong>Adventurer</strong>. Earn XP based on how the hunt unfolds:
                </p>
                <div class="scr-rules">
                    <div class="scr-rule"><strong>Adventurer beats Monster (Slay):</strong> +<?= (int)($rules['mh_slay_xp'] ?? 100) ?> × CR multiplier (×1.0 / ×1.25 / ×1.5 / ×2.0 by Elo gap)</div>
                    <div class="scr-rule"><strong>Adventurer loses to Monster (Survive):</strong> +<?= (int)($rules['mh_survive_xp'] ?? 20) ?></div>
                    <div class="scr-rule"><strong>Full Slay bonus:</strong> All Adventurers beat the Monster → +<?= (int)($rules['mh_party_bonus_xp'] ?? 50) ?> party bonus to each slayer</div>
                    <div class="scr-rule"><strong>Monster wins (TPK):</strong> Beat the whole field → +<?= (int)($rules['mh_monster_win_xp'] ?? 80) ?></div>
                    <div class="scr-rule"><strong>Monster partial (mid-pack):</strong> +<?= (int)($rules['mh_monster_partial_xp'] ?? 30) ?></div>
                    <div class="scr-rule"><strong>Monster fully slain:</strong> <?= ((int)($rules['mh_monster_loss_xp'] ?? -40) >= 0 ? '+' : '') ?><?= (int)($rules['mh_monster_loss_xp'] ?? -40) ?> (can be negative)</div>
                    <div class="scr-rule"><strong>Minimum GPs to rank:</strong> <?= (int)($rules['mh_min_gps'] ?? 6) ?></div>
                </div>
                <p class="diff-description" style="margin-top:8px; font-size:0.8rem;">
                    💡 Standings rank by the <strong>best-<?= (int)($rules['mh_best_x'] ?? 20) ?> XP sum</strong>, so turning up gives you more chances to bank a big haul — bad nights simply drop out. Your <strong>title</strong> is the separate skill track: average XP across every GP you played, where consistency is what counts.
                </p>

            <?php else: ?>
                <h2><?= $scoringInfo['icon'] ?> <?= htmlspecialchars($scoringInfo['name']) ?></h2>
                <p><?= htmlspecialchars(!empty($scoringInfo['long_description']) ? $scoringInfo['long_description'] : $scoringInfo['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($racerBreakdowns as $rb): ?>
    <div class="racer-card scr-racer-card">
        <div class="scr-racer-header">
            <h3 class="scr-racer-name"><?= htmlspecialchars($rb['name']) ?></h3>
            <div class="scr-racer-score">
                <?php if (!racerQualifies($rb['total'], $rules)): ?>
                    <span class="scr-unranked">Unranked (<?= $rb['total'] ?>/<?= $minThreshold ?> GPs)</span>
                <?php else: ?>
                    <span class="scr-gpscore"><?= round($rb['score'], 2) ?></span>
                    <?php // Label the season's real scoring system, not always "GPScore". ?>
                    <span class="scr-gpscore-label"><?= $scoringSystem === 'average_attendance'
                        ? 'GPScore' : htmlspecialchars($scoringInfo['name']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($scoringSystem === 'monster_hunt' && !empty($rb['hunts'])): ?>
            <!-- MONSTER HUNT view -->
            <div class="scr-summary">
                <?= count($rb['hunts']) ?> hunt<?= count($rb['hunts']) !== 1 ? 's' : '' ?> ·
                Best <?= $rb['best_x'] ?> sum: <strong><?= $rb['counted_total'] ?></strong> XP <span class="scr-mh-note">(the score)</span> ·
                Avg XP/GP: <strong><?= $rb['avg_xp'] ?></strong>
                <?php if ($rb['mh_title']): ?>
                    · Title: <strong><?= htmlspecialchars($rb['mh_title']) ?></strong> (lv. <?= $rb['mh_level'] ?>) <span class="scr-mh-note">— from Avg XP/GP</span>
                <?php endif; ?>
            </div>
            <div class="scr-mh-grid">
                <?php foreach ($rb['hunts'] as $h):
                    $countedClass = $h['counted'] ? 'scr-counted' : 'scr-dropped';
                    $roleClass    = strtolower(str_replace(' ', '-', $h['role']));
                    $xpLabel      = ($h['xp'] >= 0 ? '+' : '') . $h['xp'];
                ?>
                <div class="scr-mh-row <?= $countedClass ?> scr-mh-role--<?= $roleClass ?>"
                     title="<?= htmlspecialchars($h['gpid']) ?> · <?= htmlspecialchars($h['cup'] ?? '') ?> Cup · <?= date('M j', strtotime($h['date'])) ?> · <?= htmlspecialchars($h['explanation']) ?>">
                    <span class="scr-mh-gp"><?= htmlspecialchars($h['gpid']) ?></span>
                    <span class="scr-mh-role scr-mh-role-tag--<?= $roleClass ?>">
                        <?php if ($h['role'] === 'Monster'): ?>👹<?php elseif ($h['role'] === 'Slayer'): ?>🗡️<?php elseif ($h['role'] === 'Survivor'): ?>🛡️<?php else: ?>·<?php endif; ?>
                        <?= htmlspecialchars($h['role']) ?>
                    </span>
                    <?php if ($h['cr_tier']): ?>
                    <span class="scr-mh-cr">CR<?= $h['cr_tier'] ?></span>
                    <?php else: ?>
                    <span class="scr-mh-cr">—</span>
                    <?php endif; ?>
                    <span class="scr-mh-outcome"><?= htmlspecialchars($h['outcome']) ?></span>
                    <span class="scr-mh-xp scr-mh-xp--<?= $h['xp'] < 0 ? 'neg' : 'pos' ?>"><?= $xpLabel ?></span>
                    <?php if (!$h['counted']): ?>
                        <span class="scr-mh-cut">dropped</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="diff-description" style="margin-top:10px; font-size:0.8rem;">
                Hover any hunt for the full XP formula. Best <?= $rb['best_x'] ?> hunts count toward the season total; the rest are dropped.
            </p>

        <?php elseif ($scoringSystem === 'positional_points' && !empty($rb['pos']['rows'])):
            $pos  = $rb['pos'];
            $ords = array_map('ordinal', range(1, count(MK_POINTS_SCALE)));
        ?>
            <!-- Positional Points view -->
            <div class="scr-summary">
                <?= $rb['total'] ?> GP<?= $rb['total'] !== 1 ? 's' : '' ?> played &middot;
                <?php if ($pos['mode'] === 'best_n'): ?>
                    best <strong><?= $pos['counted_count'] ?></strong> counted
                    <?php if ($rb['total'] > $pos['counted_count']): ?>
                        &middot; <?= $rb['total'] - $pos['counted_count'] ?> below the cut
                    <?php endif; ?>
                <?php elseif ($pos['mode'] === 'average'): ?>
                    per-GP average of <strong><?= $rb['pos_total'] ?></strong> total ladder pts
                <?php else: ?>
                    all <?= $pos['counted_count'] ?> counted (<strong><?= $rb['pos_total'] ?></strong> ladder pts)
                <?php endif; ?>
                <?php if ($rb['pos_wins'] > 0): ?>
                    &middot; <?= $rb['pos_wins'] ?> win<?= $rb['pos_wins'] !== 1 ? 's' : '' ?> 🏆
                <?php endif; ?>
                <?php if (!empty($pos['cut_line'])): ?>
                    &middot; cut line: <?= $pos['cut_line'] ?> pts
                <?php endif; ?>
            </div>

            <?php // Count-back row — the first tie-break, so show the shape of the season. ?>
            <div class="scr-pos-counts">
                <?php foreach ($pos['pos_counts'] as $place => $n): if ($n === 0) continue; ?>
                    <span class="scr-pos-count<?= $place === 1 ? ' scr-pos-count--win' : '' ?>"
                          title="<?= $n ?>× <?= $ords[$place-1] ?? $place ?> place @ <?= MK_POINTS_SCALE[$place-1] ?? 0 ?> pts">
                        <?= $n ?>× <?= $ords[$place - 1] ?? $place ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="scr-gp-grid">
                <?php foreach ($pos['rows'] as $i => $g): ?>
                    <?php if ($pos['mode'] === 'best_n' && $i === $pos['counted_count'] && $i < count($pos['rows'])): ?>
                        <div class="scr-cut-divider">— cut line: below here doesn't count —</div>
                    <?php endif; ?>
                    <div class="scr-gp-chip <?= $g['counted'] ? 'scr-counted' : 'scr-dropped' ?>"
                         title="<?= htmlspecialchars($g['cup']) ?> Cup &middot; finished <?= $ords[$g['rank']-1] ?? $g['rank'] ?> &middot; <?= date('M j', strtotime($g['date'])) ?> &middot; <?= $g['pts'] ?> ladder pts">
                        <span class="scr-gp-rank"><?= $ords[$g['rank'] - 1] ?? $g['rank'] ?></span>
                        <span class="scr-gp-cup"><?= htmlspecialchars($g['cup']) ?></span>
                        <span class="scr-gp-pts"><?= $g['pts'] ?></span>
                        <?php if (!$g['counted']): ?><span class="scr-gp-label">not counted</span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($scoringSystem === 'top_12_unique' && !empty($rb['cup_bests'])): ?>
            <!-- Top 12 Unique view -->
            <div class="scr-summary">
                Top 12 Total: <strong><?= $rb['top12_total'] ?></strong> / 720 &middot;
                <?= count($rb['cup_bests']) ?> cups played &middot;
                <?= $rb['perfects'] ?> perfect<?= $rb['perfects'] !== 1 ? 's' : '' ?> (tiebreaker)
                <?php if ($rb['bubble_score'] !== null): ?>
                    &middot; bubble line: <?= $rb['bubble_score'] ?>
                <?php endif; ?>
            </div>
            <div class="scr-gp-grid">
                <?php $cupRank = 1; foreach ($rb['top12'] as $cup => $pts): ?>
                    <div class="scr-gp-chip scr-counted" title="#<?= $cupRank ?> — <?= htmlspecialchars($cup) ?> Cup — +<?= 60 - $pts ?> improvement possible">
                        <span class="scr-gp-rank">#<?= $cupRank ?></span>
                        <span class="scr-gp-cup"><?= htmlspecialchars($cup) ?></span>
                        <span class="scr-gp-pts"><?= $pts ?></span>
                    </div>
                <?php $cupRank++; endforeach; ?>
                <?php if (!empty($rb['dropped_cups'])): ?>
                <div class="scr-cut-divider">— cut line —</div>
                <?php endif; ?>
                <?php foreach ($rb['dropped_cups'] as $cup => $pts): ?>
                    <div class="scr-gp-chip scr-dropped" title="<?= htmlspecialchars($cup) ?> Cup — need <?= ($rb['bubble_score'] ?? 0) + 1 ?>+ to count">
                        <span class="scr-gp-cup"><?= htmlspecialchars($cup) ?></span>
                        <span class="scr-gp-pts"><?= $pts ?></span>
                        <span class="scr-gp-label">dropped</span>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($scoringSystem !== 'black_box'): ?>
            <!-- Average/drop view -->
            <div class="scr-summary">
                <?= $rb['total'] ?> GPs played &middot;
                <?= $rb['num_dropped'] ?? 0 ?> dropped &middot;
                <?= $rb['total'] - ($rb['num_dropped'] ?? 0) ?> counted
                <?php if (isset($rb['average'])): ?>
                    &middot; avg <?= $rb['average'] ?>
                <?php endif; ?>
                <?php if (isset($rb['attendance_bonus']) && $rb['attendance_bonus'] > 0): ?>
                    &middot; +<?= $rb['attendance_bonus'] ?> attendance
                <?php endif; ?>
            </div>
            <div class="scr-gp-grid">
                <?php
                // Show dropped first (they're at the start since sorted ASC by points)
                foreach ($rb['dropped'] as $gp): ?>
                    <div class="scr-gp-chip scr-dropped" title="<?= htmlspecialchars($gp['cup_name']) ?> Cup &middot; #<?= $gp['rank'] ?> &middot; <?= date('M j', strtotime($gp['race_date'])) ?>">
                        <span class="scr-gp-pts"><?= $gp['gp_points'] ?></span>
                        <span class="scr-gp-label">dropped</span>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($rb['counted'] as $gp): ?>
                    <div class="scr-gp-chip scr-counted" title="<?= htmlspecialchars($gp['cup_name']) ?> Cup &middot; #<?= $gp['rank'] ?> &middot; <?= date('M j', strtotime($gp['race_date'])) ?>">
                        <span class="scr-gp-pts"><?= $gp['gp_points'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="scr-summary"><?= $rb['total'] ?> GPs played</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
