<?php
/**
 * Cup Favourites — head-to-head cup voting + Elo ranking.
 *
 * Anyone can vote: pick the cup you'd rather race. The system builds an
 * Elo-ranked preference list of all 24 cups, useful as a seed for which
 * cups to use in upcoming tournaments.
 *
 * Path: /cdnmk/public_html/cup_favourites.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/cup_ranking.php';

$voterId = cupPrefVoterId();
[$cupA, $cupB] = pickCupPair($pdo, $voterId);
$rankings = cupRankings($pdo);
uasort($rankings, fn($a, $b) => $b['elo'] <=> $a['elo']);

$voterVotes  = cupPrefVoterVotes($pdo, $voterId);
$globalVotes = cupPrefTotalVotes($pdo);

$pageTitle = 'Cup Favourites - Kartfolio';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Cup Favourites</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🏆 Cup Favourites</h1>
        <p class="page-subtitle">WHICH CUP WOULD YOU RATHER RACE?</p>
    </header>

    <div class="cf-intro">
        <p>
            Pick your favourite of the two cups below. Every vote is a head-to-head Elo update; over time the rankings settle into a global preference list that we use to seed tournament cup pools.
        </p>
        <p class="cf-counts">
            <strong id="cf-voter-count"><?= $voterVotes ?></strong> votes from you ·
            <strong id="cf-global-count"><?= $globalVotes ?></strong> total
        </p>
    </div>

    <section class="cf-vote-card" id="cf-vote-card" data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
        <div class="cf-vs">
            <button class="cf-pick" id="cf-pick-a" data-cup="<?= htmlspecialchars($cupA) ?>">
                <div class="cf-pick-emoji"><?= getMKCupEmoji($cupA) ?></div>
                <div class="cf-pick-name"><?= htmlspecialchars($cupA) ?> Cup</div>
            </button>
            <div class="cf-vs-label">vs</div>
            <button class="cf-pick" id="cf-pick-b" data-cup="<?= htmlspecialchars($cupB) ?>">
                <div class="cf-pick-emoji"><?= getMKCupEmoji($cupB) ?></div>
                <div class="cf-pick-name"><?= htmlspecialchars($cupB) ?> Cup</div>
            </button>
        </div>
        <div class="cf-status" id="cf-status" hidden></div>
    </section>

    <section class="cf-rankings">
        <h2 class="cf-rankings-title">Global Ranking</h2>
        <p class="cf-rankings-sub">Elo from <?= $globalVotes ?> votes. Cups with no votes sit at the 1500 baseline.</p>
        <div class="cf-rankings-list" id="cf-rankings-list">
            <?php $rank = 1; foreach ($rankings as $cup => $info): ?>
            <div class="cf-row" data-cup="<?= htmlspecialchars($cup) ?>">
                <span class="cf-rank">#<?= $rank ?></span>
                <span class="cf-emoji"><?= getMKCupEmoji($cup) ?></span>
                <span class="cf-name"><?= htmlspecialchars($cup) ?> Cup</span>
                <span class="cf-elo"><?= $info['elo'] ?></span>
                <span class="cf-votes"><?= $info['votes_total'] ?> vote<?= $info['votes_total'] === 1 ? '' : 's' ?></span>
                <span class="cf-winpct"><?= $info['win_pct'] !== null ? $info['win_pct'] . '%' : '—' ?></span>
            </div>
            <?php $rank++; endforeach; ?>
        </div>
    </section>
</div>

<style>
.cf-intro { background:var(--gray-50); border:1px solid var(--gray-200); border-left:4px solid #FFD700; border-radius:8px; padding:16px 22px; margin-bottom:24px; color:var(--gray-600); }
.cf-intro p { margin:0 0 8px; line-height:1.5; }
.cf-intro p:last-child { margin:0; }
.cf-counts { font-size:0.9rem; color:#888; }
.cf-counts strong { color:#FFD700; }

.cf-vote-card { background:#fff6dc; border:1px solid #f0c040; border-radius:12px; padding:24px; margin-bottom:32px; box-shadow:0 6px 16px rgba(255,200,0,0.12); }
.cf-vs { display:flex; align-items:stretch; justify-content:center; gap:16px; }
.cf-pick { flex:1; max-width:280px; background:var(--gray-50); border:2px solid #e8c850; border-radius:14px; padding:24px 16px; cursor:pointer; transition:transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease; }
.cf-pick:hover { transform:translateY(-3px); border-color:var(--nintendo-red); box-shadow:0 8px 20px rgba(230,0,18,0.18); }
.cf-pick:focus-visible { outline:3px solid #0066cc; outline-offset:2px; }
.cf-pick:disabled { opacity:0.5; cursor:wait; }
.cf-pick-emoji { font-size:3.5rem; line-height:1; margin-bottom:8px; }
.cf-pick-name { font-size:1.1rem; font-weight:900; color:var(--gray-800); text-transform:uppercase; letter-spacing:0.5px; }
.cf-vs-label { display:flex; align-items:center; font-size:1.4rem; font-weight:900; color:var(--nintendo-red); font-style:italic; }
.cf-status { margin-top:14px; text-align:center; font-size:0.9rem; }

.cf-rankings { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:10px; padding:20px 24px; }
.cf-rankings-title { color:#fff; margin:0; font-size:1.3rem; }
.cf-rankings-sub { color:var(--gray-600); font-size:0.85rem; margin:4px 0 16px; font-style:italic; }
.cf-rankings-list { display:flex; flex-direction:column; gap:4px; }
.cf-row { display:grid; grid-template-columns:48px 36px 1fr 80px 90px 60px; align-items:center; gap:10px; padding:8px 12px; background:var(--gray-200); border-radius:6px; border-left:3px solid transparent; transition:background 0.2s ease; }
.cf-row:nth-child(1) { border-left-color:#FFD700; background:#1a1408; }
.cf-row:nth-child(2) { border-left-color:#C0C0C0; }
.cf-row:nth-child(3) { border-left-color:#CD7F32; }
.cf-row.cf-row-flash { background:#fff6dc; }
.cf-rank { font-weight:900; color:#999; font-size:0.9rem; }
.cf-emoji { font-size:1.4rem; line-height:1; text-align:center; }
.cf-name { color:#fff; font-weight:700; font-size:0.95rem; }
.cf-elo { color:#FFD700; font-weight:900; font-variant-numeric:tabular-nums; text-align:right; }
.cf-votes { color:#888; font-size:0.8rem; text-align:right; }
.cf-winpct { color:var(--gray-500); font-size:0.85rem; text-align:right; font-variant-numeric:tabular-nums; }
@media (max-width:600px) {
    .cf-vs { flex-direction:column; align-items:stretch; }
    .cf-vs-label { justify-content:center; }
    .cf-row { grid-template-columns:36px 30px 1fr 60px; }
    .cf-votes, .cf-winpct { display:none; }
}
</style>

<script>
(function () {
    const card        = document.getElementById('cf-vote-card');
    const pickABtn    = document.getElementById('cf-pick-a');
    const pickBBtn    = document.getElementById('cf-pick-b');
    const status      = document.getElementById('cf-status');
    const list        = document.getElementById('cf-rankings-list');
    const voterCount  = document.getElementById('cf-voter-count');
    const globalCount = document.getElementById('cf-global-count');

    function setButtons(disabled) {
        pickABtn.disabled = disabled;
        pickBBtn.disabled = disabled;
    }

    async function castVote(winner, loser) {
        setButtons(true);
        status.hidden = false;
        status.style.color = '#888';
        status.textContent = 'Recording vote…';

        const body = new URLSearchParams({
            winner: winner,
            loser: loser,
            csrf_token: card.dataset.csrf,
        });
        try {
            const res = await fetch('/api/cup-preference-vote', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
            });
            const data = await res.json();
            if (!data.success) {
                status.style.color = '#c0392b';
                status.textContent = 'Error: ' + (data.error || 'Unknown');
                setButtons(false);
                return;
            }
            renderNext(data.next_pair);
            renderRankings(data.rankings, winner);
            voterCount.textContent  = data.voter_votes;
            globalCount.textContent = data.global_votes;
            status.style.color = '#2EBD59';
            status.textContent = 'Voted! ' + (data.voter_votes) + ' votes from you.';
        } catch (e) {
            status.style.color = '#c0392b';
            status.textContent = 'Network error: ' + e.message;
            setButtons(false);
        }
    }

    function renderNext(pair) {
        if (!pair || pair.length !== 2) return;
        pickABtn.dataset.cup = pair[0];
        pickABtn.querySelector('.cf-pick-name').textContent = pair[0] + ' Cup';
        pickBBtn.dataset.cup = pair[1];
        pickBBtn.querySelector('.cf-pick-name').textContent = pair[1] + ' Cup';
        // Cup emoji lookup is server-side; for the AJAX update we keep the
        // existing emoji slot but rotate the name. Reload the page if you
        // want a fresh emoji per pair.
        setButtons(false);
    }

    function renderRankings(rankings, justVotedCup) {
        list.innerHTML = '';
        rankings.forEach((r, idx) => {
            const row = document.createElement('div');
            row.className = 'cf-row';
            if (r.cup === justVotedCup) row.classList.add('cf-row-flash');
            row.dataset.cup = r.cup;
            row.innerHTML =
                '<span class="cf-rank">#' + (idx + 1) + '</span>' +
                '<span class="cf-emoji">' + r.emoji + '</span>' +
                '<span class="cf-name">' + r.cup + ' Cup</span>' +
                '<span class="cf-elo">' + r.elo + '</span>' +
                '<span class="cf-votes">' + r.votes_total + ' vote' + (r.votes_total === 1 ? '' : 's') + '</span>' +
                '<span class="cf-winpct">' + (r.win_pct === null ? '—' : (r.win_pct + '%')) + '</span>';
            list.appendChild(row);
        });
    }

    pickABtn.addEventListener('click', () => castVote(pickABtn.dataset.cup, pickBBtn.dataset.cup));
    pickBBtn.addEventListener('click', () => castVote(pickBBtn.dataset.cup, pickABtn.dataset.cup));

    // Keyboard shortcuts: arrow keys (or 1/2) pick A/B
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.key === 'ArrowLeft' || e.key === '1') pickABtn.click();
        if (e.key === 'ArrowRight' || e.key === '2') pickBBtn.click();
    });
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
