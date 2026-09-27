/**
 * Awards night presenter (/display/ceremony).
 *
 * The running order is built server-side; this only walks it. One step per
 * click. The step index is kept in the URL hash so a reload lands on the same
 * envelope. Everything is written with textContent — names and award titles
 * come from the database and never become markup.
 */
(function () {
    const steps = JSON.parse(document.getElementById('cer-steps').textContent);
    const board = JSON.parse(document.getElementById('cer-board').textContent);
    const main = document.getElementById('main');
    const host = document.getElementById('host');
    const progress = document.getElementById('progress');
    const confetti = document.getElementById('confetti');
    const stage = document.getElementById('stage');

    let at = Math.min(Math.max(parseInt(location.hash.slice(1), 10) || 0, 0), steps.length - 1);

    // ── The stage is drawn at 1920×1080 and scaled to whatever it is shown on.
    function fit() {
        const s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
        stage.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
    }
    window.addEventListener('resize', fit);
    fit();

    function el(tag, cls, text) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null && text !== '') e.textContent = text;
        return e;
    }
    function portrait(ch, cls) {
        const img = el('img', cls);
        img.alt = '';
        img.src = '/assets/img/' + encodeURIComponent(ch || 'Mii') + '.png';
        img.onerror = function () { this.onerror = null; this.src = '/assets/img/Mii.png'; };
        return img;
    }

    function renderBoard(upto) {
        const wrap = el('div', 'cer-board');
        wrap.appendChild(el('div', 'cer-kicker', 'The standings'));
        const list = el('ol', 'cer-board-list');
        // Newest reveal on top, the way a countdown reads. Only the last eight
        // fit on the stage; older rows have had their moment.
        for (let i = upto - 1; i >= Math.max(0, upto - 8); i--) {
            const r = board[i];
            const li = el('li', 'cer-board-row' + (i === upto - 1 ? ' is-new' : ''));
            li.appendChild(el('span', 'cer-board-rank', r.rank));
            li.appendChild(portrait(r.char, 'cer-board-char'));
            li.appendChild(el('span', 'cer-board-name', r.name));
            if (r.tie) li.appendChild(el('span', 'cer-board-tie', 'tie'));
            li.appendChild(el('span', 'cer-board-score', r.score));
            list.appendChild(li);
        }
        wrap.appendChild(list);
        return wrap;
    }

    function render() {
        const s = steps[at];
        main.replaceChildren();
        main.className = 'cer-main cer-step-' + s.type + (s.place ? ' cer-place-' + s.place : '');

        if (s.type === 'board') {
            main.appendChild(renderBoard(s.upto));
        } else if (s.type === 'finale') {
            main.appendChild(el('div', 'cer-kicker', s.kicker));
            main.appendChild(el('div', 'cer-finale-crown', '🏆'));
            main.appendChild(el('h1', 'cer-title', s.title));
            const pod = el('div', 'cer-stand');
            // Classic podium order: 2 · 1 · 3.
            [1, 0, 2].forEach(function (i) {
                const p = s.podium[i];
                if (!p) return;
                const col = el('div', 'cer-stand-col cer-stand-' + (i + 1));
                col.appendChild(portrait(p.char, 'cer-stand-char'));
                col.appendChild(el('div', 'cer-stand-name', p.name));
                col.appendChild(el('div', 'cer-stand-block', String(i + 1)));
                pod.appendChild(col);
            });
            main.appendChild(pod);
        } else {
            if (s.char) main.appendChild(portrait(s.char, 'cer-portrait'));
            main.appendChild(el('div', 'cer-kicker', s.kicker));
            main.appendChild(el('h1', 'cer-title', s.title));
            if (s.sub) main.appendChild(el('div', 'cer-sub', s.sub));
            if (s.tie) main.appendChild(el('div', 'cer-tie', s.tie));
            if (s.type === 'ask' || s.type === 'drum') main.appendChild(el('div', 'cer-dots', '• • •'));
        }

        host.textContent = s.host || '';
        host.classList.remove('is-in');
        void host.offsetWidth;                  // restart the pop-in
        host.classList.add('is-in');

        progress.replaceChildren();
        steps.forEach(function (_, i) {
            progress.appendChild(el('span', 'cer-pip' + (i < at ? ' is-done' : i === at ? ' is-here' : '')));
        });

        const party = s.type === 'finale' || (s.type === 'podium' && s.place === 1);
        confetti.classList.toggle('is-on', party);
        if (party && !confetti.childElementCount) throwConfetti();

        history.replaceState(null, '', '#' + at);
    }

    function throwConfetti() {
        const colours = ['#e60012', '#ffd700', '#2ea84f', '#009be0', '#ff5ca2', '#ff8a1e'];
        for (let i = 0; i < 90; i++) {
            const c = el('i', 'cer-bit');
            c.style.left = (Math.random() * 100) + '%';
            c.style.background = colours[i % colours.length];
            c.style.animationDelay = (Math.random() * 3) + 's';
            c.style.animationDuration = (3 + Math.random() * 3) + 's';
            c.style.transform = 'rotate(' + (Math.random() * 360) + 'deg)';
            confetti.appendChild(c);
        }
    }

    function go(d) {
        const next = Math.min(Math.max(at + d, 0), steps.length - 1);
        if (next !== at) { at = next; render(); }
    }

    document.addEventListener('keydown', function (e) {
        if (['ArrowRight', 'ArrowDown', 'PageDown', ' ', 'Enter'].includes(e.key)) { e.preventDefault(); go(1); }
        else if (['ArrowLeft', 'ArrowUp', 'PageUp', 'Backspace'].includes(e.key)) { e.preventDefault(); go(-1); }
        else if (e.key === 'Home') { at = 0; render(); }
        else if (e.key === 'End') { at = steps.length - 1; render(); }
        else if (e.key === 'f' || e.key === 'F') {
            if (document.fullscreenElement) document.exitFullscreen();
            else document.documentElement.requestFullscreen().catch(function () {});
        }
    });
    document.addEventListener('click', function (e) {
        // Left third goes back, the rest goes on — handy on a laptop trackpad.
        go(e.clientX < window.innerWidth / 3 ? -1 : 1);
    });

    render();
})();
