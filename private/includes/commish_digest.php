<?php
/**
 * Commissioner's Desk — league signal gathering + prompt builder.
 *
 * Produces a structured snapshot of the current season for an admin-only
 * AI digest: standings, the title race, rank movers, Elo risers/fallers,
 * form streaks, and attendance. The prompt asks Gemini for a short private
 * brief (what's hot, anomalies, broadcast hooks) — NOT public-facing copy.
 *
 * All reads go through the cached gp_logic / elo helpers, so gathering is
 * cheap even though it touches every active racer.
 *
 * Path: /cdnmk/private/includes/commish_digest.php
 */

require_once __DIR__ . '/gp_logic.php';
require_once __DIR__ . '/elo_engine.php';

/**
 * Build the league signal payload for a season. Returns an array of plain
 * facts (no prose) ready to feed buildCommishPrompt().
 */
function gatherCommishSignal($pdo, $season_id) {
    $rules       = getSeasonRules($pdo, $season_id);
    $scoringInfo = getScoringSystemInfo($pdo, $season_id);
    $latestDate  = getLatestRaceDate($pdo, $season_id);

    // GP count this season.
    $gpCount = 0;
    foreach (getSeasonResultsByRacer($pdo, $season_id) as $rows) {
        foreach ($rows as $r) { $seen[$r['gpid']] = true; }
    }
    $gpCount = isset($seen) ? count($seen) : 0;

    // Standings (current) + previous standings for rank-change movers.
    $previous = calculatePreviousStandings($pdo, $season_id, $latestDate, $rules);
    $standings = [];
    foreach (getActiveRacers($pdo, $season_id) as $r) {
        $rc = getRaceCount($pdo, $r['id'], $season_id);
        if ($rc < 1) continue;
        $standings[] = [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'score'     => calculateGPScore($pdo, $r['id'], $season_id),
            'raceCount' => $rc,
        ];
    }
    sortStandingsByScoring($standings, $scoringInfo['system'], $pdo, $season_id);

    // Attach current rank + movement.
    $movers = [];
    foreach ($standings as $i => &$s) {
        $rank = $i + 1;
        $prevRank = $previous[$s['id']] ?? null;
        $s['rank'] = $rank;
        if ($prevRank !== null && $prevRank !== $rank) {
            $movers[] = [
                'name'   => $s['name'],
                'from'   => $prevRank,
                'to'     => $rank,
                'change' => $prevRank - $rank, // + = climbed
            ];
        }
    }
    unset($s);
    usort($movers, fn($a, $b) => abs($b['change']) <=> abs($a['change']));

    // Title race gap.
    $titleGap = null;
    if (count($standings) >= 2) {
        $titleGap = [
            'leader'    => $standings[0]['name'],
            'runner_up' => $standings[1]['name'],
            'gap'       => round((float)$standings[0]['score'] - (float)$standings[1]['score'], 2),
        ];
    }

    // Season Elo deltas: first rating going INTO the season's opening GP
    // ('old') → rating coming OUT of the latest GP ('new'). Read from the raw
    // chronological gp_changelog list (racers carry both 'old' and 'new').
    $eloData   = calculateAllELORatings($pdo);
    $eloFirst = $eloLast = [];
    foreach ($eloData['gp_changelog'] ?? [] as $gpLog) {
        if (strpos($gpLog['gpid'], $season_id) !== 0) continue;
        foreach ($gpLog['racers'] as $racer) {
            $name = $racer['name'];
            if (!isset($eloFirst[$name])) $eloFirst[$name] = $racer['old'];
            $eloLast[$name] = $racer['new'];
        }
    }
    $eloDeltas = [];
    foreach ($eloLast as $name => $last) {
        $eloDeltas[] = ['name' => $name, 'delta' => (int)round($last - $eloFirst[$name])];
    }
    usort($eloDeltas, fn($a, $b) => $b['delta'] <=> $a['delta']);
    $eloRisers  = array_slice(array_filter($eloDeltas, fn($e) => $e['delta'] > 0), 0, 3);
    $eloFallers = array_slice(array_reverse(array_filter($eloDeltas, fn($e) => $e['delta'] < 0)), 0, 3);

    // Current podium streaks (consecutive GPs finishing rank <= 3, most recent first).
    $streaks = [];
    foreach (getActiveRacers($pdo, $season_id) as $r) {
        $rows = getRacerSeasonRows($pdo, $r['id'], $season_id);
        // Most recent first.
        usort($rows, function ($a, $b) {
            if ($a['race_date'] !== $b['race_date']) return strcmp($b['race_date'], $a['race_date']);
            return (int)$b['id'] <=> (int)$a['id'];
        });
        $streak = 0;
        foreach ($rows as $row) {
            if ((int)$row['rank'] <= 3) $streak++;
            else break;
        }
        if ($streak >= 2) $streaks[] = ['name' => $r['name'], 'streak' => $streak];
    }
    usort($streaks, fn($a, $b) => $b['streak'] <=> $a['streak']);

    // Attendance leader.
    $attendance = [];
    foreach ($standings as $s) $attendance[$s['name']] = $s['raceCount'];
    arsort($attendance);

    return [
        'season_id'      => $season_id,
        'season_name'    => $scoringInfo['rules']['season_name'] ?? $season_id,
        'scoring_system' => is_array($scoringInfo) ? ($scoringInfo['name'] ?? '') : '',
        'gp_count'       => $gpCount,
        'standings'      => array_slice($standings, 0, 8),
        'title_gap'      => $titleGap,
        'movers'         => array_slice($movers, 0, 5),
        'elo_risers'     => array_values($eloRisers),
        'elo_fallers'    => array_values($eloFallers),
        'streaks'        => array_slice($streaks, 0, 5),
        'attendance'     => array_slice($attendance, 0, 3, true),
    ];
}

/**
 * Turn the signal payload into a Gemini prompt. The brief is private (for
 * the commissioner), so it can be frank and tactical — not broadcast copy.
 */
function buildCommishPrompt($signal, $leagueName) {
    $json = json_encode($signal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return <<<PROMPT
You are the analytics aide to the commissioner of {$leagueName}, a Mario Kart
8 Deluxe league. Write a SHORT private briefing (the commissioner reads this,
the public never sees it) based only on the season data below.

Keep it tight and tactical — about 150–220 words, plain paragraphs, no
headings, no markdown fences, no bullet symbols. Cover, only where the data
supports it:
  - The state of the title race (is it close, runaway, or wide open?).
  - Who is heating up or cooling off (rank movers, Elo swings, podium
    streaks) and why it matters.
  - One or two concrete BROADCAST HOOKS the commissioner could lean on for
    the next news post — phrase these as suggestions, e.g. "worth a story:".
  - Any anomaly worth a glance (an unusual attendance gap, a stalled leader,
    a surprise riser).

Be specific with names and numbers from the data. Do not invent events that
aren't in the data. Do not flatter. If the season is very young (few GPs),
say so plainly and keep it brief.

SEASON DATA (JSON):
{$json}
PROMPT;
}
