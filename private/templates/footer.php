<?php
// Get footer settings
global $pdo;
$leagueName = getSetting($pdo, 'league_name', 'Kartfolio League');
$governingBodyFull = getSetting($pdo, 'governing_body_full', 'Organisation Mondial du Karting');
$governingBodyShort = getSetting($pdo, 'governing_body_short', 'OMK');
$footerAbout = getSetting($pdo, 'footer_about', 'The premier competitive Mario Kart 8 Deluxe league.');
?>
</main> <footer class="site-footer">
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
    <div style="text-align: center; margin-top: 20px; padding: 10px;">
        <a href="/underground" style="font-size: 0.65rem; color: #222; text-decoration: none; font-family: monospace; opacity: 0.2; transition: opacity 0.3s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.2'">
            ⚠️ unauthorized access
        </a>
    </div>
</footer>

<style>
    .site-footer {
        margin-top: 60px;
        background: #1a1a1a;
        color: #888;
        padding: 40px 0;
        border-top: 4px solid var(--nintendo-red);
    }
    .footer-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr;
        gap: 40px;
    }
    .footer-brand .logo-text { color: white; font-size: 1.5rem; font-weight: 900; font-style: italic; }
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
            <div class="cup-racer-hint">Select 2-4 racers to find a cup most haven't done</div>
        </div>

        <div class="cup-wheel" id="cup-wheel">
            <div class="cup-wheel-result" id="cup-result">🎰</div>
        </div>
        <div class="cup-wheel-stats" id="cup-stats"></div>
        <div class="cup-racer-details" id="cup-racer-details"></div>
        <div class="cup-buttons">
            <button class="cup-pick-btn" id="cup-pick-btn">PICK!</button>
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

.cup-wheel {
    width: 300px;
    height: 300px;
    margin: 0 auto 30px;
    background: linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 8px solid var(--nintendo-red);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2), inset 0 0 30px rgba(0, 0, 0, 0.1);
    position: relative;
    transition: transform 0.3s ease;
}

.cup-wheel.spinning {
    animation: wheelSpin 3s cubic-bezier(0.17, 0.67, 0.12, 0.99);
}

@keyframes wheelSpin {
    0% { transform: rotate(0deg) scale(1); }
    20% { transform: rotate(720deg) scale(1.05); }
    40% { transform: rotate(1440deg) scale(1); }
    60% { transform: rotate(2160deg) scale(1.05); }
    80% { transform: rotate(2880deg) scale(1); }
    100% { transform: rotate(3600deg) scale(1); }
}

.cup-wheel-result {
    font-size: 4rem;
    font-weight: 900;
    color: #333;
    text-transform: uppercase;
    letter-spacing: -1px;
    line-height: 1;
}

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
    margin-bottom: 20px;
    min-height: 24px;
}

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
    gap: 10px;
    justify-content: center;
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

@media (max-width: 480px) {
    .cup-wheel-container { padding: 30px 20px; }
    .cup-racer-chips { max-height: 100px; }
    .cup-racer-chip { padding: 5px 10px; font-size: 0.72rem; }
    .cup-buttons { flex-direction: column; }
    .cup-pick-btn, .cup-close-btn { width: 100%; }
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

    // Cup Picker Functionality
    const cupPickerBtn = document.getElementById('cup-picker-btn');
    const cupOverlay = document.getElementById('cup-picker-overlay');
    const cupWheel = document.getElementById('cup-wheel');
    const cupResult = document.getElementById('cup-result');
    const cupStats = document.getElementById('cup-stats');
    const cupCloseBtn = document.getElementById('cup-close-btn');
    const cupPickBtn = document.getElementById('cup-pick-btn');
    const cupRacerChips = document.getElementById('cup-racer-chips');
    const cupRacerDetails = document.getElementById('cup-racer-details');

    let cupRacersLoaded = false;
    let cupSelectedRacers = new Set();

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

    cupOverlay.addEventListener('click', function(e) {
        if (e.target === cupOverlay) {
            cupOverlay.classList.remove('active');
        }
    });

    function openCupPicker() {
        cupOverlay.classList.add('active');
        cupResult.textContent = '🎰';
        cupStats.textContent = '';
        cupRacerDetails.innerHTML = '';
        cupWheel.classList.remove('spinning');
        cupResult.classList.remove('revealed');
        cupPickBtn.disabled = false;

        // Load racer list once
        if (!cupRacersLoaded) {
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
                    cupRacersLoaded = true;
                });
        }
    }

    function toggleRacerChip(chip, racerId) {
        if (cupSelectedRacers.has(racerId)) {
            cupSelectedRacers.delete(racerId);
            chip.classList.remove('selected');
        } else {
            if (cupSelectedRacers.size >= 4) return; // Max 4
            cupSelectedRacers.add(racerId);
            chip.classList.add('selected');
        }
        // Update hint text
        const hint = document.querySelector('.cup-racer-hint');
        if (hint) {
            const count = cupSelectedRacers.size;
            if (count === 0) {
                hint.textContent = 'Select 2\u20134 racers to find a cup most haven\u2019t done';
            } else if (count < 2) {
                hint.textContent = 'Select at least 1 more racer';
            } else {
                hint.textContent = count + ' racer' + (count !== 1 ? 's' : '') + ' selected \u2014 ready to pick!';
            }
        }
    }

    function doCupPick() {
        cupPickBtn.disabled = true;
        cupResult.textContent = '🎰';
        cupStats.textContent = '';
        cupRacerDetails.innerHTML = '';
        cupResult.classList.remove('revealed');

        // Build URL with optional racer params
        let url = '/pick-cup';
        if (cupSelectedRacers.size > 0) {
            url += '?racers=' + Array.from(cupSelectedRacers).join(',');
        }

        // Start spinning
        cupWheel.classList.add('spinning');

        fetch(url)
            .then(r => r.json())
            .then(data => {
                // Wait for spin animation
                setTimeout(() => {
                    cupWheel.classList.remove('spinning');
                    cupResult.textContent = data.cup + ' Cup';
                    cupResult.classList.add('revealed');
                    cupStats.textContent = 'Raced ' + data.seasonRaceCount + ' time' + (data.seasonRaceCount !== 1 ? 's' : '') + ' this season';
                    cupPickBtn.disabled = false;

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
                }, 3000);
            })
            .catch(error => {
                console.error('Error picking cup:', error);
                cupWheel.classList.remove('spinning');
                cupResult.textContent = 'Error!';
                cupPickBtn.disabled = false;
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
<script src="/assets/js/easter_egg.js"></script>
</body>
</html>