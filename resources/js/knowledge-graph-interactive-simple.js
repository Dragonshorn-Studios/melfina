// Interactive Knowledge Graph Viewer - Lightweight Version
// No D3.js dependency - uses vanilla JavaScript

// The link forms whose action carries the `/0` placeholder the viewer
// swaps for the selected link id.
const LINK_FORM_IDS = ['kg-fix-form', 'kg-confirm-form', 'kg-permanent-form', 'kg-unpermanent-form', 'kg-retract-form'];

const KnowledgeGraphSimple = {
    config: {
        width: 800,
        height: 800,
        nodeRadius: 8,
        focusedNodeRadius: 10,
        linkWidth: 1.25,
        focusedLinkWidth: 2.5,
        colors: {
            nodeFill: 'var(--color-panel)',
            nodeStroke: 'var(--color-cream)',
            focusedNode: 'var(--color-amber)',
            focusedNodeStroke: 'var(--color-amber)',
            permanentLink: 'var(--color-amber)',
            disputedLink: 'var(--color-danger)',
            text: 'var(--color-cream)',
            textSecondary: 'var(--color-steel)',
            compass: 'var(--color-amber)',
            grid: 'var(--color-border)',
        },
        font: {
            family: 'IBM Plex Mono, ui-monospace, monospace',
            size: 12,
            label: 11,
        },
    },

    state: {
        nodes: [],
        links: [],
        focusId: null,
        selectedLinkId: null,
        selectedNodeId: null,
        transform: { x: 0, y: 0, k: 1 },
        isDragging: false,
        dragStart: null,
        hoveredNode: null,
        draggingNode: null,
        searchQuery: '',
        savedPositions: {},
        loadingForms: new Set(),
    },

    // Get CSRF token for AJAX requests
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    },

    // Read a failed answer, whatever shape it arrives in. A 500 from the
    // web server is an HTML page, not JSON — reporting that as a network
    // error hides the real cause.
    async readError(response) {
        let message = `Request failed (HTTP ${response.status})`;

        try {
            const body = await response.text();

            if (body) {
                try {
                    const parsed = JSON.parse(body);
                    message = parsed.message || parsed.error || message;
                } catch {
                    message = `${message} — ${body.slice(0, 200)}`;
                }
            }
        } catch {
            // The body cannot be read; the status is all we have.
        }

        return message;
    },

    // Default headers for AJAX requests
    getAjaxHeaders() {
        return {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': this.getCsrfToken(),
        };
    },

    // JSON body headers — the CSRF token still has to ride along.
    getJsonHeaders() {
        return {
            ...this.getAjaxHeaders(),
            'Content-Type': 'application/json',
        };
    },

    // The one write path: it sends the request, reports a refused write
    // the way every panel does, and hands a successful answer to the
    // caller. `formId` lights the submit button while the request runs.
    async write(url, { method = 'POST', body = null, headers = null, formId = null, failure = 'Request failed.', onSuccess, onError = null }) {
        if (formId) {
            this.setLoading(formId, true);
        }

        try {
            const response = await fetch(url, {
                method,
                headers: headers ?? this.getAjaxHeaders(),
                body,
            });

            if (!response.ok) {
                const error = await this.readError(response);
                this.showStatus(error || failure, 'error');
                return;
            }

            onSuccess(await response.json());
        } catch (error) {
            onError?.(error);
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            if (formId) {
                this.setLoading(formId, false);
            }
        }
    },

    // The API answers with `from_id`/`to_id`; the viewer works with
    // `from`/`to`.
    toLink(dataLink) {
        return {
            id: dataLink.id,
            from: dataLink.from_id,
            to: dataLink.to_id,
            predicate: dataLink.predicate,
            confidence: dataLink.confidence,
            permanent: dataLink.permanent,
            disputed: dataLink.disputed,
        };
    },

    updateDimensions() {
        if (this.elements.svg) {
            const viewBox = this.elements.svg.viewBox?.baseVal;
            if (viewBox && viewBox.width > 0 && viewBox.height > 0) {
                this.config.width = viewBox.width;
                this.config.height = viewBox.height;
            } else {
                // Fallback to client dimensions
                this.config.width = this.elements.svg.clientWidth || 800;
                this.config.height = this.elements.svg.clientHeight || 800;
            }
        }
    },

    loadSavedPositions() {
        try {
            const positions = JSON.parse(localStorage.getItem('kg-node-positions') || '{}');
            this.state.savedPositions = positions;
        } catch (e) {
            this.state.savedPositions = {};
        }
    },

    elements: {
        root: null,
        svg: null,
        nodeLayer: null,
        linkLayer: null,
        textLayer: null,
        compassLayer: null,
        gridLayer: null,
    },

    async init(rootElement) {
        this.elements.root = rootElement;
        this.elements.svg = rootElement.querySelector('.kg-svg');
        
        if (!this.elements.svg) {
            console.error('SVG element not found');
            return;
        }
        
        this.updateDimensions();
        
        this.parseInitialData();
        
        this.loadSavedPositions();
        
        if (this.state.nodes.length > 0) {
            this.applyForceLayout();
        }
        
        this.setupSVG();
        
        this.setupEvents();
        
        this.render();
        
        await this.loadData();
        
    },

    parseInitialData() {
        const dataEl = document.getElementById('kg-data');

        if (!dataEl) {
            return;
        }

        try {
            const snapshot = JSON.parse(dataEl.textContent);
            this.state.nodes = snapshot.nodes || [];
            this.state.links = snapshot.links || [];
        } catch (error) {
            console.error('Failed to parse initial data:', error);
            this.showStatus('The graph data on this page could not be read.', 'error');
        }
        
        if (this.elements.root) {
            this.state.focusId = this.elements.root.dataset.focus || (this.state.nodes[0]?.id ? String(this.state.nodes[0].id) : null);
            this.state.selectedLinkId = this.elements.root.dataset.link || '';
        }
    },

    async loadData(focusId = null) {
        try {
            const params = new URLSearchParams();
            if (focusId || this.state.focusId) {
                params.set('focus', focusId || this.state.focusId);
            }
            params.set('hops', '2');
            params.set('limit', '250');
            
            const response = await fetch(`/api/v1/knowledge/graph?${params.toString()}`);
            
            if (response.ok) {
                const data = await response.json();
                this.state.nodes = data.nodes || [];
                this.state.links = data.links || [];
                
                // Update focus if needed
                if (focusId && !this.state.nodes.some(n => String(n.id) === String(focusId))) {
                    this.state.focusId = this.state.nodes[0]?.id || null;
                }
                
                // Apply layout
                this.applyForceLayout();

                this.updateURL();
                this.render();
            } else {
                this.showStatus(await this.readError(response), 'error');
            }
        } catch (error) {
            console.error('Failed to load graph data:', error);
            this.showStatus('Could not reach the server. Please try again.', 'error');
        }
    },

    setupSVG() {
        const svg = this.elements.svg;
        
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }

        this.elements.gridLayer = this.createElement('g');
        this.elements.compassLayer = this.createElement('g');
        this.elements.linkLayer = this.createElement('g');
        this.elements.nodeLayer = this.createElement('g');
        this.elements.textLayer = this.createElement('g');

        svg.appendChild(this.elements.gridLayer);
        svg.appendChild(this.elements.compassLayer);
        svg.appendChild(this.elements.linkLayer);
        svg.appendChild(this.elements.nodeLayer);
        svg.appendChild(this.elements.textLayer);
    },

    createElement(tag) {
        return document.createElementNS('http://www.w3.org/2000/svg', tag);
    },

    setupEvents() {
        const svg = this.elements.svg;
        
        // Resize handler
        window.addEventListener('resize', () => {
            this.updateDimensions();
            this.render();
        });
        
        // Zoom with mouse wheel
        svg.addEventListener('wheel', (e) => this.handleWheel(e));
        
        // Pan with mouse drag
        svg.addEventListener('mousedown', (e) => this.handlePanStart(e));
        document.addEventListener('mousemove', (e) => this.handlePanMove(e));
        document.addEventListener('mouseup', () => this.handlePanEnd());
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyDown(e));
        
        // URL sync
        window.addEventListener('popstate', () => this.handlePopState());
        
        // Form handlers
        this.setupFormHandlers();
        
        // Search
        this.setupSearch();
        
        // Refresh button
        const refreshBtn = document.getElementById('kg-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadData());
        }
    },

    setupFormHandlers() {
        // The link forms carry a `0` placeholder that the viewer swaps for
        // the selected link id. The pristine template is kept so every
        // selection rewrites the action from scratch.
        LINK_FORM_IDS.forEach((id) => {
            const form = document.getElementById(id);
            if (form) {
                form.dataset.actionTemplate = form.action;
            }
        });

        const submitHandlers = {
            'kg-new-entity-form': (e) => this.handleNewEntitySubmit(e),
            'kg-new-link-form': (e) => this.handleNewLinkSubmit(e),
            'kg-fix-form': (e) => this.handleFixLinkSubmit(e),
            'kg-confirm-form': (e) => this.handleConfirmLinkSubmit(e),
            'kg-permanent-form': (e) => this.handlePermanentLinkSubmit(e, true),
            'kg-unpermanent-form': (e) => this.handlePermanentLinkSubmit(e, false),
            'kg-retract-form': (e) => this.handleRetractLinkSubmit(e),
        };

        for (const [id, handler] of Object.entries(submitHandlers)) {
            const form = document.getElementById(id);
            if (form) {
                form.addEventListener('submit', handler);
            }
        }

        // Node edit button
        const nodeEditBtn = document.getElementById('kg-node-edit');
        if (nodeEditBtn) {
            nodeEditBtn.addEventListener('click', (e) => this.handleNodeEdit(e));
        }
    },

    setupSearch() {
        const searchInput = document.getElementById('kg-search');
        if (!searchInput) return;

        let timeout = null;
        
        searchInput.addEventListener('input', async (e) => {
            clearTimeout(timeout);
            this.state.searchQuery = e.target.value;
            
            if (this.state.searchQuery.length < 2) {
                this.updateSearchResults([]);
                return;
            }

            timeout = setTimeout(async () => {
                try {
                    const response = await fetch(`/api/v1/knowledge/entries?q=${encodeURIComponent(this.state.searchQuery)}&limit=10`);
                    if (response.ok) {
                        const data = await response.json();
                        this.updateSearchResults(data.results || []);
                    } else {
                        this.showStatus(await this.readError(response), 'error');
                    }
                } catch (error) {
                    console.error('Search failed:', error);
                    this.showStatus('Could not reach the server. Please try again.', 'error');
                }
            }, 300);
        });

        searchInput.addEventListener('blur', () => {
            setTimeout(() => this.updateSearchResults([]), 200);
        });

        // Picking a name from the results focuses that entity.
        searchInput.addEventListener('change', (e) => {
            const option = [...searchInput.list?.options ?? []]
                .find((candidate) => candidate.value === e.target.value);

            if (option?.dataset.id) {
                this.focusNode(option.dataset.id);
                e.target.value = '';
            }
        });
    },

    updateSearchResults(results) {
        const resultsEl = document.getElementById('kg-search-results');
        if (!resultsEl) return;

        resultsEl.innerHTML = '';
        
        if (results.length === 0) {
            resultsEl.classList.add('hidden');
            return;
        }

        results.forEach(r => {
            const option = document.createElement('option');
            option.value = r.name;
            option.dataset.id = r.id;
            resultsEl.appendChild(option);
        });
        
        resultsEl.classList.remove('hidden');
    },

    handleWheel(e) {
        e.preventDefault();
        
        const delta = e.deltaY > 0 ? 0.9 : 1.1;
        const svg = this.elements.svg;
        const ctm = svg.getScreenCTM();
        if (!ctm) {
            return;
        }
        const pt = svg.createSVGPoint();
        pt.x = e.clientX;
        pt.y = e.clientY;
        const mousePos = pt.matrixTransform(ctm.inverse());
        
        // Zoom towards mouse position
        this.state.transform.k *= delta;
        this.state.transform.x = mousePos.x - (mousePos.x - this.state.transform.x) / (1 / delta);
        this.state.transform.y = mousePos.y - (mousePos.y - this.state.transform.y) / (1 / delta);
        
        this.applyTransform();
    },

    handlePanStart(e) {
        if (e.target !== this.elements.svg && !e.target.classList.contains('kg-svg')) {
            return;
        }
        
        this.state.isDragging = true;
        this.state.dragStart = { x: e.clientX, y: e.clientY };
        e.preventDefault();
    },

    handlePanMove(e) {
        if (!this.state.isDragging && !this.state.draggingNode) return;
        
        if (this.state.isDragging && this.state.dragStart) {
            // Pan drag
            const dx = e.clientX - this.state.dragStart.x;
            const dy = e.clientY - this.state.dragStart.y;
            
            this.state.transform.x += dx / this.state.transform.k;
            this.state.transform.y += dy / this.state.transform.k;
            
            this.state.dragStart = { x: e.clientX, y: e.clientY };
            this.applyTransform();
            e.preventDefault();
        } else if (this.state.draggingNode && this.state.dragStart) {
            // Node drag
            const node = this.state.draggingNode;
            const dx = (e.clientX - this.state.dragStart.x) / this.state.transform.k;
            const dy = (e.clientY - this.state.dragStart.y) / this.state.transform.k;
            
            node.x = this.state.dragStart.nodeX + dx;
            node.y = this.state.dragStart.nodeY + dy;
            
            this.renderNodes();
            this.renderTextLabels();
            this.renderLinks();
            e.preventDefault();
        }
    },

    handlePanEnd() {
        if (this.state.isDragging) {
            this.state.isDragging = false;
        }
        if (this.state.draggingNode && this.state.dragStart) {
            // Save node position
            this.saveNodePosition(this.state.draggingNode);
            this.state.draggingNode = null;
        }
        this.state.dragStart = null;
    },

    applyTransform() {
        const transform = `translate(${this.state.transform.x}, ${this.state.transform.y}) scale(${this.state.transform.k})`;
        this.elements.linkLayer.setAttribute('transform', transform);
        this.elements.nodeLayer.setAttribute('transform', transform);
        this.elements.textLayer.setAttribute('transform', transform);
    },

    handleKeyDown(event) {
        // Ignore if typing in a field — Delete/Backspace is a destructive
        // shortcut and must not fire from inside the inspector forms.
        const tag = event.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || event.target.isContentEditable) {
            return;
        }

        switch (event.key) {
            case 'Escape':
                this.clearSelection();
                break;
            case 'Delete':
            case 'Backspace':
                this.handleDelete();
                break;
            case '+':
            case '=':
                this.zoomIn();
                break;
            case '-':
            case '_':
                this.zoomOut();
                break;
            case '0':
                this.resetZoom();
                break;
            case 'f':
            case 'F':
                this.focusSelected();
                break;
        }
    },

    zoomIn() {
        this.state.transform.k *= 1.2;
        this.applyTransform();
    },

    zoomOut() {
        this.state.transform.k /= 1.2;
        this.state.transform.k = Math.max(0.1, this.state.transform.k);
        this.applyTransform();
    },

    resetZoom() {
        this.state.transform = { x: 0, y: 0, k: 1 };
        this.applyTransform();
    },

    focusSelected() {
        if (this.state.selectedNodeId) {
            this.focusNode(this.state.selectedNodeId);
        }
    },

    focusNode(nodeId) {
        const node = this.state.nodes.find(n => String(n.id) === String(nodeId));
        if (!node) return;

        this.state.focusId = String(nodeId);
        this.state.selectedNodeId = String(nodeId);
        this.state.selectedLinkId = null;

        this.updateURL();
        this.showNode(node);

        // Reload data focused on this node, then centre on where it landed.
        // Centring before the layout would use stale (or missing) coordinates
        // and poison the view transform with NaN.
        this.loadData(nodeId).then(() => {
            const laid = this.state.nodes.find(n => String(n.id) === String(nodeId));
            if (!laid || !Number.isFinite(laid.x) || !Number.isFinite(laid.y)) {
                return;
            }

            this.state.transform.x = (this.config.width / 2) - laid.x;
            this.state.transform.y = (this.config.height / 2) - laid.y;
            this.state.transform.k = 1;

            this.applyTransform();
        });
    },

    // URL management
    updateURL() {
        const url = new URL(window.location.href);
        
        if (this.state.focusId) {
            url.searchParams.set('focus', this.state.focusId);
        } else {
            url.searchParams.delete('focus');
        }
        
        if (this.state.selectedLinkId) {
            url.searchParams.set('link', this.state.selectedLinkId);
        } else {
            url.searchParams.delete('link');
        }
        
        history.replaceState({}, '', url);
    },

    handlePopState() {
        const url = new URL(window.location.href);
        const focus = url.searchParams.get('focus');
        const link = url.searchParams.get('link');
        
        if (focus) {
            this.state.focusId = focus;
            this.loadData(focus);
        }
        
        if (link) {
            this.state.selectedLinkId = link;
            const selectedLink = this.state.links.find(l => String(l.id) === link);
            if (selectedLink) {
                this.showLink(selectedLink);
            }
        }
    },

    clearSelection() {
        this.state.selectedNodeId = null;
        this.state.selectedLinkId = null;
        this.state.hoveredNode = null;

        this.updateURL();
        this.render();
        this.showEmpty();
    },

    selectNode(node) {
        this.state.selectedNodeId = String(node.id);
        this.state.selectedLinkId = null;
        this.state.focusId = String(node.id);
        
        this.updateURL();
        this.showNode(node);
        this.render();
    },

    selectLink(link) {
        this.state.selectedLinkId = String(link.id);
        this.state.selectedNodeId = null;
        
        this.updateURL();
        this.showLink(link);
        this.render();
    },

    // The inspector holds three panels; exactly one of them is visible.
    showInspectorPanel(panel) {
        const panels = {
            empty: 'kg-inspect-empty',
            node: 'kg-inspect-node',
            link: 'kg-inspect-link',
        };

        for (const [name, id] of Object.entries(panels)) {
            const el = document.getElementById(id);
            if (el) el.classList.toggle('hidden', name !== panel);
        }
    },

    showEmpty() {
        this.showInspectorPanel('empty');
    },

    showNode(node) {
        this.showInspectorPanel('node');

        const nodeEl = document.getElementById('kg-inspect-node');
        if (nodeEl) {
            const nameEl = nodeEl.querySelector('#kg-node-name');
            const metaEl = nodeEl.querySelector('#kg-node-meta');
            const summaryEl = nodeEl.querySelector('#kg-node-summary');
            const editEl = nodeEl.querySelector('#kg-node-edit');
            const newFromEl = nodeEl.querySelector('#kg-new-from');

            if (nameEl) nameEl.textContent = node.name;
            if (metaEl) metaEl.textContent = node.type;
            if (summaryEl) summaryEl.textContent = node.summary || '';
            if (editEl) editEl.href = `/console/knowledge/${node.id}/edit`;
            if (newFromEl) newFromEl.value = node.name;
        }
    },

    showLink(link) {
        this.showInspectorPanel('link');

        const linkEl = document.getElementById('kg-inspect-link');

        if (linkEl) {
            const fromNode = this.state.nodes.find(n => String(n.id) === String(link.from));
            const toNode = this.state.nodes.find(n => String(n.id) === String(link.to));

            const labelEl = linkEl.querySelector('#kg-link-label');
            const metaEl = linkEl.querySelector('#kg-link-meta');
            const fixFromEl = linkEl.querySelector('#kg-fix-from');
            const fixToEl = linkEl.querySelector('#kg-fix-to');
            const fixPredicateEl = linkEl.querySelector('#kg-fix-predicate');

            if (labelEl) {
                labelEl.textContent = `${fromNode?.name ?? '?'} —${link.predicate}→ ${toNode?.name ?? '?'}`;
            }

            if (metaEl) {
                const bits = [`confidence ${link.confidence}`];
                if (link.permanent) bits.push('permanent');
                if (link.disputed) bits.push('disputed');
                metaEl.textContent = bits.join(' · ');
            }

            if (fixFromEl) fixFromEl.value = fromNode?.name ?? '';
            if (fixToEl) fixToEl.value = toNode?.name ?? '';
            if (fixPredicateEl) fixPredicateEl.value = link.predicate;

            // The link forms carry a `/0` placeholder that the viewer
            // swaps for the selected link id.
            for (const id of LINK_FORM_IDS) {
                const form = document.getElementById(id);
                if (form) {
                    const template = form.dataset.actionTemplate || form.action;
                    form.action = template.replace(/\/0(\/|$)/, `/${link.id}$1`);
                }
            }

            const permanentForm = document.getElementById('kg-permanent-form');
            const unpermanentForm = document.getElementById('kg-unpermanent-form');

            if (permanentForm) permanentForm.classList.toggle('hidden', !!link.permanent);
            if (unpermanentForm) unpermanentForm.classList.toggle('hidden', !link.permanent);
        }

        // Update new link form
        const newFromEl = document.getElementById('kg-new-from');
        if (newFromEl) {
            const fromNode = this.state.nodes.find(n => String(n.id) === String(link.from));
            newFromEl.value = fromNode?.name ?? '';
        }
    },

    render() {
        this.renderGrid();
        this.renderCompass();
        this.renderLinks();
        this.renderNodes();
        this.renderTextLabels();
    },

    renderGrid() {
        const layer = this.elements.gridLayer;
        layer.innerHTML = '';

        const gridSize = 50;
        const halfWidth = this.config.width / 2;
        const halfHeight = this.config.height / 2;

        for (let x = -halfWidth; x <= halfWidth; x += gridSize) {
            layer.appendChild(this.createLine(x, -halfHeight, x, halfHeight, this.config.colors.grid, 0.5, 0.3));
        }

        for (let y = -halfHeight; y <= halfHeight; y += gridSize) {
            layer.appendChild(this.createLine(-halfWidth, y, halfWidth, y, this.config.colors.grid, 0.5, 0.3));
        }
    },

    renderCompass() {
        const layer = this.elements.compassLayer;
        layer.innerHTML = '';
        
        const cx = this.config.width / 2;
        const cy = this.config.height / 2;
        const size = this.config.width;
        
        // Crosshairs
        layer.appendChild(this.createLine(cx, 36, cx, size - 36, this.config.colors.compass, 0.5, 0.22));
        layer.appendChild(this.createLine(48, cy, size - 48, cy, this.config.colors.compass, 0.5, 0.22));
        
        // Rings
        layer.appendChild(this.createCircle(cx, cy, 220, 'none', this.config.colors.grid, 0.75, 1));
        layer.appendChild(this.createCircle(cx, cy, 330, 'none', this.config.colors.grid, 0.5, 1, '2 6'));
    },

    renderLinks() {
        const layer = this.elements.linkLayer;
        layer.innerHTML = '';
        
        for (const link of this.state.links) {
            const fromNode = this.state.nodes.find(n => String(n.id) === String(link.from));
            const toNode = this.state.nodes.find(n => String(n.id) === String(link.to));
            
            if (!fromNode || !toNode) continue;
            
            const isSelected = String(link.id) === this.state.selectedLinkId;

            const stroke = this.getLinkStroke(link, isSelected);

            const hitLine = this.createLine(
                fromNode.x, fromNode.y,
                toNode.x, toNode.y,
                'transparent',
                18,
                1,
                'kg-edge-hit'
            );
            hitLine.setAttribute('data-link-id', String(link.id));
            hitLine.style.cursor = 'pointer';
            hitLine.addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectLink(link);
            });
            layer.appendChild(hitLine);
            
            const line = this.createLine(
                fromNode.x, fromNode.y,
                toNode.x, toNode.y,
                stroke.color,
                isSelected ? this.config.focusedLinkWidth : stroke.width,
                1,
                'kg-edge'
            );
            line.setAttribute('data-link-id', String(link.id));
            line.setAttribute('pointer-events', 'none');
            if (stroke.dash) {
                line.setAttribute('stroke-dasharray', stroke.dash);
            }
            layer.appendChild(line);
            
            const mx = (fromNode.x + toNode.x) / 2;
            const my = (fromNode.y + toNode.y) / 2;
            const label = this.createText(mx, my - 8, link.predicate, {
                'font-size': this.config.font.label,
                'font-family': this.config.font.family,
                'text-anchor': 'middle',
                'fill': link.permanent ? this.config.colors.permanentLink : this.config.colors.textSecondary,
            });
            label.setAttribute('class', 'kg-edge-label');
            label.style.cursor = 'pointer';
            label.addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectLink(link);
            });
            layer.appendChild(label);
        }
    },

    getLinkStroke(link, isSelected) {
        if (link.disputed) {
            return {
                color: this.config.colors.disputedLink,
                width: this.config.linkWidth,
                dash: '5 4'
            };
        }
        
        if (link.permanent) {
            return {
                color: this.config.colors.permanentLink,
                width: isSelected ? this.config.focusedLinkWidth : '2',
                dash: ''
            };
        }
        
        const alpha = Math.max(0.28, Math.min(0.9, link.confidence / 1000));
        return {
            color: `rgba(138, 155, 176, ${alpha})`,
            width: isSelected ? this.config.focusedLinkWidth : this.config.linkWidth,
            dash: link.confidence < 400 ? '3 3' : ''
        };
    },

    renderNodes() {
        const layer = this.elements.nodeLayer;
        layer.innerHTML = '';
        
        for (const node of this.state.nodes) {
            const isFocused = String(node.id) === this.state.focusId;
            const isSelected = String(node.id) === this.state.selectedNodeId;
            const isHovered = String(node.id) === this.state.hoveredNode;
            
            const g = this.createElement('g');
            g.setAttribute('class', 'kg-node');
            g.setAttribute('data-node-id', String(node.id));
            g.style.cursor = 'pointer';
            
            const outerRadius = isHovered ? 20 : 16;
            const outerCircle = this.createCircle(node.x, node.y, outerRadius, 'transparent', 'transparent', 0);
            g.appendChild(outerCircle);
            
            const radius = isFocused ? this.config.focusedNodeRadius : this.config.nodeRadius;
            const strokeWidth = isFocused ? 2 : 1.25;
            const strokeColor = isFocused ? this.config.colors.focusedNodeStroke : this.config.colors.nodeStroke;
            const fillColor = isFocused ? this.config.colors.focusedNode : this.config.colors.nodeFill;
            
            const circle = this.createCircle(node.x, node.y, radius, fillColor, strokeColor, strokeWidth);
            g.appendChild(circle);
            
            g.addEventListener('click', (e) => {
                e.stopPropagation();
                this.selectNode(node);
            });
            
            g.addEventListener('mouseenter', () => {
                this.state.hoveredNode = String(node.id);
                this.renderNodes();
            });
            
            g.addEventListener('mouseleave', () => {
                this.state.hoveredNode = null;
                this.renderNodes();
            });
            
            // Drag handlers for moving nodes
            g.addEventListener('mousedown', (e) => {
                if (this.state.isDragging || this.state.draggingNode) return;
                
                this.state.draggingNode = node;
                this.state.dragStart = { 
                    x: e.clientX, 
                    y: e.clientY, 
                    nodeX: node.x, 
                    nodeY: node.y 
                };
                e.stopPropagation();
                e.preventDefault();
            });
            
            layer.appendChild(g);
        }
    },

    saveNodePosition(node) {
        // Save node position to localStorage
        try {
            const positions = JSON.parse(localStorage.getItem('kg-node-positions') || '{}');
            positions[node.id] = { x: node.x, y: node.y };
            localStorage.setItem('kg-node-positions', JSON.stringify(positions));

            // Keep the in-memory copy in step, or the next layout snaps the
            // node back to where it was before the drag.
            this.state.savedPositions = positions;
        } catch (e) {
            console.warn('Failed to save node position:', e);
        }
    },

    // Loading state helpers
    setLoading(formId, isLoading = true) {
        if (isLoading) {
            this.state.loadingForms.add(formId);
        } else {
            this.state.loadingForms.delete(formId);
        }
        this.updateLoadingIndicators();
    },

    updateLoadingIndicators() {
        const forms = document.querySelectorAll('form[data-ajax]');
        forms.forEach(form => {
            const formId = form.id;
            const isLoading = this.state.loadingForms.has(formId);
            const submitBtn = form.querySelector('button[type="submit"]');
            
            if (submitBtn) {
                submitBtn.disabled = isLoading;
                if (isLoading) {
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    const originalText = submitBtn.dataset.originalText || submitBtn.textContent;
                    submitBtn.dataset.originalText = originalText;
                    submitBtn.textContent = 'Processing...';
                } else {
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    if (submitBtn.dataset.originalText) {
                        submitBtn.textContent = submitBtn.dataset.originalText;
                    }
                }
            }
        });
    },

    renderTextLabels() {
        const layer = this.elements.textLayer;
        layer.innerHTML = '';
        
        for (const node of this.state.nodes) {
            if (!Number.isFinite(node.x) || !Number.isFinite(node.y)) {
                continue;
            }

            const isHovered = String(node.id) === this.state.hoveredNode;

            // Name label
            const name = this.createText(node.x, node.y + 22, node.name, {
                'font-size': this.config.font.size,
                'font-family': this.config.font.family,
                'text-anchor': 'middle',
                'fill': isHovered ? this.config.colors.text : this.config.colors.textSecondary,
            });
            name.setAttribute('class', 'kg-node-label');

            layer.appendChild(name);
        }
    },

    createLine(x1, y1, x2, y2, stroke, strokeWidth, opacity = 1, className = '') {
        const line = this.createElement('line');
        line.setAttribute('x1', String(x1));
        line.setAttribute('y1', String(y1));
        line.setAttribute('x2', String(x2));
        line.setAttribute('y2', String(y2));
        line.setAttribute('stroke', stroke);
        line.setAttribute('stroke-width', String(strokeWidth));
        line.setAttribute('opacity', String(opacity));
        if (className) line.setAttribute('class', className);
        return line;
    },

    createCircle(cx, cy, r, fill, stroke, strokeWidth, opacity = 1, strokeDash = '') {
        const circle = this.createElement('circle');
        circle.setAttribute('cx', String(cx));
        circle.setAttribute('cy', String(cy));
        circle.setAttribute('r', String(r));
        circle.setAttribute('fill', fill);
        circle.setAttribute('stroke', stroke);
        circle.setAttribute('stroke-width', String(strokeWidth));
        circle.setAttribute('opacity', String(opacity));
        if (strokeDash) circle.setAttribute('stroke-dasharray', strokeDash);
        return circle;
    },

    createText(x, y, text, attrs = {}) {
        const textEl = this.createElement('text');
        textEl.setAttribute('x', String(x));
        textEl.setAttribute('y', String(y));
        textEl.textContent = text;
        
        for (const [key, value] of Object.entries(attrs)) {
            textEl.setAttribute(key, String(value));
        }
        
        return textEl;
    },

    applyForceLayout() {
        const nodes = this.state.nodes;
        const links = this.state.links;
        const center = { x: this.config.width / 2, y: this.config.height / 2 };

        // If no nodes, nothing to layout
        if (nodes.length === 0) return;

        // Restore saved positions; only the nodes without one are laid out.
        // A node left without coordinates would render at NaN and vanish.
        const savedPositions = this.state.savedPositions || {};
        const unplaced = [];

        for (const node of nodes) {
            const saved = savedPositions[node.id];
            if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) {
                node.x = saved.x;
                node.y = saved.y;
            } else {
                unplaced.push(node);
            }
        }

        if (unplaced.length === 0) return;

        // If we have a focused node, use hop-based layout
        if (this.state.focusId) {
            this.layoutByHops(this.state.focusId, unplaced, nodes, links, center);
        } else {
            // No focus - use spiral layout for all nodes
            this.layoutSpiral(unplaced, center);
        }
    },

    // Layout nodes in concentric circles based on hop distance from focus
    layoutByHops(focusId, nodesToPlace, allNodes, links, center) {
        // Calculate hop distances from focus
        const hopDistances = this.calculateHopDistances(focusId, allNodes, links);

        // Group nodes by hop distance
        const nodesByHop = {};
        nodesToPlace.forEach(node => {
            const hop = hopDistances[node.id] ?? 0;
            if (!nodesByHop[hop]) {
                nodesByHop[hop] = [];
            }
            nodesByHop[hop].push(node);
        });

        // Sort hop distances
        const hopLevels = Object.keys(nodesByHop).map(Number).sort((a, b) => a - b);

        // Position nodes in concentric circles by hop level
        hopLevels.forEach(hop => {
            const hopNodes = nodesByHop[hop];
            // Hop 0 is the focus itself: dead centre. The outer rings grow
            // with distance but stay inside the stage, so a node that is far
            // away (or unconnected) is still visible.
            const radius = hop === 0 ? 0 : Math.min(150 + (hop * 120), 360);
            const nodeCount = hopNodes.length;
            const ringOffset = (-Math.PI / 2) + (hop * 0.25);

            hopNodes.forEach((node, index) => {
                // Deterministic spread — a random offset would reshuffle the
                // constellation on every reload.
                const angle = ringOffset + ((index / Math.max(nodeCount, 1)) * Math.PI * 2);

                node.x = center.x + Math.cos(angle) * radius;
                node.y = center.y + Math.sin(angle) * radius;
            });
        });
    },

    // Layout nodes in a spiral pattern (for unfocused view)
    layoutSpiral(nodes, center) {
        const nodeCount = nodes.length;
        const maxRadius = Math.min(this.config.width, this.config.height) * 0.45;
        const minRadius = 100;
        
        nodes.forEach((node, index) => {
            const fraction = index / Math.max(nodeCount - 1, 1);
            const radius = minRadius + (maxRadius - minRadius) * fraction;
            const angle = index * 0.349; // ~20 degrees per node in radians
            
            node.x = center.x + Math.cos(angle) * radius;
            node.y = center.y + Math.sin(angle) * radius;
        });
    },

    // Calculate hop distance from focus node for each node using BFS
    calculateHopDistances(focusId, nodes, links) {
        const distances = {};
        
        if (!focusId) {
            nodes.forEach(node => {
                distances[node.id] = 0;
            });
            return distances;
        }
        
        // Breadth-first walk from the focus: every node's hop distance.
        const queue = [];
        const visited = new Set();
        
        queue.push({ id: focusId, distance: 0 });
        visited.add(focusId);
        distances[focusId] = 0;
        
        while (queue.length > 0) {
            const current = queue.shift();
            const currentId = current.id;
            const currentDist = current.distance;
            
            const neighbors = new Set();
            links.forEach(link => {
                if (String(link.from) === currentId) {
                    neighbors.add(String(link.to));
                }
                if (String(link.to) === currentId) {
                    neighbors.add(String(link.from));
                }
            });
            
            neighbors.forEach(id => {
                if (!visited.has(id)) {
                    visited.add(id);
                    distances[id] = currentDist + 1;
                    queue.push({ id, distance: currentDist + 1 });
                }
            });
        }
        
        // Mark unreachable nodes with a large distance
        nodes.forEach(node => {
            if (!distances.hasOwnProperty(node.id)) {
                distances[node.id] = 10; // Far away
            }
        });
        
        return distances;
    },

    // Form handlers
    async handleNewEntitySubmit(e) {
        e.preventDefault();

        const form = e.target;

        await this.write(form.action, {
            method: 'POST',
            headers: this.getAjaxHeaders(),
            body: new FormData(form),
            formId: form.id,
            failure: 'Failed to create entry.',
            onSuccess: (data) => {
                if (data.entry) {
                    // Focus on new node — the reload it triggers lays the
                    // node out, so no optimistic coordinates are needed.
                    this.focusNode(data.entry.id);

                    const partial = (data.unresolved_related || []).length > 0;
                    this.showStatus(data.message || 'Entry created.', partial ? 'info' : 'success');
                }

                form.reset();
            },
        });
    },

    async handleNewLinkSubmit(e) {
        e.preventDefault();

        const form = e.target;

        await this.write(form.action, {
            method: 'POST',
            headers: this.getAjaxHeaders(),
            body: new FormData(form),
            formId: form.id,
            failure: 'Failed to create link.',
            onSuccess: (data) => {
                if (data.link) {
                    // Add new link to state
                    const link = this.toLink(data.link);
                    this.state.links.push(link);

                    // Focus on from node
                    this.focusNode(link.from);

                    // Select the new link
                    this.selectLink(link);

                    this.showStatus(data.message || 'Link created.', 'success');
                }

                form.reset();
            },
        });
    },

    async handleFixLinkSubmit(e) {
        e.preventDefault();

        const form = e.target;
        const linkId = this.state.selectedLinkId;

        if (!linkId) return;

        await this.write(`/api/v1/knowledge/links/${linkId}`, {
            method: 'PUT',
            headers: this.getAjaxHeaders(),
            body: new FormData(form),
            formId: form.id,
            failure: 'Failed to fix link.',
            onSuccess: (data) => {
                if (data.link) {
                    // Update link in state
                    const index = this.state.links.findIndex(l => String(l.id) === linkId);
                    if (index !== -1) {
                        this.state.links[index] = this.toLink(data.link);

                        // The inspector has to show the edge as it is now,
                        // not as it was before the fix.
                        this.showLink(this.state.links[index]);
                    }

                    this.render();
                    this.showStatus(data.message || 'Link fixed.', 'success');
                }
            },
        });
    },

    async handleConfirmLinkSubmit(e) {
        e.preventDefault();

        const linkId = this.state.selectedLinkId;
        if (!linkId) return;

        await this.write(`/api/v1/knowledge/links/${linkId}/confirm`, {
            method: 'POST',
            headers: this.getAjaxHeaders(),
            formId: 'kg-confirm-form',
            failure: 'Failed to confirm link.',
            onSuccess: (data) => {
                if (data.link) {
                    const link = this.state.links.find(l => String(l.id) === linkId);
                    if (link) {
                        link.confidence = data.link.confidence;
                        this.render();
                    }
                }

                this.showStatus(data.message || 'Link confirmed.', 'success');
            },
        });
    },

    async handlePermanentLinkSubmit(e, isPermanent) {
        e.preventDefault();

        const linkId = this.state.selectedLinkId;
        if (!linkId) return;

        await this.write(`/api/v1/knowledge/links/${linkId}/permanent`, {
            method: 'POST',
            headers: this.getJsonHeaders(),
            body: JSON.stringify({ permanent: isPermanent }),
            formId: isPermanent ? 'kg-permanent-form' : 'kg-unpermanent-form',
            failure: 'Failed to update permanent status.',
            onSuccess: (data) => {
                if (data.link) {
                    const link = this.state.links.find(l => String(l.id) === linkId);
                    if (link) {
                        link.permanent = data.link.permanent;
                        link.confidence = data.link.confidence;
                        this.render();
                    }
                }

                this.showStatus(data.message || (isPermanent ? 'Link marked permanent.' : 'Permanent lock lifted.'), 'success');
            },
        });
    },

    async handleRetractLinkSubmit(e) {
        e.preventDefault();

        const linkId = this.state.selectedLinkId;
        if (!linkId) return;

        if (!confirm('Retract this link? Only you can bring it back — agents cannot re-assert it.')) {
            return;
        }

        await this.write(`/api/v1/knowledge/links/${linkId}/retract`, {
            method: 'POST',
            headers: this.getAjaxHeaders(),
            formId: 'kg-retract-form',
            failure: 'Failed to retract link.',
            onSuccess: (data) => {
                // Remove link from state
                this.state.links = this.state.links.filter(l => String(l.id) !== linkId);

                this.clearSelection();

                this.showStatus(data.message || 'Link retracted.', 'success');
            },
        });
    },

    handleNodeEdit(e) {
        e.preventDefault();
        
        const nodeId = this.state.selectedNodeId;
        if (!nodeId) return;
        
        // Redirect to edit page
        window.location.href = `/console/knowledge/${nodeId}/edit`;
    },

    handleDelete() {
        if (this.state.selectedNodeId) {
            if (confirm('Delete this node? Its links and observations go with it.')) {
                this.deleteNode(this.state.selectedNodeId);
            }
        } else if (this.state.selectedLinkId) {
            if (confirm('Delete this link? Its observations go with it.')) {
                this.deleteLink(this.state.selectedLinkId);
            }
        }
    },

    async deleteNode(nodeId) {
        await this.write(`/api/v1/knowledge/entries/${nodeId}`, {
            method: 'DELETE',
            headers: this.getAjaxHeaders(),
            failure: 'Failed to delete node.',
            onError: (error) => console.error('Error deleting node:', error),
            onSuccess: (data) => {
                this.state.nodes = this.state.nodes.filter(n => String(n.id) !== nodeId);
                this.state.links = this.state.links.filter(l =>
                    String(l.from) !== nodeId && String(l.to) !== nodeId
                );

                this.clearSelection();

                this.showStatus(data.message || 'Node deleted.', 'success');
                this.render();
            },
        });
    },

    async deleteLink(linkId) {
        await this.write(`/api/v1/knowledge/links/${linkId}`, {
            method: 'DELETE',
            headers: this.getAjaxHeaders(),
            failure: 'Failed to delete link.',
            onError: (error) => console.error('Error deleting link:', error),
            onSuccess: (data) => {
                this.state.links = this.state.links.filter(l => String(l.id) !== linkId);

                this.clearSelection();

                this.showStatus(data.message || 'Link deleted.', 'success');
                this.render();
            },
        });
    },

    showStatus(message, type = 'info') {
        const existing = document.querySelectorAll('.kg-status-message');
        existing.forEach(el => el.remove());
        
        const statusEl = document.createElement('div');
        statusEl.className = `kg-status-message fixed bottom-4 left-4 px-4 py-2 font-mono text-xs rounded ${
            type === 'error' ? 'bg-red-900/50 text-red-400 border border-red-600' :
            type === 'success' ? 'bg-green-900/50 text-green-400 border border-green-600' :
            'bg-void/80 text-steel border border-border'
        }`;
        statusEl.textContent = message;
        statusEl.style.zIndex = '1000';
        
        document.body.appendChild(statusEl);
        
        setTimeout(() => {
            statusEl.remove();
        }, 5000);
    },
};

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('knowledge-graph');
    if (root) {
        KnowledgeGraphSimple.init(root).catch((error) => {
            console.error('Failed to start the graph viewer:', error);
            KnowledgeGraphSimple.showStatus('The graph viewer could not start. Please reload the page.', 'error');
        });
    }
});
