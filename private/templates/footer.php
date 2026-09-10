<?php
// Get footer settings
global $pdo;
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$governingBodyFull = getSetting($pdo, 'governing_body_full', 'Organisation Mondial du Karting');
$governingBodyShort = getSetting($pdo, 'governing_body_short', 'OMK');
$footerAbout = getSetting($pdo, 'footer_about', 'The premier competitive Mario Kart 8 Deluxe league.');
?>
</div> <!-- /.main-content — close the centered container so the footer spans full width --> <footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <span class="logo-text"><?= htmlspecialchars($leagueName) ?></span>
            <p><?= nl2br(htmlspecialchars($footerAbout)) ?></p>
            <p style="margin-top: 10px; font-size: 0.8rem;">
                Powered by homemade <strong>GPScore™</strong> Logic & Google Gemini AI<br>
                Made with help from Claude and Gemini.
            </p>
        </div>

        <div class="footer-nav">
            <h4>Display Screens</h4>
            <ul>
                <li><a href="/vertical.php" target="_blank">🖥️ Lounge Screen  (Vertical)</a></li>
                <li><a href="/horizontal.php" target="_blank">📺 Game Room Screen (Horizontal)</a></li>
                <li><a href="/auto-vertical.php" target="_blank">🔄 Vertical Broadcast Auto-Rotator</a></li>
                <li><a href="/display/overlay" target="_blank">📡 OBS Stream Overlay</a></li>
            </ul>
        </div>

        <div class="footer-meta">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($leagueName) ?> Mario Kart League Engine</p>
            <p>Data stored via SQLite 3.</p>
            <p style="font-size: 0.85rem; color: #666; margin: 15px 0;">
                <strong>Official Statistician:</strong><br>
                <?= htmlspecialchars($governingBodyFull) ?> (<?= htmlspecialchars($governingBodyShort) ?>)
            </p>
            <p>Did you fuck something up? Contact a league admin.</p>
            <p style="margin-top: 10px;">
                <a href="/about" style="color: #888; text-decoration: none; font-size: 0.8rem; margin-right: 15px;">About</a>
                <a href="/login.php" style="color: #444; text-decoration: none; font-size: 0.7rem; opacity: 0.6;">Admin Access</a>
            </p>
        </div>
    </div>

    <!-- Hidden Underground Link -->
    <?php if (moduleEnabled($pdo, 'underground')): ?>
    <div style="text-align: center; margin-top: 20px; padding: 10px;">
        <a href="/underground" style="font-size: 0.65rem; color: #222; text-decoration: none; font-family: monospace; opacity: 0.2; transition: opacity 0.3s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.2'">
            ⚠️ unauthorized access
        </a>
    </div>
    <?php endif; ?>
</footer>

<style>
    .site-footer {
        margin-top: 60px;
        background: var(--surface-deep);
        color: #b8b8b8;
        padding: 44px 0;
        border-top: 5px solid var(--nintendo-red);   /* full-width red finish line */
    }
    .footer-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr;
        gap: 40px;
    }
    .footer-brand .logo-text { color: white; font-family: var(--font-display); font-size: 1.6rem; font-weight: 700; letter-spacing: -0.01em; }
    .footer-brand p { margin-top: 10px; font-size: 0.85rem; line-height: 1.6; }
    
    .footer-nav h4 { color: white; font-size: 0.9rem; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; }
    .footer-nav ul { list-style: none; padding: 0; margin: 0; }
    .footer-nav ul li { margin-bottom: 8px; }
    .footer-nav a { color: #aaa; text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
    .footer-nav a:hover { color: var(--nintendo-red); }
    
    .footer-meta { text-align: right; font-size: 0.75rem; }

    @media (max-width: 768px) {
        .footer-grid { grid-template-columns: 1fr; text-align: center; }
        .footer-meta { text-align: center; }
    }
</style>

<!-- Cup Picker Overlay -->
<div id="cup-picker-overlay" class="cup-overlay">
    <div class="cup-wheel-container">
        <div class="cup-wheel-title">WHAT CUP?</div>

        <!-- Racer Selection Panel -->
        <div class="cup-racer-panel" id="cup-racer-panel">
            <div class="cup-racer-label">Who's playing?</div>
            <div class="cup-racer-chips" id="cup-racer-chips"></div>
            <div class="cup-racer-hint">Select 2-<?= MK_MAX_HUMAN_PLAYERS ?> racers to find a cup most haven't done</div>
        </div>

        <!-- Kartificial IS the wheel. He hosts the World Cup already, so the
             cup draw gets the same mascot rather than a red ring and a slot
             emoji: he is on screen from the moment the modal opens, nagging
             you to pick, riffling through cup names while he "thinks", and
             announcing the draw. His lines are built server-side from the
             draw's own facts — no model call, so nothing can stall. -->
        <div class="cup-stage" id="cup-wheel">
            <img src="/assets/img/kartificial.png" class="cup-stage-img" alt="Kartificial" onerror="this.style.visibility='hidden'">
            <div class="cup-host-bubble" id="cup-host-line"></div>
        </div>
        <div class="cup-wheel-result" id="cup-result"></div>
        <div class="cup-wheel-stats" id="cup-stats"></div>
        <div class="cup-context" id="cup-context"></div>
        <div class="cup-mh-info" id="cup-mh-info"></div>
        <div class="cup-racer-details" id="cup-racer-details"></div>
        <div class="cup-buttons">
            <button class="cup-pick-btn" id="cup-pick-btn">PICK!</button>
            <button class="cup-veto-btn" id="cup-veto-btn" hidden>🚫 Not that one</button>
            <button class="cup-log-btn" id="cup-log-btn" hidden>📝 Log this GP</button>
            <button class="cup-close-btn" id="cup-close-btn">Close</button>
        </div>
    </div>
</div>

<style>
.cup-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.cup-overlay.active {
    display: flex;
}

.cup-wheel-container {
    background: white;
    border-radius: 20px;
    padding: 50px;
    text-align: center;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(230, 0, 18, 0.3);
    position: relative;
}

.cup-wheel-title {
    font-size: 2.5rem;
    font-weight: 900;
    font-style: italic;
    color: var(--nintendo-red);
    margin-bottom: 30px;
    text-transform: uppercase;
    letter-spacing: 2px;
}

/* The stage replaces the old 300px red ring: portrait left, speech bubble
   right, both present from the moment the modal opens. */
.cup-stage {
    display: flex;
    align-items: center;
    gap: 14px;
    text-align: left;
    margin: 0 0 18px;
}
.cup-stage-img {
    width: 104px;
    height: 104px;
    flex: 0 0 auto;
    object-fit: contain;
}
.cup-stage.spinning .cup-stage-img {
    animation: kartificialThink 0.55s ease-in-out infinite;
}

/* He rocks while he thinks — the wheel's job, minus the wheel. */
@keyframes kartificialThink {
    0%, 100% { transform: translateY(0) rotate(-4deg); }
    50%      { transform: translateY(-9px) rotate(4deg); }
}

.cup-wheel-result {
    /* No longer boxed inside a 300px circle, so it can run full width — but
       "Golden Dash Cup" at the old 4rem overflowed the modal. */
    font-size: 2.6rem;
    font-weight: 900;
    color: #333;
    text-transform: uppercase;
    letter-spacing: -1px;
    line-height: 1.05;
    min-height: 2.7rem;
    margin-bottom: 10px;
}
.cup-wheel-result:empty { min-height: 0; }
/* The names he riffles through before settling on one. */
.cup-wheel-result.riffling { color: #bbb; }

.cup-wheel-result.revealed {
    animation: popIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes popIn {
    0% { transform: scale(0); opacity: 0; }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); opacity: 1; }
}

.cup-wheel-stats {
    font-size: 0.9rem;
    color: #666;
    margin-bottom: 8px;
    min-height: 24px;
}

/* "The number to beat", and on Territory seasons whose cup it is. */
.cup-context {
    font-size: 0.92rem;
    color: #444;
    margin-bottom: 18px;
    line-height: 1.5;
}
.cup-context:empty { margin-bottom: 0; }
.cup-context b { font-weight: 800; }
.cup-context .cup-ctx-holder { color: #b8860b; }

/* The host: portrait left, speech bubble right. */
.cup-host-bubble {
    position: relative;
    flex: 1;
    background: #f4f4f2;
    border: 3px solid var(--ink, #111);
    border-radius: 16px;
    padding: 10px 14px;
    font-size: 0.92rem;
    line-height: 1.4;
    font-weight: 600;
}
.cup-host-bubble::before {          /* the tail, pointing at Kartificial */
    content: '';
    position: absolute;
    left: -10px; top: 50%;
    width: 0; height: 0;
    transform: translateY(-50%);
    border: 8px solid transparent;
    border-right-color: var(--ink, #111);
}
.cup-stage--grumpy .cup-host-bubble { background: #fdf0e8; }
.cup-stage--excited .cup-host-bubble { background: #fff6dc; }

/* Racer Selection Panel */
.cup-racer-panel {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid #eee;
}

.cup-racer-label {
    font-size: 0.8rem;
    font-weight: 900;
    text-transform: uppercase;
    color: #888;
    letter-spacing: 1px;
    margin-bottom: 10px;
}

.cup-racer-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: center;
    max-height: 120px;
    overflow-y: auto;
    padding: 4px 0;
}

.cup-racer-chip {
    padding: 6px 14px;
    border-radius: 20px;
    border: 2px solid #ddd;
    background: #f9f9f9;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    user-select: none;
    color: #555;
}

.cup-racer-chip:hover {
    border-color: #aaa;
    background: #f0f0f0;
}

.cup-racer-chip.selected {
    border-color: var(--nintendo-red);
    background: var(--nintendo-red);
    color: white;
}

.cup-racer-chip.selected:hover {
    background: #c70010;
    border-color: #c70010;
}

.cup-racer-hint {
    font-size: 0.72rem;
    color: #aaa;
    margin-top: 8px;
    font-style: italic;
}

/* MONSTER HUNT Role Panel */
.cup-mh-info {
    margin-bottom: 16px;
    min-height: 0;
}

.cup-mh-panel {
    background: linear-gradient(135deg, #1a0a00 0%, #2d0a00 100%);
    border: 2px solid #8B0000;
    border-radius: 12px;
    padding: 14px 18px;
    text-align: left;
    animation: mhFadeIn 0.4s ease;
}

@keyframes mhFadeIn {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.cup-mh-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.88rem;
    font-weight: 700;
    padding: 3px 0;
}

.cup-mh-label {
    font-size: 0.7rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: #aaa;
    margin-bottom: 8px;
}

.cup-mh-monster-row {
    color: #ff6666;
    font-size: 1rem;
    font-weight: 900;
    padding-bottom: 8px;
    border-bottom: 1px solid #5a1a1a;
    margin-bottom: 6px;
}

.cup-mh-monster-name {
    color: #ff4444;
}

.cup-mh-cr-badge {
    display: inline-block;
    font-size: 0.62rem;
    font-weight: 900;
    padding: 2px 5px;
    border-radius: 3px;
    color: #fff;
    vertical-align: middle;
    letter-spacing: 0.5px;
    margin-left: 4px;
}

.cup-mh-epithet {
    color: #ffaa88;
    font-size: 0.78rem;
    font-style: italic;
    font-weight: 600;
    margin-left: 3px;
}

.cup-mh-elo {
    font-size: 0.72rem;
    font-weight: 700;
    color: #888;
    margin-left: auto;
    font-family: monospace;
}

.cup-mh-adventurer-row {
    color: #a0c8ff;
    font-size: 0.82rem;
    padding: 2px 0;
}

/* Racer Details Under Result */
.cup-racer-details {
    margin-bottom: 16px;
    min-height: 0;
}

.cup-racer-detail-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    padding: 3px 0;
}

.cup-racer-detail-row .cup-rd-name {
    color: #333;
}

.cup-racer-detail-row .cup-rd-new {
    color: #2ebd59;
    font-weight: 800;
}

.cup-racer-detail-row .cup-rd-done {
    color: #999;
}

/* Buttons Row */
.cup-buttons {
    display: flex;
    /* Four buttons no longer fit one row in a 500px modal — the veto's label
       was wrapping to three lines — so they wrap as pairs instead. */
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    position: sticky;
    bottom: -50px; /* offset to sit flush with container padding */
    background: white;
    padding-top: 12px;
    margin-top: 20px;
    z-index: 2;
}

.cup-pick-btn {
    background: var(--nintendo-red);
    color: white;
    border: none;
    padding: 15px 40px;
    border-radius: 30px;
    font-size: 1.1rem;
    font-weight: 900;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    letter-spacing: 1px;
}

.cup-pick-btn:hover {
    background: #c70010;
    transform: scale(1.05);
}

.cup-pick-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
}

.cup-close-btn {
    background: #eee;
    color: #555;
    border: none;
    padding: 15px 30px;
    border-radius: 30px;
    font-size: 1rem;
    font-weight: 800;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
}

.cup-close-btn:hover {
    background: #ddd;
    transform: scale(1.05);
}

.cup-log-btn {
    background: #2EBD59;
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 30px;
    font-size: 1rem;
    font-weight: 900;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(46, 189, 89, 0.3);
}

.cup-log-btn:hover {
    background: #28a14b;
    transform: scale(1.05);
}

.cup-log-btn[hidden] { display: none !important; }

.cup-veto-btn {
    background: #fff;
    color: #444;
    border: 3px solid #ddd;
    padding: 13px 22px;
    border-radius: 30px;
    white-space: nowrap;
    font-size: 0.9rem;
    font-weight: 800;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
}
.cup-veto-btn:hover { border-color: var(--nintendo-red, #e60012); color: var(--nintendo-red, #e60012); }
.cup-veto-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.cup-veto-btn[hidden] { display: none !important; }

@media (max-width: 480px) {
    .cup-wheel-container { padding: 30px 20px; }
    .cup-racer-chips { max-height: 100px; }
    .cup-racer-chip { padding: 5px 10px; font-size: 0.72rem; }
    .cup-buttons { flex-direction: column; }
    .cup-pick-btn, .cup-close-btn, .cup-log-btn, .cup-veto-btn { width: 100%; }
    .cup-stage-img { width: 76px; height: 76px; }
    .cup-wheel-result { font-size: 2rem; }
}

</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ========================================================================
    // UNIVERSAL TOOLTIP SYSTEM
    // Fixed-position JS tooltip that works for ALL [data-tooltip] elements,
    // including badge-items (escapes overflow clipping) and touch screens.
    // ========================================================================

    const tip = document.createElement('div');
    tip.id = 'ui-tooltip';
    tip.style.cssText = [
        'position:fixed',
        'z-index:99999',
        'background:rgba(0,0,0,0.92)',
        'color:white',
        'padding:8px 12px',
        'border-radius:8px',
        'font-size:0.82rem',
        'font-weight:600',
        'max-width:220px',
        'white-space:normal',
        'text-align:center',
        'pointer-events:none',
        'box-shadow:0 4px 14px rgba(0,0,0,0.35)',
        'opacity:0',
        'transition:opacity 0.15s',
        'line-height:1.4',
        'display:none'
    ].join(';');
    document.body.appendChild(tip);

    let tipHideTimer = null;

    function showTip(el) {
        const text = el.dataset.tooltip;
        if (!text) return;
        clearTimeout(tipHideTimer);
        tip.textContent = text;
        tip.style.display = 'block';
        tip.style.opacity = '0';
        // Position after display so offsetHeight is accurate
        requestAnimationFrame(function() {
            const rect = el.getBoundingClientRect();
            const tipW = tip.offsetWidth;
            const tipH = tip.offsetHeight;
            let left = rect.left + rect.width / 2 - tipW / 2;
            left = Math.max(8, Math.min(left, window.innerWidth - tipW - 8));
            const topAbove = rect.top - tipH - 8;
            const topBelow = rect.bottom + 8;
            tip.style.left = left + 'px';
            tip.style.top = (topAbove < 8 ? topBelow : topAbove) + 'px';
            tip.style.opacity = '1';
        });
    }

    function hideTip() {
        tip.style.opacity = '0';
        tipHideTimer = setTimeout(function() { tip.style.display = 'none'; }, 150);
    }

    // Mouse: show on enter, hide on leave
    document.addEventListener('mouseover', function(e) {
        const el = e.target.closest('[data-tooltip]');
        if (el) showTip(el);
    });

    document.addEventListener('mouseout', function(e) {
        if (e.target.closest('[data-tooltip]')) hideTip();
    });

    // Touch: tap to show, tap elsewhere to hide
    document.addEventListener('touchstart', function(e) {
        const el = e.target.closest('[data-tooltip]');
        if (el) {
            // If already visible for this element, hide it (toggle)
            if (tip.style.opacity === '1' && tip.textContent === el.dataset.tooltip) {
                hideTip();
            } else {
                showTip(el);
                e.preventDefault(); // prevent ghost click on touch
            }
        } else {
            hideTip();
        }
    }, { passive: false });

    // ========================================================================
    // THE ROTATING BOX
    // ------------------------------------------------------------------------
    // Cards come from /api/front-box, fetched after the page has rendered so
    // the homepage's own render never waits on the Elo engine. Fantasy pins
    // itself to the front and stops the rotation in the last 24 hours before
    // the deadline — that is the one card that expires.
    // ========================================================================
    const fbBox = document.getElementById('frontbox');
    if (fbBox) {
        const fbCard     = document.getElementById('frontbox-card');
        const fbIcon     = document.getElementById('frontbox-icon');
        const fbKicker   = document.getElementById('frontbox-kicker');
        const fbHeadline = document.getElementById('frontbox-headline');
        const fbLine     = document.getElementById('frontbox-line');
        const fbDots     = document.getElementById('frontbox-dots');

        let fbCards = [], fbAt = 0, fbTimer = null, fbPinned = false;

        function fbRender(i) {
            const c = fbCards[i];
            if (!c) return;
            fbAt = i;
            // textContent throughout: these lines carry racer names and
            // hand-written lexicon definitions straight out of the database.
            fbIcon.textContent     = c.icon || '';
            fbKicker.textContent   = c.kicker || '';
            fbHeadline.textContent = c.headline || '';
            fbLine.textContent     = c.line || '';
            fbCard.setAttribute('href', c.href || '#');
            fbBox.classList.toggle('frontbox--urgent', !!c.urgent);
            fbDots.querySelectorAll('.frontbox-dot').forEach((d, n) =>
                d.setAttribute('aria-current', n === i ? 'true' : 'false'));
        }

        function fbAdvance() { fbRender((fbAt + 1) % fbCards.length); }

        function fbStart() {
            if (fbTimer) clearInterval(fbTimer);
            if (fbPinned || fbCards.length < 2) return;
            fbTimer = setInterval(fbAdvance, 9000);
        }

        fetch('/api/front-box')
            .then(r => r.json())
            .then(data => {
                fbCards = Array.isArray(data.cards) ? data.cards : [];
                if (!fbCards.length) return;          // nothing true to say: stay hidden

                // A fantasy card inside its last 24 hours holds the box.
                fbPinned = !!fbCards[0].urgent;
                if (fbPinned) fbCards = [fbCards[0]];

                fbCards.forEach((c, i) => {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'frontbox-dot';
                    dot.setAttribute('aria-label', c.kicker || ('Card ' + (i + 1)));
                    dot.addEventListener('click', e => {
                        e.preventDefault();
                        fbRender(i);
                        fbStart();                     // a click restarts the clock
                    });
                    fbDots.appendChild(dot);
                });
                fbDots.hidden = fbCards.length < 2;

                fbRender(0);
                fbBox.hidden = false;
                fbStart();

                // Don't rotate under someone who is reading, or in a tab
                // nobody is looking at.
                fbBox.addEventListener('mouseenter', () => { if (fbTimer) clearInterval(fbTimer); });
                fbBox.addEventListener('mouseleave', fbStart);
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) { if (fbTimer) clearInterval(fbTimer); } else fbStart();
                });
            })
            .catch(() => { /* the box simply never appears */ });
    }

    // Cup Picker Functionality
    const cupPickerBtn = document.getElementById('cup-picker-btn');
    const cupOverlay = document.getElementById('cup-picker-overlay');
    const cupWheel = document.getElementById('cup-wheel');
    const cupResult = document.getElementById('cup-result');
    const cupStats = document.getElementById('cup-stats');
    const cupCloseBtn = document.getElementById('cup-close-btn');
    const cupPickBtn = document.getElementById('cup-pick-btn');
    const cupLogBtn = document.getElementById('cup-log-btn');
    const cupRacerChips = document.getElementById('cup-racer-chips');
    const cupRacerDetails = document.getElementById('cup-racer-details');
    const cupMhInfo = document.getElementById('cup-mh-info');
    const cupVetoBtn = document.getElementById('cup-veto-btn');
    const cupContext = document.getElementById('cup-context');
    const cupHostLine = document.getElementById('cup-host-line');

    // What got picked most recently — used by the "Log this GP" button.
    let cupLastPicked = null; // { cup, racerIds: [...], monsterId: number|null }
    // The drawn cup itself, set on every draw — the veto needs it even when
    // there are too few racers for cupLastPicked to be filled in.
    let cupLastCup = null;

    let cupRacersLoaded = false;
    let cupSelectedRacers = new Set();
    // Cups turned down this time the modal was opened. The server excludes
    // them, so "Not that one" can never hand back the cup you just rejected.
    let cupVetoed = new Set();
    let cupAllCups = [];        // names to riffle through while he "thinks"
    let cupLastGpRacers = [];   // who raced the most recent GP
    let cupPreselected = false; // whether this open's chips came from that GP
    let cupRiffle = null;       // the riffle interval handle

    const CUP_MAX = <?= MK_MAX_HUMAN_PLAYERS ?>;

    function cupSetMood(mood) {
        cupWheel.classList.remove('cup-stage--neutral', 'cup-stage--grumpy', 'cup-stage--excited');
        cupWheel.classList.add('cup-stage--' + (mood || 'neutral'));
    }

    // What Kartificial says before there is anything to say about a draw. He
    // is on screen the whole time, so he may as well be useful about it.
    function cupIdleLine() {
        const n = cupSelectedRacers.size;
        if (n === 0) return 'Right. Who is playing? Tap the names, then I will choose a cup.';
        if (n === 1) return 'One racer is not a Grand Prix. Add at least one more.';
        if (cupPreselected) return 'Same line-up as the last GP. Hit PICK and I will choose.';
        return n + ' racers. Hit PICK and I will choose.';
    }

    function cupShowIdle() {
        cupHostLine.textContent = cupIdleLine();
        cupSetMood('neutral');
    }

    function cupStopRiffle() {
        if (cupRiffle) { clearInterval(cupRiffle); cupRiffle = null; }
        cupResult.classList.remove('riffling');
    }

    // Preselect the racers from the most recent GP — a game night should not
    // have to retype its own line-up. Never clobbers a manual selection.
    function cupPreselectLastGp() {
        cupPreselected = false;
        if (cupSelectedRacers.size > 0 || !cupLastGpRacers.length) return;
        cupLastGpRacers.slice(0, CUP_MAX).forEach(function (id) {
            const chip = cupRacerChips.querySelector('[data-id="' + id + '"]');
            if (chip && !chip.classList.contains('selected')) toggleRacerChip(chip, Number(id));
        });
        cupPreselected = cupSelectedRacers.size > 0;
    }

    if (cupPickerBtn) {
        cupPickerBtn.addEventListener('click', function(e) {
            e.preventDefault();
            openCupPicker();
        });
    }

    if (cupCloseBtn) {
        cupCloseBtn.addEventListener('click', function() {
            cupOverlay.classList.remove('active');
        });
    }

    if (cupPickBtn) {
        cupPickBtn.addEventListener('click', function() {
            doCupPick();
        });
    }

    if (cupVetoBtn) {
        cupVetoBtn.addEventListener('click', function() {
            if (cupLastCup) cupVetoed.add(cupLastCup);
            doCupPick();
        });
    }

    if (cupLogBtn) {
        cupLogBtn.addEventListener('click', function() {
            if (!cupLastPicked) return;
            // Order racers so the monster (if any) lands in slot 1.
            // The form has MK_MAX_HUMAN_PLAYERS racer rows; we fill 1..N in order.
            const ids = cupLastPicked.racerIds.slice();
            if (cupLastPicked.monsterId !== null) {
                const i = ids.indexOf(cupLastPicked.monsterId);
                if (i > 0) { ids.splice(i, 1); ids.unshift(cupLastPicked.monsterId); }
            }
            const params = new URLSearchParams();
            params.set('cup', cupLastPicked.cup);
            ids.slice(0, <?= MK_MAX_HUMAN_PLAYERS ?>).forEach((id, i) => params.set('r' + (i + 1), id));
            if (cupLastPicked.monsterId !== null) {
                params.set('monster', cupLastPicked.monsterId);
            }
            window.location.href = '/add-result?' + params.toString();
        });
    }

    cupOverlay.addEventListener('click', function(e) {
        if (e.target === cupOverlay) {
            cupOverlay.classList.remove('active');
        }
    });

    // Esc closes, Space/Enter spins. Skipped while a button or field has
    // focus, so tabbing to a racer chip and pressing Space still toggles it
    // rather than rolling the wheel.
    document.addEventListener('keydown', function(e) {
        if (!cupOverlay.classList.contains('active')) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            cupOverlay.classList.remove('active');
            return;
        }
        if (e.key !== ' ' && e.key !== 'Enter') return;
        const t = e.target;
        if (t && (t.tagName === 'BUTTON' || t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
        if (cupPickBtn.disabled) return;
        e.preventDefault();
        doCupPick();
    });

    function openCupPicker() {
        cupOverlay.classList.add('active');
        cupStopRiffle();
        cupResult.textContent = '';
        cupStats.textContent = '';
        cupContext.innerHTML = '';
        cupRacerDetails.innerHTML = '';
        cupMhInfo.innerHTML = '';
        cupVetoBtn.hidden = true;
        cupVetoed.clear();          // a fresh modal is a fresh set of vetoes
        cupLastCup = null;
        cupWheel.classList.remove('spinning');
        cupResult.classList.remove('revealed');
        cupPickBtn.disabled = false;
        // Hide the "Log this GP" button until a fresh pick is made.
        cupLogBtn.hidden = true;
        cupLastPicked = null;

        // Load the roster, the cup list and the last GP's line-up once
        if (!cupRacersLoaded) {
            cupHostLine.textContent = 'One moment, fetching the roster.';
            fetch('/pick-cup?list-racers')
                .then(r => r.json())
                .then(data => {
                    cupRacerChips.innerHTML = '';
                    data.racers.forEach(racer => {
                        const chip = document.createElement('button');
                        chip.className = 'cup-racer-chip';
                        chip.textContent = racer.name;
                        chip.dataset.id = racer.id;
                        chip.addEventListener('click', function() {
                            toggleRacerChip(this, racer.id);
                        });
                        cupRacerChips.appendChild(chip);
                    });
                    cupAllCups = data.allCups || [];
                    cupLastGpRacers = (data.lastGp && data.lastGp.racers) || [];
                    cupRacersLoaded = true;
                    cupPreselectLastGp();
                    cupShowIdle();
                })
                .catch(() => { cupHostLine.textContent = 'I cannot reach the roster. Try again?'; });
        } else {
            cupPreselectLastGp();
            cupShowIdle();
        }
    }

    function toggleRacerChip(chip, racerId) {
        if (cupSelectedRacers.has(racerId)) {
            cupSelectedRacers.delete(racerId);
            chip.classList.remove('selected');
        } else {
            if (cupSelectedRacers.size >= <?= MK_MAX_HUMAN_PLAYERS ?>) return; // Max MK_MAX_HUMAN_PLAYERS
            cupSelectedRacers.add(racerId);
            chip.classList.add('selected');
        }
        // Any manual change means the line-up is no longer "last GP's".
        if (cupPreselected) {
            const same = cupSelectedRacers.size === Math.min(cupLastGpRacers.length, CUP_MAX)
                         && cupLastGpRacers.slice(0, CUP_MAX).every(id => cupSelectedRacers.has(Number(id)));
            if (!same) cupPreselected = false;
        }
        // Only re-nag while no draw is on screen, so a result is not wiped.
        if (!cupLastCup) cupShowIdle();

        // Update hint text
        const hint = document.querySelector('.cup-racer-hint');
        if (hint) {
            const count = cupSelectedRacers.size;
            if (count === 0) {
                hint.textContent = 'Select 2\u2013<?= MK_MAX_HUMAN_PLAYERS ?> racers to find a cup most haven\u2019t done';
            } else if (count < 2) {
                hint.textContent = 'Select at least 1 more racer to roll a cup';
            } else if (count < 3) {
                hint.textContent = count + ' racer' + (count !== 1 ? 's' : '') + ' \u2014 ready to roll a cup. Add 1 more to also log the GP.';
            } else {
                hint.textContent = count + ' racers \u2014 ready to roll and log!';
            }
        }
    }

    function doCupPick() {
        cupPickBtn.disabled = true;
        cupVetoBtn.disabled = true;
        cupStats.textContent = '';
        cupContext.innerHTML = '';
        cupRacerDetails.innerHTML = '';
        cupMhInfo.innerHTML = '';
        cupResult.classList.remove('revealed');
        cupHostLine.textContent = cupVetoed.size ? 'Fine. Looking again…' : 'Let me have a look…';
        cupSetMood('neutral');

        // The slot-machine feel, without the slot machine: names flick past
        // while he rocks, then one of them stays.
        cupStopRiffle();
        if (cupAllCups.length) {
            cupResult.classList.add('riffling');
            cupRiffle = setInterval(function () {
                cupResult.textContent = cupAllCups[Math.floor(Math.random() * cupAllCups.length)] + ' Cup';
            }, 90);
        }
        // Reset the log button until the fresh pick settles.
        cupLogBtn.hidden = true;
        cupLastPicked = null;

        // Build URL with the selected racers and anything vetoed so far
        const cupParams = new URLSearchParams();
        if (cupSelectedRacers.size > 0) cupParams.set('racers', Array.from(cupSelectedRacers).join(','));
        if (cupVetoed.size > 0) cupParams.set('exclude', Array.from(cupVetoed).join(','));
        const url = '/pick-cup' + (cupParams.toString() ? '?' + cupParams.toString() : '');

        // Start spinning
        cupWheel.classList.add('spinning');

        fetch(url)
            .then(r => r.json())
            .then(data => {
                // Wait for spin animation
                setTimeout(() => {
                    cupWheel.classList.remove('spinning');
                    cupStopRiffle();
                    cupResult.textContent = data.cup + ' Cup';
                    cupResult.classList.add('revealed');
                    cupStats.textContent = 'Raced ' + data.seasonRaceCount + ' time' + (data.seasonRaceCount !== 1 ? 's' : '') + ' this season';
                    cupPickBtn.disabled = false;
                    cupLastCup = data.cup;

                    // Kartificial's take. textContent, not innerHTML — the
                    // line carries racer names straight out of the database.
                    if (data.host && data.host.line) {
                        cupHostLine.textContent = data.host.line;
                        cupSetMood(data.host.mood);
                    }
                    if (data.allCups) cupAllCups = data.allCups;

                    // The number to beat, and on Territory seasons the holder.
                    const ctx = [];
                    // On a Territory season the holder IS the best score in
                    // that cup, so printing both says the same number twice.
                    const dupHolder = data.territory && data.bestThisSeason
                                      && data.territory.points === data.bestThisSeason.score;
                    if (data.bestThisSeason && !dupHolder) {
                        ctx.push(document.createTextNode('🎯 Number to beat: '));
                        const b = document.createElement('b');
                        b.textContent = data.bestThisSeason.score + ' pts (' + data.bestThisSeason.name + ')';
                        ctx.push(b);
                    }
                    if (data.territory) {
                        if (ctx.length) ctx.push(document.createElement('br'));
                        ctx.push(document.createTextNode('🚩 Held by '));
                        const h = document.createElement('b');
                        h.className = 'cup-ctx-holder';
                        h.textContent = data.territory.holder + ' — ' + data.territory.points + ' pts';
                        ctx.push(h);
                    }
                    if (data.excludedToday && data.excludedToday.length && !data.relaxed) {
                        if (ctx.length) ctx.push(document.createElement('br'));
                        ctx.push(document.createTextNode(
                            '⏭️ Skipping ' + data.excludedToday.length + ' cup' +
                            (data.excludedToday.length !== 1 ? 's' : '') + ' already raced today'));
                    }
                    cupContext.innerHTML = '';
                    ctx.forEach(n => cupContext.appendChild(n));

                    // Veto is only meaningful while something else can be drawn.
                    cupVetoBtn.hidden = false;
                    cupVetoBtn.disabled = (cupVetoed.size + 1) >= data.allCups.length;

                    // Show MONSTER HUNT roles if applicable
                    if (data.is_monster_hunt && data.monster) {
                        let mhHtml = '<div class="cup-mh-panel">';
                        mhHtml += '<div class="cup-mh-label">⚔️ MONSTER HUNT — Role Assignment</div>';
                        const crBgColors = { 1: '#8a6d00', 2: '#8a4000', 3: '#7a1a1a', 4: '#4a0010' };
                        const crTier = data.monster.cr_tier || 1;
                        mhHtml += '<div class="cup-mh-row cup-mh-monster-row">';
                        mhHtml += '👹 <span class="cup-mh-monster-name">' + data.monster.name + '</span>';
                        if (data.monster.cr_tier) {
                            mhHtml += '<span class="cup-mh-cr-badge" style="background:' + (crBgColors[crTier] || '#555') + '">CR' + crTier + '</span>';
                            mhHtml += '<em class="cup-mh-epithet">' + data.monster.cr_epithet + '</em>';
                        }
                        mhHtml += '<span class="cup-mh-elo">ELO ' + data.monster.elo + '</span>';
                        mhHtml += '</div>';
                        if (data.adventurers && data.adventurers.length > 0) {
                            data.adventurers.forEach(adv => {
                                mhHtml += '<div class="cup-mh-row cup-mh-adventurer-row">';
                                mhHtml += '🗡️ ' + adv.name;
                                mhHtml += '<span class="cup-mh-elo">ELO ' + adv.elo + '</span>';
                                mhHtml += '</div>';
                            });
                        }
                        mhHtml += '</div>';
                        cupMhInfo.innerHTML = mhHtml;
                    }

                    // Show per-racer details if available
                    if (data.racerDetails && data.racerDetails.length > 0) {
                        let html = '';
                        data.racerDetails.forEach(rd => {
                            if (rd.hasDone) {
                                html += '<div class="cup-racer-detail-row"><span class="cup-rd-name">' + rd.name + '</span> <span class="cup-rd-done">done (best: ' + rd.bestScore + 'pts)</span></div>';
                            } else {
                                html += '<div class="cup-racer-detail-row"><span class="cup-rd-name">' + rd.name + '</span> <span class="cup-rd-new">\u2728 NEW</span></div>';
                            }
                        });
                        cupRacerDetails.innerHTML = html;
                    }

                    // Remember what got picked, then reveal the "Log this GP"
                    // shortcut. Logging requires at least 3 racers \u2014 the
                    // system rejects GPs with fewer entries \u2014 so the button
                    // only shows up when that threshold is met.
                    if (cupSelectedRacers.size >= 3) {
                        cupLastPicked = {
                            cup:       data.cup,
                            racerIds:  Array.from(cupSelectedRacers),
                            monsterId: (data.is_monster_hunt && data.monster) ? data.monster.id : null,
                        };
                        cupLogBtn.hidden = false;
                    }
                }, 3000);
            })
            .catch(error => {
                console.error('Error picking cup:', error);
                cupWheel.classList.remove('spinning');
                cupStopRiffle();
                cupResult.textContent = '';
                cupHostLine.textContent = 'Something went wrong down here. Try again?';
                cupSetMood('grumpy');
                cupPickBtn.disabled = false;
                cupVetoBtn.disabled = false;
            });
    }

    // ========================================================================
    // CONFIRMATION MODAL SYSTEM (Admin Pages Only)
    // ========================================================================

    // Initialize modal when admin is logged in (needed on archive, view-recap, etc.)
    const isAdmin = <?= (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ? 'true' : 'false' ?>;

    if (isAdmin && !document.getElementById('confirm-modal')) {
        const modalHTML = `
            <div id="confirm-modal" class="modal-overlay" style="display: none;">
                <div class="modal-container">
                    <div class="modal-icon" id="modal-icon">⚠️</div>
                    <h2 class="modal-title" id="modal-title">Confirm Action</h2>
                    <p class="modal-message" id="modal-message">Are you sure?</p>
                    <div class="modal-actions">
                        <button class="btn btn-secondary" id="modal-cancel">Cancel</button>
                        <button class="btn btn-danger" id="modal-confirm">Confirm</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const confirmModal = document.getElementById('confirm-modal');
        const modalIcon = document.getElementById('modal-icon');
        const modalTitle = document.getElementById('modal-title');
        const modalMessage = document.getElementById('modal-message');
        const modalCancel = document.getElementById('modal-cancel');
        const modalConfirm = document.getElementById('modal-confirm');

        // Show confirmation modal
        window.showConfirm = function(options) {
            return new Promise((resolve) => {
                modalIcon.textContent = options.icon || '⚠️';
                modalTitle.textContent = options.title || 'Confirm Action';
                modalMessage.textContent = options.message || 'Are you sure?';

                confirmModal.style.display = 'flex';
                confirmModal.classList.add('active');

                const handleConfirm = () => {
                    cleanup();
                    resolve(true);
                };

                const handleCancel = () => {
                    cleanup();
                    resolve(false);
                };

                const cleanup = () => {
                    confirmModal.classList.remove('active');
                    confirmModal.style.display = 'none';
                    modalConfirm.removeEventListener('click', handleConfirm);
                    modalCancel.removeEventListener('click', handleCancel);
                    confirmModal.removeEventListener('click', handleOverlayClick);
                };

                const handleOverlayClick = (e) => {
                    if (e.target === confirmModal) {
                        handleCancel();
                    }
                };

                modalConfirm.addEventListener('click', handleConfirm);
                modalCancel.addEventListener('click', handleCancel);
                confirmModal.addEventListener('click', handleOverlayClick);
            });
        };
    }

});
</script>

<!-- Secret Easter Egg -->
<script src="<?= assetUrl('/assets/js/easter_egg.js') ?>"></script>
</body>
</html>