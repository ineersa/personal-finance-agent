# UX Map Gotchas & Debugging

## Map Not Showing

### Missing Height

The map container **must** have an explicit height. Without it, the div collapses to 0px:

```twig
{# WRONG: no height, map invisible #}
{{ ux_map(map) }}

{# CORRECT: explicit height #}
{{ ux_map(map, {style: 'height: 400px;'}) }}

{# CORRECT: via CSS class #}
{{ ux_map(map, {class: 'h-96'}) }}
```

### Missing Renderer Package

```bash
# You need the base package AND a renderer:
composer require symfony/ux-map
composer require symfony/ux-leaflet-map    # OR symfony/ux-google-map
```

Without a renderer, the map will not render.

### Wrong DSN

```bash
# .env
# WRONG:
UX_MAP_DSN=leaflet
# CORRECT:
UX_MAP_DSN=leaflet://default

# WRONG (Google):
UX_MAP_DSN=google://default
# CORRECT (Google requires API key):
UX_MAP_DSN=google://YOUR_API_KEY@default
```

---

## Google Maps Issues

### Missing API Key

Google Maps requires a valid API key with the Maps JavaScript API enabled:

```bash
UX_MAP_DSN=google://AIzaSyB...your-key...@default
```

### Missing Map ID

Some Google Maps features (like custom styles) require a Map ID:

```php
$googleOptions = (new GoogleOptions())
    ->mapId('YOUR_MAP_ID');

$map->options($googleOptions);
```

Or set it globally:

```yaml
ux_map:
    google_maps:
        default_map_id: 'YOUR_MAP_ID'
```

---

## Markers Not Visible

### fitBoundsToMarkers Not Called

If markers are outside the initial viewport, they won't be visible:

```php
// WRONG: center is in Paris, marker is in London, zoom too high
$map = (new Map())
    ->center(new Point(48.8566, 2.3522))
    ->zoom(15);
$map->addMarker(new Marker(position: new Point(51.5074, -0.1278)));

// CORRECT: auto-fit to show all markers
$map = (new Map())
    ->center(new Point(48.8566, 2.3522))
    ->zoom(12)
    ->fitBoundsToMarkers();  // Adjusts viewport to show all markers
```

If there are **zero markers**, `fitBoundsToMarkers()` has no effect -- the map falls back to the `center` and `zoom` values. Always set both as defaults.

### Wrong Coordinate Order

```php
// WRONG: longitude first
new Point(2.3522, 48.8566);

// CORRECT: latitude first, then longitude
new Point(48.8566, 2.3522);
```

`Point` takes `(latitude, longitude)` -- the same order as Google Maps, but opposite of GeoJSON.

---

## LiveComponent Map Issues

### Missing ComponentWithMapTrait

```php
// WRONG: no map trait
#[AsLiveComponent]
final class MapComponent
{
    use DefaultActionTrait;

    public function getMap(): Map { ... }  // Won't work reactively
}

// CORRECT: use the trait
#[AsLiveComponent]
final class MapComponent
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    protected function instantiateMap(): Map { ... }
}
```

### Missing instantiateMap() Method

The trait requires implementing `instantiateMap()`:

```php
// WRONG: method name
protected function createMap(): Map { ... }

// CORRECT: exact method name
protected function instantiateMap(): Map { ... }
```

### Template Rendering

```twig
{# WRONG: using a variable #}
{{ ux_map(map, {style: 'height: 400px;'}) }}

{# CORRECT: using this.map (from the trait) #}
{{ ux_map(this.map, {style: 'height: 400px;'}) }}
```

---

## Event Listener Issues

### Event Not Firing

Events are dispatched on the map's container element, not on `document` or `window`:

```javascript
// WRONG: listening on document
document.addEventListener('ux:map:connect', handler);

// CORRECT: listening on the map element
this.element.addEventListener('ux:map:connect', handler);
```

### Accessing the Native Map Object

```javascript
this.element.addEventListener('ux:map:connect', (event) => {
    const { map } = event.detail;

    // `map` is the native Leaflet L.Map or Google Maps google.maps.Map instance
    // You can call any native API method on it
    map.setZoom(15);  // Leaflet
    map.setZoom(15);  // Google Maps (same API for this method)
});
```

### Cleaning Up Event Listeners

```javascript
export default class extends Controller {
    connect() {
        this._boundOnConnect = this._onConnect.bind(this);
        this.element.addEventListener('ux:map:connect', this._boundOnConnect);
    }

    disconnect() {
        this.element.removeEventListener('ux:map:connect', this._boundOnConnect);
    }

    _onConnect(event) {
        // ...
    }
}
```

---

## Polygon Gotchas

### Unclosed Polygons

Polygons are automatically closed (the last point connects to the first). You do NOT need to repeat the first point:

```php
// CORRECT: 3 points, polygon auto-closes
new Polygon(points: [
    new Point(48.87, 2.33),
    new Point(48.87, 2.37),
    new Point(48.84, 2.35),
]);

// ALSO FINE: but redundant
new Polygon(points: [
    new Point(48.87, 2.33),
    new Point(48.87, 2.37),
    new Point(48.84, 2.35),
    new Point(48.87, 2.33),  // Unnecessary
]);
```

### Minimum Points

Polygons require at least 3 points. Polylines require at least 2 points.

---

## Circle Radius

The radius is in **meters**, not pixels or degrees:

```php
// 500 meters
new Circle(center: new Point(48.8566, 2.3522), radius: 500);

// 1 km
new Circle(center: new Point(48.8566, 2.3522), radius: 1000);

// 10 km
new Circle(center: new Point(48.8566, 2.3522), radius: 10000);
```

---

## Twig Component JSON Format

When using the `<twig:ux:map>` component, markers and other data must be valid JSON:

```html
{# WRONG: single quotes in JSON #}
<twig:ux:map :markers="[{'position': [48.85, 2.35]}]" />

{# CORRECT: proper JSON with double quotes #}
<twig:ux:map :markers='[{"position": [48.85, 2.35]}]' />
```

Note the outer quotes are single `'...'` so that JSON double quotes work inside.

---

## Performance: Many Markers

For maps with hundreds of markers, consider:

1. **Server-side clustering** -- group nearby markers in PHP before sending
2. **Loading markers via AJAX** -- use a Stimulus controller to fetch markers based on viewport
3. **LiveComponent with pagination** -- load a subset based on the visible area

The default approach sends all markers as JSON in the HTML, which works well up to ~200-300 markers.
