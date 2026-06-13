<?php
/**
 * Fantasy Draft - Weekly Predictions
 * Path: /cdnmk/public_html/fantasy.php
 *
 * Three bet types per week:
 *   1. Weekly MVP – Who scores the most points this week?
 *   2. Head-to-Head – Pick the winner of auto-generated matchups
 *   3. Prop Bets – Yes/No predictions about the week
 *
 * Predictors can be registered racers OR guest names.
 * Runs Sunday 18:00 → Sunday 18:00.
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';
require_once __DIR__ . '/../private/includes/elo_engine.php';
require_once __DIR__ . '/../private/includes/csrf.php';

// ============================================================
// 1. Schema – Create tables if needed
// ============================================================
$pdo->exec("CREATE TABLE IF NOT EXISTS fantasy_predictors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    racer_id INTEGER DEFAULT NULL,
    guest_name TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(racer_id),
    UNIQUE(guest_name)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS fantasy_weeks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    week_key TEXT NOT NULL UNIQUE,
    deadline TEXT NOT NULL,
    scored BOOLEAN DEFAULT 0,
    scored_at DATETIME DEFAULT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS fantasy_bets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    week_key TEXT NOT NULL,
    predictor_id INTEGER NOT NULL,
    bet_type TEXT NOT NULL,
    bet_key TEXT NOT NULL,
    bet_value TEXT NOT NULL,
    confidence INTEGER NOT NULL DEFAULT 1,
    points_earned INTEGER DEFAULT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(week_key, predictor_id, bet_type, bet_key)
)");
// Inline migration for existing DBs (idempotent).
try { $pdo->exec("ALTER TABLE fantasy_bets ADD COLUMN confidence INTEGER NOT NULL DEFAULT 1"); }
catch (PDOException $e) {}

// ============================================================
// 2. Timing – Sunday-to-Sunday window
// ============================================================
$currentSeason = getCurrentSeasonNumber();
$now = new DateTime();
$dayOfWeek = (int)$now->format('N');
$currentHour = (int)$now->format('H');

$deadline = clone $now;
if ($dayOfWeek < 7 || ($dayOfWeek === 7 && $currentHour < 18)) {
    $deadline->modify('Sunday this week');
} else {
    $deadline->modify('next Sunday');
}
$deadline->setTime(18, 0, 0);

$submissionsOpen = $now < $deadline;
$deadlineFormatted = $deadline->format('l, M j \a\t g:i A');
$timeRemaining = $now->diff($deadline);

if ($timeRemaining->days > 0) {
    $timeRemainingStr = $timeRemaining->days . 'd ' . $timeRemaining->h . 'h remaining';
} elseif ($timeRemaining->h > 0) {
    $timeRemainingStr = $timeRemaining->h . 'h ' . $timeRemaining->i . 'm remaining';
} else {
    $timeRemainingStr = $timeRemaining->i . 'm remaining';
}

// Week key: e.g. "2025-W07" based on the deadline date
$weekKey = $deadline->format('Y-\\WW');

// Ensure this week exists in fantasy_weeks
$wkCheck = $pdo->prepare("SELECT id FROM fantasy_weeks WHERE week_key = ?");
$wkCheck->execute([$weekKey]);
if (!$wkCheck->fetchColumn()) {
    $wkIns = $pdo->prepare("INSERT OR IGNORE INTO fantasy_weeks (week_key, deadline) VALUES (?, ?)");
    $wkIns->execute([$weekKey, $deadline->format('Y-m-d H:i:s')]);
}

// ============================================================
// 3. Racer data for bets
// ============================================================
$allRacersFull = $pdo->query("SELECT id, name, nickname FROM racers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$racerNameMap = [];
foreach ($allRacersFull as $r) {
    $racerNameMap[$r['id']] = $r['name'];
}

// Season racers with standings
$racerStmt = $pdo->prepare("SELECT DISTINCT r.id, r.name FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ? AND res.gpid LIKE 's%'");
$racerStmt->execute([$currentSeason . '%']);
$seasonRacers = $racerStmt->fetchAll(PDO::FETCH_ASSOC);

$standings = [];
foreach ($seasonRacers as $r) {
    $standings[] = ['id' => $r['id'], 'name' => $r['name'], 'score' => calculateGPScore($pdo, $r['id'], $currentSeason)];
}
usort($standings, fn($a, $b) => $b['score'] <=> $a['score']);

// Recent form: avg points last 5 GPs for each racer
$racerForm = [];
foreach ($seasonRacers as $r) {
    $fStmt = $pdo->prepare("SELECT gp_points FROM results WHERE racer_id = ? AND gpid LIKE ? AND gpid LIKE 's%' ORDER BY race_date DESC, gpid DESC LIMIT 5");
    $fStmt->execute([$r['id'], $currentSeason . '%']);
    $pts = $fStmt->fetchAll(PDO::FETCH_COLUMN);
    $racerForm[$r['id']] = count($pts) > 0 ? round(array_sum($pts) / count($pts), 1) : 0;
}

// Generate head-to-head matchups based on closest rivalries (max 3)
$h2hMatchups = [];
$srCount = count($standings);
if ($srCount >= 2) {
    // Find all pairings with their rivalry intensity (meetings * closeness)
    $rivalryPairs = [];
    for ($i = 0; $i < $srCount; $i++) {
        for ($j = $i + 1; $j < $srCount; $j++) {
            $aId = $standings[$i]['id'];
            $bId = $standings[$j]['id'];
            $rivStmt = $pdo->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN r1.rank < r2.rank THEN 1 ELSE 0 END) as p1_wins
                FROM results r1
                JOIN results r2 ON r1.gpid = r2.gpid AND r1.cup_name = r2.cup_name
                WHERE r1.racer_id = ? AND r2.racer_id = ? AND r1.gpid LIKE ?
            ");
            $rivStmt->execute([$aId, $bId, $currentSeason . '%']);
            $rivData = $rivStmt->fetch(PDO::FETCH_ASSOC);
            $meetings = (int)$rivData['total'];
            if ($meetings >= 2) {
                $winRate = $rivData['p1_wins'] / $meetings;
                $closeness = 1.0 - abs($winRate - 0.5) * 2.0; // 1.0 = perfectly even, 0.0 = total domination
                $intensity = $meetings * $closeness;
                $rivalryPairs[] = [
                    'a' => $standings[$i],
                    'b' => $standings[$j],
                    'meetings' => $meetings,
                    'closeness' => round($closeness * 100),
                    'intensity' => $intensity,
                ];
            }
        }
    }
    // Sort by rivalry intensity descending (closest + most frequent = best matchup)
    usort($rivalryPairs, fn($x, $y) => $y['intensity'] <=> $x['intensity']);

    // Pick top 3, avoiding reusing any racer
    $usedIds = [];
    foreach ($rivalryPairs as $pair) {
        if (count($h2hMatchups) >= 3) break;
        if (in_array($pair['a']['id'], $usedIds) || in_array($pair['b']['id'], $usedIds)) continue;
        $h2hMatchups[] = $pair;
        $usedIds[] = $pair['a']['id'];
        $usedIds[] = $pair['b']['id'];
    }
}

// Generate prop bets for this week
$propBets = [];
if ($srCount >= 2) {
    // Prop 1: Will anyone score a perfect 60 this week?
    $propBets[] = [
        'key' => 'perfect_60',
        'label' => 'Someone scores a perfect 60 this week',
        'description' => 'Any racer hits the maximum GP score in any race this week',
    ];

    // Prop 2: Will the standings leader win a GP this week?
    if (!empty($standings)) {
        $leader = $standings[0];
        $propBets[] = [
            'key' => 'leader_wins_gp',
            'label' => $leader['name'] . ' wins at least one GP this week',
            'description' => 'The current standings leader takes 1st place in any GP',
        ];
    }

    // Prop 3: LOL alert — does a LOL-flagged race happen?
    $propBets[] = [
        'key' => 'lol_alert',
        'label' => 'A LOL race happens this week',
        'description' => 'At least one GP this week is flagged as a LOL race',
    ];

    // Prop 4: Underdog podium
    if ($srCount >= 4) {
        $underdog = $standings[$srCount - 1];
        $propBets[] = [
            'key' => 'underdog_podium',
            'label' => $underdog['name'] . ' finishes on the podium (top 3) in a GP',
            'description' => 'The last-place racer in the standings gets a top 3 finish',
        ];
    }
}

// ============================================================
// 4. Predictor management (racer or guest)
// ============================================================
function getOrCreatePredictor($pdo, $racerId, $guestName) {
    if ($racerId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM fantasy_predictors WHERE racer_id = ?");
        $stmt->execute([$racerId]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $ins = $pdo->prepare("INSERT INTO fantasy_predictors (racer_id) VALUES (?)");
            $ins->execute([$racerId]);
            $id = $pdo->lastInsertId();
        }
        return (int)$id;
    } elseif (!empty($guestName)) {
        $name = trim($guestName);
        $stmt = $pdo->prepare("SELECT id FROM fantasy_predictors WHERE guest_name = ?");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            $ins = $pdo->prepare("INSERT INTO fantasy_predictors (guest_name) VALUES (?)");
            $ins->execute([$name]);
            $id = $pdo->lastInsertId();
        }
        return (int)$id;
    }
    return 0;
}

function getPredictorName($pdo, $predictorId, $racerNameMap) {
    $stmt = $pdo->prepare("SELECT racer_id, guest_name FROM fantasy_predictors WHERE id = ?");
    $stmt->execute([$predictorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'Unknown';
    if ($row['racer_id'] && isset($racerNameMap[$row['racer_id']])) {
        return $racerNameMap[$row['racer_id']];
    }
    return $row['guest_name'] ?: 'Unknown';
}

/** Insert a bet, skipping silently on duplicate. Returns true if inserted. */
function insertBet($pdo, $weekKey, $predictorId, $betType, $betKey, $betValue, $confidence = 1) {
    $conf = max(1, min(3, (int)$confidence)); // clamp 1..3
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO fantasy_bets (week_key, predictor_id, bet_type, bet_key, bet_value, confidence) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$weekKey, $predictorId, $betType, $betKey, $betValue, $conf]);
    return $stmt->rowCount() > 0;
}

/**
 * Confidence multiplier semantics:
 *   - Correct pick: base × confidence
 *   - Wrong pick:   -(ceil(base / 2)) × confidence  (you risked it; you wear it)
 *   - Push (H2H tie): unchanged, 1 point (tie doesn't reward conviction)
 */
function confidenceScore(int $base, int $confidence, bool $correct, bool $push = false): int {
    if ($push) return 1;
    $confidence = max(1, min(3, $confidence));
    if ($correct) return $base * $confidence;
    if ($base === 0) return 0;
    return -((int)ceil($base / 2)) * $confidence;
}

/** Human-readable confidence chip ('light' / 'medium' / 'lock'). */
function confidenceLabel(int $confidence): string {
    return ['light', 'medium', 'lock'][max(1, min(3, $confidence)) - 1];
}

/** Emit the 3-pill confidence picker for a given form field name. */
function renderConfidencePicker(string $fieldName): void {
    ?>
    <fieldset class="fan-conf-picker">
        <legend class="fan-conf-legend">Confidence</legend>
        <label class="fan-conf-pill">
            <input type="radio" name="<?= htmlspecialchars($fieldName) ?>" value="1" checked>
            <span class="fan-conf-chip" data-level="1">Light ×1</span>
        </label>
        <label class="fan-conf-pill">
            <input type="radio" name="<?= htmlspecialchars($fieldName) ?>" value="2">
            <span class="fan-conf-chip" data-level="2">Medium ×2</span>
        </label>
        <label class="fan-conf-pill">
            <input type="radio" name="<?= htmlspecialchars($fieldName) ?>" value="3">
            <span class="fan-conf-chip" data-level="3">🔒 Lock ×3</span>
        </label>
    </fieldset>
    <?php
}

// ============================================================
// 5. Page mode
// ============================================================
$mode = 'leaderboard';
if (isset($_GET['submit'])) $mode = 'submit';
if (isset($_GET['score'])) $mode = 'score';

// ============================================================
// 6. Handle POST submission
// ============================================================
$submitSuccess = '';
$submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'submit') {
    verify_csrf();
    if (!$submissionsOpen) {
        $submitError = 'Submissions are closed! The deadline was ' . $deadlineFormatted . '.';
    } else {
        $racerIdPick = intval($_POST['predictor_racer_id'] ?? 0);
        $guestNamePick = trim($_POST['predictor_guest_name'] ?? '');

        // If "other" was chosen, use guest name
        if ($racerIdPick === -1) {
            $racerIdPick = 0;
        }

        $predictorId = getOrCreatePredictor($pdo, $racerIdPick, $racerIdPick > 0 ? '' : $guestNamePick);
        if (!$predictorId) {
            $submitError = 'Please select who you are or enter a name.';
        } else {
            $betCount = 0;
            $dupeCount = 0;

            // MVP pick
            $mvpPick = intval($_POST['mvp_pick'] ?? 0);
            $mvpConf = (int)($_POST['mvp_pick_conf'] ?? 1);
            if ($mvpPick > 0) {
                insertBet($pdo, $weekKey, $predictorId, 'mvp', 'weekly_mvp', $mvpPick, $mvpConf)
                    ? $betCount++ : $dupeCount++;
            }

            // ELO climber pick
            $eloPick = intval($_POST['elo_gain_pick'] ?? 0);
            $eloConf = (int)($_POST['elo_gain_pick_conf'] ?? 1);
            if ($eloPick > 0) {
                insertBet($pdo, $weekKey, $predictorId, 'elo_gain', 'top_climber', $eloPick, $eloConf)
                    ? $betCount++ : $dupeCount++;
            }

            // H2H picks
            foreach ($h2hMatchups as $idx => $matchup) {
                $h2hVal  = intval($_POST['h2h_' . $idx] ?? 0);
                $h2hConf = (int)($_POST['h2h_' . $idx . '_conf'] ?? 1);
                if ($h2hVal > 0) {
                    $betKey = 'h2h_' . $matchup['a']['id'] . '_' . $matchup['b']['id'];
                    insertBet($pdo, $weekKey, $predictorId, 'h2h', $betKey, $h2hVal, $h2hConf)
                        ? $betCount++ : $dupeCount++;
                }
            }

            // Prop bets
            foreach ($propBets as $prop) {
                $propVal  = $_POST['prop_' . $prop['key']] ?? '';
                $propConf = (int)($_POST['prop_' . $prop['key'] . '_conf'] ?? 1);
                if ($propVal === 'yes' || $propVal === 'no') {
                    insertBet($pdo, $weekKey, $predictorId, 'prop', $prop['key'], $propVal, $propConf)
                        ? $betCount++ : $dupeCount++;
                }
            }

            if ($betCount > 0 && $dupeCount === 0) {
                $submitSuccess = 'Locked in ' . $betCount . ' prediction' . ($betCount > 1 ? 's' : '') . ' for ' . $weekKey . '!';
            } elseif ($dupeCount > 0 && $betCount > 0) {
                $submitSuccess = 'Locked in ' . $betCount . ' new prediction' . ($betCount > 1 ? 's' : '') . '. ' . $dupeCount . ' already submitted.';
            } elseif ($dupeCount > 0) {
                $submitError = 'You already submitted predictions for this week.';
            } else {
                $submitError = 'No predictions were selected.';
            }
        }
    }
}

// ============================================================
// 7. Admin scoring
// ============================================================
$scoreResults = [];
$scoreWeekKey = '';

if ($mode === 'score' && isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    $scoreWeekKey = trim($_GET['week'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($scoreWeekKey)) {
        verify_csrf();
        // Get all GPs played during this week's window
        $wkRow = $pdo->prepare("SELECT deadline FROM fantasy_weeks WHERE week_key = ?");
        $wkRow->execute([$scoreWeekKey]);
        $wkDeadline = $wkRow->fetchColumn();

        if ($wkDeadline) {
            $wkEnd = new DateTime($wkDeadline);
            $wkStart = clone $wkEnd;
            $wkStart->modify('-7 days');

            // Find GPs in this window
            $gpStmt = $pdo->prepare("
                SELECT DISTINCT gpid FROM results
                WHERE race_date >= ? AND race_date < ? AND gpid LIKE 's%'
                ORDER BY gpid ASC
            ");
            $gpStmt->execute([$wkStart->format('Y-m-d H:i:s'), $wkEnd->format('Y-m-d H:i:s')]);
            $weekGPs = $gpStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($weekGPs)) {
                $scoreResults = ['error' => 'No GPs found in this week\'s window.'];
            } else {
                // Aggregate: points and GP count per racer across the week's GPs
                $weeklyTotals = [];  // racer_id => total gp_points
                $weeklyGPCounts = []; // racer_id => number of GPs played
                $gpCount = count($weekGPs);
                $anyPerfect60 = false;
                $leaderWonGP = false;
                $underdogPodium = false;
                $anyLol = false;

                // Derive leader/underdog from the season actually played this week,
                // not the current season (which may differ for historical weeks).
                $weekSeasonId = preg_replace('/gp\d+$/', '', $weekGPs[0]); // e.g. 's01' from 's01gp59'
                if ($weekSeasonId !== $currentSeason) {
                    $wsRacerStmt = $pdo->prepare("SELECT DISTINCT r.id, r.name FROM racers r JOIN results res ON r.id = res.racer_id WHERE res.gpid LIKE ?");
                    $wsRacerStmt->execute([$weekSeasonId . '%']);
                    $wsRacers = $wsRacerStmt->fetchAll(PDO::FETCH_ASSOC);
                    $wsStandings = [];
                    foreach ($wsRacers as $r) {
                        $wsStandings[] = ['id' => $r['id'], 'score' => calculateGPScore($pdo, $r['id'], $weekSeasonId)];
                    }
                    usort($wsStandings, fn($a, $b) => $b['score'] <=> $a['score']);
                    $scoringStandings = $wsStandings;
                } else {
                    $scoringStandings = $standings;
                }
                $leaderId = !empty($scoringStandings) ? $scoringStandings[0]['id'] : 0;
                $underdogId = count($scoringStandings) >= 4 ? $scoringStandings[count($scoringStandings) - 1]['id'] : 0;

                foreach ($weekGPs as $gp) {
                    $resStmt = $pdo->prepare("SELECT racer_id, rank, gp_points, is_lol FROM results WHERE gpid = ?");
                    $resStmt->execute([$gp]);
                    $rows = $resStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $rid = (int)$row['racer_id'];
                        $weeklyTotals[$rid] = ($weeklyTotals[$rid] ?? 0) + (int)$row['gp_points'];
                        $weeklyGPCounts[$rid] = ($weeklyGPCounts[$rid] ?? 0) + 1;
                        if ((int)$row['gp_points'] >= 60) $anyPerfect60 = true;
                        if ($rid === $leaderId && (int)$row['rank'] === 1) $leaderWonGP = true;
                        if ($rid === $underdogId && (int)$row['rank'] <= 3) $underdogPodium = true;
                        if (!empty($row['is_lol'])) $anyLol = true;
                    }
                }

                // Biggest ELO climber of the week (sum ELO changes across weekGPs)
                $weekGPsSet  = array_flip($weekGPs);
                $eloData     = calculateAllELORatings($pdo);
                $nameToId    = [];
                foreach ($allRacersFull as $r) { $nameToId[$r['name']] = (int)$r['id']; }
                $eloGainsById = [];
                foreach ($eloData['all_changes'] as $chg) {
                    if (!isset($weekGPsSet[$chg['gpid']])) continue;
                    $rid = $nameToId[$chg['racer']] ?? 0;
                    if (!$rid) continue;
                    $eloGainsById[$rid] = ($eloGainsById[$rid] ?? 0) + $chg['change'];
                }
                arsort($eloGainsById);
                $topClimberId = $eloGainsById ? (int)array_key_first($eloGainsById) : 0;

                // Find best form: highest average PPG (not total)
                $weeklyAverages = [];
                foreach ($weeklyTotals as $rid => $total) {
                    $weeklyAverages[$rid] = $total / $weeklyGPCounts[$rid];
                }
                arsort($weeklyAverages);
                $actualMvpId = array_key_first($weeklyAverages);

                // Score all bets for this week
                $betsStmt = $pdo->prepare("SELECT * FROM fantasy_bets WHERE week_key = ?");
                $betsStmt->execute([$scoreWeekKey]);
                $allBets = $betsStmt->fetchAll(PDO::FETCH_ASSOC);

                $scoredPredictors = [];
                foreach ($allBets as $bet) {
                    $points     = null;
                    $confidence = (int)($bet['confidence'] ?? 1);

                    if ($bet['bet_type'] === 'mvp') {
                        $correct = ((int)$bet['bet_value'] === $actualMvpId);
                        $points  = confidenceScore(5, $confidence, $correct);
                    } elseif ($bet['bet_type'] === 'elo_gain') {
                        $correct = ($topClimberId && (int)$bet['bet_value'] === $topClimberId);
                        $points  = confidenceScore(4, $confidence, $correct);
                    } elseif ($bet['bet_type'] === 'h2h') {
                        // Parse h2h_A_B key — compare by average PPG, not total
                        preg_match('/h2h_(\d+)_(\d+)/', $bet['bet_key'], $m);
                        if ($m) {
                            $aId = (int)$m[1]; $bId = (int)$m[2];
                            $aAvg = $weeklyAverages[$aId] ?? 0;
                            $bAvg = $weeklyAverages[$bId] ?? 0;
                            $pickedId = (int)$bet['bet_value'];
                            if (abs($aAvg - $bAvg) > 0.01) {
                                $winnerId = ($aAvg > $bAvg) ? $aId : $bId;
                                $correct  = ($pickedId === $winnerId);
                                $points   = confidenceScore(3, $confidence, $correct);
                            } else {
                                $points = confidenceScore(3, $confidence, false, true); // push
                            }
                        }
                    } elseif ($bet['bet_type'] === 'prop') {
                        $outcome = false;
                        switch ($bet['bet_key']) {
                            case 'perfect_60': $outcome = $anyPerfect60; break;
                            case 'leader_wins_gp': $outcome = $leaderWonGP; break;
                            case 'lol_alert': $outcome = $anyLol; break;
                            case 'gps_over_3': $outcome = ($gpCount > 3); break;
                            case 'underdog_podium': $outcome = $underdogPodium; break;
                        }
                        $betYes = ($bet['bet_value'] === 'yes');
                        $correct = (($betYes && $outcome) || (!$betYes && !$outcome));
                        $points  = confidenceScore(2, $confidence, $correct);
                    }

                    if ($points !== null) {
                        $upd = $pdo->prepare("UPDATE fantasy_bets SET points_earned = ? WHERE id = ?");
                        $upd->execute([$points, $bet['id']]);

                        $pid = $bet['predictor_id'];
                        if (!isset($scoredPredictors[$pid])) {
                            $scoredPredictors[$pid] = ['name' => getPredictorName($pdo, $pid, $racerNameMap), 'total' => 0, 'bets' => []];
                        }
                        $scoredPredictors[$pid]['total'] += $points;
                        $scoredPredictors[$pid]['bets'][] = ['type' => $bet['bet_type'], 'key' => $bet['bet_key'], 'value' => $bet['bet_value'], 'points' => $points];
                    }
                }

                // Mark week as scored
                $pdo->prepare("UPDATE fantasy_weeks SET scored = 1, scored_at = CURRENT_TIMESTAMP WHERE week_key = ?")->execute([$scoreWeekKey]);

                $scoreResults = [
                    'success' => true,
                    'week' => $scoreWeekKey,
                    'gp_count' => $gpCount,
                    'gps' => $weekGPs,
                    'mvp' => ($racerNameMap[$actualMvpId] ?? 'Unknown') . ' (' . round($weeklyAverages[$actualMvpId], 1) . ' avg PPG)',
                    'predictors' => $scoredPredictors,
                ];
            }
        } else {
            $scoreResults = ['error' => 'Week not found.'];
        }
    }
}

// ============================================================
// 8. Leaderboard data
// ============================================================
$leaderboard = [];

// Week history needed in leaderboard, score mode, and admin score form
$whStmt = $pdo->query("SELECT week_key, deadline, scored FROM fantasy_weeks ORDER BY week_key DESC");
$weekHistory = $whStmt->fetchAll(PDO::FETCH_ASSOC);

if ($mode === 'leaderboard') {
    // Aggregate leaderboard across all weeks. Hit/total counts ignore push
    // results (points_earned = 1 on H2H tie) and only count strict positives.
    $lbStmt = $pdo->query("
        SELECT fp.id as predictor_id, fp.racer_id, fp.guest_name,
               COALESCE(SUM(fb.points_earned), 0) as total_points,
               COUNT(DISTINCT fb.week_key) as weeks_played,
               SUM(CASE WHEN fb.bet_type = 'mvp' AND fb.points_earned > 0 THEN 1 ELSE 0 END) as mvp_hits,
               SUM(CASE WHEN fb.bet_type = 'h2h' AND fb.points_earned >= 3 THEN 1 ELSE 0 END) as h2h_hits,
               SUM(CASE WHEN fb.bet_type = 'prop' AND fb.points_earned > 0 THEN 1 ELSE 0 END) as prop_hits,
               SUM(CASE WHEN fb.points_earned > 0 THEN 1 ELSE 0 END) as total_hits,
               SUM(CASE WHEN fb.points_earned IS NOT NULL AND fb.points_earned != 1 THEN 1 ELSE 0 END) as graded_bets,
               SUM(CASE WHEN fb.confidence = 3 THEN 1 ELSE 0 END) as locks_made,
               SUM(CASE WHEN fb.confidence = 3 AND fb.points_earned > 0 THEN 1 ELSE 0 END) as locks_hit
        FROM fantasy_predictors fp
        JOIN fantasy_bets fb ON fp.id = fb.predictor_id
        WHERE fb.points_earned IS NOT NULL
        GROUP BY fp.id
        ORDER BY total_points DESC
    ");
    $leaderboard = $lbStmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute accuracy %.
    foreach ($leaderboard as &$lb) {
        $lb['accuracy_pct'] = (int)$lb['graded_bets'] > 0
            ? round((int)$lb['total_hits'] / (int)$lb['graded_bets'] * 100)
            : 0;
    }
    unset($lb);

    // Fill display names
    foreach ($leaderboard as &$lb) {
        if ($lb['racer_id'] && isset($racerNameMap[$lb['racer_id']])) {
            $lb['display_name'] = $racerNameMap[$lb['racer_id']];
        } else {
            $lb['display_name'] = $lb['guest_name'] ?: 'Unknown';
        }
    }
    unset($lb);
}

// ============================================================
// 9. Existing predictions for current week (for display)
// ============================================================
$currentWeekPredictions = [];
$cwpStmt = $pdo->prepare("
    SELECT fb.predictor_id, fb.bet_type, fb.bet_key, fb.bet_value, fb.points_earned,
           fp.racer_id, fp.guest_name
    FROM fantasy_bets fb
    JOIN fantasy_predictors fp ON fb.predictor_id = fp.id
    WHERE fb.week_key = ?
    ORDER BY fb.predictor_id, fb.bet_type
");
$cwpStmt->execute([$weekKey]);
$cwpRows = $cwpStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($cwpRows as $row) {
    $pid = $row['predictor_id'];
    if (!isset($currentWeekPredictions[$pid])) {
        $name = ($row['racer_id'] && isset($racerNameMap[$row['racer_id']])) ? $racerNameMap[$row['racer_id']] : ($row['guest_name'] ?: 'Unknown');
        $currentWeekPredictions[$pid] = ['name' => $name, 'bets' => []];
    }
    $currentWeekPredictions[$pid]['bets'][] = $row;
}

// ============================================================
// 10. Render Page
// ============================================================
$pageTitle = "Fantasy Draft - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <div class="racer-card fan-header-card">
        <h1 class="fan-title">Fantasy Draft</h1>
        <p class="fan-subtitle">Weekly predictions &mdash; bet on who dominates, pick matchup winners, and call your props</p>
        <div class="fan-week-badge"><?= htmlspecialchars($weekKey) ?></div>
        <?php if ($submissionsOpen): ?>
        <p class="fan-deadline fan-deadline-open">&#9200; Predictions open &mdash; <?= $timeRemainingStr ?> until <?= $deadlineFormatted ?></p>
        <?php else: ?>
        <p class="fan-deadline fan-deadline-locked">&#128274; Predictions locked &mdash; next window opens Sunday at 6 PM</p>
        <?php endif; ?>
    </div>

    <div class="fan-tabs">
        <a href="/fantasy" class="fan-tab <?= $mode === 'leaderboard' ? 'fan-tab-active' : '' ?>">Leaderboard</a>
        <a href="/fantasy?submit" class="fan-tab <?= $mode === 'submit' ? 'fan-tab-active' : '' ?>">Make Picks</a>
    </div>

    <!-- ============================================================ -->
    <!-- LEADERBOARD MODE -->
    <!-- ============================================================ -->
    <?php if ($mode === 'leaderboard'): ?>

    <div class="racer-card fan-leaderboard-card">
        <h2 class="fan-section-title">Season Leaderboard</h2>
        <?php if (!empty($leaderboard)): ?>
        <div class="table-scroll-wrapper">
            <table class="clean-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Predictor</th>
                        <th>Points</th>
                        <th>Weeks</th>
                        <th>Accuracy</th>
                        <th title="Confidence-3 picks that hit / total locks">Locks</th>
                        <th>MVPs</th>
                        <th>H2H</th>
                        <th>Props</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($leaderboard as $lb): ?>
                    <tr <?= $rank <= 3 ? 'class="top-three"' : '' ?>>
                        <td class="txt-center fan-rank"><?= $rank ?></td>
                        <td><strong><?= htmlspecialchars($lb['display_name']) ?></strong></td>
                        <td class="txt-center fan-points-val"><?= (int)$lb['total_points'] ?></td>
                        <td class="txt-center"><?= (int)$lb['weeks_played'] ?></td>
                        <td class="txt-center"><?= (int)$lb['accuracy_pct'] ?>%</td>
                        <td class="txt-center"><?= (int)$lb['locks_hit'] ?>/<?= (int)$lb['locks_made'] ?></td>
                        <td class="txt-center"><?= (int)$lb['mvp_hits'] ?></td>
                        <td class="txt-center"><?= (int)$lb['h2h_hits'] ?></td>
                        <td class="txt-center"><?= (int)$lb['prop_hits'] ?></td>
                    </tr>
                    <?php $rank++; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="fan-empty">No predictions scored yet. Get your picks in!</div>
        <?php endif; ?>
    </div>

    <!-- This Week's Picks (spoiler-free until scored) -->
    <?php if (!empty($currentWeekPredictions)): ?>
    <div class="racer-card fan-thisweek-card">
        <h2 class="fan-section-title">This Week's Entries (<?= htmlspecialchars($weekKey) ?>)</h2>
        <div class="fan-entries-grid">
            <?php foreach ($currentWeekPredictions as $pid => $entry): ?>
            <div class="fan-entry-chip">
                <span class="fan-entry-name"><?= htmlspecialchars($entry['name']) ?></span>
                <span class="fan-entry-count"><?= count($entry['bets']) ?> pick<?= count($entry['bets']) !== 1 ? 's' : '' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Admin: Score a Week -->
    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
    <div class="racer-card fan-score-card">
        <h2 class="fan-section-title">Score a Week</h2>
        <form method="GET" action="/fantasy" class="fan-score-form">
            <input type="hidden" name="score" value="1">
            <label class="fan-label">Week:
                <select name="week" class="fan-select" style="width: auto; min-width: 140px;" required>
                    <option value="">Choose...</option>
                    <?php foreach ($weekHistory as $wh): ?>
                    <option value="<?= htmlspecialchars($wh['week_key']) ?>" <?= $wh['scored'] ? 'style="color:#999;"' : '' ?>>
                        <?= htmlspecialchars($wh['week_key']) ?> <?= $wh['scored'] ? '(scored)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="fan-btn">Score Week</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Week History -->
    <?php if (!empty($weekHistory)): ?>
    <div class="racer-card fan-history-card">
        <h2 class="fan-section-title">Week History</h2>
        <div class="fan-history-list" id="fan-history-list">
            <?php foreach ($weekHistory as $wh): ?>
            <?php
                $whBets = $pdo->prepare("
                    SELECT fb.predictor_id, fb.bet_type, fb.bet_key, fb.bet_value, fb.confidence, fb.points_earned,
                           fp.racer_id, fp.guest_name
                    FROM fantasy_bets fb
                    JOIN fantasy_predictors fp ON fb.predictor_id = fp.id
                    WHERE fb.week_key = ?
                    ORDER BY fb.points_earned DESC, fb.predictor_id
                ");
                $whBets->execute([$wh['week_key']]);
                $whBetRows = $whBets->fetchAll(PDO::FETCH_ASSOC);

                // Group by predictor
                $whGrouped = [];
                foreach ($whBetRows as $br) {
                    $pid = $br['predictor_id'];
                    if (!isset($whGrouped[$pid])) {
                        $name = ($br['racer_id'] && isset($racerNameMap[$br['racer_id']])) ? $racerNameMap[$br['racer_id']] : ($br['guest_name'] ?: 'Unknown');
                        $whGrouped[$pid] = ['name' => $name, 'total' => 0, 'bets' => []];
                    }
                    if ($br['points_earned'] !== null) {
                        $whGrouped[$pid]['total'] += (int)$br['points_earned'];
                    }
                    $whGrouped[$pid]['bets'][] = $br;
                }
                usort($whGrouped, fn($a, $b) => $b['total'] <=> $a['total']);
            ?>
            <div class="fan-hist-gp" data-week="<?= htmlspecialchars($wh['week_key']) ?>">
                <div class="fan-hist-header">
                    <span class="fan-hist-gpid"><?= htmlspecialchars($wh['week_key']) ?></span>
                    <span class="fan-hist-count"><?= count($whGrouped) ?> predictor<?= count($whGrouped) !== 1 ? 's' : '' ?></span>
                    <?php if ($wh['scored']): ?>
                    <span class="fan-hist-scored">Scored</span>
                    <?php else: ?>
                    <span class="fan-hist-pending">Pending</span>
                    <?php endif; ?>
                    <span class="fan-hist-toggle">+</span>
                </div>
                <div class="fan-hist-rows" style="display: none;">
                    <?php foreach ($whGrouped as $wg): ?>
                    <div class="fan-hist-row">
                        <div class="fan-hist-predictor"><?= htmlspecialchars($wg['name']) ?></div>
                        <?php $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin']; ?>
                        <?php if ($wh['scored'] || $isAdmin): ?>
                        <div class="fan-hist-picks">
                            <?php foreach ($wg['bets'] as $bet): ?>
                            <span class="fan-hist-pick">
                                <?php if ($bet['points_earned'] !== null): ?>
                                <span class="fan-hist-icon <?= $bet['points_earned'] > 0 ? 'fan-hit' : ($bet['points_earned'] < 0 ? 'fan-miss' : 'fan-push') ?>"><?= $bet['points_earned'] > 0 ? 'Y' : ($bet['points_earned'] < 0 ? 'N' : '=') ?></span>
                                <?php endif; ?>
                                <?php
                                    $conf  = (int)($bet['confidence'] ?? 1);
                                    $label = '';
                                    if ($bet['bet_type'] === 'mvp') {
                                        $label = 'Best Form: ' . ($racerNameMap[(int)$bet['bet_value']] ?? '?');
                                    } elseif ($bet['bet_type'] === 'elo_gain') {
                                        $label = 'ELO Climber: ' . ($racerNameMap[(int)$bet['bet_value']] ?? '?');
                                    } elseif ($bet['bet_type'] === 'h2h') {
                                        $label = 'H2H: ' . ($racerNameMap[(int)$bet['bet_value']] ?? '?');
                                    } elseif ($bet['bet_type'] === 'prop') {
                                        $label = ucfirst(str_replace('_', ' ', $bet['bet_key'])) . ': ' . strtoupper($bet['bet_value']);
                                    }
                                ?>
                                <?= htmlspecialchars($label) ?>
                                <?php if ($conf >= 2): ?>
                                    <span class="fan-conf-badge fan-conf-badge--<?= confidenceLabel($conf) ?>" title="<?= ucfirst(confidenceLabel($conf)) ?> confidence (×<?= $conf ?>)">
                                        <?= $conf === 3 ? '🔒' : '×' . $conf ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($bet['points_earned'] !== null): ?>
                                    <span class="fan-pts-readout"><?= ($bet['points_earned'] > 0 ? '+' : '') . (int)$bet['points_earned'] ?></span>
                                <?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($wh['scored']): ?>
                        <div class="fan-hist-pts"><?= $wg['total'] ?> pts</div>
                        <?php endif; ?>
                        <?php else: ?>
                        <div class="fan-hist-picks">
                            <span class="fan-hist-pick" style="color: var(--gray-400); font-style: italic;"><?= count($wg['bets']) ?> pick<?= count($wg['bets']) !== 1 ? 's' : '' ?> &mdash; hidden until scored</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* leaderboard */ ?>

    <!-- ============================================================ -->
    <!-- SUBMIT MODE -->
    <!-- ============================================================ -->
    <?php if ($mode === 'submit'): ?>
    <div class="racer-card fan-submit-card">
        <h2 class="fan-section-title">Make Your Picks &mdash; <?= htmlspecialchars($weekKey) ?></h2>

        <?php if ($submissionsOpen): ?>
        <div class="fan-deadline-banner fan-deadline-banner-open">
            <span>&#9200;</span>
            <div>
                <div class="fan-deadline-label">Deadline: <?= $deadlineFormatted ?></div>
                <div class="fan-deadline-remaining"><?= $timeRemainingStr ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="fan-deadline-banner fan-deadline-banner-locked">
            <span>&#128274;</span>
            <div>
                <strong>Submissions closed!</strong> Next window opens Sunday at 6:00 PM.
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($submitSuccess)): ?>
            <div class="fan-success"><?= htmlspecialchars($submitSuccess) ?></div>
        <?php endif; ?>
        <?php if (!empty($submitError)): ?>
            <div class="fan-error"><?= htmlspecialchars($submitError) ?></div>
        <?php endif; ?>

        <?php if ($submissionsOpen): ?>
        <form method="POST" action="/fantasy?submit" class="fan-form" id="fan-form">
            <?= csrf_field() ?>
            <!-- Who's predicting? -->
            <div class="fan-form-group">
                <label class="fan-label">Who's predicting?</label>
                <select name="predictor_racer_id" id="fan-predictor-select" class="fan-select" required>
                    <option value="">Choose...</option>
                    <?php foreach ($allRacersFull as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="-1">Other...</option>
                </select>
                <input type="text" name="predictor_guest_name" id="fan-guest-name" class="fan-input" placeholder="Enter your name" style="display:none; margin-top: 8px;">
            </div>

            <!-- Section 1: Weekly MVP -->
            <div class="fan-bet-section">
                <div class="fan-bet-header">
                    <h3 class="fan-bet-title">&#127942; Best Form</h3>
                    <span class="fan-pts-badge">5 pts</span>
                </div>
                <p class="fan-bet-desc">Who will have the best form this week? Measured by average points per GP &mdash; not total, so it's fair regardless of how many races each person plays.</p>
                <select name="mvp_pick" class="fan-select" required>
                    <option value="">Pick the week's best performer...</option>
                    <?php foreach ($standings as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (avg: <?= $racerForm[$s['id']] ?? 0 ?> pts)</option>
                    <?php endforeach; ?>
                </select>
                <?php renderConfidencePicker('mvp_pick_conf'); ?>
            </div>

            <!-- Section 2: Biggest ELO Climber -->
            <?php if (!empty($eloClimberCandidates)): ?>
            <div class="fan-bet-section">
                <div class="fan-bet-header">
                    <h3 class="fan-bet-title">&#128200; Biggest ELO Climber</h3>
                    <span class="fan-pts-badge">4 pts</span>
                </div>
                <p class="fan-bet-desc">Who gains the most ELO across this week's GPs? Upsets, beating higher-rated racers, and strong recoveries all pay off here.</p>
                <select name="elo_gain_pick" class="fan-select">
                    <option value="">Pick the week's biggest climber...</option>
                    <?php foreach ($standings as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php renderConfidencePicker('elo_gain_pick_conf'); ?>
            </div>
            <?php endif; ?>

            <!-- Section 3: Head-to-Head Matchups -->
            <?php if (!empty($h2hMatchups)): ?>
            <div class="fan-bet-section">
                <div class="fan-bet-header">
                    <h3 class="fan-bet-title">&#9876;&#65039; Head-to-Head</h3>
                    <span class="fan-pts-badge fan-pts-blue">3 pts each</span>
                </div>
                <p class="fan-bet-desc">Who outscores whom this week? Matchups based on the season's closest rivalries.</p>
                <?php foreach ($h2hMatchups as $idx => $matchup): ?>
                <div class="fan-h2h-matchup">
                    <label class="fan-h2h-option">
                        <input type="radio" name="h2h_<?= $idx ?>" value="<?= $matchup['a']['id'] ?>" required>
                        <span class="fan-h2h-name"><?= htmlspecialchars($matchup['a']['name']) ?></span>
                        <span class="fan-h2h-form">avg <?= $racerForm[$matchup['a']['id']] ?? 0 ?></span>
                    </label>
                    <span class="fan-h2h-vs"><?= $matchup['meetings'] ?> races &middot; <?= $matchup['closeness'] ?>% close</span>
                    <label class="fan-h2h-option">
                        <input type="radio" name="h2h_<?= $idx ?>" value="<?= $matchup['b']['id'] ?>">
                        <span class="fan-h2h-name"><?= htmlspecialchars($matchup['b']['name']) ?></span>
                        <span class="fan-h2h-form">avg <?= $racerForm[$matchup['b']['id']] ?? 0 ?></span>
                    </label>
                    <?php renderConfidencePicker('h2h_' . $idx . '_conf'); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Section 4: Prop Bets -->
            <?php if (!empty($propBets)): ?>
            <div class="fan-bet-section">
                <div class="fan-bet-header">
                    <h3 class="fan-bet-title">&#127183; Prop Bets</h3>
                    <span class="fan-pts-badge fan-pts-gold">2 pts each</span>
                </div>
                <p class="fan-bet-desc">Yes or no — will these things happen this week?</p>
                <?php foreach ($propBets as $prop): ?>
                <div class="fan-prop-row">
                    <div class="fan-prop-label"><?= htmlspecialchars($prop['label']) ?></div>
                    <div class="fan-prop-buttons">
                        <label class="fan-prop-btn">
                            <input type="radio" name="prop_<?= $prop['key'] ?>" value="yes" required>
                            <span class="fan-prop-yes">Yes</span>
                        </label>
                        <label class="fan-prop-btn">
                            <input type="radio" name="prop_<?= $prop['key'] ?>" value="no">
                            <span class="fan-prop-no">No</span>
                        </label>
                    </div>
                    <?php renderConfidencePicker('prop_' . $prop['key'] . '_conf'); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <button type="submit" class="fan-submit-btn">&#128274; Lock It In</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; /* submit */ ?>

    <!-- ============================================================ -->
    <!-- SCORE MODE (admin) -->
    <!-- ============================================================ -->
    <?php if ($mode === 'score'): ?>
        <?php if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']): ?>
        <div class="racer-card fan-score-result-card">
            <div class="fan-error">Admin access required to score predictions.</div>
        </div>
        <?php elseif (empty($scoreWeekKey)): ?>
        <div class="racer-card fan-score-result-card">
            <h2 class="fan-section-title">Score Predictions</h2>
            <form method="GET" action="/fantasy" class="fan-score-form">
                <input type="hidden" name="score" value="1">
                <label class="fan-label">Week:
                    <select name="week" class="fan-select" style="width: auto; min-width: 140px;" required>
                        <option value="">Choose...</option>
                        <?php foreach ($weekHistory as $wh): ?>
                        <option value="<?= htmlspecialchars($wh['week_key']) ?>"><?= htmlspecialchars($wh['week_key']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="fan-btn">Score Week</button>
            </form>
        </div>
        <?php elseif (isset($scoreResults['error'])): ?>
        <div class="racer-card fan-score-result-card">
            <h2 class="fan-section-title">Scoring: <?= htmlspecialchars($scoreWeekKey) ?></h2>
            <div class="fan-error"><?= $scoreResults['error'] ?></div>
            <a href="/fantasy" class="fan-btn fan-btn-back">Back to Leaderboard</a>
        </div>
        <?php else: ?>
        <!-- Show score form to trigger scoring POST -->
        <div class="racer-card fan-score-result-card">
            <h2 class="fan-section-title">Score: <?= htmlspecialchars($scoreWeekKey) ?></h2>
            <?php if (isset($scoreResults['success'])): ?>
            <div class="fan-success">Scored <?= htmlspecialchars($scoreResults['week']) ?>! (<?= $scoreResults['gp_count'] ?> GPs: <?= implode(', ', $scoreResults['gps']) ?>). MVP: <?= htmlspecialchars($scoreResults['mvp']) ?></div>
            <div class="table-scroll-wrapper">
                <table class="clean-table">
                    <thead>
                        <tr><th>Predictor</th><th class="txt-center">Total</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scoreResults['predictors'] as $sp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sp['name']) ?></strong></td>
                            <td class="txt-center fan-points-val"><?= $sp['total'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p>Press the button to score all predictions for <strong><?= htmlspecialchars($scoreWeekKey) ?></strong> based on results recorded this week.</p>
            <form method="POST" action="/fantasy?score=1&week=<?= urlencode($scoreWeekKey) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="fan-btn">Score Now</button>
            </form>
            <?php endif; ?>
            <a href="/fantasy" class="fan-btn fan-btn-back">Back to Leaderboard</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
(function() {
    // "Other" name toggle
    var sel = document.getElementById('fan-predictor-select');
    var guest = document.getElementById('fan-guest-name');
    if (sel && guest) {
        sel.addEventListener('change', function() {
            if (this.value === '-1') {
                guest.style.display = '';
                guest.required = true;
                guest.focus();
            } else {
                guest.style.display = 'none';
                guest.required = false;
                guest.value = '';
            }
        });
    }

    // History accordion
    var historyList = document.getElementById('fan-history-list');
    if (historyList) {
        var cards = historyList.querySelectorAll('.fan-hist-gp');
        cards.forEach(function(card) {
            var header = card.querySelector('.fan-hist-header');
            var rows = card.querySelector('.fan-hist-rows');
            rows.style.display = 'none';
            header.addEventListener('click', function() {
                var isOpen = rows.style.display !== 'none';
                rows.style.display = isOpen ? 'none' : '';
                card.classList.toggle('fan-hist-open', !isOpen);
            });
        });
        if (cards.length > 0) {
            var first = cards[0].querySelector('.fan-hist-rows');
            first.style.display = '';
            cards[0].classList.add('fan-hist-open');
        }
    }
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
