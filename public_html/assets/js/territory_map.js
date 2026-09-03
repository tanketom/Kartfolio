// Territory overworld page script — draws /assets/js/overworld.js onto #tt-map
// from the JSON in #tt-data (territoryMapPayload), lays labels and tooltips
// over it, and runs the Map / Rankings switch on the homepage.
(function () {
  const dataEl = document.getElementById('tt-data'), canvas = document.getElementById('tt-map');
  if (!dataEl || !canvas || !window.Overworld) return;
  const D = JSON.parse(dataEl.textContent), O = window.Overworld;
  const card = canvas.parentElement, overlay = document.getElementById('tt-overlay'), tip = document.getElementById('tt-tip');
  const layoutName = canvas.dataset.layout || 'landscape';
  const hex = h => [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)];
  const colorOf = rid => hex(D.colors[rid] || '#999999');
  const ctx = canvas.getContext('2d');

  function draw() {
    const L = O.layout(layoutName); const Wpx = L.W * O.TS, Hpx = L.H * O.TS;
    canvas.width = Wpx; canvas.height = Hpx;
    const img = ctx.createImageData(Wpx, Hpx), d = img.data;
    const put = (x, y, c) => { if (x < 0 || y < 0 || x >= Wpx || y >= Hpx) return; const i = (y * Wpx + x) * 4; d[i] = c[0]; d[i + 1] = c[1]; d[i + 2] = c[2]; d[i + 3] = 255; };
    const stopColors = D.cups.map(c => c.holder ? colorOf(c.holder) : null);
    const flagColors = L.worlds.map(ids => { const t = {}; ids.forEach(i => { const h = D.cups[i].holder; if (h) t[h] = (t[h] || 0) + 1; }); const top = Object.keys(t).sort((a, b) => t[b] - t[a])[0]; return top ? colorOf(top) : null; });
    O.renderOverworld(put, { layout: layoutName, stopColors, tintColors: stopColors, flagColors });
    ctx.putImageData(img, 0, 0);

    overlay.innerHTML = '';
    const pct = (tx, ty, dx = 8, dy = 8) => [((tx * O.TS + dx) / Wpx * 100), ((ty * O.TS + dy) / Hpx * 100)];
    const tilePx = card.clientWidth / L.W;
    card.style.setProperty('--tt-hit', (O.TS * 2 / Wpx * 100) + '%');
    card.style.setProperty('--tt-lbl', Math.max(8, Math.min(24, tilePx * 0.72)) + 'px');
    card.style.setProperty('--tt-wn', Math.max(7, Math.min(18, tilePx * 0.5)) + 'px');
    D.cups.forEach((c, i) => {
      const [x, y] = L.stops[i]; const [lx, ly] = pct(x, y);
      const lbl = document.createElement('div'); lbl.className = 'tt-lbl'; lbl.style.left = lx + '%'; lbl.style.top = (ly + (O.TS * 0.75 / Hpx * 100)) + '%';
      lbl.innerHTML = c.cup + (c.holder ? `<small>${c.name} · ${c.pts}</small>` : '<small>open</small>');
      overlay.appendChild(lbl);
      if (c.flip) { const f = document.createElement('div'); f.className = 'tt-flip'; f.style.left = lx + '%'; f.style.top = (ly - (O.TS * 0.6 / Hpx * 100)) + '%'; f.textContent = '🚩'; f.title = 'Changed hands on the latest race night'; overlay.appendChild(f); }
      if (tip) {
        const hit = document.createElement('button'); hit.className = 'tt-stop'; hit.type = 'button'; hit.style.left = lx + '%'; hit.style.top = ly + '%'; hit.setAttribute('aria-label', c.cup + ' Cup');
        const show = (ev) => {
          const r = card.getBoundingClientRect();
          let body;
          if (c.holder) {
            const decay = D.decay ? ` · undefended ${c.undefended} / ${D.decay}` : '';
            const how = c.pts >= 60 ? 'A perfect 60 — a 60 takes it on the tie.' : `Post ${c.pts} or better on any night to take it.`;
            body = `<b>${c.cup} Cup</b>Held by <strong>${c.name}</strong> · ${c.pts} to beat${decay}<br><span class="m">${how}${c.flip ? ' Changed hands last night (' + c.flip + ').' : ''}</span>`;
          } else body = `<b>${c.cup} Cup</b>Nobody has raced it this season.<br><span class="m">First score plants the flag.</span>`;
          tip.innerHTML = body;
          const cx = ev.clientX ?? (r.left + lx / 100 * r.width), cy = ev.clientY ?? (r.top + ly / 100 * r.height);
          tip.style.left = Math.min(r.width - 260, Math.max(0, cx - r.left + 14)) + 'px'; tip.style.top = (cy - r.top + 14) + 'px'; tip.classList.add('on');
        };
        hit.addEventListener('mousemove', show); hit.addEventListener('focus', show);
        hit.addEventListener('mouseleave', () => tip.classList.remove('on')); hit.addEventListener('blur', () => tip.classList.remove('on'));
        overlay.appendChild(hit);
      }
    });
    L.castles.forEach(([x, y], i) => { const [lx, ly] = pct(x, y, 8, 20); const n = document.createElement('div'); n.className = 'tt-wnum'; n.style.left = lx + '%'; n.style.top = ly + '%'; n.textContent = String(i + 1); overlay.appendChild(n); });
  }
  draw();
  let raf = null; window.addEventListener('resize', () => { if (raf) cancelAnimationFrame(raf); raf = requestAnimationFrame(draw); });

  // Map / Rankings switch (homepage only). The script tag sits above the
  // standings grid in the HTML, so wait for the document before looking it up.
  const wireSwitch = () => {
    const segs = Array.from(document.querySelectorAll('.tt-seg')), grid = document.getElementById('leaderboard-grid');
    if (!segs.length || !grid) return;
    const setView = (v) => { const map = v === 'map'; card.hidden = !map; grid.hidden = map; segs.forEach(s => s.setAttribute('aria-pressed', String(s.dataset.view === v))); try { localStorage.setItem('tt-view', v); } catch (e) {} if (map) draw(); };
    segs.forEach(s => s.addEventListener('click', () => setView(s.dataset.view)));
    let saved = 'map'; try { saved = localStorage.getItem('tt-view') || 'map'; } catch (e) {}
    setView(saved);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireSwitch); else wireSwitch();
})();
