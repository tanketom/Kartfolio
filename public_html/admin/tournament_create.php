<?php
/**
 * Tournament Creation - Select Participants & Format
 * Path: /cdnmk/public_html/admin/tournament_create.php
 */
require_once __DIR__ . '/../../private/includes/db.php';
require_once __DIR__ . '/../../private/includes/auth.php';
require_admin();

$message = "";
$error = "";

// Fetch all racers with their current ELO ratings
// Calculate ELO ratings on-the-fly for seeding
require_once __DIR__ . '/../../private/includes/gp_logic.php';

// Get all racers
$racersStmt = $pdo->query("SELECT id, name FROM racers ORDER BY name ASC");
$allRacers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate current ELO ratings for all racers
$stmt = $pdo->query("
    SELECT res.gpid, res.race_date, res.racer_id, r.name, res.rank
    FROM results res
    JOIN racers r ON res.racer_id = r.id
    ORDER BY res.race_date ASC, res.gpid ASC, res.rank ASC
");
$all_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ELO calculation
define('INITIAL_RATING', 1500);
define('K_FACTOR_NEW', 40);
define('K_FACTOR_MID', 30);
define('K_FACTOR_VET', 20);

function calculateExpectedScore($racerRating, $opponentRatings) {
    $expected = 0;
    foreach ($opponentRatings as $oppRating) {
        $expected += 1 / (1 + pow(10, ($oppRating - $racerRating) / 400));
    }
    return $expected;
}

function getKFactor($gamesPlayed) {
    if ($gamesPlayed < 10) return K_FACTOR_NEW;
    if ($gamesPlayed < 30) return K_FACTOR_MID;
    return K_FACTOR_VET;
}

// Group by GP and calculate ELO
$gps = [];
foreach ($all_results as $result) {
    $gpid = $result['gpid'];
    if (!isset($gps[$gpid])) {
        $gps[$gpid] = ['date' => $result['race_date'], 'results' => []];
    }
    $gps[$gpid]['results'][] = $result;
}

$ratings = [];
$games_played = [];

foreach ($gps as $gpid => $gp) {
    $results = $gp['results'];
    $numRacers = count($results);

    foreach ($results as $result) {
        $racer = $result['name'];
        $racerId = $result['racer_id'];
        if (!isset($ratings[$racerId])) {
            $ratings[$racerId] = INITIAL_RATING;
            $games_played[$racerId] = 0;
        }
    }

    $changes = [];
    foreach ($results as $result) {
        $racerId = $result['racer_id'];
        $currentRating = $ratings[$racerId];
        $k = getKFactor($games_played[$racerId]);
        $actualScore = $numRacers - $result['rank'];

        $opponentRatings = [];
        foreach ($results as $opp) {
            if ($opp['racer_id'] !== $racerId) {
                $opponentRatings[] = $ratings[$opp['racer_id']];
            }
        }

        $expectedScore = calculateExpectedScore($currentRating, $opponentRatings);
        $ratingChange = $k * ($actualScore - $expectedScore);
        $changes[$racerId] = ['new' => max(100, $currentRating + $ratingChange)];
    }

    foreach ($changes as $racerId => $change) {
        $ratings[$racerId] = $change['new'];
        $games_played[$racerId]++;
    }
}

// Attach ELO to racers
foreach ($allRacers as &$racer) {
    $racer['elo'] = isset($ratings[$racer['id']]) ? round($ratings[$racer['id']]) : 1500;
    $racer['games'] = $games_played[$racer['id']] ?? 0;
}
unset($racer);

// Sort by ELO descending
usort($allRacers, fn($a, $b) => $b['elo'] <=> $a['elo']);

// Fetch available seasons
$seasonsStmt = $pdo->query("SELECT season_id FROM season_meta ORDER BY season_id DESC");
$seasons = $seasonsStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = "Create Tournament - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin">Admin</a>
        <span class="breadcrumb-separator">/</span>
        <a href="/admin/tournaments">Tournaments</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Create</span>
    </nav>

    <header class="section-header tcreate-header">
        <h1>🏆 Create New Tournament</h1>
        <p class="tcreate-header-desc">Select participants and tournament format. Players will be seeded by their current ELO rating.</p>
    </header>

    <form method="POST" action="/admin/tournament-setup" id="tournamentForm">
        <?= csrf_field() ?>
        <div class="racer-card tcreate-details-card">
            <h2 class="tcreate-details-title">Tournament Details</h2>

            <div class="tcreate-fields-grid">
                <div>
                    <label class="tcreate-field-label">
                        Tournament Name
                    </label>
                    <input type="text" name="tournament_name" id="tournamentNameInput" required
                           placeholder="e.g., Season 1 Championship"
                           class="tcreate-text-input">
                    <div class="tcreate-name-suggestion-hint">
                        <div class="tcreate-suggestions-label">Quick suggestions:</div>
                        <div class="tcreate-suggestions-row">
                            <button type="button" onclick="suggestName(1)" class="name-suggestion-btn">
                                <?php
                                $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                echo date('Y') . ' ' . $months[date('n') - 1] . ' ' . $days[date('w')] . ' Invitational';
                                ?>
                            </button>
                            <button type="button" onclick="suggestName(2)" class="name-suggestion-btn">
                                CDN <?= date('Y') ?> Open
                            </button>
                            <button type="button" onclick="suggestName(3)" class="name-suggestion-btn">
                                <?= $currentSeason ? 'Season ' . $currentSeason . ' Championship' : 'Championship Cup' ?>
                            </button>
                            <button type="button" onclick="suggestName(4)" class="name-suggestion-btn">
                                The <?= $months[date('n') - 1] ?> Classic
                            </button>
                            <button type="button" onclick="suggestName(5)" class="name-suggestion-btn">
                                <?= date('Y') ?> Winter Showdown
                            </button>
                            <button type="button" onclick="suggestName(6)" class="name-suggestion-btn">
                                Grand Prix Royale
                            </button>
                            <button type="button" onclick="suggestName(7)" class="name-suggestion-btn">
                                Drift Masters Cup
                            </button>
                            <button type="button" onclick="suggestName(8)" class="name-suggestion-btn">
                                <?= $months[date('n') - 1] ?> Madness
                            </button>
                            <button type="button" onclick="suggestName(9)" class="name-suggestion-btn">
                                Battle for the Blue Shell
                            </button>
                            <button type="button" onclick="suggestName(10)" class="name-suggestion-btn">
                                Rainbow Road Rumble
                            </button>
                        </div>
                    </div>
                </div>

                <div class="tcreate-3col-grid">
                    <div>
                        <label class="tcreate-field-label">
                            Tournament Format
                        </label>
                        <select name="format" id="formatSelect" required class="tcreate-select">
                            <option value="single_elim">Single Elimination</option>
                            <option value="double_elim">Double Elimination</option>
                            <option value="gauntlet">Gauntlet</option>
                            <option value="team_relay">Team Relay</option>
                            <option value="survivor">Survivor</option>
                            <option value="team_scramble">Team Scramble</option>
                            <option value="world_cup">World Cup</option>
                        </select>
                    </div>

                    <div id="worldCupWrap" style="display:none;">
                        <label class="tcreate-field-label">Group matchdays</label>
                        <input type="number" name="group_gps" min="1" max="6" value="3" class="tcreate-select">
                        <small style="color:#888;">GPs each group races before the knockout. 3 = the classic World Cup rhythm.</small>
                    </div>

                    <div id="survivorElimWrap" style="display:none;">
                        <label class="tcreate-field-label">Eliminations per round</label>
                        <input type="number" name="eliminations_per_round" min="1" max="6" value="1" class="tcreate-select">
                        <small style="color:#888;">Bottom N finishers each round are knocked out. Use 2+ for big fields to keep things moving.</small>
                    </div>

                    <div id="scrambleTeamsWrap" style="display:none;">
                        <label class="tcreate-field-label">Number of teams</label>
                        <input type="number" name="num_teams" min="2" max="6" value="2" class="tcreate-select">
                        <small style="color:#888;">The field is snake-drafted into this many balanced teams. Everyone races one GP; the team with the most combined points wins.</small>
                    </div>

                    <div>
                        <label class="tcreate-field-label">
                            Season (Optional)
                        </label>
                        <select name="season_id" class="tcreate-select">
                            <option value="">No Season Link</option>
                            <?php foreach ($seasons as $season): ?>
                                <option value="<?= htmlspecialchars($season) ?>">Season <?= htmlspecialchars($season) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="tcreate-field-label">
                            Tiebreaker Rule
                        </label>
                        <select name="tiebreaker_rule" class="tcreate-select">
                            <option value="points">Points (Primary)</option>
                            <option value="placement">Placement (Primary)</option>
                        </select>
                        <div class="tcreate-tiebreaker-hint">
                            Determines winner if match results are close
                        </div>
                    </div>
                </div>
            </div>

            <div class="tcreate-format-info-box">
                <div class="tcreate-format-info-title">ℹ️ Format Information</div>
                <div id="formatInfo" class="tcreate-format-info-text">
                    <strong>Single Elimination:</strong> Lose once and you're out. Fast and dramatic. Perfect for quick tournaments.
                </div>
            </div>
        </div>

        <div class="racer-card">
            <div class="tcreate-participants-header">
                <h2 class="tcreate-participants-title">
                    Select Participants
                </h2>
                <div class="tcreate-count-row">
                    <span class="tcreate-count-label">
                        Selected: <span id="selectedCount" class="tcreate-count-value">0</span>
                    </span>
                    <button type="button" onclick="selectAll()" class="btn-secondary tcreate-btn-sm">
                        Select All
                    </button>
                    <button type="button" onclick="clearAll()" class="btn-secondary tcreate-btn-sm">
                        Clear All
                    </button>
                </div>
            </div>

            <div class="tcreate-seeding-notice">
                <strong class="tcreate-seeding-label">🎯 Seeding:</strong>
                <span class="tcreate-seeding-text">
                    Players are automatically seeded by their current ELO rating (highest = #1 seed).
                </span>
            </div>

            <div class="tcreate-racers-grid">
                <?php foreach ($allRacers as $idx => $racer): ?>
                <label class="racer-checkbox">
                    <input type="checkbox" name="participants[]" value="<?= $racer['id'] ?>" class="participant-checkbox"
                           onchange="updateCount()"
                           class="tcreate-checkbox-input">
                    <div class="tcreate-racer-info">
                        <div class="tcreate-racer-name">
                            <?= htmlspecialchars($racer['name']) ?>
                        </div>
                        <div class="tcreate-racer-elo">
                            ELO: <strong><?= $racer['elo'] ?></strong> • <?= $racer['games'] ?> GPs
                        </div>
                    </div>
                    <div class="seed-number tcreate-seed-number">
                        #<?= $idx + 1 ?>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="tcreate-submit-row">
                <button type="submit" class="btn-primary tcreate-submit-btn">
                    Continue to Bracket Setup →
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// Generate name suggestions
const nameSuggestions = [
    '<?php
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    echo date('Y') . ' ' . $months[date('n') - 1] . ' ' . $days[date('w')] . ' Invitational';
    ?>',
    'CDN <?= date('Y') ?> Open',
    '<?= $currentSeason ? 'Season ' . $currentSeason . ' Championship' : 'Championship Cup' ?>',
    'The <?= $months[date('n') - 1] ?> Classic',
    '<?= date('Y') ?> Winter Showdown',
    'Grand Prix Royale',
    'Drift Masters Cup',
    '<?= $months[date('n') - 1] ?> Madness',
    'Battle for the Blue Shell',
    'Rainbow Road Rumble'
];

function suggestName(index) {
    document.getElementById('tournamentNameInput').value = nameSuggestions[index - 1];
}


// Format information
const formatInfo = {
    'single_elim': '<strong>Single Elimination:</strong> Lose once and you\'re out. Fast and dramatic.<br><span class="tcreate-format-best-for">📌 Best for:</span> Quick tournaments, time-limited events, high-stakes drama. (8 players = 7 races)',
    'double_elim': '<strong>Double Elimination:</strong> Two brackets (Winners + Losers). Lose once, drop to losers bracket. Lose twice, you\'re out.<br><span class="tcreate-format-best-for">📌 Best for:</span> Giving players a second chance, comeback stories, competitive tournaments. (8 players = ~13 races)',
    'gauntlet': '<strong>Gauntlet:</strong> One "Boss" racer defends their title against all challengers in sequence. Boss must win all matches; challengers only need to win once to become the new Boss.<br><span class="tcreate-format-best-for">📌 Best for:</span> Champion defense events, asymmetric challenge mode, testing dominance. (8 players = 7-14 races)',
    'team_relay': '<strong>Team Relay:</strong> Players split into teams. Each team member races one leg, team with most cumulative wins advances. Emphasizes team strategy and balance.<br><span class="tcreate-format-best-for">📌 Best for:</span> Team-based competition, social/party tournaments, collaborative play. (8 players = ~7 races)',
    'survivor': '<strong>Survivor:</strong> One big multi-player race each round. The bottom finisher is eliminated; everyone else returns next round. Last racer standing wins.<br><span class="tcreate-format-best-for">📌 Best for:</span> Pure attrition drama, large fields, deathboard storylines. (8 players = 7 rounds; bump eliminations/round for bigger fields)',
    'team_scramble': '<strong>Team Scramble:</strong> A one-night event. The field is snake-drafted into balanced teams, everyone races a single GP, and the team with the highest combined points wins. No bracket, no elimination — just a quick team showdown.<br><span class="tcreate-format-best-for">📌 Best for:</span> Casual nights, mixing up rivalries, fast team fun. (any field size, 1 GP)',
    'world_cup': '<strong>World Cup:</strong> The 2026 treatment. A pot-seeded draw splits the field into groups of ~4, each group races its matchdays, then the top 2 per group — plus the best third-placed racers — advance to a head-to-head knockout. Hosted by Kartificial. 🏆<br><span class="tcreate-format-best-for">📌 Best for:</span> Multi-night flagship events, 8–16 racers, maximum drama. (Bracket Pick\'em opens automatically)'
};

document.getElementById('formatSelect').addEventListener('change', function() {
    document.getElementById('formatInfo').innerHTML = formatInfo[this.value];
    // Format-specific config fields.
    const elimWrap = document.getElementById('survivorElimWrap');
    if (elimWrap) elimWrap.style.display = (this.value === 'survivor') ? 'block' : 'none';
    const scrambleWrap = document.getElementById('scrambleTeamsWrap');
    if (scrambleWrap) scrambleWrap.style.display = (this.value === 'team_scramble') ? 'block' : 'none';
    const wcWrap = document.getElementById('worldCupWrap');
    if (wcWrap) wcWrap.style.display = (this.value === 'world_cup') ? 'block' : 'none';
});

function updateCount() {
    const count = document.querySelectorAll('.participant-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

function selectAll() {
    document.querySelectorAll('.participant-checkbox').forEach(cb => {
        cb.checked = true;
    });
    updateCount();
}

function clearAll() {
    document.querySelectorAll('.participant-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateCount();
}

// Validate form submission
document.getElementById('tournamentForm').addEventListener('submit', function(e) {
    const count = document.querySelectorAll('.participant-checkbox:checked').length;
    if (count < 2) {
        e.preventDefault();
        alert('Please select at least 2 participants for the tournament.');
        return false;
    }
    if (count > 32) {
        e.preventDefault();
        alert('Maximum 32 participants allowed. Please deselect some players.');
        return false;
    }
});
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
