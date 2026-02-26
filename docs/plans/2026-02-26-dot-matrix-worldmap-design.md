# Dot-Matrix World Map Design

## Goal
Replace the hand-drawn SVG blob world map with a canvas-based dot-matrix world map using Natural Earth GeoJSON data.

## Approach
- Load Natural Earth 1:110m land polygons (TopoJSON, ~25KB gzipped)
- Sample a dense uniform grid across the viewport
- Point-in-polygon test to determine which grid points fall on land
- Render land points as small circles on an HTML5 Canvas
- Add subtle shimmer/pulse animation for a living, data-driven feel
- Keep all existing overlay elements (pings, feed, toasts, stats) unchanged

## Technical Details

### Data Source
- `land-110m.json` — Natural Earth TopoJSON, served alongside `index.html`
- TopoJSON decoded at runtime using inline decoder (~30 lines)

### Rendering
- Full-viewport `<canvas>` element replaces the SVG map
- Equirectangular projection: `x = (lng + 180) / 360 * width`, `y = (90 - lat) / 180 * height`
- Grid spacing: ~4-5px for dense coverage (~3000-5000 visible land points)
- Point style: 1.2px radius circles, color `#2A2A50`, opacity 0.3-0.6 with variation
- Europe glow: radial gradient overlay centered on ~10E, 50N
- Connection arcs: drawn as quadratic bezier curves on canvas

### Animation
- Subtle shimmer: each point gets a random phase offset, opacity oscillates +-0.05 over 3-4s via requestAnimationFrame
- Keeps CPU usage low by only redrawing every ~50ms (20fps cap)

### Projection Mapping
- `geoToMap(lat, lng)` updated to match equirectangular projection
- Returns percentage coordinates for ping overlay positioning
- Padding: 3% horizontal, 8% top, 5% bottom (to clear header/footer)

### Responsive
- Canvas redraws on `resize` event (debounced 200ms)
- Grid re-sampled at new dimensions

## What Changes
1. Remove: SVG element with hand-drawn paths + euroGlow gradient
2. Add: `<canvas id="mapCanvas">` element
3. Add: `land-110m.json` file
4. Modify: `geoToMap()` function for new projection
5. Add: ~120 lines JS for GeoJSON loading, point sampling, canvas rendering, animation

## What Stays
- Header, bottom bar, feed, toast container — all untouched
- Ping animation (HTML divs with CSS keyframes)
- Polling logic, stats, version chart
- All CSS styles except map-svg class
