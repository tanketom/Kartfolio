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

    // Fetch all GPs sorted by points ascending (matching the scoring logic)
    $gpStmt = $pdo->prepare("
        SELECT res.gpid, res.gp_points, res.race_date, res.cup_name, res.rank
        FROM results res
        WHERE res.racer_id = ? AND res.gpid LIKE ? AND res.gpid LIKE 's%'
        ORDER BY res.gp_points ASC
    ");
    $gpStmt->execute([$rid, $seasonId . '%']);
    $gps = $gpStmt->fetchAll(PDO::FETCH_ASSOC);

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
        $dropRate = $rules['drop_rate'] ?? 10;
        $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
        $entry['num_dropped'] = $numToDrop;
        $entry['dropped'] = array_slice($gps, 0, $numToDrop);
        $entry['counted'] = array_slice($gps, $numToDrop);

        // Attendance bonus
        $attWeight = $rules['attendance_weight'] ?? 1.0;
        $weeklyCap = $rules['weekly_bonus_cap'] ?? 2;
        $weeklyTracker = [];
        $attBonus = 0;
        foreach ($gps as $g) {
            $wk = date('Y-W', strtotime($g['race_date']));
            if (!isset($weeklyTracker[$wk])) $weeklyTracker[$wk] = 0;
            if ($weeklyTracker[$wk] < $weeklyCap) {
                $attBonus += $attWeight;
                $weeklyTracker[$wk] += $attWeight;
            }
        }
        $countedPoints = array_column($entry['counted'], 'gp_points');
        $avg = count($countedPoints) > 0 ? array_sum($countedPoints) / count($countedPoints) : 0;
        $entry['average'] = round($avg, 2);
        $entry['attendance_bonus'] = round($attBonus, 2);
    } elseif ($scoringSystem === 'preseason') {
        $dropRate = $rules['drop_rate'] ?? 10;
        $numToDrop = ($dropRate > 0) ? floor($totalRaces / $dropRate) : 0;
        $entry['num_dropped'] = $numToDrop;
        $entry['dropped'] = array_slice($gps, 0, $numToDrop);
        $entry['counted'] = array_slice($gps, $numToDrop);
        $countedPoints = array_column($entry['counted'], 'gp_points');
        $avg = count($countedPoints) > 0 ? array_sum($countedPoints) / count($countedPoints) : 0;
        $entry['average'] = round($avg, 2);
    } elseif ($scoringSystem === 'top_12_unique') {
        // Best score per cup, top 12
        $allCups = getMK8DCups();
        $cupBests = [];
        foreach ($allCups as $cupName) {
            $cStmt = $pdo->prepare("SELECT MAX(gp_points) as best FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' AND cup_name = ?");
            $cStmt->execute([$rid, $seasonId . '%', $cupName]);
            $best = $cStmt->fetchColumn();
            if ($best) $cupBests[$cupName] = (int)$best;
        }
        arsort($cupBests);
        $entry['cup_bests'] = $cupBests;
        $top12 = array_slice($cupBests, 0, 12, true);
        $dropped = array_slice($cupBests, 12, null, true);
        $entry['top12'] = $top12;
        $entry['dropped_cups'] = $dropped;
        $entry['top12_total'] = array_sum($top12);
        $entry['bubble_score'] = count($cupBests) >= 12 ? min($top12) : null;
        $entry['perfects'] = count(array_filter($top12, fn($p) => $p === 60));
    } elseif ($scoringSystem === 'monster_hunt') {
        // Walk every GP this racer played and reconstruct per-GP role +
        // CR + outcome + XP. Mirrors mhComputeRaw() in gp_logic.php so the
        // numbers line up exactly with the official scoring.
        $changelog       = getMonsterHuntEloChangelog($pdo);
        $slay_xp         = (int)($rules['mh_slay_xp']           ?? 100);
        $survive_xp      = (int)($rules['mh_survive_xp']         ?? 20);
        $party_bonus_xp  = (int)($rules['mh_party_bonus_xp']     ?? 50);
        $monster_win_xp  = (int)($rules['mh_monster_win_xp']     ?? 80);
        $monster_part_xp = (int)($rules['mh_monster_partial_xp'] ?? 30);
        $monster_loss_xp = (int)($rules['mh_monster_loss_xp']    ?? -40);
        $best_x          = max(1, (int)($rules['mh_best_x']      ?? 20));
        $racerName       = $racer['name'];

        $hunts = []; // per-GP breakdown
        $seasonGPsStmt = $pdo->prepare("SELECT DISTINCT gpid, race_date, cup_name FROM results WHERE gpid LIKE ? ORDER BY race_date ASC, gpid ASC");
        $seasonGPsStmt->execute([$seasonId . '%']);
        foreach ($seasonGPsStmt->fetchAll(PDO::FETCH_ASSOC) as $gp) {
            $gpid = $gp['gpid'];
            if (!isset($changelog[$gpid][$racerName])) continue;
            $gpData = $changelog[$gpid];

            // Solo GP — straight survive XP.
            if (count($gpData) < 2) {
                $hunts[] = [
                    'gpid' => $gpid, 'cup' => $gp['cup_name'], 'date' => $gp['race_date'],
                    'role' => 'Lone Adventurer', 'monster' => null,
                    'cr_tier' => null, 'cr_mult' => null,
                    'outcome' => 'no monster', 'xp' => $survive_xp,
                    'explanation' => "+$survive_xp (no Monster — solo)",
                ];
                continue;
            }

            [$monsterName, $monsterElo] = pickMonster($gpid, $gpData, $pdo);
            $monsterRank = $gpData[$monsterName]['rank'];

            // CR tier from Elo gap to the average adventurer.
            $advElos = [];
            foreach ($gpData as $name => $d) { if ($name !== $monsterName) $advElos[] = $d['old_elo']; }
            $avgAdv = count($advElos) > 0 ? array_sum($advElos) / count($advElos) : $monsterElo;
            $eloGap = max(0, $monsterElo - $avgAdv);
            if      ($eloGap < 50)  { $cr = 1; $crMult = 1.0;  }
            elseif  ($eloGap < 150) { $cr = 2; $crMult = 1.25; }
            elseif  ($eloGap < 300) { $cr = 3; $crMult = 1.5;  }
            else                    { $cr = 4; $crMult = 2.0;  }

            $advWon = $advLost = 0;
            foreach ($gpData as $name => $d) {
                if ($name === $monsterName) continue;
                if ($d['rank'] < $monsterRank) $advWon++; else $advLost++;
            }
            $fullSlay = ($advLost === 0 && $advWon > 0);
            $isTpk    = ($advWon === 0);
            $outcome  = $isTpk ? 'TPK' : ($fullSlay ? 'Full Slay' : 'Partial');

            // Compute XP + explanation depending on the racer's role.
            if ($racerName === $monsterName) {
                $role = 'Monster';
                if ($isTpk)        { $xp = $monster_win_xp;  $expl = "+$monster_win_xp (Monster Win — beat the whole field)"; }
                elseif ($fullSlay) { $xp = $monster_loss_xp; $expl = ($monster_loss_xp >= 0 ? '+' : '') . "$monster_loss_xp (Full Slay — every adventurer beat you)"; }
                else               { $xp = $monster_part_xp; $expl = "+$monster_part_xp (Monster Partial — survived some)"; }
            } else {
                $myRank = $gpData[$racerName]['rank'];
                if ($myRank < $monsterRank) {
                    $role = 'Slayer';
                    $base = (int)round($slay_xp * $crMult);
                    $xp = $base + ($fullSlay ? $party_bonus_xp : 0);
                    $expl = "+$base ($slay_xp slay × {$crMult} CR{$cr})"
                          . ($fullSlay ? " + $party_bonus_xp (Party Bonus)" : '');
                } else {
                    $role = 'Survivor';
                    $xp = $survive_xp;
                    $expl = "+$survive_xp (survived but didn't beat the Monster)";
                }
            }

            $hunts[] = [
                'gpid' => $gpid, 'cup' => $gp['cup_name'], 'date' => $gp['race_date'],
                'role' => $role, 'monster' => $monsterName,
                'cr_tier' => $cr, 'cr_mult' => $crMult,
                'outcome' => $outcome, 'xp' => $xp,
                'explanation' => $expl,
            ];
        }

        // Sort by XP desc to identify which hunts make the best-X cut.
        $sortedByXp = $hunts;
        usort($sortedByXp, fn($a, $b) => $b['xp'] <=> $a['xp']);
        $cutoffXp = isset($sortedByXp[$best_x - 1]) ? $sortedByXp[$best_x - 1]['xp'] : PHP_INT_MIN;
        $countedCount = 0;
        $countedTotal = 0;
        foreach ($hunts as &$h) {
            if ($countedCount < $best_x && $h['xp'] >= $cutoffXp) {
                $h['counted']   = true;
                $countedCount++;
                $countedTotal  += $h['xp'];
            } else {
                $h['counted'] = false;
            }
        }
        unset($h);

        // Chronological order in the UI so storylines read naturally.
        usort($hunts, fn($a, $b) => strcmp($a['date'] . $a['gpid'], $b['date'] . $b['gpid']));

        $entry['hunts'] = $hunts;
        $entry['best_x'] = $best_x;
        $entry['counted_total'] = $countedTotal;
        $entry['avg_xp'] = $countedCount > 0 ? round($countedTotal / $countedCount, 1) : 0;
        $mh = getMonsterHuntDisplayData($pdo, $rid, $seasonId, $rules);
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
                    <code>Score = Sum of your <?= (int)($rules['mh_best_x'] ?? 20) ?> best XP hauls · Display rank = avg XP per GP</code>
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
                    💡 Standings rank by <strong>average XP per kept GP</strong> — consistency wins over grinding bad nights.
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
                    <span class="scr-gpscore-label">GPScore</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($scoringSystem === 'monster_hunt' && !empty($rb['hunts'])): ?>
            <!-- MONSTER HUNT view -->
            <div class="scr-summary">
                <?= count($rb['hunts']) ?> hunt<?= count($rb['hunts']) !== 1 ? 's' : '' ?> ·
                Best <?= $rb['best_x'] ?> sum: <strong><?= $rb['counted_total'] ?></strong> XP ·
                Avg XP/hunt: <strong><?= $rb['avg_xp'] ?></strong>
                <?php if ($rb['mh_title']): ?>
                    · Title: <strong><?= htmlspecialchars($rb['mh_title']) ?></strong> (lv. <?= $rb['mh_level'] ?>)
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
