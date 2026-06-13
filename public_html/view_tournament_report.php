<?php
/**
 * View Tournament Report - Detailed Tournament Summary
 * Path: /cdnmk/public_html/view_tournament_report.php
 */
require_once __DIR__ . '/../private/includes/db.php';

// Validate Input
if (!isset($_GET['id'])) {
    header("Location: /tournaments_hall_of_fame");
    exit;
}

$tournamentId = $_GET['id'];

// Fetch Tournament Details
$stmt = $pdo->prepare("
    SELECT t.*, r.name as winner_name, r.id as winner_id
    FROM tournaments t
    LEFT JOIN racers r ON t.winner_id = r.id
    WHERE t.id = ? AND t.status = 'completed'
");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    die("<h3>Tournament Not Found</h3><p>This tournament does not exist or has not been completed yet.</p><a href='/tournaments_hall_of_fame'>Back to Hall of Fame</a>");
}

// Fetch Participants with their seeds and final placements
$participantsStmt = $pdo->prepare("
    SELECT tp.*, r.name, tp.elo_at_registration
    FROM tournament_participants tp
    JOIN racers r ON tp.racer_id = r.id
    WHERE tp.tournament_id = ?
    ORDER BY tp.seed ASC
");
$participantsStmt->execute([$tournamentId]);
$participants = $participantsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Matches
$matchesStmt = $pdo->prepare("
    SELECT m.*,
           p1.name as player1_name,
           p2.name as player2_name,
           w.name as winner_name
    FROM tournament_matches m
    LEFT JOIN racers p1 ON m.player1_id = p1.id
    LEFT JOIN racers p2 ON m.player2_id = p2.id
    LEFT JOIN racers w ON m.winner_id = w.id
    WHERE m.tournament_id = ?
    ORDER BY m.round, m.match_number
");
$matchesStmt->execute([$tournamentId]);
$allMatches = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

// Group matches by round
$matchesByRound = [];
foreach ($allMatches as $match) {
    $roundKey = $match['bracket'] . '_' . $match['round'];
    $matchesByRound[$roundKey][] = $match;
}

// Format labels
$formatLabels = [
    'single_elim' => 'Single Elimination',
    'double_elim' => 'Double Elimination',
    'gauntlet' => 'Gauntlet',
    'team_relay' => 'Team Relay',
    'survivor' => 'Survivor',
    'team_scramble' => 'Team Scramble',
    'world_cup' => 'World Cup'
];
$formatLabel = $formatLabels[$tournament['format']] ?? $tournament['format'];

$pageTitle = $tournament['name'] . " - Tournament Report";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <!-- Tournament Header -->
    <div class="racer-card tourney-report-header-card">
        <div class="tourney-report-top-row">
            <div>
                <div class="tourney-report-format-label">
                    <?= $formatLabel ?> Tournament
                </div>
                <h1 class="tourney-report-title">
                    <?= htmlspecialchars($tournament['name']) ?>
                </h1>
                <div class="tourney-report-dates">
                    <?= date('F j, Y', strtotime($tournament['start_date'])) ?>
                    <?php if ($tournament['end_date']): ?>
                        - <?= date('F j, Y', strtotime($tournament['end_date'])) ?>
                    <?php endif; ?>
                </div>
                <?php if ($tournament['season_id']): ?>
                    <div class="tourney-season-badge">
                        Season <?= htmlspecialchars($tournament['season_id']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tourney-champion-box">
                <div class="tourney-champion-label">
                    🏆 Champion
                </div>
                <div class="tourney-champion-name">
                    <?= htmlspecialchars($tournament['winner_name']) ?>
                </div>
                <div class="tourney-champion-medal">🏅</div>
            </div>
        </div>

        <div class="tourney-stat-row">
            <div>
                <div class="tourney-stat-label">Format</div>
                <div class="tourney-stat-value"><?= $formatLabel ?></div>
            </div>
            <div>
                <div class="tourney-stat-label">Participants</div>
                <div class="tourney-stat-value"><?= count($participants) ?></div>
            </div>
            <div>
                <div class="tourney-stat-label">Total Matches</div>
                <div class="tourney-stat-value"><?= count($allMatches) ?></div>
            </div>
        </div>
    </div>

    <!-- Tournament Bracket/Results -->
    <div class="racer-card tourney-results-card">
        <h2 class="tourney-section-title">Tournament Results</h2>

        <?php
        function formatRoundName($round, $bracket) {
            if ($bracket === 'gauntlet') return 'Gauntlet Challenge';
            if ($bracket === 'team_relay') return 'Team Relay Matches';

            $names = [
                'R1' => 'Round 1',
                'R2' => 'Round 2',
                'R3' => 'Round 3',
                'QF' => 'Quarter Finals',
                'SF' => 'Semi Finals',
                'F' => 'Finals',
                'GF' => 'Grand Finals'
            ];
            $prefix = $bracket === 'losers' ? 'Losers ' : '';
            return $prefix . ($names[$round] ?? $round);
        }
        ?>

        <?php foreach ($matchesByRound as $roundKey => $matches):
            $firstMatch = $matches[0];
            $roundName = formatRoundName($firstMatch['round'], $firstMatch['bracket']);
        ?>
        <div class="tourney-round-block">
            <h3 class="tourney-round-name">
                <?= htmlspecialchars($roundName) ?>
            </h3>
            <div class="tourney-matches-grid">
                <?php foreach ($matches as $match): ?>
                <div style="background: var(--gray-100); border-radius: 8px; padding: 20px; border-left: 4px solid <?= $match['status'] === 'completed' ? '#2EBD59' : 'var(--gray-400)' ?>;">
                    <div class="tourney-match-num">
                        Match #<?= $match['match_number'] ?>
                    </div>

                    <?php if ($match['status'] === 'bye'): ?>
                        <div class="tourney-bye-text">
                            <strong><?= htmlspecialchars($match['player1_name']) ?></strong> - Bye (Advanced Automatically)
                        </div>
                    <?php else: ?>
                        <div class="tourney-matchup-row">
                            <div class="tourney-matchup-players">
                                <div style="font-weight: <?= $match['winner_id'] == $match['player1_id'] ? '900' : '600' ?>; color: <?= $match['winner_id'] == $match['player1_id'] ? 'var(--nintendo-red)' : '#333' ?>; font-size: 1.1rem;">
                                    <?= htmlspecialchars($match['player1_name']) ?>
                                    <?php if ($match['winner_id'] == $match['player1_id']): ?>
                                        <span class="tourney-winner-check">✓</span>
                                    <?php endif; ?>
                                </div>
                                <div class="tourney-vs-divider">vs</div>
                                <div style="font-weight: <?= $match['winner_id'] == $match['player2_id'] ? '900' : '600' ?>; color: <?= $match['winner_id'] == $match['player2_id'] ? 'var(--nintendo-red)' : '#333' ?>; font-size: 1.1rem;">
                                    <?= htmlspecialchars($match['player2_name']) ?>
                                    <?php if ($match['winner_id'] == $match['player2_id']): ?>
                                        <span class="tourney-winner-check">✓</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($match['winner_id']): ?>
                            <div class="tourney-winner-box">
                                <div class="tourney-winner-label">Winner</div>
                                <div class="tourney-winner-name"><?= htmlspecialchars($match['winner_name']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Participants Section -->
    <div class="racer-card tourney-participants-card">
        <h2 class="tourney-section-title">Tournament Participants</h2>
        <p class="tourney-participants-sub">Seeded by ELO rating at time of registration</p>

        <div class="tourney-participants-grid">
            <?php foreach ($participants as $p): ?>
            <div style="padding: 15px; background: var(--gray-100); border-radius: 8px; display: flex; justify-content: space-between; align-items: center; <?= $p['racer_id'] == $tournament['winner_id'] ? 'border: 2px solid var(--nintendo-red); background: #fdecec;' : '' ?>">
                <div>
                    <div class="tourney-participant-name">
                        <?= htmlspecialchars($p['name']) ?>
                        <?php if ($p['racer_id'] == $tournament['winner_id']): ?>
                            <span class="tourney-winner-medal">🏅</span>
                        <?php endif; ?>
                    </div>
                    <div class="tourney-participant-elo">
                        ELO: <?= $p['elo_at_registration'] ?>
                    </div>
                </div>
                <div class="tourney-participant-seed">
                    #<?= $p['seed'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="tourney-back-row">
        <a href="/tournaments_hall_of_fame" class="tourney-back-btn">
            ← Back to Tournament Hall of Fame
        </a>
    </div>
</div>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
