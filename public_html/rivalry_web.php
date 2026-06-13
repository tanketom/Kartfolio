<?php
/**
 * Rivalry Web - D3.js Force-Directed Graph of Head-to-Head Matchups
 * Path: /cdnmk/public_html/rivalry_web.php
 */
require_once __DIR__ . '/../private/includes/db.php';
require_once __DIR__ . '/../private/includes/gp_logic.php';

$selectedSeason = $_GET['season'] ?? getCurrentSeasonNumber();

// Fetch all seasons for the dropdown
$seasonsStmt = $pdo->query("SELECT season_id, status FROM season_meta ORDER BY season_id DESC");
$availableSeasons = $seasonsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch racers who participated in the selected season (season GPs only)
$racersStmt = $pdo->prepare("
    SELECT DISTINCT r.id, r.name
    FROM racers r
    JOIN results res ON r.id = res.racer_id
    WHERE res.gpid LIKE ?
    ORDER BY r.name
");
$racersStmt->execute([$selectedSeason . '%']);
$racers = $racersStmt->fetchAll(PDO::FETCH_ASSOC);

// Build nodes array with GP count per racer
$nodes = [];
$racerMap = [];
foreach ($racers as $idx => $racer) {
    $gpStmt = $pdo->prepare("SELECT COUNT(DISTINCT gpid) as gps FROM results WHERE racer_id = ? AND gpid LIKE ?");
    $gpStmt->execute([$racer['id'], $selectedSeason . '%']);
    $gpCount = (int)$gpStmt->fetchColumn();

    $nodes[] = [
        'id' => (int)$racer['id'],
        'name' => $racer['name'],
        'gps' => $gpCount
    ];
    $racerMap[$racer['id']] = $racer['name'];
}

// Build links array for each unique pair
$links = [];
$racerCount = count($racers);
for ($i = 0; $i < $racerCount; $i++) {
    for ($j = $i + 1; $j < $racerCount; $j++) {
        $p1 = $racers[$i];
        $p2 = $racers[$j];

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total,
                   SUM(CASE WHEN r1.rank < r2.rank THEN 1 ELSE 0 END) as p1_wins
            FROM results r1
            JOIN results r2 ON r1.gpid = r2.gpid AND r1.cup_name = r2.cup_name
            WHERE r1.racer_id = ? AND r2.racer_id = ? AND r1.gpid LIKE ?
        ");
        $stmt->execute([$p1['id'], $p2['id'], $selectedSeason . '%']);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int)$data['total'];
        if ($total > 0) {
            $p1Wins = (int)$data['p1_wins'];
            $p2Wins = $total - $p1Wins;
            $links[] = [
                'source' => (int)$p1['id'],
                'target' => (int)$p2['id'],
                'matchups' => $total,
                'p1_wins' => $p1Wins,
                'p2_wins' => $p2Wins,
                'p1_name' => $p1['name'],
                'p2_name' => $p2['name']
            ];
        }
    }
}

$nodesJson = json_encode($nodes);
$linksJson = json_encode($links);

$pageTitle = "Rivalry Web - Kartfolio";
$extraCss = '<link rel="stylesheet" href="/assets/css/pages.css">';
include __DIR__ . '/../private/templates/header.php';
?>

<div class="stats-container">
    <div class="racer-card rweb-header-card">
        <h1 class="rweb-title">Rivalry Web</h1>
        <p class="rweb-subtitle">Who races whom &mdash; and who dominates</p>
    </div>

    <div class="racer-card rweb-graph-card">
        <div class="rweb-controls">
            <form method="GET" class="rweb-season-form">
                <label>Season:
                    <select name="season" onchange="this.form.submit()">
                        <?php foreach ($availableSeasons as $s): ?>
                            <option value="<?= htmlspecialchars($s['season_id']) ?>"
                                <?= $s['season_id'] === $selectedSeason ? 'selected' : '' ?>>
                                <?= htmlspecialchars(strtoupper($s['season_id'])) ?>
                                <?= $s['status'] === 'active' ? '(current)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <label class="rweb-slider-label">Min Matchups: <span id="rweb-min-val">1</span>
                <input type="range" id="rweb-min-matchups" min="1" max="20" value="1">
            </label>
        </div>

        <?php if (count($racers) < 2): ?>
            <div class="rweb-empty-state">Not enough data to display the rivalry web for this season.</div>
        <?php else: ?>
            <div id="rweb-graph" class="rweb-graph-container"></div>
        <?php endif; ?>
    </div>

    <div id="rweb-detail" class="racer-card rweb-detail-panel" style="display:none;">
        <h3 id="rweb-detail-title"></h3>
        <div id="rweb-detail-content"></div>
        <button class="rweb-close-btn" onclick="document.getElementById('rweb-detail').style.display='none'">Close</button>
    </div>
</div>

<div id="rweb-tooltip" class="rweb-tooltip" style="display:none;position:fixed;pointer-events:none;z-index:100;"></div>

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

    const allNodes = <?= $nodesJson ?>;
    const allLinks = <?= $linksJson ?>;

    // Assign color index per node
    const nodeColorMap = {};
    allNodes.forEach((n, i) => {
        nodeColorMap[n.id] = colorPalette[i % colorPalette.length];
    });

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

    const svg = d3.select('#rweb-graph')
        .append('svg')
        .attr('width', width)
        .attr('height', height);

    // Arrowhead marker (unused but good for future)
    svg.append('defs');

    const linkGroup = svg.append('g').attr('class', 'rweb-links');
    const nodeGroup = svg.append('g').attr('class', 'rweb-nodes');
    const labelGroup = svg.append('g').attr('class', 'rweb-labels');

    // Deep copy data to avoid D3 mutating originals on re-render
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
        // If close to 50/50 (within 15%), use gray
        if (Math.abs(p1Rate - p2Rate) < 0.15) return '#aaa';
        // Use dominant racer's color
        if (p1Rate > p2Rate) {
            return nodeColorMap[link.source.id !== undefined ? link.source.id : link.source] || '#aaa';
        } else {
            return nodeColorMap[link.target.id !== undefined ? link.target.id : link.target] || '#aaa';
        }
    }

    function nodeRadius(d) {
        return Math.max(15, Math.min(35, 10 + d.gps));
    }

    // Draw
    let linkElements = linkGroup.selectAll('line')
        .data(links)
        .join('line')
        .attr('stroke', d => getLinkColor(d))
        .attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2)))
        .attr('stroke-opacity', 0.6)
        .style('cursor', 'pointer');

    let nodeElements = nodeGroup.selectAll('circle')
        .data(nodes)
        .join('circle')
        .attr('r', d => nodeRadius(d))
        .attr('fill', d => nodeColorMap[d.id])
        .attr('stroke', '#fff')
        .attr('stroke-width', 2)
        .style('cursor', 'pointer');

    let labelElements = labelGroup.selectAll('text')
        .data(nodes)
        .join('text')
        .text(d => d.name)
        .attr('text-anchor', 'middle')
        .attr('dy', d => nodeRadius(d) + 14)
        .attr('font-size', '11px')
        .attr('font-weight', '700')
        .attr('fill', '#333')
        .attr('pointer-events', 'none');

    // Simulation tick
    simulation.on('tick', () => {
        linkElements
            .attr('x1', d => d.source.x)
            .attr('y1', d => d.source.y)
            .attr('x2', d => d.target.x)
            .attr('y2', d => d.target.y);

        nodeElements
            .attr('cx', d => d.x = Math.max(nodeRadius(d), Math.min(width - nodeRadius(d), d.x)))
            .attr('cy', d => d.y = Math.max(nodeRadius(d), Math.min(height - nodeRadius(d), d.y)));

        labelElements
            .attr('x', d => d.x)
            .attr('y', d => d.y);
    });

    // Drag
    const drag = d3.drag()
        .on('start', function(event, d) {
            if (!event.active) simulation.alphaTarget(0.3).restart();
            d.fx = d.x;
            d.fy = d.y;
        })
        .on('drag', function(event, d) {
            d.fx = event.x;
            d.fy = event.y;
        })
        .on('end', function(event, d) {
            if (!event.active) simulation.alphaTarget(0);
            d.fx = null;
            d.fy = null;
        });

    nodeElements.call(drag);

    // Tooltip helpers
    function showTooltip(html, event) {
        tooltip.innerHTML = html;
        tooltip.style.display = 'block';
        const tx = event.clientX + 14;
        const ty = event.clientY - 10;
        tooltip.style.left = Math.min(tx, window.innerWidth - 280) + 'px';
        tooltip.style.top = Math.max(ty, 10) + 'px';
    }

    function hideTooltip() {
        tooltip.style.display = 'none';
    }

    // Hover on node
    nodeElements
        .on('mouseover', function(event, d) {
            // Get connected link IDs
            const connectedLinks = links.filter(l =>
                (l.source.id === d.id || l.target.id === d.id)
            );
            const connectedNodeIds = new Set();
            connectedNodeIds.add(d.id);
            connectedLinks.forEach(l => {
                connectedNodeIds.add(l.source.id);
                connectedNodeIds.add(l.target.id);
            });

            // Dim non-connected
            nodeElements
                .attr('opacity', n => connectedNodeIds.has(n.id) ? 1 : 0.15);
            labelElements
                .attr('opacity', n => connectedNodeIds.has(n.id) ? 1 : 0.15);
            linkElements
                .attr('stroke-opacity', l =>
                    (l.source.id === d.id || l.target.id === d.id) ? 1.0 : 0.05
                )
                .attr('stroke-width', l =>
                    (l.source.id === d.id || l.target.id === d.id)
                        ? Math.min(10, Math.max(2, l.matchups / 2 + 1))
                        : Math.min(8, Math.max(1, l.matchups / 2))
                );

            // Calculate overall win rate for this racer
            let totalWins = 0;
            let totalMatches = 0;
            connectedLinks.forEach(l => {
                totalMatches += l.matchups;
                if (l.source.id === d.id) {
                    totalWins += l.p1_wins;
                } else {
                    totalWins += l.p2_wins;
                }
            });
            const overallRate = totalMatches > 0 ? Math.round((totalWins / totalMatches) * 100) : 0;

            showTooltip(
                '<strong>' + d.name + '</strong><br>' +
                'GPs played: ' + d.gps + '<br>' +
                'Overall win rate: ' + overallRate + '%',
                event
            );
        })
        .on('mousemove', function(event) {
            const tx = event.clientX + 14;
            const ty = event.clientY - 10;
            tooltip.style.left = Math.min(tx, window.innerWidth - 280) + 'px';
            tooltip.style.top = Math.max(ty, 10) + 'px';
        })
        .on('mouseout', function() {
            nodeElements.attr('opacity', 1);
            labelElements.attr('opacity', 1);
            linkElements
                .attr('stroke-opacity', 0.6)
                .attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2)));
            hideTooltip();
        });

    // Click on node -> detail panel
    nodeElements.on('click', function(event, d) {
        event.stopPropagation();

        const connectedLinks = links.filter(l =>
            l.source.id === d.id || l.target.id === d.id
        );

        detailTitle.textContent = d.name + ' \u2014 Head-to-Head Records';

        if (connectedLinks.length === 0) {
            detailContent.innerHTML = '<p style="color:#888;">No head-to-head matchups found.</p>';
        } else {
            // Sort by matchups descending
            connectedLinks.sort((a, b) => b.matchups - a.matchups);

            let html = '<table class="rweb-detail-table"><thead><tr>' +
                '<th>Opponent</th><th>Matchups</th><th>Wins</th><th>Losses</th><th>Win Rate</th>' +
                '</tr></thead><tbody>';

            connectedLinks.forEach(l => {
                let opponent, wins, losses;
                if (l.source.id === d.id) {
                    opponent = l.p2_name;
                    wins = l.p1_wins;
                    losses = l.p2_wins;
                } else {
                    opponent = l.p1_name;
                    wins = l.p2_wins;
                    losses = l.p1_wins;
                }
                const rate = l.matchups > 0 ? Math.round((wins / l.matchups) * 100) : 0;
                const barColor = rate >= 50 ? '#2EBD59' : 'var(--nintendo-red)';

                html += '<tr>' +
                    '<td>' + opponent + '</td>' +
                    '<td>' + l.matchups + '</td>' +
                    '<td>' + wins + '</td>' +
                    '<td>' + losses + '</td>' +
                    '<td>' +
                        '<span class="rweb-winrate-bar" style="width:' + Math.max(4, rate * 0.8) + 'px;background:' + barColor + ';"></span>' +
                        rate + '%' +
                    '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            detailContent.innerHTML = html;
        }

        detailPanel.style.display = 'flex';
        detailPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // Hover on edge
    linkElements
        .on('mouseover', function(event, d) {
            showTooltip(
                '<strong>' + d.p1_name + ' vs ' + d.p2_name + '</strong><br>' +
                d.p1_wins + '&ndash;' + d.p2_wins + ' (' + d.matchups + ' matchups)',
                event
            );
        })
        .on('mousemove', function(event) {
            const tx = event.clientX + 14;
            const ty = event.clientY - 10;
            tooltip.style.left = Math.min(tx, window.innerWidth - 280) + 'px';
            tooltip.style.top = Math.max(ty, 10) + 'px';
        })
        .on('mouseout', function() {
            hideTooltip();
        });

    // Min matchups slider
    slider.addEventListener('input', function() {
        const minVal = parseInt(this.value);
        sliderVal.textContent = minVal;

        // Filter links
        const filteredLinks = allLinks.filter(l => l.matchups >= minVal);

        // Determine which nodes are still connected
        const connectedIds = new Set();
        filteredLinks.forEach(l => {
            connectedIds.add(l.source.id !== undefined ? l.source.id : l.source);
            connectedIds.add(l.target.id !== undefined ? l.target.id : l.target);
        });

        // Show all nodes but only filtered links
        // Re-copy data
        nodes = allNodes.map(d => {
            // Preserve positions if available
            const existing = simulation.nodes().find(n => n.id === d.id);
            if (existing) {
                return Object.assign({}, d, { x: existing.x, y: existing.y, vx: existing.vx, vy: existing.vy });
            }
            return Object.assign({}, d);
        });

        links = filteredLinks.map(d => Object.assign({}, d));

        // Update simulation
        simulation.nodes(nodes);
        simulation.force('link').links(links);

        // Re-render
        linkElements = linkGroup.selectAll('line')
            .data(links, d => {
                const sId = d.source.id !== undefined ? d.source.id : d.source;
                const tId = d.target.id !== undefined ? d.target.id : d.target;
                return sId + '-' + tId;
            })
            .join('line')
            .attr('stroke', d => getLinkColor(d))
            .attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2)))
            .attr('stroke-opacity', 0.6)
            .style('cursor', 'pointer');

        nodeElements = nodeGroup.selectAll('circle')
            .data(nodes, d => d.id)
            .join('circle')
            .attr('r', d => nodeRadius(d))
            .attr('fill', d => nodeColorMap[d.id])
            .attr('stroke', '#fff')
            .attr('stroke-width', 2)
            .attr('opacity', d => connectedIds.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2)
            .style('cursor', 'pointer');

        labelElements = labelGroup.selectAll('text')
            .data(nodes, d => d.id)
            .join('text')
            .text(d => d.name)
            .attr('text-anchor', 'middle')
            .attr('dy', d => nodeRadius(d) + 14)
            .attr('font-size', '11px')
            .attr('font-weight', '700')
            .attr('fill', '#333')
            .attr('pointer-events', 'none')
            .attr('opacity', d => connectedIds.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2);

        // Re-attach drag
        nodeElements.call(drag);

        // Re-attach hover/click on nodes
        nodeElements
            .on('mouseover', function(event, d) {
                const connLinks = links.filter(l =>
                    (l.source.id === d.id || l.target.id === d.id)
                );
                const cIds = new Set();
                cIds.add(d.id);
                connLinks.forEach(l => {
                    cIds.add(l.source.id);
                    cIds.add(l.target.id);
                });

                nodeElements.attr('opacity', n => cIds.has(n.id) ? 1 : 0.15);
                labelElements.attr('opacity', n => cIds.has(n.id) ? 1 : 0.15);
                linkElements
                    .attr('stroke-opacity', l =>
                        (l.source.id === d.id || l.target.id === d.id) ? 1.0 : 0.05
                    )
                    .attr('stroke-width', l =>
                        (l.source.id === d.id || l.target.id === d.id)
                            ? Math.min(10, Math.max(2, l.matchups / 2 + 1))
                            : Math.min(8, Math.max(1, l.matchups / 2))
                    );

                let tw = 0, tm = 0;
                connLinks.forEach(l => {
                    tm += l.matchups;
                    tw += (l.source.id === d.id) ? l.p1_wins : l.p2_wins;
                });
                const or = tm > 0 ? Math.round((tw / tm) * 100) : 0;

                showTooltip(
                    '<strong>' + d.name + '</strong><br>' +
                    'GPs played: ' + d.gps + '<br>' +
                    'Overall win rate: ' + or + '%',
                    event
                );
            })
            .on('mousemove', function(event) {
                tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px';
                tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px';
            })
            .on('mouseout', function() {
                const cIds2 = new Set();
                filteredLinks.forEach(l => {
                    cIds2.add(l.source.id !== undefined ? l.source.id : l.source);
                    cIds2.add(l.target.id !== undefined ? l.target.id : l.target);
                });
                nodeElements.attr('opacity', d => cIds2.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2);
                labelElements.attr('opacity', d => cIds2.has(d.id) || filteredLinks.length === 0 ? 1 : 0.2);
                linkElements
                    .attr('stroke-opacity', 0.6)
                    .attr('stroke-width', d => Math.min(8, Math.max(1, d.matchups / 2)));
                hideTooltip();
            })
            .on('click', function(event, d) {
                event.stopPropagation();
                const connLinks = links.filter(l =>
                    l.source.id === d.id || l.target.id === d.id
                );
                detailTitle.textContent = d.name + ' \u2014 Head-to-Head Records';
                if (connLinks.length === 0) {
                    detailContent.innerHTML = '<p style="color:#888;">No head-to-head matchups found.</p>';
                } else {
                    connLinks.sort((a, b) => b.matchups - a.matchups);
                    let html = '<table class="rweb-detail-table"><thead><tr>' +
                        '<th>Opponent</th><th>Matchups</th><th>Wins</th><th>Losses</th><th>Win Rate</th>' +
                        '</tr></thead><tbody>';
                    connLinks.forEach(l => {
                        let opp, w, lo;
                        if (l.source.id === d.id) {
                            opp = l.p2_name; w = l.p1_wins; lo = l.p2_wins;
                        } else {
                            opp = l.p1_name; w = l.p2_wins; lo = l.p1_wins;
                        }
                        const rt = l.matchups > 0 ? Math.round((w / l.matchups) * 100) : 0;
                        const bc = rt >= 50 ? '#2EBD59' : 'var(--nintendo-red)';
                        html += '<tr><td>' + opp + '</td><td>' + l.matchups + '</td><td>' + w + '</td><td>' + lo + '</td>' +
                            '<td><span class="rweb-winrate-bar" style="width:' + Math.max(4, rt * 0.8) + 'px;background:' + bc + ';"></span>' + rt + '%</td></tr>';
                    });
                    html += '</tbody></table>';
                    detailContent.innerHTML = html;
                }
                detailPanel.style.display = 'flex';
                detailPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

        // Re-attach hover on links
        linkElements
            .on('mouseover', function(event, d) {
                showTooltip(
                    '<strong>' + d.p1_name + ' vs ' + d.p2_name + '</strong><br>' +
                    d.p1_wins + '&ndash;' + d.p2_wins + ' (' + d.matchups + ' matchups)',
                    event
                );
            })
            .on('mousemove', function(event) {
                tooltip.style.left = Math.min(event.clientX + 14, window.innerWidth - 280) + 'px';
                tooltip.style.top = Math.max(event.clientY - 10, 10) + 'px';
            })
            .on('mouseout', function() {
                hideTooltip();
            });

        // Reheat
        simulation.alpha(0.5).restart();
    });

    // Set initial slider max based on data
    if (allLinks.length > 0) {
        const maxMatchups = Math.max(...allLinks.map(l => l.matchups));
        slider.max = Math.max(1, maxMatchups);
    }

    // Responsive resize
    window.addEventListener('resize', function() {
        width = container.clientWidth;
        svg.attr('width', width);
        simulation.force('center', d3.forceCenter(width / 2, height / 2));
        simulation.alpha(0.3).restart();
    });

})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../private/templates/footer.php'; ?>
