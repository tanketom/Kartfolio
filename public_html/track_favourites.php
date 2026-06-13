<?php
/**
 * Track Favourites — head-to-head track voting + Elo ranking.
 *
 * Anyone can vote: pick the track you'd rather race. The system builds
 * an Elo-ranked preference list of all 96 tracks, useful as a seed for
 * which tracks to feature in upcoming tournaments.
 *
 * Path: /cdnmk/public_html/track_favourites.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/csrf.php';
require_once __DIR__ . '/../private/includes/track_ranking.php';

$voterId = trackPrefVoterId();
[$trackA, $trackB] = pickTrackPair($pdo, $voterId);
$rankings = trackRankings($pdo);
uasort($rankings, fn($a, $b) => $b['elo'] <=> $a['elo']);

$voterVotes  = trackPrefVoterVotes($pdo, $voterId);
$globalVotes = trackPrefTotalVotes($pdo);

$pageTitle = 'Track Favourites - Kartfolio';
$extraCss  = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <nav class="breadcrumb">
        <a href="/">← Home</a>
        <span class="breadcrumb-separator">/</span>
        <span class="breadcrumb-current">Track Favourites</span>
    </nav>

    <header class="page-header">
        <h1 class="page-title">🏁 Track Favourites</h1>
        <p class="page-subtitle">WHICH TRACK WOULD YOU RATHER RACE?</p>
    </header>

    <div class="tf-intro">
        <p>
            Pick your favourite of the two tracks below. Every vote is a head-to-head Elo update; over time the rankings settle into a global preference list we use to seed tournament track pools.
        </p>
        <p class="tf-counts">
            <strong id="tf-voter-count"><?= $voterVotes ?></strong> votes from you ·
            <strong id="tf-global-count"><?= $globalVotes ?></strong> total ·
            <strong>96</strong> tracks in the pool
        </p>
    </div>

    <section class="tf-vote-card" id="tf-vote-card" data-csrf="<?= htmlspecialchars(csrf_token()) ?>">
        <div class="tf-vs">
            <button class="tf-pick" id="tf-pick-a" data-track="<?= htmlspecialchars($trackA) ?>">
                <div class="tf-pick-visual">
                    <img class="tf-pick-img"   id="tf-pick-a-img"
                         src="/assets/img/tracks/<?= htmlspecialchars(getMKTrackImageSlug($trackA)) ?>.png"
                         alt="<?= htmlspecialchars($trackA) ?>"
                         onerror="this.classList.add('tf-pick-img--missing'); this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <div class="tf-pick-emoji" id="tf-pick-a-emoji" style="display:none;"><?= getMKTrackEmoji($trackA) ?></div>
                </div>
                <div class="tf-pick-name" id="tf-pick-a-name"><?= htmlspecialchars($trackA) ?></div>
                <div class="tf-pick-cup"  id="tf-pick-a-cup"><?= htmlspecialchars(getMKTrackCup($trackA) ?? '') ?> Cup</div>
            </button>
            <div class="tf-vs-label">vs</div>
            <button class="tf-pick" id="tf-pick-b" data-track="<?= htmlspecialchars($trackB) ?>">
                <div class="tf-pick-visual">
                    <img class="tf-pick-img"   id="tf-pick-b-img"
                         src="/assets/img/tracks/<?= htmlspecialchars(getMKTrackImageSlug($trackB)) ?>.png"
                         alt="<?= htmlspecialchars($trackB) ?>"
                         onerror="this.classList.add('tf-pick-img--missing'); this.style.display='none'; this.nextElementSibling.style.display='block';">
                    <div class="tf-pick-emoji" id="tf-pick-b-emoji" style="display:none;"><?= getMKTrackEmoji($trackB) ?></div>
                </div>
                <div class="tf-pick-name" id="tf-pick-b-name"><?= htmlspecialchars($trackB) ?></div>
                <div class="tf-pick-cup"  id="tf-pick-b-cup"><?= htmlspecialchars(getMKTrackCup($trackB) ?? '') ?> Cup</div>
            </button>
        </div>
        <div class="tf-shortcuts">← or 1 for the left · → or 2 for the right</div>
        <div class="tf-status" id="tf-status" hidden></div>
    </section>

    <section class="tf-rankings">
        <h2 class="tf-rankings-title">Global Ranking</h2>
        <p class="tf-rankings-sub">Elo from <span id="tf-rank-votes"><?= $globalVotes ?></span> votes. Tracks with no votes sit at the 1500 baseline.</p>
        <div class="tf-rankings-list" id="tf-rankings-list">
            <?php $rank = 1; foreach ($rankings as $track => $info):
                $slug = getMKTrackImageSlug($track);
                $emoji = getMKTrackEmoji($track);
            ?>
            <div class="tf-row" data-track="<?= htmlspecialchars($track) ?>">
                <span class="tf-rank">#<?= $rank ?></span>
                <span class="tf-thumb">
                    <img class="tf-thumb-img" src="/assets/img/tracks/<?= htmlspecialchars($slug) ?>.png"
                         alt="" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
                    <span class="tf-thumb-emoji" style="display:none;"><?= $emoji ?></span>
                </span>
                <span class="tf-name"><?= htmlspecialchars($track) ?></span>
                <span class="tf-cup-tag"><?= htmlspecialchars($info['cup'] ?? '') ?></span>
                <span class="tf-elo"><?= $info['elo'] ?></span>
                <span class="tf-votes"><?= $info['votes_total'] ?></span>
                <span class="tf-winpct"><?= $info['win_pct'] !== null ? $info['win_pct'] . '%' : '—' ?></span>
            </div>
            <?php $rank++; endforeach; ?>
        </div>
    </section>
</div>

<style>
.tf-intro { background:var(--gray-50); border:1px solid var(--gray-200); border-left:4px solid #FFD700; border-radius:8px; padding:16px 22px; margin-bottom:24px; color:var(--gray-600); }
.tf-intro p { margin:0 0 8px; line-height:1.5; }
.tf-intro p:last-child { margin:0; }
.tf-counts { font-size:0.9rem; color:#888; }
.tf-counts strong { color:#FFD700; }

.tf-vote-card { background:#fff6dc; border:1px solid #f0c040; border-radius:12px; padding:24px; margin-bottom:32px; box-shadow:0 6px 16px rgba(255,200,0,0.12); }
.tf-vs { display:flex; align-items:stretch; justify-content:center; gap:16px; }
.tf-pick { flex:1; max-width:320px; background:var(--gray-50); border:2px solid #e8c850; border-radius:14px; padding:24px 16px; cursor:pointer; transition:transform 0.12s ease, box-shadow 0.12s ease, border-color 0.12s ease; }
.tf-pick:hover { transform:translateY(-3px); border-color:var(--nintendo-red); box-shadow:0 8px 20px rgba(230,0,18,0.18); }
.tf-pick:focus-visible { outline:3px solid #0066cc; outline-offset:2px; }
.tf-pick:disabled { opacity:0.5; cursor:wait; }
.tf-pick-visual { width:100%; aspect-ratio:16/9; background:#f5f3eb; border-radius:8px; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:10px; }
.tf-pick-img { width:100%; height:100%; object-fit:cover; }
.tf-pick-emoji { font-size:3.5rem; line-height:1; }
.tf-pick-name { font-size:1.1rem; font-weight:900; color:var(--gray-800); line-height:1.2; }
.tf-pick-cup { font-size:0.72rem; color:#888; text-transform:uppercase; letter-spacing:1px; margin-top:6px; font-weight:700; }
.tf-vs-label { display:flex; align-items:center; font-size:1.4rem; font-weight:900; color:var(--nintendo-red); font-style:italic; }
.tf-shortcuts { margin-top:12px; text-align:center; font-size:0.75rem; color:#999; letter-spacing:0.5px; }
.tf-status { margin-top:8px; text-align:center; font-size:0.9rem; }

.tf-rankings { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:10px; padding:20px 24px; }
.tf-rankings-title { color:#fff; margin:0; font-size:1.3rem; }
.tf-rankings-sub { color:var(--gray-600); font-size:0.85rem; margin:4px 0 16px; font-style:italic; }
.tf-rankings-list { display:flex; flex-direction:column; gap:3px; }
.tf-row { display:grid; grid-template-columns:48px 28px 1fr 100px 70px 50px 50px; align-items:center; gap:10px; padding:6px 12px; background:var(--gray-200); border-radius:5px; border-left:3px solid transparent; transition:background 0.2s ease; font-size:0.88rem; }
.tf-row:nth-child(1) { border-left-color:#FFD700; background:#1a1408; }
.tf-row:nth-child(2) { border-left-color:#C0C0C0; }
.tf-row:nth-child(3) { border-left-color:#CD7F32; }
.tf-row.tf-row-flash { background:#fff6dc; }
.tf-rank { font-weight:900; color:#999; font-size:0.85rem; }
.tf-thumb { display:flex; align-items:center; justify-content:center; width:28px; height:28px; }
.tf-thumb-img { width:28px; height:28px; object-fit:cover; border-radius:4px; }
.tf-thumb-emoji { font-size:1.2rem; line-height:1; }
.tf-name { color:#fff; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.tf-cup-tag { color:#888; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; text-align:right; }
.tf-elo { color:#FFD700; font-weight:900; font-variant-numeric:tabular-nums; text-align:right; }
.tf-votes { color:#888; font-size:0.78rem; text-align:right; }
.tf-winpct { color:var(--gray-500); font-size:0.8rem; text-align:right; font-variant-numeric:tabular-nums; }
@media (max-width:700px) {
    .tf-vs { flex-direction:column; align-items:stretch; }
    .tf-vs-label { justify-content:center; }
    .tf-row { grid-template-columns:36px 24px 1fr 60px; font-size:0.82rem; }
    .tf-cup-tag, .tf-votes, .tf-winpct { display:none; }
}
</style>

<script>
(function () {
    const card        = document.getElementById('tf-vote-card');
    const pickABtn    = document.getElementById('tf-pick-a');
    const pickBBtn    = document.getElementById('tf-pick-b');
    const status      = document.getElementById('tf-status');
    const list        = document.getElementById('tf-rankings-list');
    const voterCount  = document.getElementById('tf-voter-count');
    const globalCount = document.getElementById('tf-global-count');
    const rankVotes   = document.getElementById('tf-rank-votes');

    function setButtons(disabled) {
        pickABtn.disabled = disabled;
        pickBBtn.disabled = disabled;
    }

    function renderPick(slot, payload) {
        const btn   = document.getElementById('tf-pick-' + slot);
        const img   = document.getElementById('tf-pick-' + slot + '-img');
        const emoji = document.getElementById('tf-pick-' + slot + '-emoji');
        const name  = document.getElementById('tf-pick-' + slot + '-name');
        const cup   = document.getElementById('tf-pick-' + slot + '-cup');
        btn.dataset.track = payload.name;
        // Reset image; let onerror flip to emoji if the file is missing.
        emoji.style.display = 'none';
        emoji.textContent   = payload.emoji;
        img.style.display   = '';
        img.alt             = payload.name;
        img.src             = '/assets/img/tracks/' + payload.slug + '.png';
        name.textContent    = payload.name;
        cup.textContent     = payload.cup ? payload.cup + ' Cup' : '';
    }

    function renderRankings(rankings, justVotedTrack) {
        list.innerHTML = '';
        rankings.forEach((r, idx) => {
            const row = document.createElement('div');
            row.className = 'tf-row';
            if (r.track === justVotedTrack) row.classList.add('tf-row-flash');
            row.dataset.track = r.track;
            row.innerHTML =
                '<span class="tf-rank">#' + (idx + 1) + '</span>' +
                '<span class="tf-thumb">' +
                    '<img class="tf-thumb-img" src="/assets/img/tracks/' + r.slug + '.png" alt="" ' +
                         'onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'inline\';">' +
                    '<span class="tf-thumb-emoji" style="display:none;">' + r.emoji + '</span>' +
                '</span>' +
                '<span class="tf-name">' + r.track + '</span>' +
                '<span class="tf-cup-tag">' + (r.cup || '') + '</span>' +
                '<span class="tf-elo">' + r.elo + '</span>' +
                '<span class="tf-votes">' + r.votes_total + '</span>' +
                '<span class="tf-winpct">' + (r.win_pct === null ? '—' : (r.win_pct + '%')) + '</span>';
            list.appendChild(row);
        });
    }

    async function castVote(winner, loser) {
        setButtons(true);
        status.hidden = false;
        status.style.color = '#888';
        status.textContent = 'Recording vote…';

        const body = new URLSearchParams({
            winner: winner,
            loser:  loser,
            csrf_token: card.dataset.csrf,
        });
        try {
            const res = await fetch('/api/track-preference-vote', {
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
            renderPick('a', data.next_pair[0]);
            renderPick('b', data.next_pair[1]);
            renderRankings(data.rankings, winner);
            voterCount.textContent  = data.voter_votes;
            globalCount.textContent = data.global_votes;
            rankVotes.textContent   = data.global_votes;
            status.style.color = '#2EBD59';
            status.textContent = 'Voted! ' + data.voter_votes + ' votes from you.';
            setButtons(false);
        } catch (e) {
            status.style.color = '#c0392b';
            status.textContent = 'Network error: ' + e.message;
            setButtons(false);
        }
    }

    pickABtn.addEventListener('click', () => castVote(pickABtn.dataset.track, pickBBtn.dataset.track));
    pickBBtn.addEventListener('click', () => castVote(pickBBtn.dataset.track, pickABtn.dataset.track));

    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.key === 'ArrowLeft' || e.key === '1') pickABtn.click();
        if (e.key === 'ArrowRight' || e.key === '2') pickBBtn.click();
    });
})();
</script>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
