<?php
/**
 * Read-only public JSON API (v1).
 *
 * No auth, no writes — just a clean data feed for embeds / Discord webhooks /
 * spreadsheets. CORS-open. Routed via .htaccess:
 *   GET /api/v1/standings[?season=sNN]   season standings
 *   GET /api/v1/racers                   all racers
 *   GET /api/v1/racer?id=N               one racer's career summary
 *   GET /api/v1/teams[?season=sNN]       team (constructor) standings
 *   GET /api/v1/mikkoliiga[?season=sNN]  Mikkoliiga standings
 *   GET /api/v1/seasons                  season list + scoring system
 *
 * Path: /cdnmk/public_html/api/data.php
 */

require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/gp_logic.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=60');

/** Emit an envelope and exit. */
function api_out($data, array $meta = []): void {
    echo json_encode([
        'data' => $data,
        'meta' => array_merge(['generated_at' => date('c')], $meta),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function api_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

$resource = preg_replace('/[^a-z_]/', '', strtolower($_GET['resource'] ?? ''));
$season   = preg_match('/^s\d{2}$/', $_GET['season'] ?? '') ? $_GET['season'] : getCurrentSeasonNumber();

switch ($resource) {

    case 'standings': {
        $scoringInfo = getScoringSystemInfo($pdo, $season);
        $rows = [];
        foreach (getActiveRacers($pdo, $season) as $r) {
            $rc = getRaceCount($pdo, (int)$r['id'], $season);
            if ($rc < 1) continue;
            $rows[] = [
                'id'     => (int)$r['id'],
                'name'   => $r['name'],
                'score'  => calculateGPScore($pdo, (int)$r['id'], $season),
                'gps'    => $rc,
            ];
        }
        sortStandingsByScoring($rows, $scoringInfo['system'], $pdo, $season);
        foreach ($rows as $i => &$row) { $row = ['rank' => $i + 1] + $row; }
        unset($row);
        api_out($rows, ['season' => $season, 'scoring_system' => $scoringInfo['system'], 'scoring_name' => $scoringInfo['name']]);
    }

    case 'racers': {
        $stmt = $pdo->query("SELECT id, name, nickname, in_mikkoliiga FROM racers ORDER BY name ASC");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'name'         => $r['name'],
                'nickname'     => $r['nickname'] ?: null,
                'in_mikkoliiga'=> (bool)$r['in_mikkoliiga'],
            ];
        }
        api_out($out, ['count' => count($out)]);
    }

    case 'racer': {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) api_error('Missing or invalid id', 400);
        $rs = $pdo->prepare("SELECT id, name, nickname, catchphrase FROM racers WHERE id = ?");
        $rs->execute([$id]);
        $racer = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$racer) api_error('Racer not found', 404);

        // Career totals across all season GPs.
        $cs = $pdo->prepare("
            SELECT COUNT(*) AS gps,
                   SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) AS wins,
                   SUM(CASE WHEN rank <= 3 THEN 1 ELSE 0 END) AS podiums,
                   AVG(gp_points) AS avg_pts,
                   MAX(gp_points) AS best
            FROM results WHERE racer_id = ? AND gpid LIKE 's%'
        ");
        $cs->execute([$id]);
        $career = $cs->fetch(PDO::FETCH_ASSOC) ?: [];

        api_out([
            'id'         => (int)$racer['id'],
            'name'       => $racer['name'],
            'nickname'   => $racer['nickname'] ?: null,
            'catchphrase'=> $racer['catchphrase'] ?: null,
            'career'     => [
                'gps'     => (int)($career['gps'] ?? 0),
                'wins'    => (int)($career['wins'] ?? 0),
                'podiums' => (int)($career['podiums'] ?? 0),
                'avg'     => $career['avg_pts'] !== null ? round((float)$career['avg_pts'], 1) : null,
                'best'    => (int)($career['best'] ?? 0),
            ],
        ]);
    }

    case 'teams': {
        $out = [];
        foreach (getTeamStandings($pdo, $season) as $i => $t) {
            $out[] = [
                'rank'    => $i + 1,
                'name'    => $t['name'],
                'color'   => $t['color'],
                'score'   => (int)$t['score'],
                'members' => array_values($t['members']),
            ];
        }
        api_out($out, ['season' => $season, 'best_n' => teamBestN($pdo, $season)]);
    }

    case 'mikkoliiga': {
        $out = [];
        foreach (getMikkoliigaStandings($pdo, $season) as $i => $m) {
            $out[] = [
                'rank'        => $i + 1,
                'id'          => (int)$m['id'],
                'name'        => $m['name'],
                'score'       => (int)$m['score'],
                'gps_counted' => (int)$m['gps_counted'],
            ];
        }
        api_out($out, ['season' => $season, 'best_x' => MIKKOLIIGA_BEST_X]);
    }

    case 'seasons': {
        $stmt = $pdo->query("SELECT season_id, status, scoring_system, season_name FROM season_meta ORDER BY season_id DESC");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $out[] = [
                'season_id'      => $s['season_id'],
                'name'           => $s['season_name'] ?: $s['season_id'],
                'status'         => $s['status'],
                'scoring_system' => $s['scoring_system'],
            ];
        }
        api_out($out, ['count' => count($out)]);
    }

    default:
        api_error('Unknown resource. Try: standings, racers, racer, teams, mikkoliiga, seasons', 404);
}
