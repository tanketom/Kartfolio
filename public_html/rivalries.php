<?php
/**
 * The Nemesis Matrix - Enhanced Rivalry Index + Head-to-Head Comparison
 * Path: /cdnmk/public_html/rivalries.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$currentSeason = getCurrentSeasonNumber();

// Head-to-Head comparison params (from picker form or /compare redirect)
$racerA = $_GET['a'] ?? null;
$racerB = $_GET['b'] ?? null;
$allRacers = $pdo->query("SELECT id, name FROM racers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = "Nemesis Index - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';

// 1. Fetch only racers who participated in current season
$racersStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
    ORDER BY r.name ASC
");
$racersStmt->execute([$currentSeason . "%"]);
$racers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);
$matrix = [];
$feuds = [];

// 2. NEMESIS OF THE WEEK (Exact same logic as index.php)
$topNemesis = null;
try {
    $feudStmt = $pdo->prepare("
        SELECT r1.name as p1, r2.name as p2, 
               COUNT(*) as meetings,
               SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) as p1_wins
        FROM results res1
        JOIN results res2 ON res1.gpid = res2.gpid AND res1.cup_name = res2.cup_name
        JOIN racers r1 ON res1.racer_id = r1.id
        JOIN racers r2 ON res2.racer_id = r2.id
        WHERE res1.racer_id < res2.racer_id 
          AND res1.gpid LIKE ?
        GROUP BY res1.racer_id, res2.racer_id
        HAVING meetings >= 2
        ORDER BY (COUNT(*) * (1.0 - ABS((CAST(SUM(CASE WHEN res1.rank < res2.rank THEN 1 ELSE 0 END) AS FLOAT) / COUNT(*)) - 0.5) * 2.0)) DESC
        LIMIT 1
    ");
    $feudStmt->execute([$currentSeason . "%"]);
    $topNemesis = $feudStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fail silently
}

// 3. Build Matrix & Existing Feuds Logic — every pair from the season cache
//    (seasonMatchups). This was a COUNT + a history query per ordered pair.
$matchups = seasonMatchups($pdo, $currentSeason);
foreach ($racers as $p1) {
    foreach ($racers as $p2) {
        if ($p1['id'] == $p2['id']) continue;
        $data = $matchups[(int)$p1['id']][(int)$p2['id']] ?? null;

        if ($data && $data['total'] > 0) {
            $rate = ($data['wins'] / $data['total']) * 100;

            $matrix[$p1['id']][$p2['id']] = [
                'rate' => $rate,
                'wins' => $data['wins'],
                'total' => $data['total'],
                'history' => $data['history']
            ];
            
            if ($p1['id'] < $p2['id']) {
                $closeness = 1 - (abs($rate - 50) / 50);
                $feuds[] = [
                    'p1' => $p1['name'],
                    'p2' => $p2['name'],
                    'intensity' => $data['total'] * $closeness,
                    'total' => $data['total']
                ];
            }
        }
    }
}
usort($feuds, fn($a, $b) => $b['intensity'] <=> $a['intensity']);
?>

<div class="stats-container">
    <h1 class="rivals-page-title">Nemesis Index</h1>

    <?php if ($topNemesis): ?>
    <div class="racer-card rivals-nemesis-card">
        <div class="rivals-live-badge">LIVE TREND</div>
        <h2 class="rivals-nemesis-label">Nemesis of the Week</h2>
        <div class="rivals-nemesis-names">
            <?= htmlspecialchars($topNemesis['p1']) ?> <span class="vs-text">VS</span> <?= htmlspecialchars($topNemesis['p2']) ?>
        </div>
        <p class="rivals-nemesis-subtext">Locked in a tight struggle over <?= $topNemesis['meetings'] ?> meetings.</p>
    </div>
    <?php endif; ?>

    <h3 class="rivals-section-label">Other Intense Rivalries</h3>
    <div class="rivals-grid">
        <?php foreach (array_slice($feuds, 0, 3) as $f): ?>
            <div class="racer-card rivals-feud-card">
                <div class="rivals-feud-names"><?= $f['p1'] ?> <span class="vs-text">vs</span> <?= $f['p2'] ?></div>
                <div class="rivals-feud-count"><?= $f['total'] ?> Races Shared</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="rivals-matrix-wrap">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th class="matrix-corner">vs.</th>
                    <?php foreach ($racers as $r): ?>
                        <th class="rotate"><div><span><?= htmlspecialchars($r['name']) ?></span></div></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($racers as $rowRacer): ?>
                <tr>
                    <th class="row-header"><?= htmlspecialchars($rowRacer['name']) ?></th>
                    <?php foreach ($racers as $colRacer): ?>
                        <?php 
                            $d = $matrix[$rowRacer['id']][$colRacer['id']] ?? null;
                            if ($rowRacer['id'] == $colRacer['id']) { echo "<td class='matrix-cell--self'></td>"; continue; }
                            
                            $alpha = 0.1; $bg = "rgba(0,0,0,0.03)"; 
                            if ($d) {
                                if ($d['rate'] >= 50) {
                                    $alpha = ($d['rate'] - 50) / 50;
                                    $bg = "rgba(46, 189, 89, $alpha)";
                                } else {
                                    $alpha = (50 - $d['rate']) / 50;
                                    $bg = "rgba(230, 0, 18, $alpha)";
                                }
                            }
                        ?>
                        <td style="background:<?= $bg ?>; font-weight: <?= ($d && $d['rate'] > 50) ? '900' : '400' ?>; cursor: <?= $d ? 'pointer' : 'default' ?>;"
                            <?= $d ? 'class="matchup-cell" data-p1="'.htmlspecialchars($rowRacer['name']).'" data-p2="'.htmlspecialchars($colRacer['name']).'" data-wins="'.$d['wins'].'" data-total="'.$d['total'].'" data-history="'.htmlspecialchars(json_encode($d['history'])).'"' : '' ?>>
                            <?= $d ? round($d['rate'])."%" : "-" ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Rivalry Web Graph (embedded) -->
    <?php if (count($racers) >= 2):
        // Build nodes with GP count
        $rwebNodes = [];
        foreach ($racers as $r) {
            $rwebNodes[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'gps' => racerSeasonGpCount($pdo, (int)$r['id'], $currentSeason)];
        }
        // Build links for each unique pair
        $rwebLinks = [];
        $rCount = count($racers);
        for ($i = 0; $i < $rCount; $i++) {
            for ($j = $i + 1; $j < $rCount; $j++) {
                $p1 = $racers[$i]; $p2 = $racers[$j];
                $lData  = $matchups[(int)$p1['id']][(int)$p2['id']] ?? ['total' => 0, 'wins' => 0];
                $lTotal = (int)$lData['total'];
                if ($lTotal > 0) {
                    $rwebLinks[] = [
                        'source' => (int)$p1['id'], 'target' => (int)$p2['id'],
                        'matchups' => $lTotal, 'p1_wins' => (int)$lData['wins'],
                        'p2_wins' => $lTotal - (int)$lData['wins'],
                        'p1_name' => $p1['name'], 'p2_name' => $p2['name']
                    ];
                }
            }
        }
    ?>
    <h3 class="rivals-section-label" style="margin-top: 40px;">Rivalry Web</h3>
    <div class="racer-card rweb-graph-card" style="margin-bottom: 30px;">
        <div class="rweb-controls">
            <label class="rweb-slider-label">Min Matchups: <span id="rweb-min-val">1</span>
                <input type="range" id="rweb-min-matchups" min="1" max="20" value="1">
            </label>
        </div>
        <div id="rweb-graph" class="rweb-graph-container"></div>
    </div>

    <div id="rweb-detail" class="racer-card rweb-detail-panel" style="display:none;">
        <h3 id="rweb-detail-title"></h3>
        <div id="rweb-detail-content"></div>
        <button class="rweb-close-btn" onclick="document.getElementById('rweb-detail').style.display='none'">Close</button>
    </div>
    <div id="rweb-tooltip" class="rweb-tooltip" style="display:none;position:fixed;pointer-events:none;z-index:100;"></div>
    <?php endif; ?>

</div>

<!-- Matchup Modal -->
<div id="matchupModal" class="rivals-modal">
    <div class="rivals-modal-inner">
        <button onclick="closeModal()" class="rivals-modal-close">&times;</button>
        <h2 id="modalTitle" class="rivals-modal-title"></h2>
        <p id="modalSubtitle" class="rivals-modal-subtitle"></p>
        <div id="modalContent"></div>
    </div>
</div>

<script>
document.querySelectorAll('.matchup-cell').forEach(cell => {
    cell.addEventListener('click', function() {
        const p1 = this.dataset.p1;
        const p2 = this.dataset.p2;
        const wins = this.dataset.wins;
        const total = this.dataset.total;
        const history = JSON.parse(this.dataset.history);

        document.getElementById('modalTitle').textContent = `${p1} vs ${p2}`;
        document.getElementById('modalSubtitle').textContent = `Head-to-head record: ${wins}-${total - wins} (${Math.round((wins/total)*100)}% win rate)`;

        let content = '<div>';
        history.forEach(match => {
            const isWin = match.p1_rank < match.p2_rank;
            const rowClass = isWin ? 'win' : 'loss';
            const date = new Date(match.race_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

            content += `
                <div class="matchup-row ${rowClass}">
                    <div>
                        <div class="matchup-date">${date}</div>
                        <div class="matchup-cup">${match.cup_name} Cup</div>
                        <div class="matchup-gpid">${match.gpid.toUpperCase()}</div>
                    </div>
                    <div class="matchup-ranks">
                        <div>
                            <div class="matchup-rank-label">RANK</div>
                            <div class="matchup-rank ${isWin ? 'matchup-rank--win' : 'matchup-rank--loss'}">#${match.p1_rank}</div>
                        </div>
                        <div class="matchup-vs-sep">vs</div>
                        <div>
                            <div class="matchup-rank-label">RANK</div>
                            <div class="matchup-rank ${!isWin ? 'matchup-rank--win' : 'matchup-rank--loss'}">#${match.p2_rank}</div>
                        </div>
                        <div class="matchup-points-wrap">
                            <div class="matchup-points-label">POINTS</div>
                            <div class="matchup-points-value">${match.p1_points} - ${match.p2_points}</div>
                        </div>
                    </div>
                </div>
            `;
        });
        content += '</div>';

        document.getElementById('modalContent').innerHTML = content;
        document.getElementById('matchupModal').style.display = 'block';
    });
});

function closeModal() {
    document.getElementById('matchupModal').style.display = 'none';
}

// Close modal on outside click
document.getElementById('matchupModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});
</script>

<?php if (count($racers) >= 2): ?>
<script src="https://d3js.org/d3.v7.min.js"></script>
<script>
(function() {
    const colorPalette = [
        'var(--nintendo-red)', '#0066CC', '#2EBD59', '#FF8C00', '#8B5CF6',
        '#EC4899', '#14B8A6', '#F59E0B', '#6366F1', '#EF4444',
        '#10B981', '#3B82F6', '#F97316', '#A855F7', '#06B6D4',
        '#D946EF', '#84CC16', '#FB923C'
    ];

    const allNodes = <?= jsonForScript($rwebNodes) ?>;
    const allLinks = <?= jsonForScript($rwebLinks) ?>;

    const nodeColorMap = {};
    allNodes.forEach((n, i) => { nodeColorMap[n.id] = colorPalette[i % colorPalette.length]; });

    const container = document.getElementById('rweb-graph');
    if (!container) return;

    const tooltip = document.getElementById('rweb-tooltip');
    const detailPanel = document.getElementById('rweb-detail');
    const detailTitle = document.getElementById('rweb-detail-title');
    const detailContent = document.getElementById('rweb-detail-content');
    const slider = document.getElementById('rweb-min-matchups');
    const sliderVal = document.getElementById('rweb-min-val');

    let width = container.clientWidth;
    let height = 600;

    const svg = d3.select('#rweb-graph').append('svg').attr('width', width).attr('height', height);
    svg.append('defs');

    const linkGroup = svg.append('g').attr('class', 'rweb-links');
    const nodeGroup = svg.append('g').attr('class', 'rweb-nodes');
    const labelGroup = svg.append('g').attr('class', 'rweb-labels');

    let nodes = allNodes.map(d => Object.assign({}, d));
    let links = allLinks.map(d => Object.assign({}, d));

    const simulation = d3.forceSimulation(nodes)
        .force('link', d3.forceLink(links).id(d => d.id).distance(d => Math.max(80, 180 - d.matchups * 3)))
        .force('charge', d3.forceManyBody().strength(-400))
        .force('center', d3.forceCenter(width / 2, height / 2))
        .force('collision', d3.forceCollide().radius(45));

    function getLinkColor(link) {
        const total = link.matchups;
        if (total === 0) return '#ccc';
        const p1Rate = link.p1_wins / total;
        const p2Rate = link.p2_wins / total;
        if (Math.abs(p1Rate - p2Rate) < 0.15) return '#aaa';
        if (p1Rate > p2Rate) return nodeColorMap[link.source.id !== undefined ? link.source.id : link.source] || '#aaa';
        return nodeColorMap[link.target.id !== undefined ? link.target.id : link.target] || '#aaa';
    }

    function nodeRadius(d) { return Math.max(15, Math.min(35, 10 + d.gps)); }

    let linkElements = linkGroup.selectAll('line').data(links).join('line')
        .attr('stroke', d => getLinkColor(d))
        .attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2)))
        .attr('stroke-opacity', 0.6).style('cursor', 'pointer');

    let nodeElements = nodeGroup.selectAll('circle').data(nodes).join('circle')
        .attr('r', d => nodeRadius(d)).attr('fill', d => nodeColorMap[d.id])
        .attr('stroke', '#fff').attr('stroke-width', 2).style('cursor', 'pointer');

    let labelElements = labelGroup.selectAll('text').data(nodes).join('text')
        .text(d => d.name).attr('text-anchor', 'middle')
        .attr('dy', d => nodeRadius(d) + 14).attr('font-size', '11px')
        .attr('font-weight', '700').attr('fill', '#333').attr('pointer-events', 'none');

    simulation.on('tick', () => {
        linkElements.attr('x1', d => d.source.x).attr('y1', d => d.source.y)
            .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
        nodeElements.attr('cx', d => d.x = Math.max(nodeRadius(d), Math.min(width - nodeRadius(d), d.x)))
            .attr('cy', d => d.y = Math.max(nodeRadius(d), Math.min(height - nodeRadius(d), d.y)));
        labelElements.attr('x', d => d.x).attr('y', d => d.y);
    });

    const drag = d3.drag()
        .on('start', function(event, d) { if (!event.active) simulation.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
        .on('drag', function(event, d) { d.fx = event.x; d.fy = event.y; })
        .on('end', function(event, d) { if (!event.active) simulation.alphaTarget(0); d.fx = null; d.fy = null; });
    nodeElements.call(drag);

    function showTooltip(html, event) {
        tooltip.innerHTML = html; tooltip.style.display = 'block';
        tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px';
        tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px';
    }
    function hideTooltip() { tooltip.style.display = 'none'; }

    nodeElements
        .on('mouseover', function(event, d) {
            const connLinks = links.filter(l => l.source.id === d.id || l.target.id === d.id);
            const cIds = new Set([d.id]);
            connLinks.forEach(l => { cIds.add(l.source.id); cIds.add(l.target.id); });
            nodeElements.attr('opacity', n => cIds.has(n.id) ? 1 : 0.15);
            labelElements.attr('opacity', n => cIds.has(n.id) ? 1 : 0.15);
            linkElements.attr('stroke-opacity', l => (l.source.id === d.id || l.target.id === d.id) ? 1.0 : 0.05)
                .attr('stroke-width', l => (l.source.id === d.id || l.target.id === d.id) ? Math.min(10, Math.max(2, l.matchups / 2 + 1)) : Math.min(8, Math.max(1, l.matchups / 2)));
            let tw = 0, tm = 0;
            connLinks.forEach(l => { tm += l.matchups; tw += (l.source.id === d.id) ? l.p1_wins : l.p2_wins; });
            showTooltip('<strong>' + d.name + '</strong><br>GPs: ' + d.gps + '<br>Win rate: ' + (tm > 0 ? Math.round((tw / tm) * 100) : 0) + '%', event);
        })
        .on('mousemove', function(event) { tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px'; tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px'; })
        .on('mouseout', function() {
            nodeElements.attr('opacity', 1); labelElements.attr('opacity', 1);
            linkElements.attr('stroke-opacity', 0.6).attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2)));
            hideTooltip();
        });

    nodeElements.on('click', function(event, d) {
        event.stopPropagation();
        const connLinks = links.filter(l => l.source.id === d.id || l.target.id === d.id);
        detailTitle.textContent = d.name + ' \u2014 Head-to-Head Records';
        if (connLinks.length === 0) { detailContent.innerHTML = '<p style="color:#888;">No matchups.</p>'; }
        else {
            connLinks.sort((a, b) => b.matchups - a.matchups);
            let html = '<table class="rweb-detail-table"><thead><tr><th>Opponent</th><th>Matchups</th><th>Wins</th><th>Losses</th><th>Win Rate</th></tr></thead><tbody>';
            connLinks.forEach(l => {
                let opp, w, lo;
                if (l.source.id === d.id) { opp = l.p2_name; w = l.p1_wins; lo = l.p2_wins; } else { opp = l.p1_name; w = l.p2_wins; lo = l.p1_wins; }
                const rt = l.matchups > 0 ? Math.round((w / l.matchups) * 100) : 0;
                html += '<tr><td>' + opp + '</td><td>' + l.matchups + '</td><td>' + w + '</td><td>' + lo + '</td><td><span class="rweb-winrate-bar" style="width:' + Math.max(4, rt * 0.8) + 'px;background:' + (rt >= 50 ? '#2EBD59' : 'var(--nintendo-red)') + ';"></span>' + rt + '%</td></tr>';
            });
            html += '</tbody></table>';
            detailContent.innerHTML = html;
        }
        detailPanel.style.display = 'flex';
        detailPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    linkElements
        .on('mouseover', function(event, d) { showTooltip('<strong>' + d.p1_name + ' vs ' + d.p2_name + '</strong><br>' + d.p1_wins + '&ndash;' + d.p2_wins + ' (' + d.matchups + ' matchups)', event); })
        .on('mousemove', function(event) { tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px'; tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px'; })
        .on('mouseout', function() { hideTooltip(); });

    // Min matchups slider
    slider.addEventListener('input', function() {
        const minVal = parseInt(this.value);
        sliderVal.textContent = minVal;
        const filteredLinks = allLinks.filter(l => l.matchups >= minVal);
        const connectedIds = new Set();
        filteredLinks.forEach(l => { connectedIds.add(l.source.id !== undefined ? l.source.id : l.source); connectedIds.add(l.target.id !== undefined ? l.target.id : l.target); });

        nodes = allNodes.map(d => {
            const existing = simulation.nodes().find(n => n.id === d.id);
            return existing ? Object.assign({}, d, { x: existing.x, y: existing.y, vx: existing.vx, vy: existing.vy }) : Object.assign({}, d);
        });
        links = filteredLinks.map(d => Object.assign({}, d));
        simulation.nodes(nodes);
        simulation.force('link').links(links);

        linkElements = linkGroup.selectAll('line').data(links, d => { const s = d.source.id !== undefined ? d.source.id : d.source; const t = d.target.id !== undefined ? d.target.id : d.target; return s + '-' + t; }).join('line')
            .attr('stroke', d => getLinkColor(d)).attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2))).attr('stroke-opacity', 0.6).style('cursor', 'pointer');
        nodeElements = nodeGroup.selectAll('circle').data(nodes, d => d.id).join('circle')
            .attr('r', d => nodeRadius(d)).attr('fill', d => nodeColorMap[d.id]).attr('stroke', '#fff').attr('stroke-width', 2).attr('opacity', d => connectedIds.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2).style('cursor', 'pointer');
        labelElements = labelGroup.selectAll('text').data(nodes, d => d.id).join('text')
            .text(d => d.name).attr('text-anchor', 'middle').attr('dy', d => nodeRadius(d) + 14).attr('font-size', '11px').attr('font-weight', '700').attr('fill', '#333').attr('pointer-events', 'none').attr('opacity', d => connectedIds.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2);

        nodeElements.call(drag);
        // Re-attach events
        nodeElements
            .on('mouseover', function(event, d) {
                const cl = links.filter(l => l.source.id === d.id || l.target.id === d.id);
                const ci = new Set([d.id]); cl.forEach(l => { ci.add(l.source.id); ci.add(l.target.id); });
                nodeElements.attr('opacity', n => ci.has(n.id) ? 1 : 0.15); labelElements.attr('opacity', n => ci.has(n.id) ? 1 : 0.15);
                linkElements.attr('stroke-opacity', l => (l.source.id === d.id || l.target.id === d.id) ? 1.0 : 0.05)
                    .attr('stroke-width', l => (l.source.id === d.id || l.target.id === d.id) ? Math.min(10, Math.max(2, l.matchups / 2 + 1)) : Math.min(8, Math.max(1, l.matchups / 2)));
                let tw2 = 0, tm2 = 0; cl.forEach(l => { tm2 += l.matchups; tw2 += (l.source.id === d.id) ? l.p1_wins : l.p2_wins; });
                showTooltip('<strong>' + d.name + '</strong><br>GPs: ' + d.gps + '<br>Win rate: ' + (tm2 > 0 ? Math.round((tw2 / tm2) * 100) : 0) + '%', event);
            })
            .on('mousemove', function(event) { tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px'; tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px'; })
            .on('mouseout', function() {
                const ci2 = new Set(); filteredLinks.forEach(l => { ci2.add(l.source.id !== undefined ? l.source.id : l.source); ci2.add(l.target.id !== undefined ? l.target.id : l.target); });
                nodeElements.attr('opacity', d => ci2.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2); labelElements.attr('opacity', d => ci2.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2);
                linkElements.attr('stroke-opacity', 0.6).attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2))); hideTooltip();
            })
            .on('click', function(event, d) {
                event.stopPropagation();
                const cl = links.filter(l => l.source.id === d.id || l.target.id === d.id);
                detailTitle.textContent = d.name + ' \u2014 Head-to-Head Records';
                if (cl.length === 0) { detailContent.innerHTML = '<p style="color:#888;">No matchups.</p>'; } else {
                    cl.sort((a, b) => b.matchups - a.matchups);
                    let h = '<table class="rweb-detail-table"><thead><tr><th>Opponent</th><th>Matchups</th><th>Wins</th><th>Losses</th><th>Win Rate</th></tr></thead><tbody>';
                    cl.forEach(l => { let o,w,lo; if(l.source.id===d.id){o=l.p2_name;w=l.p1_wins;lo=l.p2_wins;}else{o=l.p1_name;w=l.p2_wins;lo=l.p1_wins;} const r=l.matchups>0?Math.round((w/l.matchups)*100):0; h+='<tr><td>'+o+'</td><td>'+l.matchups+'</td><td>'+w+'</td><td>'+lo+'</td><td><span class="rweb-winrate-bar" style="width:'+Math.max(4,r*0.8)+'px;background:'+(r>=50?'#2EBD59':'var(--nintendo-red)')+';"></span>'+r+'%</td></tr>'; });
                    h += '</tbody></table>'; detailContent.innerHTML = h;
                }
                detailPanel.style.display = 'flex'; detailPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        linkElements
            .on('mouseover', function(event, d) { showTooltip('<strong>' + d.p1_name + ' vs ' + d.p2_name + '</strong><br>' + d.p1_wins + '&ndash;' + d.p2_wins + ' (' + d.matchups + ' matchups)', event); })
            .on('mousemove', function(event) { tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px'; tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px'; })
            .on('mouseout', function() { hideTooltip(); });

        simulation.alpha(0.5).restart();
    });

    if (allLinks.length > 0) { slider.max = Math.max(1, Math.max(...allLinks.map(l => l.matchups))); }

    window.addEventListener('resize', function() {
        width = container.clientWidth;
        svg.attr('width', width);
        simulation.force('center', d3.forceCenter(width / 2, height / 2));
        simulation.alpha(0.3).restart();
    });
})();
</script>
<?php endif; ?>

<!-- ================================================================
     HEAD-TO-HEAD COMPARISON
     ================================================================ -->
<div class="stats-container" id="head-to-head">
    <h2 class="rivals-page-title rivals-h2h-title">Head-to-Head</h2>
    <p class="rivals-h2h-subtitle">Compare two racers side by side.</p>

    <!-- Racer Picker -->
    <div class="compare-picker">
        <form method="GET" action="/rivalries" class="compare-picker-form">
            <div class="compare-picker-slot">
                <label>Racer A</label>
                <select name="a" required>
                    <option value="">Select racer...</option>
                    <?php foreach ($allRacers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $racerA == $r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="compare-picker-vs">VS</div>
            <div class="compare-picker-slot">
                <label>Racer B</label>
                <select name="b" required>
                    <option value="">Select racer...</option>
                    <?php foreach ($allRacers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $racerB == $r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Compare</button>
        </form>
    </div>

<?php if ($racerA && $racerB && $racerA != $racerB):
    // Fetch racer info
    $stmtA = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
    $stmtA->execute([$racerA]);
    $cmpA = $stmtA->fetch(PDO::FETCH_ASSOC);

    $stmtB = $pdo->prepare("SELECT * FROM racers WHERE id = ?");
    $stmtB->execute([$racerB]);
    $cmpB = $stmtB->fetch(PDO::FETCH_ASSOC);

    if ($cmpA && $cmpB):

    // --- Head-to-head record ---
    $h2hStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN ra.rank < rb.rank THEN 1 ELSE 0 END) as a_wins,
            SUM(CASE WHEN rb.rank < ra.rank THEN 1 ELSE 0 END) as b_wins,
            SUM(CASE WHEN ra.rank = rb.rank THEN 1 ELSE 0 END) as draws,
            COUNT(*) as meetings,
            AVG(ra.gp_points) as a_avg_when_meeting,
            AVG(rb.gp_points) as b_avg_when_meeting,
            AVG(ra.rank - rb.rank) as avg_rank_gap
        FROM results ra
        JOIN results rb ON ra.gpid = rb.gpid
        WHERE ra.racer_id = ? AND rb.racer_id = ?
    ");
    $h2hStmt->execute([$racerA, $racerB]);
    $h2h = $h2hStmt->fetch(PDO::FETCH_ASSOC);
    $meetings = (int)$h2h['meetings'];

    // --- Career stats for each ---
    $careerQuery = "
        SELECT
            COUNT(*) as total_gps,
            SUM(gp_points) as total_points,
            AVG(gp_points) as avg_score,
            MAX(gp_points) as personal_best,
            SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN gp_points = 60 THEN 1 ELSE 0 END) as perfect_60s
        FROM results WHERE racer_id = ? AND gpid LIKE 's%'
    ";
    $stmtCA = $pdo->prepare($careerQuery);
    $stmtCA->execute([$racerA]);
    $careerA = $stmtCA->fetch(PDO::FETCH_ASSOC);

    $stmtCB = $pdo->prepare($careerQuery);
    $stmtCB->execute([$racerB]);
    $careerB = $stmtCB->fetch(PDO::FETCH_ASSOC);

    // --- Most used characters ---
    $charQuery = "SELECT character_used, COUNT(*) as uses FROM results WHERE racer_id = ? AND gpid LIKE 's%' GROUP BY character_used ORDER BY uses DESC LIMIT 1";
    $charAStmt = $pdo->prepare($charQuery); $charAStmt->execute([$racerA]); $mainCharA = $charAStmt->fetchColumn() ?: 'Mii';
    $charBStmt = $pdo->prepare($charQuery); $charBStmt->execute([$racerB]); $mainCharB = $charBStmt->fetchColumn() ?: 'Mii';

    // --- Current season GPScore ---
    $scoreA = calculateGPScore($pdo, $racerA, $currentSeason);
    $scoreB = calculateGPScore($pdo, $racerB, $currentSeason);

    // --- Cup-by-cup comparison ---
    // Career best per cup for both racers in ONE grouped query (this was
    // 24 MAX() queries per racer). Career-wide, so the season cache doesn't apply.
    $allCups = getMKAllCups();
    $cupComparison = [];
    $cupBest = [];   // racer_id => cup => best
    $cupQ = $pdo->prepare("SELECT racer_id, cup_name, MAX(gp_points) AS best FROM results WHERE racer_id IN (?, ?) AND gpid LIKE 's%' AND cup_name IS NOT NULL GROUP BY racer_id, cup_name");
    $cupQ->execute([$racerA, $racerB]);
    foreach ($cupQ->fetchAll(PDO::FETCH_ASSOC) as $r) $cupBest[(int)$r['racer_id']][$r['cup_name']] = (int)$r['best'];
    foreach ($allCups as $cupName) {
        $bestA = $cupBest[(int)$racerA][$cupName] ?? 0;
        $bestB = $cupBest[(int)$racerB][$cupName] ?? 0;
        if ($bestA > 0 || $bestB > 0) {
            $cupComparison[$cupName] = ['a' => $bestA, 'b' => $bestB];
        }
    }

    // --- Score distribution (buckets of 5) ---
    $distQuery = "SELECT gp_points FROM results WHERE racer_id = ? AND gpid LIKE 's%'";
    $distAStmt = $pdo->prepare($distQuery); $distAStmt->execute([$racerA]); $scoresA = $distAStmt->fetchAll(PDO::FETCH_COLUMN);
    $distBStmt = $pdo->prepare($distQuery); $distBStmt->execute([$racerB]); $scoresB = $distBStmt->fetchAll(PDO::FETCH_COLUMN);

    $buckets = [];
    for ($i = 0; $i <= 60; $i += 5) {
        $label = $i . '-' . min($i + 4, MK_MAX_GP_POINTS);
        $countA = count(array_filter($scoresA, fn($s) => $s >= $i && $s <= min($i + 4, MK_MAX_GP_POINTS)));
        $countB = count(array_filter($scoresB, fn($s) => $s >= $i && $s <= min($i + 4, MK_MAX_GP_POINTS)));
        $buckets[] = ['label' => $label, 'a' => $countA, 'b' => $countB];
    }

    // --- Recent meetings (last 10 shared GPs) ---
    $recentStmt = $pdo->prepare("
        SELECT ra.gpid, ra.gp_points as a_pts, rb.gp_points as b_pts,
               ra.rank as a_rank, rb.rank as b_rank,
               ra.race_date, ra.cup_name
        FROM results ra
        JOIN results rb ON ra.gpid = rb.gpid
        WHERE ra.racer_id = ? AND rb.racer_id = ?
        ORDER BY ra.race_date DESC, ra.gpid DESC
        LIMIT 10
    ");
    $recentStmt->execute([$racerA, $racerB]);
    $recentMeetings = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Win rate
    $aWinPct = $meetings > 0 ? round(($h2h['a_wins'] / $meetings) * 100, 1) : 0;
    $bWinPct = $meetings > 0 ? round(($h2h['b_wins'] / $meetings) * 100, 1) : 0;
?>

    <!-- Showdown Header -->
    <div class="compare-showdown">
        <div class="compare-fighter compare-fighter--left">
            <img src="/assets/img/<?= htmlspecialchars($mainCharA) ?>.png" onerror="this.src='/assets/img/Mii.png'" class="compare-fighter-img">
            <div class="compare-fighter-name"><?= htmlspecialchars($cmpA['name']) ?></div>
            <?php if (!empty($cmpA['nickname'])): ?>
                <div class="compare-fighter-nick">"<?= htmlspecialchars($cmpA['nickname']) ?>"</div>
            <?php endif; ?>
        </div>
        <div class="compare-vs-block">
            <div class="compare-vs-text">VS</div>
            <div class="compare-meetings"><?= $meetings ?> meetings</div>
        </div>
        <div class="compare-fighter compare-fighter--right">
            <img src="/assets/img/<?= htmlspecialchars($mainCharB) ?>.png" onerror="this.src='/assets/img/Mii.png'" class="compare-fighter-img">
            <div class="compare-fighter-name"><?= htmlspecialchars($cmpB['name']) ?></div>
            <?php if (!empty($cmpB['nickname'])): ?>
                <div class="compare-fighter-nick">"<?= htmlspecialchars($cmpB['nickname']) ?>"</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Head-to-Head Record -->
    <?php if ($meetings > 0): ?>
    <div class="card">
        <h2 class="card-header">Head-to-Head Record</h2>
        <div class="compare-record">
            <div class="compare-record-bar">
                <div class="compare-record-bar-a" style="width: <?= $aWinPct ?>%;">
                    <?= (int)$h2h['a_wins'] ?>W
                </div>
                <?php if ((int)$h2h['draws'] > 0): ?>
                <div class="compare-record-bar-draw" style="width: <?= round(((int)$h2h['draws'] / $meetings) * 100, 1) ?>%;">
                    <?= (int)$h2h['draws'] ?>D
                </div>
                <?php endif; ?>
                <div class="compare-record-bar-b" style="width: <?= $bWinPct ?>%;">
                    <?= (int)$h2h['b_wins'] ?>W
                </div>
            </div>
            <div class="compare-record-labels">
                <span><?= htmlspecialchars($cmpA['name']) ?> <?= $aWinPct ?>%</span>
                <span><?= htmlspecialchars($cmpB['name']) ?> <?= $bWinPct ?>%</span>
            </div>
        </div>

        <div class="compare-record-stats">
            <div class="compare-record-stat">
                <div class="compare-record-stat-label">Avg Score When Meeting</div>
                <div class="compare-record-stat-values">
                    <span class="<?= $h2h['a_avg_when_meeting'] >= $h2h['b_avg_when_meeting'] ? 'compare-winner' : '' ?>">
                        <?= number_format($h2h['a_avg_when_meeting'], 1) ?>
                    </span>
                    <span class="compare-record-stat-vs">vs</span>
                    <span class="<?= $h2h['b_avg_when_meeting'] >= $h2h['a_avg_when_meeting'] ? 'compare-winner' : '' ?>">
                        <?= number_format($h2h['b_avg_when_meeting'], 1) ?>
                    </span>
                </div>
            </div>
            <div class="compare-record-stat">
                <div class="compare-record-stat-label">Avg Rank Gap</div>
                <div class="compare-record-stat-values">
                    <span><?= number_format(abs($h2h['avg_rank_gap']), 1) ?> positions</span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Career Comparison -->
    <div class="card">
        <h2 class="card-header">Career Comparison</h2>
        <table class="clean-table compare-table">
            <thead>
                <tr>
                    <th>Stat</th>
                    <th class="compare-col-a"><?= htmlspecialchars($cmpA['name']) ?></th>
                    <th class="compare-col-b"><?= htmlspecialchars($cmpB['name']) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stats = [
                    ['GPScore (' . strtoupper($currentSeason) . ')', $scoreA, $scoreB, 'high'],
                    ['Total GPs', $careerA['total_gps'], $careerB['total_gps'], 'high'],
                    ['Career Avg', number_format($careerA['avg_score'], 1), number_format($careerB['avg_score'], 1), 'high'],
                    ['Personal Best', $careerA['personal_best'], $careerB['personal_best'], 'high'],
                    ['Total Wins', $careerA['wins'], $careerB['wins'], 'high'],
                    ['Perfect 60s', $careerA['perfect_60s'], $careerB['perfect_60s'], 'high'],
                    ['Total Points', $careerA['total_points'], $careerB['total_points'], 'high'],
                ];
                foreach ($stats as $stat):
                    $aVal = is_numeric($stat[1]) ? (float)$stat[1] : 0;
                    $bVal = is_numeric($stat[2]) ? (float)$stat[2] : 0;
                    $aWins = ($stat[3] === 'high') ? $aVal > $bVal : $aVal < $bVal;
                    $bWins = ($stat[3] === 'high') ? $bVal > $aVal : $aVal > $bVal;
                ?>
                <tr>
                    <td class="compare-stat-name"><?= $stat[0] ?></td>
                    <td class="compare-col-a <?= $aWins ? 'compare-winner' : '' ?>"><?= $stat[1] ?></td>
                    <td class="compare-col-b <?= $bWins ? 'compare-winner' : '' ?>"><?= $stat[2] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Cup-by-Cup Best Scores -->
    <?php if (!empty($cupComparison)): ?>
    <div class="card">
        <h2 class="card-header">Cup Best Scores</h2>
        <div class="compare-cups-grid">
            <?php foreach ($cupComparison as $cupName => $scores):
                $aLeads = $scores['a'] > $scores['b'];
                $bLeads = $scores['b'] > $scores['a'];
                $tied = $scores['a'] === $scores['b'] && $scores['a'] > 0;
            ?>
            <div class="compare-cup-row">
                <span class="compare-cup-score <?= $aLeads ? 'compare-winner' : '' ?><?= $scores['a'] == 0 ? ' compare-empty' : '' ?>">
                    <?= $scores['a'] ?: '—' ?>
                </span>
                <span class="compare-cup-name <?= $tied ? 'compare-tied' : '' ?>">
                    <?= htmlspecialchars($cupName) ?>
                </span>
                <span class="compare-cup-score <?= $bLeads ? 'compare-winner' : '' ?><?= $scores['b'] == 0 ? ' compare-empty' : '' ?>">
                    <?= $scores['b'] ?: '—' ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
            $aCupWins = count(array_filter($cupComparison, fn($s) => $s['a'] > $s['b']));
            $bCupWins = count(array_filter($cupComparison, fn($s) => $s['b'] > $s['a']));
            $cupTies = count(array_filter($cupComparison, fn($s) => $s['a'] === $s['b'] && $s['a'] > 0));
        ?>
        <div class="compare-cups-summary">
            <span class="<?= $aCupWins > $bCupWins ? 'compare-winner' : '' ?>"><?= htmlspecialchars($cmpA['name']) ?>: <?= $aCupWins ?> cups</span>
            <span>Tied: <?= $cupTies ?></span>
            <span class="<?= $bCupWins > $aCupWins ? 'compare-winner' : '' ?>"><?= htmlspecialchars($cmpB['name']) ?>: <?= $bCupWins ?> cups</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Meetings -->
    <?php if (!empty($recentMeetings)): ?>
    <div class="card">
        <h2 class="card-header">Recent Meetings</h2>
        <table class="clean-table compare-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cup</th>
                    <th class="compare-col-a"><?= htmlspecialchars($cmpA['name']) ?></th>
                    <th class="compare-col-b"><?= htmlspecialchars($cmpB['name']) ?></th>
                    <th>Winner</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentMeetings as $m):
                    $aWon = $m['a_rank'] < $m['b_rank'];
                    $bWon = $m['b_rank'] < $m['a_rank'];
                ?>
                <tr>
                    <td><?= date('M j', strtotime($m['race_date'])) ?></td>
                    <td><?= htmlspecialchars($m['cup_name']) ?></td>
                    <td class="compare-col-a <?= $aWon ? 'compare-winner' : '' ?>"><?= $m['a_pts'] ?> pts (#<?= $m['a_rank'] ?>)</td>
                    <td class="compare-col-b <?= $bWon ? 'compare-winner' : '' ?>"><?= $m['b_pts'] ?> pts (#<?= $m['b_rank'] ?>)</td>
                    <td>
                        <?php if ($aWon): ?>
                            <?= htmlspecialchars($cmpA['name']) ?>
                        <?php elseif ($bWon): ?>
                            <?= htmlspecialchars($cmpB['name']) ?>
                        <?php else: ?>
                            Draw
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Score Distribution -->
    <div class="card">
        <h2 class="card-header">Score Distribution</h2>
        <div class="compare-dist">
            <?php
            $maxCount = max(1, max(array_column($buckets, 'a')), max(array_column($buckets, 'b')));
            foreach ($buckets as $bucket):
                if ($bucket['a'] == 0 && $bucket['b'] == 0) continue;
                $aPct = ($bucket['a'] / $maxCount) * 100;
                $bPct = ($bucket['b'] / $maxCount) * 100;
            ?>
            <div class="compare-dist-row">
                <div class="compare-dist-bar-left">
                    <div class="compare-dist-fill compare-dist-fill--a" style="width: <?= $aPct ?>%;">
                        <?php if ($bucket['a'] > 0): ?><span><?= $bucket['a'] ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="compare-dist-label"><?= $bucket['label'] ?></div>
                <div class="compare-dist-bar-right">
                    <div class="compare-dist-fill compare-dist-fill--b" style="width: <?= $bPct ?>%;">
                        <?php if ($bucket['b'] > 0): ?><span><?= $bucket['b'] ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="compare-dist-legend">
                <span class="compare-dist-legend-a"><?= htmlspecialchars($cmpA['name']) ?></span>
                <span class="compare-dist-legend-b"><?= htmlspecialchars($cmpB['name']) ?></span>
            </div>
        </div>
    </div>

<?php
    endif; // $cmpA && $cmpB
endif; // racerA && racerB
?>

</div>

<?php
// Auto-scroll to head-to-head section if comparison params present
if ($racerA && $racerB):
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('head-to-head').scrollIntoView({ behavior: 'smooth' });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>