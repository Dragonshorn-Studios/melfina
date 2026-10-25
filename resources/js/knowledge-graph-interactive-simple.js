// Interactive Knowledge Graph Viewer - Lightweight Version
// No D3.js dependency - uses vanilla JavaScript

const KnowledgeGraphSimple = {
    config: {
        width: 800,
        height: 800,
        nodeRadius: 8,
        focusedNodeRadius: 10,
        linkWidth: 1.25,
        focusedLinkWidth: 2.5,
        colors: {
            background: '#152238',
            node: '#E8E4D8',
            nodeFill: '#152238',
            nodeStroke: '#E8E4D8',
            focusedNode: '#E8A838',
            focusedNodeStroke: '#E8A838',
            link: 'rgba(138, 155, 176, 0.6)',
            permanentLink: '#E8A838',
            disputedLink: '#C45C4A',
            text: '#E8E4D8',
            textSecondary: '#8A9BB0',
            compass: '#E8A838',
            grid: '#2A3A52',
        },
        font: {
            family: 'IBM Plex Mono, ui-monospace, monospace',
            size: 12,
            small: 10,
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
        hoveredLink: null,
        draggingNode: null,
        searchQuery: '',
        predicates: [],
        types: [],
        loadingForms: new Set(),
    },

    // Get CSRF token for AJAX requests
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    },

    // Default headers for AJAX requests
    getAjaxHeaders() {
        return {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': this.getCsrfToken(),
        };
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
        
        // Parse initial data
        this.parseInitialData();
        
        // Load predicates and types
        await this.loadMetadata();
        
        // Setup SVG layers
        this.setupSVG();
        
        // Setup event listeners
        this.setupEvents();
        
        // Initial render
        this.render();
        
        // Load fresh data from API
        await this.loadData();
        

    },

    parseInitialData() {
        const dataEl = document.getElementById('kg-data');
        const routesEl = document.getElementById('kg-routes');
        
        if (dataEl) {
            const snapshot = JSON.parse(dataEl.textContent);
            this.state.nodes = snapshot.nodes || [];
            this.state.links = snapshot.links || [];
        }
        
        if (this.elements.root) {
            this.state.focusId = this.elements.root.dataset.focus || (this.state.nodes[0]?.id ? String(this.state.nodes[0].id) : null);
            this.state.selectedLinkId = this.elements.root.dataset.link || '';
        }
    },

    async loadMetadata() {
        try {
            const [predicatesRes, typesRes] = await Promise.all([
                fetch('/api/knowledge/predicates'),
                fetch('/api/knowledge/types'),
            ]);
            
            if (predicatesRes.ok) {
                const data = await predicatesRes.json();
                this.state.predicates = data.predicates || [];
            }
            
            if (typesRes.ok) {
                const data = await typesRes.json();
                this.state.types = data.types || [];
            }
        } catch (error) {
            console.error('Failed to load metadata:', error);
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
            
            const response = await fetch(`/api/knowledge/graph?${params.toString()}`);
            
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
            }
        } catch (error) {
            console.error('Failed to load graph data:', error);
        }
    },

    setupSVG() {
        const svg = this.elements.svg;
        
        // Clear existing content
        while (svg.firstChild) {
            svg.removeChild(svg.firstChild);
        }

        // Create layers
        this.elements.gridLayer = this.createElement('g');
        this.elements.compassLayer = this.createElement('g');
        this.elements.linkLayer = this.createElement('g');
        this.elements.nodeLayer = this.createElement('g');
        this.elements.textLayer = this.createElement('g');
        this.elements.overlay = this.createElement('g');

        svg.appendChild(this.elements.gridLayer);
        svg.appendChild(this.elements.compassLayer);
        svg.appendChild(this.elements.linkLayer);
        svg.appendChild(this.elements.nodeLayer);
        svg.appendChild(this.elements.textLayer);
        svg.appendChild(this.elements.overlay);
    },

    createElement(tag) {
        return document.createElementNS('http://www.w3.org/2000/svg', tag);
    },

    setupEvents() {
        const svg = this.elements.svg;
        
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
        // New entity form
        const newEntityForm = document.getElementById('kg-new-entity-form');
        if (newEntityForm) {
            newEntityForm.addEventListener('submit', (e) => this.handleNewEntitySubmit(e));
        }

        // New link form
        const newLinkForm = document.getElementById('kg-new-link-form');
        if (newLinkForm) {
            newLinkForm.addEventListener('submit', (e) => this.handleNewLinkSubmit(e));
        }

        // Link fix form
        const fixForm = document.getElementById('kg-fix-form');
        if (fixForm) {
            fixForm.addEventListener('submit', (e) => this.handleFixLinkSubmit(e));
        }

        // Confirm form
        const confirmForm = document.getElementById('kg-confirm-form');
        if (confirmForm) {
            confirmForm.addEventListener('submit', (e) => this.handleConfirmLinkSubmit(e));
        }

        // Permanent forms
        const permanentForm = document.getElementById('kg-permanent-form');
        const unpermanentForm = document.getElementById('kg-unpermanent-form');
        
        if (permanentForm) {
            permanentForm.addEventListener('submit', (e) => this.handlePermanentLinkSubmit(e, true));
        }
        
        if (unpermanentForm) {
            unpermanentForm.addEventListener('submit', (e) => this.handlePermanentLinkSubmit(e, false));
        }

        // Retract form
        const retractForm = document.getElementById('kg-retract-form');
        if (retractForm) {
            retractForm.addEventListener('submit', (e) => this.handleRetractLinkSubmit(e));
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
                    const response = await fetch(`/api/knowledge/entries?q=${encodeURIComponent(this.state.searchQuery)}&limit=10`);
                    if (response.ok) {
                        const data = await response.json();
                        this.updateSearchResults(data.results || []);
                    }
                } catch (error) {
                    console.error('Search failed:', error);
                }
            }, 300);
        });

        searchInput.addEventListener('blur', () => {
            setTimeout(() => this.updateSearchResults([]), 200);
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
            option.dataset.type = r.type;
            resultsEl.appendChild(option);
        });
        
        resultsEl.classList.remove('hidden');
    },

    // Pan/Zoom handlers
    handleWheel(e) {
        e.preventDefault();
        
        const delta = e.deltaY > 0 ? 0.9 : 1.1;
        const svg = this.elements.svg;
        const pt = svg.createSVGPoint();
        pt.x = e.clientX;
        pt.y = e.clientY;
        const mousePos = pt.matrixTransform(svg.getScreenCTM().inverse());
        
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
        if (!this.state.isDragging || !this.state.dragStart) return;
        
        const dx = e.clientX - this.state.dragStart.x;
        const dy = e.clientY - this.state.dragStart.y;
        
        this.state.transform.x += dx / this.state.transform.k;
        this.state.transform.y += dy / this.state.transform.k;
        
        this.state.dragStart = { x: e.clientX, y: e.clientY };
        this.applyTransform();
        e.preventDefault();
    },

    handlePanEnd() {
        this.state.isDragging = false;
        this.state.dragStart = null;
    },

    applyTransform() {
        const transform = `translate(${this.state.transform.x}, ${this.state.transform.y}) scale(${this.state.transform.k})`;
        this.elements.linkLayer.setAttribute('transform', transform);
        this.elements.nodeLayer.setAttribute('transform', transform);
        this.elements.textLayer.setAttribute('transform', transform);
        this.elements.overlay.setAttribute('transform', transform);
    },

    // Keyboard handlers
    handleKeyDown(event) {
        // Ignore if typing in an input
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') {
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
        
        // Center view on node
        const centerX = this.config.width / 2;
        const centerY = this.config.height / 2;
        
        this.state.transform.x = centerX - node.x;
        this.state.transform.y = centerY - node.y;
        this.state.transform.k = 1;
        
        this.applyTransform();
        this.updateURL();
        this.showNode(node);
        
        // Reload data focused on this node
        this.loadData(nodeId);
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

    // Selection management
    clearSelection() {
        this.state.selectedNodeId = null;
        this.state.selectedLinkId = null;
        this.state.hoveredNode = null;
        this.state.hoveredLink = null;
        
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

    // Display functions
    showEmpty() {
        const emptyEl = document.getElementById('kg-inspect-empty');
        const nodeEl = document.getElementById('kg-inspect-node');
        const linkEl = document.getElementById('kg-inspect-link');
        
        if (emptyEl) emptyEl.classList.remove('hidden');
        if (nodeEl) nodeEl.classList.add('hidden');
        if (linkEl) linkEl.classList.add('hidden');
    },

    showNode(node) {
        const emptyEl = document.getElementById('kg-inspect-empty');
        const nodeEl = document.getElementById('kg-inspect-node');
        const linkEl = document.getElementById('kg-inspect-link');
        
        if (emptyEl) emptyEl.classList.add('hidden');
        if (nodeEl) nodeEl.classList.remove('hidden');
        if (linkEl) linkEl.classList.add('hidden');
        
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
        const emptyEl = document.getElementById('kg-inspect-empty');
        const nodeEl = document.getElementById('kg-inspect-node');
        const linkEl = document.getElementById('kg-inspect-link');
        
        if (emptyEl) emptyEl.classList.add('hidden');
        if (nodeEl) nodeEl.classList.add('hidden');
        if (linkEl) linkEl.classList.remove('hidden');
        
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
            
            // Update form actions
            const fixForm = document.getElementById('kg-fix-form');
            const confirmForm = document.getElementById('kg-confirm-form');
            const permanentForm = document.getElementById('kg-permanent-form');
            const unpermanentForm = document.getElementById('kg-unpermanent-form');
            const retractForm = document.getElementById('kg-retract-form');
            
            const linkUrl = (template, id) => template.replace(/\/0(\/|$)/, `/${id}$1`);
            
            if (fixForm) fixForm.action = linkUrl(fixForm.action, link.id);
            if (confirmForm) confirmForm.action = linkUrl(confirmForm.action, link.id);
            if (permanentForm) permanentForm.action = linkUrl(permanentForm.action, link.id);
            if (unpermanentForm) unpermanentForm.action = linkUrl(unpermanentForm.action, link.id);
            if (retractForm) retractForm.action = linkUrl(retractForm.action, link.id);
            
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

    // Render functions
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
            const line = this.createElement('line');
            line.setAttribute('x1', String(x));
            line.setAttribute('y1', String(-halfHeight));
            line.setAttribute('x2', String(x));
            line.setAttribute('y2', String(halfHeight));
            line.setAttribute('stroke', this.config.colors.grid);
            line.setAttribute('stroke-width', '0.5');
            line.setAttribute('opacity', '0.3');
            layer.appendChild(line);
        }
        
        for (let y = -halfHeight; y <= halfHeight; y += gridSize) {
            const line = this.createElement('line');
            line.setAttribute('x1', String(-halfWidth));
            line.setAttribute('y1', String(y));
            line.setAttribute('x2', String(halfWidth));
            line.setAttribute('y2', String(y));
            line.setAttribute('stroke', this.config.colors.grid);
            line.setAttribute('stroke-width', '0.5');
            line.setAttribute('opacity', '0.3');
            layer.appendChild(line);
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
            const isHovered = String(link.id) === this.state.hoveredLink;
            
            // Calculate stroke properties
            const stroke = this.getLinkStroke(link, isSelected);
            
            // Create hit detection area (thicker, transparent)
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
            hitLine.addEventListener('mouseenter', () => {
                this.state.hoveredLink = String(link.id);
                this.renderLinks();
            });
            hitLine.addEventListener('mouseleave', () => {
                this.state.hoveredLink = null;
                this.renderLinks();
            });
            layer.appendChild(hitLine);
            
            // Create visible line
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
            
            // Add label
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
            
            // Outer circle (for hover effect)
            const outerRadius = isHovered ? 20 : 16;
            const outerCircle = this.createCircle(node.x, node.y, outerRadius, 'transparent', 'transparent', 0);
            g.appendChild(outerCircle);
            
            // Main circle
            const radius = isFocused ? this.config.focusedNodeRadius : this.config.nodeRadius;
            const strokeWidth = isFocused ? 2 : 1.25;
            const strokeColor = isFocused ? this.config.colors.focusedNodeStroke : this.config.colors.nodeStroke;
            const fillColor = isFocused ? this.config.colors.focusedNode : this.config.colors.nodeFill;
            
            const circle = this.createCircle(node.x, node.y, radius, fillColor, strokeColor, strokeWidth);
            g.appendChild(circle);
            
            // Click handler
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
            let isDragging = false;
            let dragStart = null;
            
            g.addEventListener('mousedown', (e) => {
                if (this.state.isDragging) return;
                
                isDragging = true;
                dragStart = { x: e.clientX, y: e.clientY, nodeX: node.x, nodeY: node.y };
                e.stopPropagation();
                e.preventDefault();
            });
            
            document.addEventListener('mousemove', (e) => {
                if (!isDragging || !dragStart) return;
                
                const dx = (e.clientX - dragStart.x) / this.state.transform.k;
                const dy = (e.clientY - dragStart.y) / this.state.transform.k;
                
                node.x = dragStart.nodeX + dx;
                node.y = dragStart.nodeY + dy;
                
                this.renderNodes();
                this.renderTextLabels();
                this.renderLinks();
            });
            
            document.addEventListener('mouseup', () => {
                if (isDragging) {
                    isDragging = false;
                    dragStart = null;
                    // Save position
                    this.saveNodePosition(node);
                }
            });
            
            layer.appendChild(g);
        }
    },

    saveNodePosition(node) {
        // For now, just keep in local state
        // Node positions are stored locally and reset on page reload
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
            const isFocused = String(node.id) === this.state.focusId;
            const isSelected = String(node.id) === this.state.selectedNodeId;
            const isHovered = String(node.id) === this.state.hoveredNode;
            
            const g = this.createElement('g');
            
            // Name label
            const name = this.createText(node.x, node.y + 22, node.name, {
                'font-size': this.config.font.size,
                'font-family': this.config.font.family,
                'text-anchor': 'middle',
                'fill': isHovered ? this.config.colors.text : this.config.colors.textSecondary,
            });
            name.setAttribute('class', 'kg-node-label');
            
            g.appendChild(name);
            layer.appendChild(g);
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

    // Layout algorithm
    applyForceLayout() {
        const nodes = this.state.nodes;
        const links = this.state.links;
        const center = { x: this.config.width / 2, y: this.config.height / 2 };
        
        // If no nodes, nothing to layout
        if (nodes.length === 0) return;
        
        // If we have a focused node, use hop-based layout
        if (this.state.focusId) {
            const focusNode = nodes.find(n => String(n.id) === this.state.focusId);
            
            // Position focus at center
            if (focusNode) {
                focusNode.x = center.x;
                focusNode.y = center.y;
            }
            
            // Use hop-based layout with concentric circles
            this.layoutByHops(this.state.focusId, nodes, links, center);
        } else {
            // No focus - use spiral layout for all nodes
            this.layoutSpiral(nodes, center);
        }
    },

    // Layout nodes in concentric circles based on hop distance from focus
    layoutByHops(focusId, nodes, links, center) {
        // Calculate hop distances from focus
        const hopDistances = this.calculateHopDistances(focusId, nodes, links);
        
        // Group nodes by hop distance
        const nodesByHop = {};
        nodes.forEach(node => {
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
            const radius = 150 + (hop * 120);
            const nodeCount = hopNodes.length;
            
            hopNodes.forEach((node, index) => {
                const angle = (index / Math.max(nodeCount, 1)) * Math.PI * 2;
                
                // Add small random offset for better distribution
                const randomOffset = ((Math.random() - 0.5) * 0.3); // +/- 30% of angle spacing
                const finalAngle = angle + randomOffset;
                
                node.x = center.x + Math.cos(finalAngle) * radius;
                node.y = center.y + Math.sin(finalAngle) * radius;
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
        
        // BFS to calculate hop distances
        const queue = [];
        const visited = new Set();
        
        queue.push({ id: focusId, distance: 0 });
        visited.add(focusId);
        distances[focusId] = 0;
        
        while (queue.length > 0) {
            const current = queue.shift();
            const currentId = current.id;
            const currentDist = current.distance;
            
            // Find all neighbors of current node
            const neighbors = new Set();
            links.forEach(link => {
                if (String(link.from) === currentId) {
                    neighbors.add(String(link.to));
                }
                if (String(link.to) === currentId) {
                    neighbors.add(String(link.from));
                }
            });
            
            // Add unvisited neighbors to queue
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
        const formId = form.id;
        const formData = new FormData(form);
        
        // Add return parameter
        formData.append('return', 'graph');
        
        this.setLoading(formId, true);
        
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: this.getAjaxHeaders(),
                body: formData,
            });
            
            if (response.ok) {
                const data = await response.json();
                
                if (data.entry) {
                    // Add new node to state
                    this.state.nodes.push({
                        id: data.entry.id,
                        name: data.entry.name,
                        type: data.entry.type,
                        summary: data.entry.summary,
                    });
                    
                    // Focus on new node
                    this.focusNode(data.entry.id);
                    
                    // Show success message
                    this.showStatus(data.message || 'Entry created.', 'success');
                }
                
                // Reset form
                form.reset();
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to create entry.', 'error');
            }
        } catch (error) {
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            this.setLoading(formId, false);
        }
    },

    async handleNewLinkSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formId = form.id;
        const formData = new FormData(form);
        
        this.setLoading(formId, true);
        
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: this.getAjaxHeaders(),
                body: formData,
            });
            
            if (response.ok) {
                const data = await response.json();
                
                if (data.link) {
                    // Add new link to state
                    this.state.links.push({
                        id: data.link.id,
                        from: data.link.from_id,
                        to: data.link.to_id,
                        predicate: data.link.predicate,
                        confidence: data.link.confidence,
                        permanent: data.link.permanent,
                        disputed: data.link.disputed,
                    });
                    
                    // Focus on from node
                    this.focusNode(data.link.from_id);
                    
                    // Select the new link
                    this.selectLink(data.link);
                    
                    this.showStatus(data.message || 'Link created.', 'success');
                }
                
                form.reset();
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to create link.', 'error');
            }
        } catch (error) {
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            this.setLoading(formId, false);
        }
    },

    async handleFixLinkSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formId = form.id;
        const formData = new FormData(form);
        const linkId = this.state.selectedLinkId;
        
        if (!linkId) return;
        
        this.setLoading(formId, true);
        
        try {
            const response = await fetch(`/api/knowledge/links/${linkId}`, {
                method: 'PUT',
                headers: this.getAjaxHeaders(),
                body: formData,
            });
            
            if (response.ok) {
                const data = await response.json();
                
                if (data.link) {
                    // Update link in state
                    const index = this.state.links.findIndex(l => String(l.id) === linkId);
                    if (index !== -1) {
                        this.state.links[index] = {
                            id: data.link.id,
                            from: data.link.from_id,
                            to: data.link.to_id,
                            predicate: data.link.predicate,
                            confidence: data.link.confidence,
                            permanent: data.link.permanent,
                            disputed: data.link.disputed,
                        };
                    }
                    
                    this.render();
                    this.showStatus(data.message || 'Link fixed.', 'success');
                }
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to fix link.', 'error');
            }
        } catch (error) {
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            this.setLoading(formId, false);
        }
    },

    async handleConfirmLinkSubmit(e) {
        e.preventDefault();
        
        const linkId = this.state.selectedLinkId;
        if (!linkId) return;
        
        const formId = 'kg-confirm-form';
        this.setLoading(formId, true);
        
        try {
            const response = await fetch(`/api/knowledge/links/${linkId}/confirm`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
            
            if (response.ok) {
                const data = await response.json();
                
                if (data.link) {
                    const link = this.state.links.find(l => String(l.id) === linkId);
                    if (link) {
                        link.confidence = data.link.confidence;
                        this.render();
                    }
                }
                
                this.showStatus(data.message || 'Link confirmed.', 'success');
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to confirm link.', 'error');
            }
        } catch (error) {
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            this.setLoading(formId, false);
        }
    },

    async handlePermanentLinkSubmit(e, isPermanent) {
        e.preventDefault();
        
        const linkId = this.state.selectedLinkId;
        if (!linkId) return;
        
        const formId = isPermanent ? 'kg-permanent-form' : 'kg-unpermanent-form';
        this.setLoading(formId, true);
        
        try {
            const response = await fetch(`/api/knowledge/links/${linkId}/permanent`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ permanent: isPermanent }),
            });
            
            if (response.ok) {
                const data = await response.json();
                
                if (data.link) {
                    const link = this.state.links.find(l => String(l.id) === linkId);
                    if (link) {
                        link.permanent = data.link.permanent;
                        link.confidence = data.link.confidence;
                        this.render();
                    }
                }
                
                this.showStatus(data.message || (isPermanent ? 'Link marked permanent.' : 'Permanent lock lifted.'), 'success');
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to update permanent status.', 'error');
            }
        } catch (error) {
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            this.setLoading(formId, false);
        }
    },

    async handleRetractLinkSubmit(e) {
        e.preventDefault();
        
        const linkId = this.state.selectedLinkId;
        if (!linkId) return;
        
        if (!confirm('Retract this link? Agents can still re-assert it unless you recreate and lock it.')) {
            return;
        }
        
        const formId = 'kg-retract-form';
        this.setLoading(formId, true);
        
        try {
            const response = await fetch(`/api/knowledge/links/${linkId}/retract`, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
            
            if (response.ok) {
                const data = await response.json();
                
                // Remove link from state
                this.state.links = this.state.links.filter(l => String(l.id) !== linkId);
                
                this.clearSelection();
                
                this.showStatus(data.message || 'Link retracted.', 'success');
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to retract link.', 'error');
            }
        } catch (error) {
            this.showStatus('Network error. Please try again.', 'error');
        } finally {
            this.setLoading(formId, false);
        }
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
            if (confirm('Delete this node? Its whole version history goes with it.')) {
                this.deleteNode(this.state.selectedNodeId);
            }
        } else if (this.state.selectedLinkId) {
            if (confirm('Delete this link?')) {
                this.deleteLink(this.state.selectedLinkId);
            }
        }
    },

    async deleteNode(nodeId) {
        try {
            const response = await fetch(`/api/knowledge/entries/${nodeId}`, {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
            
            if (response.ok) {
                const data = await response.json();
                
                this.state.nodes = this.state.nodes.filter(n => String(n.id) !== nodeId);
                this.state.links = this.state.links.filter(l => 
                    String(l.from) !== nodeId && String(l.to) !== nodeId
                );
                
                this.clearSelection();
                
                this.showStatus(data.message || 'Node deleted.', 'success');
                this.render();
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to delete node.', 'error');
            }
        } catch (error) {
            console.error('Error deleting node:', error);
            this.showStatus('Network error. Please try again.', 'error');
        }
    },

    async deleteLink(linkId) {
        try {
            const response = await fetch(`/api/knowledge/links/${linkId}`, {
                method: 'DELETE',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            });
            
            if (response.ok) {
                const data = await response.json();
                
                this.state.links = this.state.links.filter(l => String(l.id) !== linkId);
                
                this.clearSelection();
                
                this.showStatus(data.message || 'Link deleted.', 'success');
                this.render();
            } else {
                const error = await response.json();
                this.showStatus(error.message || 'Failed to delete link.', 'error');
            }
        } catch (error) {
            console.error('Error deleting link:', error);
            this.showStatus('Network error. Please try again.', 'error');
        }
    },

    showStatus(message, type = 'info') {
        // Remove existing status messages
        const existing = document.querySelectorAll('.kg-status-message');
        existing.forEach(el => el.remove());
        
        // Create new status message
        const statusEl = document.createElement('div');
        statusEl.className = `kg-status-message fixed bottom-4 left-4 px-4 py-2 font-mono text-xs rounded ${
            type === 'error' ? 'bg-red-900/50 text-red-400 border border-red-600' :
            type === 'success' ? 'bg-green-900/50 text-green-400 border border-green-600' :
            'bg-void/80 text-steel border border-border'
        }`;
        statusEl.textContent = message;
        statusEl.style.zIndex = '1000';
        
        document.body.appendChild(statusEl);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            statusEl.remove();
        }, 5000);
    },
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('knowledge-graph');
    if (root) {
        KnowledgeGraphSimple.init(root);
    }
});
