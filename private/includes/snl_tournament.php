<?php
/**
 * Snakes & Ladders — TOURNAMENT engine.
 *
 * Shape (mirrors Survivor's round-based model so it reuses the generic
 * match-recording form + tournament_match_participants):
 *   - Every round, the field is split into balanced HEATS of ≤4 racers.
 *     One tournament_matches row per heat (bracket='snl', round='R1','R2',…).
 *   - You record each heat's finishing placements with the normal results
 *     form. Placement → board "roll": 1st=4 · 2nd=3 · 3rd=2 · 4th+=1.
 *   - Tokens climb a shared board (deterministic per tournament). Ladders
 *     climb, snakes slide. Exact-landing endgame: overshoot the final square
 *     and you bounce back.
 *   - No elimination — everyone races every round until the FIRST token
 *     lands exactly on the final square. That racer is champion.
 *
 * Board state is never stored: it is replayed from the recorded placements
 * each time (deterministic), so there is nothing to keep in sync.
 */

const SNL_HEAT_SIZE = 4;

/** Board config off the tournaments row, with safe defaults. */
function snlConfig(array $t): array {
    return [
        'len'   => max(12, (int)($t['snl_board_len'] ?? 30)),
        'chaos' => in_array($t['snl_chaos'] ?? 'medium', ['low','medium','high'], true) ? $t['snl_chaos'] : 'medium',
    ];
}

/**
 * Deterministic board → map of square => destination (ladder if dest>square,
 * snake if dest<square). Seeded from the tournament id + chaos so it is fixed
 * for the event and reproducible on any restore (same crc32 trick as packs).
 */
function snlBoard(int $tournamentId, int $len, string $chaos): array {
    $density = ['low' => 12, 'medium' => 8, 'high' => 6][$chaos] ?? 8;
    $spanDiv = ['low' => 5,  'medium' => 4, 'high' => 3][$chaos] ?? 4;
    $n    = max(2, intdiv($len, $density));
    $span = max(3, intdiv($len, $spanDiv));

    mt_srand(crc32("snl-board-{$tournamentId}-{$chaos}-{$len}"));
    $jump = []; $used = [1 => true, $len => true];
    for ($i = 0; $i < $n; $i++) {                       // ladders
        for ($t = 0; $t < 80; $t++) {
            $foot = mt_rand(2, $len - 3);
            $top  = $foot + mt_rand(3, $span);
            if ($top >= $len || isset($used[$foot]) || isset($used[$top])) continue;
            $jump[$foot] = $top; $used[$foot] = $used[$top] = true; break;
        }
    }
    for ($i = 0; $i < $n; $i++) {                       // snakes
        for ($t = 0; $t < 80; $t++) {
            $head = mt_rand(6, $len - 2);
            $tail = $head - mt_rand(3, $span);
            if ($tail < 2 || isset($used[$head]) || isset($used[$tail])) continue;
            $jump[$head] = $tail; $used[$head] = $used[$tail] = true; break;
        }
    }
    mt_srand();
    return $jump;
}

/** Split ids into heats of ≤SNL_HEAT_SIZE, never leaving a heat of 1. */
function snlChunkHeats(array $ids, int $size = SNL_HEAT_SIZE): array {
    $heats = array_chunk($ids, $size);
    $last  = end($heats);
    if (count($heats) > 1 && count($last) === 1) {       // pull one up from the prior heat
        $solo = array_pop($heats);
        $prev = array_pop($heats);
        $heats[] = array_slice($prev, 0, count($prev) - 1);
        $heats[] = [array_slice($prev, -1)[0], $solo[0]];
    }
    return $heats;
}

/** finishing placement → board advance. */
function snlRoll(int $placement): int { return max(1, 5 - $placement); }

/** Insert one round's heats as pending matches (+ their participant rows). */
function snlCreateRound(PDO $pdo, int $tid, int $roundNum, array $ids): void {
    $heats = snlChunkHeats($ids);
    $mIns = $pdo->prepare("
        INSERT INTO tournament_matches
            (tournament_id, round, match_number, bracket, player1_id, player2_id, status, num_participants, num_advance)
        VALUES (?, ?, ?, 'snl', ?, ?, 'pending', ?, ?)");
    $pIns = $pdo->prepare("INSERT INTO tournament_match_participants (match_id, racer_id) VALUES (?, ?)");
    foreach ($heats as $i => $heat) {
        $mIns->execute([$tid, "R{$roundNum}", $i + 1, $heat[0], $heat[1] ?? null, count($heat), count($heat)]);
        $mid = (int)$pdo->lastInsertId();
        foreach ($heat as $rid) $pIns->execute([$mid, $rid]);
    }
}

/** Round-1 generation. $participants are seeded [['id'=>..],..]. */
function generateSnlBracket(PDO $pdo, int $tid, array $participants, int $boardLen, string $chaos): void {
    if (count($participants) < 2) throw new InvalidArgumentException('Snakes & Ladders needs at least 2 racers.');
    $pdo->prepare("UPDATE tournaments SET snl_board_len = ?, snl_chaos = ? WHERE id = ?")
        ->execute([max(12, $boardLen), $chaos, $tid]);
    snlCreateRound($pdo, $tid, 1, array_map(fn($p) => (int)$p['id'], $participants));
}

/**
 * Replay all completed heats in order → board state.
 * Returns: len, jump map, positions[id]=>square, winnerId|null, winRound|null,
 * arrival[id]=>global-move-index (for tiebreaks), and per-token last hazard.
 */
function snlReplay(PDO $pdo, int $tid): array {
    $t = $pdo->query("SELECT * FROM tournaments WHERE id = " . (int)$tid)->fetch(PDO::FETCH_ASSOC);
    ['len' => $len, 'chaos' => $chaos] = snlConfig($t);
    $jump = snlBoard($tid, $len, $chaos);

    $rows = $pdo->prepare("
        SELECT m.round, m.match_number, tmp.racer_id, tmp.placement
        FROM tournament_matches m
        JOIN tournament_match_participants tmp ON tmp.match_id = m.id
        WHERE m.tournament_id = ? AND m.bracket = 'snl' AND m.status = 'completed'
          AND tmp.placement IS NOT NULL
        ORDER BY CAST(SUBSTR(m.round, 2) AS INTEGER) ASC, m.match_number ASC, tmp.placement ASC");
    $rows->execute([$tid]);

    $pos = []; $arrival = []; $lastHazard = []; $snakeHits = []; $winner = null; $winRound = null; $step = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['racer_id']; $step++;
        if (!isset($pos[$id])) $pos[$id] = 0;
        if ($pos[$id] >= $len) continue;                 // already home
        $raw    = $pos[$id] + snlRoll((int)$r['placement']);
        $landed = ($raw > $len) ? $len - ($raw - $len) : $raw;
        if (isset($jump[$landed])) {
            $lastHazard[$id] = [$landed, $jump[$landed]];
            if ($jump[$landed] < $landed) $snakeHits[$id] = ($snakeHits[$id] ?? 0) + 1;   // a snake sends you backwards
            $landed = $jump[$landed];
        }
        $pos[$id] = $landed; $arrival[$id] = $step;
        if ($pos[$id] === $len && $winner === null) { $winner = $id; $winRound = (int)substr($r['round'], 1); }
    }
    return compact('len', 'jump', 'pos', 'winner', 'winRound', 'arrival', 'lastHazard', 'snakeHits');
}

/** Is the latest round fully recorded? [allDone, roundNum, totalHeats, doneHeats] */
function snlLatestRoundStatus(PDO $pdo, int $tid): array {
    $r = $pdo->prepare("
        SELECT round,
               COUNT(*) AS total,
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done
        FROM tournament_matches
        WHERE tournament_id = ? AND bracket = 'snl'
        GROUP BY round
        ORDER BY CAST(SUBSTR(round, 2) AS INTEGER) DESC
        LIMIT 1");
    $r->execute([$tid]);
    $row = $r->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, 0, 0, 0];
    return [(int)$row['done'] === (int)$row['total'], (int)substr($row['round'], 1), (int)$row['total'], (int)$row['done']];
}

/**
 * Advance after a heat is recorded. No-op until the whole round is in. Then
 * either finish the tournament (a token reached home) or spawn the next round.
 */
function advanceSnl(PDO $pdo, int $tid): array {
    [$allDone, $roundNum] = snlLatestRoundStatus($pdo, $tid);
    if (!$allDone) return ['status' => 'awaiting_heats'];

    $st = snlReplay($pdo, $tid);

    if ($st['winner'] !== null) {
        snlFinalize($pdo, $tid, $st);
        return ['status' => 'completed', 'winner_id' => $st['winner']];
    }

    // Next round — everyone races again; rotate the order so heats vary.
    $ids = array_map('intval', $pdo->query(
        "SELECT racer_id FROM tournament_participants WHERE tournament_id = " . (int)$tid . " ORDER BY seed ASC")
        ->fetchAll(PDO::FETCH_COLUMN));
    $k   = $roundNum % max(1, count($ids));
    $ids = array_merge(array_slice($ids, $k), array_slice($ids, 0, $k));
    // Seed leaders into the same heats by sorting on board position, then rotate.
    usort($ids, fn($a, $b) => ($st['pos'][$b] ?? 0) <=> ($st['pos'][$a] ?? 0));
    snlCreateRound($pdo, $tid, $roundNum + 1, $ids);
    return ['status' => 'next_round', 'round' => $roundNum + 1];
}

/** Write final standings + trophies when a token reaches home. */
function snlFinalize(PDO $pdo, int $tid, array $st): void {
    $ranked = [];
    foreach ($st['pos'] as $id => $sq) $ranked[] = ['id' => $id, 'sq' => $sq, 'arr' => $st['arrival'][$id] ?? PHP_INT_MAX];
    // furthest square first; if tied, whoever got there in fewer moves
    usort($ranked, fn($a, $b) => ($b['sq'] <=> $a['sq']) ?: ($a['arr'] <=> $b['arr']));

    $place = $pdo->prepare("UPDATE tournament_participants SET final_placement = ? WHERE tournament_id = ? AND racer_id = ?");
    foreach ($ranked as $i => $row) $place->execute([$i + 1, $tid, $row['id']]);

    $pdo->prepare("UPDATE tournaments SET status = 'completed', winner_id = ?, end_date = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$st['winner'], $tid]);

    $pdo->prepare("DELETE FROM tournament_trophies WHERE tournament_id = ?")->execute([$tid]);
    $trophy = $pdo->prepare("INSERT INTO tournament_trophies (tournament_id, racer_id, placement, trophy_type) VALUES (?, ?, ?, ?)");
    $types = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];
    foreach (array_slice($ranked, 0, 3) as $i => $row) $trophy->execute([$tid, $row['id'], $i + 1, $types[$i + 1]]);
}

/**
 * Render the live board panel (standings chips + serpentine grid + tokens).
 * Shared by the admin bracket view and the public tournament view so they
 * never drift. $footer is an optional caption line under the board.
 */
function snlBoardHtml(PDO $pdo, int $tid, ?string $footer = null): string {
    $snl   = snlReplay($pdo, $tid);
    $names = $pdo->query("SELECT id, name FROM racers")->fetchAll(PDO::FETCH_KEY_PAIR);
    $len   = $snl['len'];

    $tokensAt = [];
    foreach ($snl['pos'] as $rid => $sq) $tokensAt[$sq][] = $names[$rid] ?? ('#' . $rid);
    $stand = $snl['pos']; arsort($stand);

    // Endpoint roles so both ends of each hazard are marked, not just the start.
    $role = [];   // square => 'foot'|'top'|'head'|'tail'
    $nLad = 0; $nSnk = 0;
    foreach ($snl['jump'] as $from => $to) {
        if ($to > $from) { $role[$from] = 'foot'; $role[$to] = 'top';  $nLad++; }
        else             { $role[$from] = 'head'; $role[$to] = 'tail'; $nSnk++; }
    }

    // Boustrophedon matrix — square 1 bottom-left, snaking UP (even logical
    // rows L→R, odd rows R→L). Building a full rows×cols grid (with nulls in
    // the partial top row) keeps the columns aligned under CSS auto-flow.
    $cols = min(10, max(5, (int)ceil(sqrt($len))));
    $rows = (int)ceil($len / $cols);
    $mx = array_fill(0, $rows, array_fill(0, $cols, null));
    for ($s = 1; $s <= $len; $s++) {
        $lr = intdiv($s - 1, $cols);
        $i  = ($s - 1) % $cols;
        $vc = ($lr % 2 === 0) ? $i : ($cols - 1 - $i);
        $mx[($rows - 1) - $lr][$vc] = $s;
    }

    ob_start(); ?>
    <div class="snl-board-card">
        <header class="snl-head">
            <h2>🐍 Snakes &amp; Ladders 🪜</h2>
            <span class="snl-sub">First token to land <strong>exactly</strong> on square <?= $len ?> wins ·
                <?= $nLad ?> ladders, <?= $nSnk ?> snakes · finish a heat → 1st = +4, 2nd = +3, 3rd = +2, 4th = +1</span>
        </header>
        <div class="snl-standings">
            <?php $rk = 0; foreach ($stand as $rid => $sq): $rk++; ?>
                <span class="snl-chip <?= $sq == $len ? 'snl-chip--home' : '' ?>">
                    <b><?= $rk ?>.</b> <?= htmlspecialchars($names[$rid] ?? ('#'.$rid)) ?>
                    <span class="snl-chip-sq"><?= $sq == $len ? '🏁' : $sq ?></span>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="snl-board" data-jumps='<?= htmlspecialchars(json_encode((object)$snl['jump']), ENT_QUOTES) ?>'>
            <svg class="snl-overlay" preserveAspectRatio="none"></svg>
            <div class="snl-grid" style="grid-template-columns: repeat(<?= $cols ?>, 1fr);">
                <?php foreach ($mx as $rowCells): ?>
                    <?php foreach ($rowCells as $n): ?>
                        <?php if ($n === null): ?>
                            <div class="snl-cell snl-empty"></div>
                        <?php else:
                            $r = $role[$n] ?? null;
                            $cls = $n == $len ? 'snl-finish'
                                 : ($r === 'foot' || $r === 'top' ? 'snl-ladder'
                                 : ($r === 'head' || $r === 'tail' ? 'snl-snake' : ''));
                        ?>
                        <div class="snl-cell <?= $cls ?>" data-sq="<?= $n ?>">
                            <span class="snl-num"><?= $n ?></span>
                            <?php if ($n == $len): ?><span class="snl-glyph">🏁</span>
                            <?php elseif ($r === 'foot'): ?><span class="snl-glyph">🪜</span>
                            <?php elseif ($r === 'head'): ?><span class="snl-glyph">🐍</span><?php endif; ?>
                            <?php if (!empty($tokensAt[$n])): ?>
                                <span class="snl-tokens"><?php foreach ($tokensAt[$n] as $tn): ?><span class="snl-token" title="<?= htmlspecialchars($tn) ?>"><?= htmlspecialchars(mb_substr($tn,0,3)) ?></span><?php endforeach; ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($footer !== null): ?><p class="snl-foot"><?= htmlspecialchars($footer) ?></p><?php endif; ?>
    </div>
    <style>
    .snl-board-card { background:var(--gray-50); border:2.5px solid var(--ink); border-radius:16px; box-shadow:4px 4px 0 var(--ink); padding:20px 24px; margin-bottom:24px; }
    .snl-head { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
    .snl-head h2 { margin:0; font-size:1.4rem; }
    .snl-sub { color:var(--gray-600); font-size:0.85rem; max-width:560px; }
    .snl-standings { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
    .snl-chip { background:var(--gray-100); border:2px solid var(--ink); border-radius:999px; padding:3px 12px; font-size:0.85rem; box-shadow:2px 2px 0 var(--ink); }
    .snl-chip--home { background:#fff6dc; }
    .snl-chip-sq { font-family:var(--font-mono); font-weight:700; margin-left:4px; }
    .snl-board { position:relative; }
    .snl-overlay { position:absolute; inset:0; width:100%; height:100%; z-index:0; pointer-events:none; overflow:visible; }
    .snl-grid { position:relative; z-index:1; display:grid; gap:5px; }
    .snl-cell { position:relative; aspect-ratio:1; border:2px solid var(--ink); border-radius:8px; background:transparent; padding:3px; display:flex; flex-direction:column; min-height:54px; }
    .snl-empty { border:none; background:transparent; }
    .snl-num { font-family:var(--font-mono); font-size:0.62rem; color:var(--gray-500); font-weight:700; }
    .snl-glyph { font-size:0.8rem; line-height:1; }
    .snl-ladder { background:rgba(46,189,89,0.12); }
    .snl-snake  { background:rgba(230,0,18,0.10); }
    .snl-finish { background:#fff6dc; box-shadow:inset 0 0 0 2px var(--gold); }
    .snl-tokens { position:relative; z-index:2; margin-top:auto; display:flex; flex-wrap:wrap; gap:2px; }
    .snl-token { background:var(--nintendo-red); color:#fff; border:1.5px solid var(--ink); border-radius:6px; font-size:0.6rem; font-weight:800; padding:1px 4px; line-height:1.3; }
    .snl-foot { color:var(--gray-500); font-size:0.8rem; font-style:italic; margin-top:14px; }
    </style>
    <script>
    if (!window.__snlBoardInit) {
        window.__snlBoardInit = true;
        function __snlCenter(grid, br, sq) {
            const el = grid.querySelector('[data-sq="' + sq + '"]');
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return { x: r.left - br.left + r.width / 2, y: r.top - br.top + r.height / 2 };
        }
        function __snlLadder(a, b) {
            const dx = b.x - a.x, dy = b.y - a.y, L = Math.hypot(dx, dy) || 1;
            const ux = dx / L, uy = dy / L, px = -uy, py = ux, w = 7;
            const rail = (s) => `<line x1="${a.x+px*s}" y1="${a.y+py*s}" x2="${b.x+px*s}" y2="${b.y+py*s}" stroke="#7a4a12" stroke-width="3.5" stroke-linecap="round"/>`;
            let rungs = ''; const n = Math.max(2, Math.floor(L / 16));
            for (let i = 1; i < n; i++) { const cx = a.x + dx*i/n, cy = a.y + dy*i/n;
                rungs += `<line x1="${cx+px*w}" y1="${cy+py*w}" x2="${cx-px*w}" y2="${cy-py*w}" stroke="#b5791f" stroke-width="3" stroke-linecap="round"/>`; }
            return `<g opacity="0.9">${rail(w)}${rail(-w)}${rungs}</g>`;
        }
        function __snlSnake(a, b) {
            // a = head (higher square), b = tail. Wavy body + a little head.
            const dx = b.x - a.x, dy = b.y - a.y, L = Math.hypot(dx, dy) || 1;
            const px = -dy / L, py = dx / L, amp = Math.min(28, L * 0.22);
            const c1 = `${a.x + dx*0.33 + px*amp},${a.y + dy*0.33 + py*amp}`;
            const c2 = `${a.x + dx*0.66 - px*amp},${a.y + dy*0.66 - py*amp}`;
            const d = `M ${a.x} ${a.y} C ${c1} ${c2} ${b.x} ${b.y}`;
            return `<g opacity="0.92">
                <path d="${d}" fill="none" stroke="#a01018" stroke-width="7" stroke-linecap="round"/>
                <path d="${d}" fill="none" stroke="#ff6b6b" stroke-width="3" stroke-linecap="round" stroke-dasharray="2 6"/>
                <circle cx="${a.x}" cy="${a.y}" r="7.5" fill="#a01018"/>
                <circle cx="${a.x-2.5}" cy="${a.y-2}" r="1.6" fill="#fff"/>
                <circle cx="${a.x+2.5}" cy="${a.y-2}" r="1.6" fill="#fff"/>
            </g>`;
        }
        function __snlDraw(board) {
            const grid = board.querySelector('.snl-grid'), svg = board.querySelector('.snl-overlay');
            if (!grid || !svg) return;
            const br = board.getBoundingClientRect();
            svg.setAttribute('viewBox', `0 0 ${br.width} ${br.height}`);
            const jumps = JSON.parse(board.getAttribute('data-jumps') || '{}');
            let out = '';
            for (const f in jumps) {
                const a = __snlCenter(grid, br, f), b = __snlCenter(grid, br, jumps[f]);
                if (!a || !b) continue;
                out += (+jumps[f] > +f) ? __snlLadder(a, b) : __snlSnake(a, b);
            }
            svg.innerHTML = out;
        }
        function __snlDrawAll() { document.querySelectorAll('.snl-board').forEach(__snlDraw); }
        window.addEventListener('load', __snlDrawAll);
        let __snlT; window.addEventListener('resize', () => { clearTimeout(__snlT); __snlT = setTimeout(__snlDrawAll, 120); });
        if (document.readyState !== 'loading') setTimeout(__snlDrawAll, 60);
    }
    </script>
    <?php
    return ob_get_clean();
}
