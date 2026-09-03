// Kart Land overworld — procedural pixel renderer for the Territory scoring system.
//
// Six islands are defined ONCE in island-local tile coordinates (shape, terrace,
// stops, road, castle, set dressing). A layout only places the islands and draws
// the bridges between them, so landscape (site) and portrait (Lounge screen)
// show the same islands with the same stops. Everything is autotiled from masks:
// sand rim + ink outline + ribbed cliff face under every south edge; roads from
// a path mask; road over sea = plank bridge. Territory = flat wash of the
// holder's colour over a neutral base (so a holder looks the same on grass,
// forest or valley sand), applied to land tiles nearest to a held stop.
//
// Shared by public_html (canvas) and the headless check (node → PPM).
(function (root) {
  const TS = 16;
  const P = {
    sea: [58,142,224], crest: [225,243,255], grass: [122,206,74], grassD: [86,166,52], grassL: [160,228,110],
    sand: [240,226,160], cliff: [214,148,88], cliffD: [170,104,58], ink: [30,34,52],
    road: [250,224,75], roadE: [120,84,20], stone: [138,143,156], stoneD: [92,97,112],
    wood: [138,90,43], woodL: [201,141,75], rock: [107,74,58], rockL: [143,107,88], valley: [231,211,161], valleyD: [205,182,130],
    tree: [62,140,58], treeD: [40,100,40], water: [140,198,255], white: [255,255,255], red: [230,0,18], pipe: [47,191,58], pipeL: [73,217,85],
    cloud: [255,255,255], cloudS: [214,226,240], washBase: [232,228,214],
  };
  const SPR = {
    hill: ['......KKKK......','....KKggggKK....','...KgLLLggggK...','..KggLLggggggK..','..KggggggggggK..','...KKKKKKKKKK...'],
    pipe: ['..KKKKKKKKKK....','.KLPPPPPPPPLK...','.KKKKKKKKKKKK...','..KLPPPPPPPK....','..KLPPPPPPPK....','..KLPPPPPPPK....','..KLPPPPPPPK....','..KLPPPPPPPK....','..KKKKKKKKK.....'],
    house: ['......KK........','.....KRRK.......','....KRRRRK......','...KRRRRRRK.....','..KRRRRRRRRK....','..KKKKKKKKKK....','..KSSSSSSSSK....','..KSSKKKSSSK....','..KSSKKKSSSK....','..KSSKKKSSSK....','..KKKKKKKKKK....'],
    tree: ['....KKKK........','...KTTTTK.......','..KTTdTTTK......','..KTTTTTTK......','...KTTTTK.......','....KKKK........','.....KK.........','.....KK.........'],
    rock: ['....KK..........','...KMmK.........','..KMmMMK...KK...','.KMMMMMMK.KMmK..','.KMMMmMMKKMMMMK.','.KKKKKKKKKKKKKK.'],
    castle: ['..KK...KK...KK..','..KNK.KNNK.KNK..','..KNKKKNNKKKNK..','..KNNNNNNNNNNK..','..KNNNKKKKNNNK..','..KNNNKnnKNNNK..','..KNNNKnnKNNNK..','..KNNNNNNNNNNK..','..KNNNNNNNNNNK..','..KNNNNKKNNNNK..','..KNNNNKKNNNNK..','..KNNNNKKNNNNK..','..KKKKKKKKKKKK..'],
    cloud: ['.....KKKK.......','...KKCCCCKK.....','..KCCCCCCCCKKK..','.KCCCCCCCCCCCCK.','.KCCCCCCCCCCCCK.','..KcccccccccccK.','...KKKKKKKKKKK..'],
  };
  const MAP = { K: 'ink', g: 'grassD', L: 'grassL', P: 'pipe', R: 'red', S: 'sand', T: 'tree', d: 'treeD', M: 'rock', m: 'rockL', N: 'stone', n: 'stoneD', C: 'cloud', c: 'cloudS' };

  // ── the islands, island-local coordinates. land rects: [x,y,w,h(,type)] type 1 grass, 2 valley sand, 3 forest ──
  const ISLANDS = {
    west:   { land: [[1,2,11,12],[3,0,7,2],[11,5,4,6],[0,7,1,5]], stops: [[3,10],[5,5],[8,2],[11,8],[7,12]],
              paths: [[[3,10],[3,5],[5,5],[8,5],[8,2],[11,2],[11,8],[7,8],[7,12],[3,12]]], castle: [5,12],
              deco: { hill: [[4,2],[13,10]], pipe: [[6,6]], house: [[2,4]], tree: [[5,8],[12,3]] } },
    north:  { land: [[2,1,17,10],[5,0,8,1],[19,4,3,5],[0,5,2,4]], raise: [[6,2,9,4],[4,4,2,2]],
              stops: [[3,8],[6,8],[10,4],[16,4],[18,8],[12,9]],
              paths: [[[3,8],[6,8],[10,8],[10,4],[16,4],[16,8],[18,8],[12,8],[12,9]]], castle: [7,5],
              deco: { hill: [[12,2],[1,3],[22,5]], pipe: [[14,9]], water: [[11,11]] } },
    east:   { land: [[2,1,12,9],[6,0,4,1],[0,5,2,3]], stops: [[4,7],[7,3],[11,3],[12,7]],
              paths: [[[4,8],[4,7],[7,7],[7,3],[11,3],[11,7],[12,7]]], castle: [9,1],
              deco: { hill: [[5,4]], pipe: [[12,2]], house: [[9,8]] } },
    forest: { land: [[2,0,15,11,3],[0,3,2,5,3],[17,2,3,6,3]], stops: [[14,9],[12,3],[7,2],[4,6],[9,8]],
              paths: [[[14,0],[14,9],[12,9],[12,3],[7,3],[7,2],[7,0]],[[7,2],[4,2],[4,6],[9,6],[9,8]]], castle: [2,8],
              deco: { tree: [[3,1],[5,4],[10,1],[13,5],[15,2],[7,9],[2,4],[10,10],[16,7],[4,9],[12,6],[1,6]], house: [[11,1]] } },
    islet:  { land: [[0,0,6,6],[6,2,2,2]], stops: [[2,2]], paths: [[[2,0],[2,2]]], castle: null, deco: { house: [[4,3]] } },
    valley: { land: [[2,2,25,9,2],[5,0,6,2,2],[17,0,8,2,2],[27,4,3,5,2],[0,5,2,4,2]], stops: [[5,6],[12,4],[21,8]],
              paths: [[[5,0],[5,6],[12,6],[12,4],[21,4],[21,8]]], castle: [23,9],
              deco: { rock: [[7,3],[10,8],[16,2],[18,6],[24,2],[25,6],[9,9],[14,9],[3,8]], pipe: [[4,3]] } },
  };
  // island order == cup order: stops 1-5 west, 6-11 north, 12-15 east, 16-20 forest, 21 islet, 22-24 valley
  const ORDER = ['west', 'north', 'east', 'forest', 'islet', 'valley'];

  // ── layouts: where each island sits, and the bridges between them (absolute tiles) ──
  const LAYOUTS = {
    landscape: { W: 56, H: 42,
      place: { west: [1,13], north: [8,0], east: [38,0], forest: [34,15], islet: [23,14], valley: [19,28] },
      links: [[[9,15],[9,12],[11,12],[11,8]], [[26,8],[36,8],[42,8]], [[50,7],[50,12],[48,12],[48,15]], [[20,9],[20,12],[25,12],[25,16]], [[8,25],[8,29],[24,29]]],
      clouds: [[3,3],[31,12],[16,32],[50,29],[33,1]] },
    portrait: { W: 34, H: 72,
      place: { north: [6,0], west: [2,15], islet: [24,17], east: [10,31], forest: [8,44], valley: [2,58] },
      links: [[[10,17],[10,11],[9,11],[9,8]], [[24,8],[24,12],[32,12],[32,38],[23,38]], [[14,38],[14,42],[15,42],[15,44]], [[17,52],[17,56],[7,56],[7,59]], [[18,9],[18,12],[26,12],[26,19]]],
      clouds: [[28,2],[2,12],[26,28],[3,41],[30,55],[20,68]] },
  };

  function build(name) {
    const L = LAYOUTS[name]; const { W, H } = L;
    const grid = () => Array.from({ length: H }, () => new Array(W).fill(0));
    const rect = (m, x, y, w, h, v = 1) => { for (let j = y; j < y + h; j++) for (let i = x; i < x + w; i++) if (j >= 0 && j < H && i >= 0 && i < W) m[j][i] = v; };
    const path = (m, pts) => { for (let k = 0; k + 1 < pts.length; k++) { const [x0, y0] = pts[k], [x1, y1] = pts[k + 1]; if (x0 !== x1 && y0 !== y1) { for (let x = Math.min(x0, x1); x <= Math.max(x0, x1); x++) m[y0][x] = 1; for (let y = Math.min(y0, y1); y <= Math.max(y0, y1); y++) m[y][x1] = 1; } else { for (let x = Math.min(x0, x1); x <= Math.max(x0, x1); x++) for (let y = Math.min(y0, y1); y <= Math.max(y0, y1); y++) if (y >= 0 && y < H && x >= 0 && x < W) m[y][x] = 1; } } };
    const land = grid(), raise = grid(), road = grid(), isle = grid();
    const stops = [], castles = [], worlds = [], deco = { hill: [], pipe: [], house: [], tree: [], rock: [], water: [], cloud: L.clouds || [] };
    let stopIdx = 0;
    ORDER.forEach((id, ii) => {
      const I = ISLANDS[id]; const [ox, oy] = L.place[id]; const tr = ([x, y]) => [x + ox, y + oy];
      I.land.forEach(([x, y, w, h, v]) => { rect(land, x + ox, y + oy, w, h, v || 1); rect(isle, x + ox, y + oy, w, h, ii + 1); });
      (I.raise || []).forEach(([x, y, w, h]) => rect(raise, x + ox, y + oy, w, h, 1));
      I.paths.forEach(p => path(road, p.map(tr)));
      const ids = []; I.stops.forEach(s => { ids.push(stopIdx++); stops.push(tr(s)); });
      if (I.castle) { castles.push(tr(I.castle)); worlds.push(ids); }
      Object.keys(I.deco || {}).forEach(k => I.deco[k].forEach(d => deco[k].push(tr(d))));
    });
    L.links.forEach(p => path(road, p));
    return { name, W, H, land, raise, road, isle, stops, castles, worlds, deco };
  }
  const built = {}; const layout = (name) => built[name] || (built[name] = build(name));

  function renderOverworld(put, opts = {}) {
    const L = layout(opts.layout || 'landscape'); const { W, H, land, raise, road, isle, stops, castles, deco } = L;
    const at = (m, x, y) => (y >= 0 && y < H && x >= 0 && x < W) ? m[y][x] : 0;
    const px = (x, y, k) => put(x, y, P[k] || k);
    const blend = (a, b, t) => [Math.round(a[0] + (b[0] - a[0]) * t), Math.round(a[1] + (b[1] - a[1]) * t), Math.round(a[2] + (b[2] - a[2]) * t)];
    // territory: every land tile takes the nearest held stop on its own island (within reach)
    const tintCol = opts.tintColors || []; const reach = opts.tintReach || 7;
    const tint = Array.from({ length: H }, () => new Array(W).fill(null));
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) if (land[y][x]) { let best = null, bd = 1e9; stops.forEach(([sx, sy], i) => { if (!tintCol[i] || isle[sy][sx] !== isle[y][x]) return; const d = Math.abs(sx - x) + Math.abs(sy - y); if (d < bd) { bd = d; best = i; } }); if (best !== null && bd <= reach) tint[y][x] = tintCol[best]; }
    // the wash: one flat colour per holder, whatever the ground — mixed over a neutral base, not over grass/sand
    const wash = (c, hi) => blend(P.washBase, c, hi ? 0.5 : 0.6);

    for (let y = 0; y < H * TS; y++) for (let x = 0; x < W * TS; x++) px(x, y, 'sea');
    let seed = 7; const rnd = (n) => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed % n; };
    for (let i = 0; i < Math.round(W * H / 14); i++) { const x = rnd(W * TS - 9), y = rnd(H * TS - 3); [[0,1],[1,0],[2,0],[3,1],[5,1],[6,0],[7,0],[8,1]].forEach(([dx, dy]) => px(x + dx, y + dy, 'crest')); }
    const paintLand = (m, depth, terrace) => {
      for (let ty = 0; ty < H; ty++) for (let tx = 0; tx < W; tx++) {
        const v = at(m, tx, ty); if (!v) continue;
        const N = at(m, tx, ty - 1) > 0, S = at(m, tx, ty + 1) > 0, E = at(m, tx + 1, ty) > 0, Wn = at(m, tx - 1, ty) > 0;
        const lv = terrace ? (land[ty][tx] || 1) : v;
        const ground = lv === 2 ? 'valley' : 'grass', shade = lv === 2 ? 'valleyD' : 'grassD', hi = lv === 2 ? 'sand' : 'grassL';
        const tc = tint[ty][tx];
        for (let py = 0; py < TS; py++) for (let pxx = 0; pxx < TS; pxx++) {
          let col = ground;
          if (((tx * 7 + ty * 3 + pxx) % 11 === 0) && py % 5 === 2) col = hi;
          if (!N && py === 0) col = 'ink'; else if (!N && py <= 2) col = 'sand'; else if (!N && py === 3) col = shade;
          if (!Wn && pxx === 0) col = 'ink'; else if (!Wn && pxx <= 2 && col !== 'ink') col = 'sand'; else if (!Wn && pxx === 3 && col === ground) col = shade;
          if (!E && pxx === 15) col = 'ink'; else if (!E && pxx >= 13 && col !== 'ink') col = 'sand'; else if (!E && pxx === 12 && col === ground) col = shade;
          if (!S && py === 15) col = 'ink'; else if (!S && py >= 13 && col !== 'ink') col = 'sand'; else if (!S && py === 12 && col === ground) col = shade;
          if (tc && (col === ground || col === hi)) put(tx * TS + pxx, ty * TS + py, wash(tc, col === hi)); else px(tx * TS + pxx, ty * TS + py, col);
        }
        if (!S) {
          for (let d = 0; d < depth; d++) { const cy = (ty + 1) * TS + d; for (let pxx = 0; pxx < TS; pxx++) { let col = (pxx % 6 === 3) ? 'cliffD' : 'cliff'; if (d === depth - 1) col = 'ink'; else if (d === depth - 2) col = 'cliffD'; if (!Wn && pxx === 0) col = 'ink'; if (!E && pxx === 15) col = 'ink'; px(tx * TS + pxx, cy, col); } }
          if (!terrace) for (let pxx = 2; pxx < 14; pxx += 4) px(tx * TS + pxx, (ty + 1) * TS + depth, 'crest');
        }
      }
    };
    paintLand(land, 28, false); paintLand(raise, 14, true);
    for (let ty = 0; ty < H; ty++) for (let tx = 0; tx < W; tx++) if (land[ty][tx] === 3 && !road[ty][tx]) [[3,4],[11,10]].forEach(([cx, cy]) => { if (((tx * 5 + ty * 3 + cx) % 3) !== 0) for (let dy = -3; dy <= 3; dy++) for (let dx = -3; dx <= 3; dx++) { const d = dx * dx + dy * dy; if (d <= 10) px(tx * TS + cx + dx, ty * TS + cy + dy, d >= 8 ? 'ink' : (dx < 0 && dy < 0 ? 'tree' : 'treeD')); } });
    for (let ty = 0; ty < H; ty++) for (let tx = 0; tx < W; tx++) if (road[ty][tx]) {
      const n = at(road, tx, ty - 1), s = at(road, tx, ty + 1), e = at(road, tx + 1, ty), w = at(road, tx - 1, ty);
      const onLand = land[ty][tx] > 0 || raise[ty][tx] > 0;
      for (let py = 0; py < TS; py++) for (let pxx = 0; pxx < TS; pxx++) {
        const core = pxx >= 5 && pxx <= 10 && py >= 5 && py <= 10;
        const arm = (n && pxx >= 5 && pxx <= 10 && py < 5) || (s && pxx >= 5 && pxx <= 10 && py > 10) || (w && py >= 5 && py <= 10 && pxx < 5) || (e && py >= 5 && py <= 10 && pxx > 10);
        if (!(core || arm)) continue;
        const edge = (pxx === 5 && !(w && py >= 5 && py <= 10)) || (pxx === 10 && !(e && py >= 5 && py <= 10)) || (py === 5 && !(n && pxx >= 5 && pxx <= 10)) || (py === 10 && !(s && pxx >= 5 && pxx <= 10));
        if (onLand) px(tx * TS + pxx, ty * TS + py, edge ? 'roadE' : 'road');
        else { const horiz = w || e; const plank = horiz ? (pxx % 3 === 0) : (py % 3 === 0); px(tx * TS + pxx, ty * TS + py, edge ? 'ink' : (plank ? 'woodL' : 'wood')); }
      }
    }
    const sprite = (tx, ty, rows) => rows.forEach((r, py) => { for (let pxx = 0; pxx < r.length; pxx++) { const ch = r[pxx]; if (ch !== '.') px(tx * TS + pxx, ty * TS + py, MAP[ch]); } });
    ['hill', 'pipe', 'house', 'tree', 'rock', 'cloud'].forEach(k => deco[k].forEach(([x, y]) => sprite(x, y, SPR[k])));
    castles.forEach(([x, y]) => sprite(x, y, SPR.castle));
    deco.water.forEach(([x, y]) => { for (let d = 0; d < 30; d++) for (let pxx = 5; pxx < 11; pxx++) px(x * TS + pxx, (y + 1) * TS + d - 2, ((pxx + Math.floor(d / 3)) % 3 === 0) ? 'white' : 'water'); });
    if (opts.flagColors) castles.forEach(([x, y], i) => { const c = opts.flagColors[i]; if (!c) return; for (let d = 0; d < 7; d++) px(x * TS + 7, y * TS - 7 + d, 'ink'); for (let dy = 0; dy < 4; dy++) for (let dx = 1; dx <= 6 - (dy === 1 || dy === 2 ? 1 : 0); dx++) put(x * TS + 7 + dx, y * TS - 7 + dy, c); });
    (opts.stopColors || []).forEach((c, i) => { const [x, y] = stops[i]; const cx = x * TS + 8, cy = y * TS + 8; const fill = c || P.road; for (let dy = -5; dy <= 5; dy++) for (let dx = -5; dx <= 5; dx++) { const d = dx * dx + dy * dy; if (d > 30) continue; put(cx + dx, cy + dy, d >= 20 ? P.ink : (d <= 4 ? P.white : fill)); } });
  }
  root.Overworld = { TS, layout, renderOverworld, P, ORDER };
})(typeof module !== 'undefined' ? module.exports : window);
