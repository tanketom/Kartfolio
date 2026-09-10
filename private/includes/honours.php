<?php
/**
 * A racer's honours — everything they have actually WON, in one place.
 *
 * The profile already showed season podiums (gold / silver / bronze from the
 * frozen placement snapshots) and nothing else, so a tournament win, a
 * Mikkoliiga crown or a shelf of season awards lived only as a badge among a
 * hundred others. Sports profiles lead with honours; these are the rest of
 * them, rendered as boxes under the podium row.
 *
 * Every honour is returned whether or not it has been won, because an empty
 * case is part of the point — you can see what there is to win. Counts of zero
 * render greyed, exactly like an unearned medal.
 *
 * Cheap by construction: one small query per honour, memoised per request, and
 * the whole set is built once per racer.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gp_logic.php';

/**
 * @return array<int, array{icon:string, label:string, count:int, detail:string, title:string}>
 */
function careerHonours(PDO $pdo, int $racerId, string $racerName): array {
    static $cache = [];
    $key = $racerId . '|' . $racerName;
    if (isset($cache[$key])) return $cache[$key];

    $out = [];
    $add = function (string $icon, string $label, array $items, string $what) use (&$out) {
        $out[] = [
            'icon'   => $icon,
            'label'  => $label,
            'count'  => count($items),
            'detail' => $items ? implode(' · ', $items) : '—',
            'title'  => $items ? $what . ': ' . implode(', ', $items) : $what,
        ];
    };

    // ── Tournaments ─────────────────────────────────────────────────────────
    $tourneys = [];
    try {
        $st = $pdo->prepare("SELECT name FROM tournaments WHERE winner_id = ? ORDER BY COALESCE(end_date, created_at) ASC");
        $st->execute([$racerId]);
        $tourneys = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {}
    $add('🏆', 'Tournaments', $tourneys, 'Tournaments won');

    // ── Mikkoliiga crowns — the sub-league's season leaders ──────────────────
    // Read the same way the Mikkoligan badge reads it, so the two agree.
    $mikko = [];
    try {
        foreach ($pdo->query("SELECT season_id FROM season_meta ORDER BY season_id ASC")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $st = getMikkoliigaStandings($pdo, (string)$sid);
            if ($st && ($st[0]['score'] ?? 0) > 0 && (int)$st[0]['id'] === $racerId) $mikko[] = strtoupper((string)$sid);
        }
    } catch (Throwable $e) {}
    $add('🌟', 'Mikkoliiga', $mikko, 'Mikkoliiga seasons topped');

    // ── Fantasy crowns — frozen into season_meta when a season is archived ───
    $fantasy = [];
    try {
        $st = $pdo->query("SELECT season_id, fantasy_champion FROM season_meta WHERE fantasy_champion IS NOT NULL AND fantasy_champion != '' ORDER BY season_id ASC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Ties share the crown and are stored joined with ' & '.
            foreach (explode('&', (string)$row['fantasy_champion']) as $winner) {
                if (strcasecmp(trim($winner), $racerName) === 0) { $fantasy[] = strtoupper((string)$row['season_id']); break; }
            }
        }
    } catch (PDOException $e) {}
    $add('🧙', 'Fantasy', $fantasy, 'Fantasy seasons won');

    // ── Team titles ─────────────────────────────────────────────────────────
    // getTeamStandings() decides who won a teams season; the Constructor badge
    // reads it the same way, so the badge and this box cannot disagree. Live
    // seasons are provisional and excluded, as they are there.
    $teams = [];
    try {
        $archived = $pdo->query("SELECT season_id FROM season_meta WHERE status = 'archived'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pdo->query("SELECT DISTINCT season_id FROM teams")->fetchAll(PDO::FETCH_COLUMN) as $ts) {
            if (!in_array($ts, $archived, true)) continue;
            $st = getTeamStandings($pdo, (string)$ts);
            if (empty($st) || ($st[0]['score'] ?? 0) <= 0) continue;
            if (isset($st[0]['members'][$racerId])) $teams[] = strtoupper((string)$ts);
        }
    } catch (Throwable $e) { $teams = []; }
    $add('🏗️', 'Team titles', $teams, 'Seasons won with a team');

    // ── Season awards — the ceremony, minus Champion (the gold medal above) ──
    $awards = [];
    try {
        $st = $pdo->prepare("SELECT season_id, award_category FROM season_awards
                             WHERE winner_name = ? AND award_category != 'Champion'
                             ORDER BY season_id ASC, award_category ASC");
        $st->execute([$racerName]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $awards[] = (string)$row['award_category'];
    } catch (PDOException $e) {}
    $add('🎖️', 'Season awards', $awards, 'Season awards won');

    return $cache[$key] = $out;
}
