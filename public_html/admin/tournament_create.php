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

// Current season (for name suggestions / placeholder)
$currentSeason = function_exists('getCurrentSeasonNumber') ? getCurrentSeasonNumber() : '';

$pageTitle = "Create Tournament - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/admin.css">';
include __DIR__ . '/../../private/templates/header.php';

$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$days   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
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

    <header class="page-header">
        <h1 class="page-title">🏆 Create Tournament</h1>
        <p class="page-subtitle">PICK YOUR RACERS — THE FORMATS RE-RANK THEMSELVES TO FIT THE FIELD</p>
    </header>

    <form method="POST" action="/admin/tournament-setup" id="tournamentForm">
        <?= csrf_field() ?>

        <!-- ── STEP 1 · racers ───────────────────────────────────────── -->
        <div class="tc-step">
            <div class="tc-step-head">
                <span class="tc-step-num">1</span>
                <h2 class="tc-step-title">Who's racing?</h2>
                <span class="tc-count-badge"><span id="selCount">0</span> selected</span>
                <span class="tc-bulk">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="bulkSel(true)">Select all</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="bulkSel(false)">Clear</button>
                </span>
            </div>
            <p class="tc-step-hint">🎯 Seeded automatically by Elo (highest = #1 seed). The format cards below re-rank live as you pick.</p>
            <div class="tc-racer-grid">
                <?php foreach ($allRacers as $idx => $racer): ?>
                <label class="tc-racer">
                    <input type="checkbox" name="participants[]" value="<?= $racer['id'] ?>" class="tc-cb" onchange="recompute()">
                    <span class="tc-racer-seed">#<?= $idx + 1 ?></span>
                    <span class="tc-racer-main">
                        <span class="tc-racer-name"><?= htmlspecialchars($racer['name']) ?></span>
                        <span class="tc-racer-elo">Elo <strong><?= $racer['elo'] ?></strong> · <?= $racer['games'] ?> GPs</span>
                    </span>
                    <span class="tc-racer-check">✓</span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── STEP 2 · format ───────────────────────────────────────── -->
        <div class="tc-step">
            <div class="tc-step-head">
                <span class="tc-step-num">2</span>
                <h2 class="tc-step-title">Choose a format</h2>
                <span class="tc-fit-note">Best fit for <b id="fmtCount">0</b> racers, ranked ↓</span>
            </div>
            <div id="formatCards" class="tc-format-grid">
                <p class="tc-empty">Pick at least 2 racers above to see recommended formats.</p>
            </div>
            <input type="hidden" name="format" id="formatInput" required value="">

            <!-- per-format config (revealed when its card is chosen) -->
            <div id="cfg-survivor" class="tc-cfg" hidden>
                <label class="tc-field-label">Eliminations per round</label>
                <input type="number" name="eliminations_per_round" min="1" max="6" value="1" class="tc-input">
                <small>Bottom N finishers knocked out each round. Bump to 2+ for big fields.</small>
            </div>
            <div id="cfg-team_scramble" class="tc-cfg" hidden>
                <label class="tc-field-label">Number of teams</label>
                <input type="number" name="num_teams" min="2" max="6" value="2" class="tc-input">
                <small>The field is snake-drafted into this many balanced teams. One GP, highest combined points wins.</small>
            </div>
            <div id="cfg-world_cup" class="tc-cfg" hidden>
                <label class="tc-field-label">Group matchdays</label>
                <input type="number" name="group_gps" min="1" max="6" value="3" class="tc-input">
                <small>GPs each group races before the knockout. 3 = the classic World Cup rhythm.</small>
            </div>
            <div id="cfg-snakes_ladders" class="tc-cfg" hidden>
                <div class="tc-cfg-row">
                    <div>
                        <label class="tc-field-label">Board length</label>
                        <input type="number" name="snl_board_len" id="snlLen" min="12" max="120" value="30" class="tc-input" oninput="snlHint()">
                        <small>Squares to the finish. <span id="snlRounds">~9</span> heats-worth of racing, give or take.</small>
                    </div>
                    <div>
                        <label class="tc-field-label">Chaos</label>
                        <select name="snl_chaos" class="tc-input">
                            <option value="low">Low — few snakes &amp; ladders, skill rules</option>
                            <option value="medium" selected>Medium — balanced</option>
                            <option value="high">High — hazards everywhere, party mode</option>
                        </select>
                        <small>How many snakes &amp; ladders, and how far they fling you.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── STEP 3 · details ──────────────────────────────────────── -->
        <div class="tc-step">
            <div class="tc-step-head">
                <span class="tc-step-num">3</span>
                <h2 class="tc-step-title">Name &amp; options</h2>
            </div>
            <label class="tc-field-label">Tournament name</label>
            <input type="text" name="tournament_name" id="tournamentNameInput" required
                   placeholder="e.g., Season <?= htmlspecialchars($currentSeason ?? '') ?> Championship" class="tc-input tc-input-lg">
            <div class="tc-suggestions">
                <?php
                $suggestions = [
                    date('Y') . ' ' . $months[date('n') - 1] . ' ' . $days[date('w')] . ' Invitational',
                    'CDN ' . date('Y') . ' Open',
                    $currentSeason ? 'Season ' . $currentSeason . ' Championship' : 'Championship Cup',
                    'The ' . $months[date('n') - 1] . ' Classic',
                    'Battle for the Blue Shell',
                    'Rainbow Road Rumble',
                ];
                foreach ($suggestions as $i => $s): ?>
                    <button type="button" class="name-suggestion-btn" onclick="document.getElementById('tournamentNameInput').value=this.textContent.trim()"><?= htmlspecialchars($s) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="tc-cfg-row tc-mt">
                <div>
                    <label class="tc-field-label">Link to season (optional)</label>
                    <select name="season_id" class="tc-input">
                        <option value="">No season link</option>
                        <?php foreach ($seasons as $season): ?>
                            <option value="<?= htmlspecialchars($season) ?>">Season <?= htmlspecialchars($season) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tc-field-label">Tiebreaker</label>
                    <select name="tiebreaker_rule" class="tc-input">
                        <option value="points">Points (primary)</option>
                        <option value="placement">Placement (primary)</option>
                    </select>
                    <small>Used to break close match results.</small>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary tc-submit" id="tcSubmit" disabled>Pick racers &amp; a format to continue</button>
    </form>
</div>

<style>
.tc-step { display:block; background:var(--gray-50); border:2.5px solid var(--ink); border-radius:16px; box-shadow:4px 4px 0 var(--ink); padding:22px 26px; margin-bottom:22px; }
.tc-step-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:6px; }
.tc-step-num { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:999px; background:var(--nintendo-red); color:#fff; border:2px solid var(--ink); font-family:var(--font-display); font-weight:700; box-shadow:2px 2px 0 var(--ink); }
.tc-step-title { margin:0; font-size:1.4rem; flex:1; }
.tc-count-badge { background:var(--gray-50); border:2px solid var(--ink); border-radius:999px; padding:3px 14px; font-weight:800; box-shadow:2px 2px 0 var(--ink); }
.tc-count-badge #selCount { font-family:var(--font-mono); color:var(--nintendo-red); }
.tc-bulk { display:flex; gap:6px; }
.tc-step-hint { color:var(--gray-600); font-size:0.88rem; margin:0 0 16px; }

.tc-racer-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:10px; }
.tc-racer { display:flex; align-items:center; gap:10px; background:var(--gray-50); border:2px solid var(--gray-300); border-radius:12px; padding:10px 12px; cursor:pointer; transition:border-color .12s, box-shadow .12s, transform .12s; position:relative; }
.tc-racer:hover { border-color:var(--gray-500); }
.tc-racer .tc-cb { position:absolute; opacity:0; pointer-events:none; }
.tc-racer-seed { font-family:var(--font-display); font-weight:700; color:var(--gray-400); font-size:0.95rem; min-width:30px; }
.tc-racer-main { flex:1; min-width:0; display:flex; flex-direction:column; }
.tc-racer-name { font-weight:800; text-transform:uppercase; font-size:0.95rem; line-height:1.1; }
.tc-racer-elo { font-size:0.72rem; color:var(--gray-600); }
.tc-racer-check { color:transparent; font-weight:900; font-size:1.1rem; }
.tc-racer:has(.tc-cb:checked) { border-color:var(--ink); box-shadow:3px 3px 0 var(--ink); background:#fff6dc; transform:translate(-1px,-1px); }
.tc-racer:has(.tc-cb:checked) .tc-racer-seed { color:var(--nintendo-red); }
.tc-racer:has(.tc-cb:checked) .tc-racer-check { color:var(--success-text,#157347); }

.tc-fit-note { color:var(--gray-600); font-size:0.85rem; }
.tc-fit-note b { color:var(--nintendo-red); font-family:var(--font-mono); }
.tc-format-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:12px; }
.tc-empty { color:var(--gray-500); font-style:italic; }
.tc-fmt { text-align:left; background:var(--gray-50); border:2.5px solid var(--ink); border-radius:14px; box-shadow:4px 4px 0 var(--ink); padding:14px 16px; cursor:pointer; transition:transform .12s, box-shadow .12s; display:flex; flex-direction:column; gap:6px; font:inherit; }
.tc-fmt:hover:not(:disabled) { transform:translate(-2px,-2px); box-shadow:6px 6px 0 var(--ink); }
.tc-fmt:disabled { opacity:.45; cursor:not-allowed; box-shadow:none; }
.tc-fmt.sel { background:#fff6dc; box-shadow:6px 6px 0 var(--nintendo-red); border-color:var(--nintendo-red); }
.tc-fmt-top { display:flex; align-items:center; gap:8px; }
.tc-fmt-emoji { font-size:1.4rem; }
.tc-fmt-name { font-family:var(--font-display); font-weight:700; font-size:1.05rem; flex:1; }
.tc-fmt-badge { font-size:0.66rem; font-weight:800; text-transform:uppercase; letter-spacing:.5px; padding:2px 8px; border-radius:999px; border:1.5px solid var(--ink); }
.tc-fmt-badge.great { background:#e6f6ec; color:#157347; }
.tc-fmt-badge.ok    { background:#e2f4fc; color:#0066aa; }
.tc-fmt-badge.bad   { background:var(--gray-200); color:var(--gray-600); }
.tc-fmt-blurb { font-size:0.8rem; color:var(--gray-700); line-height:1.4; }
.tc-fmt-why { font-size:0.72rem; color:var(--gray-500); font-style:italic; }

.tc-cfg { margin-top:16px; padding:14px 16px; background:var(--gray-100); border:2px dashed var(--gray-300); border-radius:12px; }
.tc-cfg small { display:block; color:var(--gray-600); font-size:0.75rem; margin-top:4px; }
.tc-cfg-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.tc-field-label { display:block; font-weight:800; font-size:0.78rem; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-600); margin-bottom:6px; }
.tc-input { width:100%; background:var(--gray-50); border:2px solid var(--gray-300); border-radius:8px; padding:10px 12px; font:inherit; font-size:16px; }
.tc-input:focus { outline:none; border-color:var(--nintendo-red); }
.tc-input-lg { font-size:1.05rem; font-weight:700; }
.tc-suggestions { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
.tc-mt { margin-top:18px; }
.tc-submit { width:100%; margin-top:6px; font-size:1.05rem; padding:16px; }
.tc-submit:disabled { opacity:.5; cursor:not-allowed; }
@media (max-width:560px){ .tc-cfg-row { grid-template-columns:1fr; } }
</style>

<script>
const FORMATS = [
  { key:'snakes_ladders', emoji:'🐍', name:'Snakes & Ladders', min:2,  ideal:[4,12], max:16,
    blurb:'Climb one shared board in rotating heats of 4. Luck + skill, everyone stays in the hunt.' },
  { key:'survivor', emoji:'💀', name:'Survivor', min:4, ideal:[6,20], max:32,
    blurb:'One big race each round; the bottom finisher is eliminated. Pure attrition drama.' },
  { key:'team_scramble', emoji:'🤝', name:'Team Scramble', min:4, ideal:[6,16], max:24,
    blurb:'Snake-drafted into balanced teams, one GP, highest combined points wins. A quick night.' },
  { key:'world_cup', emoji:'🌍', name:'World Cup', min:8, ideal:[12,16], max:24,
    blurb:'Groups of four → knockout, hosted by Kartificial. The flagship multi-night event.' },
  { key:'single_elim', emoji:'⚔️', name:'Single Elimination', min:4, ideal:[8,16], max:32, pow2:true,
    blurb:'Lose once, you’re out. Fast and brutal. Cleanest at 8 or 16.' },
  { key:'double_elim', emoji:'🔁', name:'Double Elimination', min:4, ideal:[8,16], max:24, pow2:true,
    blurb:'Winners + losers brackets — one bad race won’t end your run.' },
  { key:'gauntlet', emoji:'👑', name:'Gauntlet', min:3, ideal:[4,8], max:12,
    blurb:'One Boss defends the title against every challenger in sequence.' },
  { key:'team_relay', emoji:'🏁', name:'Team Relay', min:4, ideal:[6,12], max:16, even:true,
    blurb:'Split into teams; each member races a leg. Team strategy and balance.' },
];

function fitOf(f, n) {
  if (n < f.min)  return { score:-1, label:`Needs ${f.min}+`, cls:'bad', why:`Add ${f.min - n} more racer${f.min-n>1?'s':''}.` };
  if (n > f.max)  return { score:-1, label:`Max ${f.max}`,  cls:'bad', why:`Too many — cap is ${f.max}.` };
  let why = '', score, cls, label;
  if (n >= f.ideal[0] && n <= f.ideal[1]) {
    score = 100; cls = 'great'; label = 'Perfect fit';
    if (f.pow2 && (n & (n-1)) !== 0) { score = 78; label = 'Good'; why = `Cleanest at a power of 2 (8, 16).`; }
    if (f.even && n % 2) { score = 72; label = 'Good'; why = 'Works best with an even count.'; }
  } else {
    const dist = n < f.ideal[0] ? f.ideal[0] - n : n - f.ideal[1];
    score = Math.max(12, 60 - dist*9); cls = 'ok'; label = 'Workable';
    why = n < f.ideal[0] ? `Sweetest spot is ${f.ideal[0]}–${f.ideal[1]}.` : `Gets long past ${f.ideal[1]}.`;
  }
  return { score, label, cls, why };
}

let chosen = '';
function recompute() {
  const n = document.querySelectorAll('.tc-cb:checked').length;
  document.getElementById('selCount').textContent = n;
  document.getElementById('fmtCount').textContent = n;

  const grid = document.getElementById('formatCards');
  if (n < 2) {
    grid.innerHTML = '<p class="tc-empty">Pick at least 2 racers above to see recommended formats.</p>';
    chosen = ''; document.getElementById('formatInput').value = '';
    document.querySelectorAll('.tc-cfg').forEach(c => c.hidden = true);
    syncSubmit(); return;
  }
  const ranked = FORMATS.map(f => ({ f, fit: fitOf(f, n) }))
                        .sort((a,b) => b.fit.score - a.fit.score);
  grid.innerHTML = ranked.map(({f, fit}) => `
    <button type="button" class="tc-fmt ${chosen===f.key?'sel':''}" data-key="${f.key}"
            ${fit.score < 0 ? 'disabled' : ''} onclick="selectFormat('${f.key}')">
      <span class="tc-fmt-top">
        <span class="tc-fmt-emoji">${f.emoji}</span>
        <span class="tc-fmt-name">${f.name}</span>
        <span class="tc-fmt-badge ${fit.cls}">${fit.label}</span>
      </span>
      <span class="tc-fmt-blurb">${f.blurb}</span>
      ${fit.why ? `<span class="tc-fmt-why">${fit.why}</span>` : ''}
    </button>`).join('');

  // if the chosen format is now invalid, drop it
  if (chosen) {
    const cf = FORMATS.find(f => f.key === chosen);
    if (cf && fitOf(cf, n).score < 0) { chosen=''; document.getElementById('formatInput').value=''; document.querySelectorAll('.tc-cfg').forEach(c=>c.hidden=true); }
  }
  syncSubmit();
}

function selectFormat(key) {
  chosen = key;
  document.getElementById('formatInput').value = key;
  document.querySelectorAll('.tc-fmt').forEach(b => b.classList.toggle('sel', b.dataset.key === key));
  document.querySelectorAll('.tc-cfg').forEach(c => c.hidden = (c.id !== 'cfg-' + key));
  syncSubmit();
}

function syncSubmit() {
  const n = document.querySelectorAll('.tc-cb:checked').length;
  const ok = n >= 2 && chosen !== '';
  const btn = document.getElementById('tcSubmit');
  btn.disabled = !ok;
  btn.textContent = ok ? 'Create Tournament →'
    : (n < 2 ? 'Pick at least 2 racers' : 'Choose a format');
}

function bulkSel(on) { document.querySelectorAll('.tc-cb').forEach(cb => cb.checked = on); recompute(); }

function snlHint() {
  const len = parseInt(document.getElementById('snlLen').value || '30', 10);
  document.getElementById('snlRounds').textContent = '~' + Math.max(2, Math.round(len / 3.5));
}

document.getElementById('tournamentForm').addEventListener('submit', function(e) {
  const n = document.querySelectorAll('.tc-cb:checked').length;
  if (n < 2 || !chosen) { e.preventDefault(); alert('Pick at least 2 racers and a format.'); }
  if (n > 32) { e.preventDefault(); alert('Maximum 32 participants.'); }
});
recompute(); snlHint();
</script>

<?php include __DIR__ . '/../../private/templates/footer.php'; ?>
