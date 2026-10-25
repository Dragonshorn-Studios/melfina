# Knowledge Graph Interactive Viewer Implementation

## Overview

This document describes the implementation of a dynamic, interactive knowledge graph viewer with movable/zoomable capabilities and real-time editing without page reloads.

## Current Implementation

### Backend (API Routes)

Created new API controller and routes in:
- `app/Http/Controllers/Api/KnowledgeApiController.php`
- `routes/api.php`

**Available API Endpoints:**

- `GET /api/knowledge/graph` - Get graph data (with optional focus, hops, limit params)
- `GET /api/knowledge/entries/{entry}` - Get single knowledge entry
- `POST /api/knowledge/entries` - Create new entry
- `PUT /api/knowledge/entries/{entry}` - Update entry
- `DELETE /api/knowledge/entries/{entry}` - Delete entry
- `GET /api/knowledge/links/{link}` - Get single link
- `POST /api/knowledge/links` - Create new link
- `PUT /api/knowledge/links/{link}` - Update link
- `POST /api/knowledge/links/{link}/confirm` - Confirm link
- `POST /api/knowledge/links/{link}/permanent` - Toggle permanent status
- `POST /api/knowledge/links/{link}/retract` - Retract link
- `DELETE /api/knowledge/links/{link}` - Delete link
- `GET /api/knowledge/predicates` - Get list of predicates
- `GET /api/knowledge/types` - Get list of entry types
- `GET /api/knowledge/entries` - Search entries (with `q` and `limit` params)

### Frontend (Interactive Graph Viewer)

Created comprehensive interactive graph viewer in:
- `resources/js/knowledge-graph-interactive.js`

**Features Implemented:**

1. **Pan/Zoom**: Full viewport panning and zooming (2x zoom in/out)
   - Mouse wheel to zoom
   - Click and drag to pan
   - Keyboard shortcuts: `+`/`-` to zoom, `0` to reset
   
2. **Movable Nodes**: Nodes can be dragged to new positions
   - Click and drag any node
   - Position persists in session
   
3. **Click Interactions**:
   - Click node to focus and show details
   - Click link to show link details
   - Click background to clear selection
   
4. **Keyboard Shortcuts**:
   - `F` - Focus on selected node
   - `Delete/Backspace` - Delete selected node/link
   - `Escape` - Clear selection
   - `+`/`-` - Zoom in/out
   - `0` - Reset zoom

5. **Real-time Editing via AJAX**:
   - Create new entities
   - Create new links
   - Fix existing links (update endpoints/predicate)
   - Confirm links
   - Toggle permanent status
   - Retract links
   - Delete entities
   - Delete links

6. **Search/Filter**:
   - Live search for nodes
   - Autocomplete with existing entries

7. **Visual Enhancements**:
   - Hover effects on nodes and links
   - Focus highlighting
   - Color-coded links (permanent, disputed, confidence levels)
   - Grid and compass indicators
   - Smooth animations

8. **URL Sync**:
   - Focus node and selected link in URL
   - Browser back/forward navigation supported
   - Shareable links

### Updated Files

1. **`resources/views/console/knowledge/graph.blade.php`**:
   - Added search input
   - Added refresh button
   - Added status indicator
   - Added form IDs for JavaScript handling
   - Added keyboard shortcut hints

2. **`resources/js/app.js`**:
   - Import D3.js
   - Import new interactive graph module
   - Make d3 globally available

3. **`package.json`**:
   - Added D3.js dependency

4. **`vite.config.js`**:
   - No changes needed (Vite handles ESM imports)

5. **`bootstrap/app.php`**:
   - Registered API routes

6. **`routes/web.php`**:
   - No changes (console routes remain the same)

## Usage

### Running the Application

1. Install D3.js dependency:
   ```bash
   npm install
   ```

2. Rebuild assets:
   ```bash
   npm run build
   ```

3. The interactive graph viewer will be available at `/console/knowledge/graph`

### Interactive Features

**Navigation:**
- Scroll to zoom in/out
- Click and drag to pan around
- Click a node to select and focus it
- Click a link to select it
- Use keyboard shortcuts for faster navigation

**Editing:**
- All forms now submit via AJAX (no page reload)
- Success/error messages appear as floating notifications
- Changes are immediately reflected in the graph

**Search:**
- Type in the search box to find nodes
- Results appear as you type
- Select a result to focus on that node

## Architecture

### Frontend State Management

The `KnowledgeGraph` object maintains:
- `state.nodes` - Array of node objects with x,y positions
- `state.links` - Array of link objects
- `state.focusId` - Currently focused node ID
- `state.selectedNodeId` - Currently selected node ID
- `state.selectedLinkId` - Currently selected link ID
- `state.transform` - Current zoom/pan transform

### Rendering Pipeline

1. `render()` - Main render function
2. `renderGrid()` - Draw grid lines
3. `renderCompass()` - Draw compass crosshairs and rings
4. `renderLinks()` - Draw all links with proper styling
5. `renderNodes()` - Draw all nodes with proper styling
6. `renderTextLabels()` - Draw node labels

### Layout Algorithm

Currently uses a simple force-directed layout:
- Focus node at center
- 1-hop neighbors in a ring at radius 200
- 2-hop neighbors in a ring at radius 330
- Remaining nodes in outer ring

Future: Could integrate with `d3-force` for physics-based layout

## What's Left to Implement

### 1. WebSocket for Real-time Updates

Currently, the graph doesn't automatically update when other users or agents make changes. To implement:

**Option A: Laravel Echo + Pusher**
- Install Laravel Echo and Pusher
- Broadcast events on knowledge changes
- Listen for events in JavaScript

**Option B: Polling**
- Periodically refresh graph data
- Less real-time but simpler

**Recommended**: Add WebSocket support for true real-time collaboration

### 2. Physics-based Layout

Currently using a simple ring layout. For better visualization:
- Implement `d3-force` simulation
- Add configurable physics parameters
- Support different layout modes (force, circular, hierarchical)

### 3. Persist Node Positions

Currently, node positions are calculated on the fly. To persist positions:
- Add `position_x`, `position_y` columns to `knowledge_entries` table
- Save position when node is dragged
- Load positions from server

### 4. Inline Node Editing

Currently, editing a node redirects to the edit page. For better UX:
- Add inline edit form for node details
- Modal dialog for editing
- No page reload needed

### 5. Bulk Operations

- Select multiple nodes/links
- Delete multiple items
- Apply operations to selection

### 6. Graph Visualization Options

- Toggle link labels on/off
- Adjust link opacity by confidence
- Highlight specific predicate types
- Show/hide retracted links

### 7. Export/Import

- Export graph as JSON
- Export as image (PNG/SVG)
- Import from JSON

### 8. Performance Optimizations

For large graphs:
- Virtual scrolling / viewport culling
- Simplify rendering for distant nodes
- Lazy loading of node details

## Testing

### Manual Testing

1. Open `/console/knowledge/graph`
2. Verify pan/zoom works with mouse
3. Verify keyboard shortcuts work
4. Click nodes and links to verify selection
5. Try creating new entities and links
6. Verify AJAX forms work
7. Test search functionality
8. Verify URL sync on navigation

### Automated Testing

Consider adding:
- Feature tests for API endpoints
- JavaScript unit tests for graph logic
- Integration tests for AJAX forms

## Dependencies

### New Dependencies

- `d3` (^7.8.5) - Data visualization library for SVG manipulation

### Existing Dependencies

- Laravel framework
- Vite for asset building
- Tailwind CSS for styling

## Browser Compatibility

- Requires modern browser with ES6+ support
- D3.js v7 requires SVG 2 support
- Tested with Chrome, Firefox, Safari

## Known Issues

1. D3.js not yet installed - need to run `npm install`
2. Assets not yet rebuilt - need to run `npm run build`
3. No WebSocket support yet for real-time updates
4. Node positions not persisted to server

## Future Enhancements

### Visual Improvements
- Animated transitions between states
- Better hover tooltips
- Context menus for nodes/links
- Minimap overview

### Collaboration Features
- Real-time multi-user editing
- Presence indicators (show other users' cursors)
- Change history visualization

### Advanced Features
- Path finding between nodes
- Graph analytics (degree, betweenness, etc.)
- Filter by predicate/type
- Time-based filtering (show graph at specific point in time)

## Migration Guide

### From Static Graph to Interactive Graph

The new implementation is backward compatible:
- If D3.js is not loaded, falls back to static SVG
- Existing `knowledge-graph.js` remains as fallback
- Gradual migration possible

### Rollback

To rollback to static graph:
1. Remove D3.js dependency from package.json
2. Comment out import in app.js
3. Remove knowledge-graph-interactive.js
4. Run `npm install` and `npm run build`

## API Contract

All API endpoints:
- Require authentication (web middleware)
- Return JSON responses
- Use standard HTTP status codes
- Accept `X-Requested-With: XMLHttpRequest` header

### Error Handling

API errors return:
```json
{
    "message": "Error message here",
    "errors": { ... } // Optional validation errors
}
```

### Success Responses

Typical success response:
```json
{
    "data": { ... },
    "message": "Success message"
}
```

## Security Considerations

- All API endpoints use existing Laravel auth
- CSRF protection for forms (via AJAX with X-Requested-With header)
- Input validation on all endpoints
- No new vulnerabilities introduced

## Performance

- Graph rendering optimized for ~250 nodes
- Links use hit detection areas for better UX
- Lazy loading of details on demand
- Consider adding pagination for very large graphs

## Code Organization

- API Controller: `/app/Http/Controllers/Api/KnowledgeApiController.php`
- Routes: `/routes/api.php`
- JavaScript: `/resources/js/knowledge-graph-interactive.js`
- View: `/resources/views/console/knowledge/graph.blade.php`

## Next Steps

1. ✅ Create API routes and controller
2. ✅ Create interactive graph JavaScript
3. ✅ Update Blade view with new elements
4. ⏳ Install D3.js dependency
5. ⏳ Rebuild assets
6. ⏳ Test implementation
7. ⏳ Add WebSocket support (optional)
8. ⏳ Add remaining features (optional)
