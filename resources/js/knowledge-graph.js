// Owner constellation for the knowledge graph. Ring layout around a
// focused entity; amber edges are owner-permanent, steel is inferred.
const root = document.getElementById('knowledge-graph');

if (root) {
    const dataEl = document.getElementById('kg-data');
    const routesEl = document.getElementById('kg-routes');
    const snapshot = JSON.parse(dataEl.textContent);
    const routes = JSON.parse(routesEl.textContent);
    const svg = root.querySelector('.kg-svg');
    const nodes = snapshot.nodes ?? [];
    const links = snapshot.links ?? [];
    const ns = 'http://www.w3.org/2000/svg';

    const byId = Object.fromEntries(nodes.map((node) => [String(node.id), node]));
    let focusId = root.dataset.focus || (nodes[0] ? String(nodes[0].id) : '');
    let selectedLinkId = root.dataset.link || '';

    const width = 720;
    const height = 480;
    const cx = width / 2;
    const cy = height / 2;

    function neighborsOf(id) {
        const out = [];
        for (const link of links) {
            if (String(link.from) === id) {
                out.push(String(link.to));
            } else if (String(link.to) === id) {
                out.push(String(link.from));
            }
        }
        return [...new Set(out)];
    }

    function layout() {
        const pos = {};
        if (nodes.length === 0) {
            return pos;
        }
        if (!byId[focusId]) {
            focusId = String(nodes[0].id);
        }
        pos[focusId] = { x: cx, y: cy };

        const ring1 = neighborsOf(focusId);
        ring1.forEach((id, index) => {
            const angle = (index / Math.max(ring1.length, 1)) * Math.PI * 2 - Math.PI / 2;
            pos[id] = { x: cx + Math.cos(angle) * 150, y: cy + Math.sin(angle) * 118 };
        });

        const placed = new Set([focusId, ...ring1]);
        const ring2 = [];
        for (const id of ring1) {
            for (const peer of neighborsOf(id)) {
                if (!placed.has(peer)) {
                    ring2.push(peer);
                    placed.add(peer);
                }
            }
        }
        ring2.forEach((id, index) => {
            const angle = (index / Math.max(ring2.length, 1)) * Math.PI * 2 - Math.PI / 2;
            pos[id] = { x: cx + Math.cos(angle) * 270, y: cy + Math.sin(angle) * 200 };
        });

        const rest = nodes.map((node) => String(node.id)).filter((id) => !placed.has(id));
        rest.forEach((id, index) => {
            const angle = (index / Math.max(rest.length, 1)) * Math.PI * 2;
            pos[id] = { x: cx + Math.cos(angle) * 320, y: cy + Math.sin(angle) * 230 };
        });

        return pos;
    }

    function el(name, attrs) {
        const node = document.createElementNS(ns, name);
        for (const [key, value] of Object.entries(attrs)) {
            node.setAttribute(key, value);
        }
        return node;
    }

    function strokeFor(link) {
        if (link.disputed) {
            return { color: '#C45C4A', dash: '5 4', width: '1.5' };
        }
        if (link.permanent) {
            return { color: '#E8A838', dash: '', width: '2' };
        }
        const alpha = Math.max(0.28, Math.min(0.9, link.confidence / 1000));
        return { color: `rgba(138, 155, 176, ${alpha})`, dash: link.confidence < 400 ? '3 3' : '', width: '1.25' };
    }

    function linkUrl(template, id) {
        return template.replace(/\/0(\/|$)/, `/${id}$1`);
    }

    function showEmpty() {
        document.getElementById('kg-inspect-empty').classList.remove('hidden');
        document.getElementById('kg-inspect-node').classList.add('hidden');
        document.getElementById('kg-inspect-link').classList.add('hidden');
    }

    function showNode(node) {
        document.getElementById('kg-inspect-empty').classList.add('hidden');
        document.getElementById('kg-inspect-node').classList.remove('hidden');
        document.getElementById('kg-inspect-link').classList.add('hidden');
        document.getElementById('kg-node-name').textContent = node.name;
        document.getElementById('kg-node-meta').textContent = node.type;
        document.getElementById('kg-node-summary').textContent = node.summary || '';
        document.getElementById('kg-node-edit').href = `${routes.edit}/${node.id}/edit`;
        document.getElementById('kg-new-from').value = node.name;
        syncUrl();
    }

    function syncUrl() {
        const url = new URL(window.location.href);
        if (focusId) {
            url.searchParams.set('focus', focusId);
        } else {
            url.searchParams.delete('focus');
        }
        if (selectedLinkId) {
            url.searchParams.set('link', selectedLinkId);
        } else {
            url.searchParams.delete('link');
        }
        history.replaceState({}, '', url);
    }

    function showLink(link) {
        const from = byId[String(link.from)];
        const to = byId[String(link.to)];
        document.getElementById('kg-inspect-empty').classList.add('hidden');
        document.getElementById('kg-inspect-node').classList.add('hidden');
        document.getElementById('kg-inspect-link').classList.remove('hidden');
        document.getElementById('kg-link-label').textContent = `${from?.name ?? '?'} —${link.predicate}→ ${to?.name ?? '?'}`;
        const bits = [`confidence ${link.confidence}`];
        if (link.permanent) {
            bits.push('permanent');
        }
        if (link.disputed) {
            bits.push('disputed');
        }
        document.getElementById('kg-link-meta').textContent = bits.join(' · ');
        document.getElementById('kg-fix-from').value = from?.name ?? '';
        document.getElementById('kg-fix-to').value = to?.name ?? '';
        document.getElementById('kg-fix-predicate').value = link.predicate;
        document.getElementById('kg-fix-form').action = linkUrl(routes.update, link.id);
        document.getElementById('kg-confirm-form').action = linkUrl(routes.confirm, link.id);
        document.getElementById('kg-permanent-form').action = linkUrl(routes.permanent, link.id);
        document.getElementById('kg-unpermanent-form').action = linkUrl(routes.permanent, link.id);
        document.getElementById('kg-permanent-form').classList.toggle('hidden', !!link.permanent);
        document.getElementById('kg-unpermanent-form').classList.toggle('hidden', !link.permanent);
        const retract = document.getElementById('kg-retract-form');
        if (retract) {
            retract.action = linkUrl(routes.destroy, link.id);
        }
        document.getElementById('kg-new-from').value = from?.name ?? '';
        syncUrl();
    }

    function draw() {
        const pos = layout();
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }

        const stars = el('g', { class: 'kg-stars' });
        for (let i = 0; i < 48; i++) {
            stars.appendChild(el('circle', {
                cx: String((i * 97) % width),
                cy: String((i * 53) % height),
                r: i % 7 === 0 ? '1.2' : '0.6',
                fill: '#8A9BB0',
                opacity: i % 5 === 0 ? '0.35' : '0.12',
            }));
        }
        svg.appendChild(stars);

        const compass = el('g', { class: 'kg-compass', opacity: '0.22' });
        compass.appendChild(el('line', {
            x1: String(cx), y1: '36', x2: String(cx), y2: String(height - 36),
            stroke: '#E8A838', 'stroke-width': '0.5',
        }));
        compass.appendChild(el('line', {
            x1: '48', y1: String(cy), x2: String(width - 48), y2: String(cy),
            stroke: '#E8A838', 'stroke-width': '0.5',
        }));
        compass.appendChild(el('circle', {
            cx: String(cx), cy: String(cy), r: '150',
            fill: 'none', stroke: '#2A3A52', 'stroke-width': '0.75',
        }));
        compass.appendChild(el('circle', {
            cx: String(cx), cy: String(cy), r: '270',
            fill: 'none', stroke: '#2A3A52', 'stroke-width': '0.5',
            'stroke-dasharray': '2 6',
        }));
        svg.appendChild(compass);

        const edgeLayer = el('g', {});
        for (const link of links) {
            const a = pos[String(link.from)];
            const b = pos[String(link.to)];
            if (!a || !b) {
                continue;
            }
            const style = strokeFor(link);
            const line = el('line', {
                x1: String(a.x),
                y1: String(a.y),
                x2: String(b.x),
                y2: String(b.y),
                stroke: style.color,
                'stroke-width': selectedLinkId === String(link.id) ? '2.5' : style.width,
                'stroke-dasharray': style.dash,
                'data-link-id': String(link.id),
                class: 'kg-edge',
            });
            line.style.cursor = 'pointer';
            line.addEventListener('click', (event) => {
                event.stopPropagation();
                selectedLinkId = String(link.id);
                showLink(link);
                draw();
            });
            edgeLayer.appendChild(line);

            const mx = (a.x + b.x) / 2;
            const my = (a.y + b.y) / 2;
            const label = el('text', {
                x: String(mx),
                y: String(my - 6),
                fill: link.permanent ? '#E8A838' : '#8A9BB0',
                'font-size': '9',
                'font-family': 'IBM Plex Mono, ui-monospace, monospace',
                'text-anchor': 'middle',
            });
            label.textContent = link.predicate;
            edgeLayer.appendChild(label);
        }
        svg.appendChild(edgeLayer);

        const nodeLayer = el('g', {});
        for (const node of nodes) {
            const p = pos[String(node.id)];
            if (!p) {
                continue;
            }
            const g = el('g', { class: 'kg-node', 'data-node-id': String(node.id) });
            g.style.cursor = 'pointer';
            const focused = String(node.id) === focusId;
            g.appendChild(el('circle', {
                cx: String(p.x),
                cy: String(p.y),
                r: focused ? '7' : '5',
                fill: focused ? '#E8A838' : '#152238',
                stroke: focused ? '#E8A838' : '#E8E4D8',
                'stroke-width': focused ? '2' : '1.25',
            }));
            const name = el('text', {
                x: String(p.x),
                y: String(p.y + 18),
                fill: '#E8E4D8',
                'font-size': '11',
                'font-family': 'IBM Plex Mono, ui-monospace, monospace',
                'text-anchor': 'middle',
            });
            name.textContent = node.name;
            g.appendChild(name);
            g.addEventListener('click', (event) => {
                event.stopPropagation();
                focusId = String(node.id);
                selectedLinkId = '';
                showNode(node);
                draw();
            });
            nodeLayer.appendChild(g);
        }
        svg.appendChild(nodeLayer);
    }

    if (selectedLinkId) {
        const link = links.find((row) => String(row.id) === selectedLinkId);
        if (link) {
            showLink(link);
        } else {
            selectedLinkId = '';
        }
    }
    if (! selectedLinkId) {
        if (focusId && byId[focusId]) {
            showNode(byId[focusId]);
        } else {
            showEmpty();
        }
    }

    if (nodes.length === 0) {
        showEmpty();
    }

    draw();
}
