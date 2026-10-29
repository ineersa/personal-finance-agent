# UX Map API Reference

## Core Classes

### Map

```php
use Symfony\UX\Map\Map;

$map = new Map();

$map
    ->center(new Point(48.8566, 2.3522))  // Center point
    ->zoom(12)                             // Initial zoom level
    ->minZoom(3)                           // Minimum zoom
    ->maxZoom(18)                          // Maximum zoom
    ->fitBoundsToMarkers()                 // Auto-fit to show all markers
    ->options($rendererOptions)            // Renderer-specific options
    ->addMarker($marker)
    ->addPolygon($polygon)
    ->addPolyline($polyline)
    ->addCircle($circle);
```

---

### Point

```php
use Symfony\UX\Map\Point;

$point = new Point(
    latitude: 48.8566,
    longitude: 2.3522,
);
```

---

### Marker

```php
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Icon;

$marker = new Marker(
    position: new Point(48.8566, 2.3522),    // Required
    title: 'Paris',                           // Optional: tooltip text
    infoWindow: new InfoWindow(...),          // Optional: popup
    icon: Icon::ux('mdi:pin')->width(32)->height(32),  // Optional: custom icon
    extra: ['category' => 'city'],            // Optional: custom data for JS
);
```

---

### InfoWindow

```php
use Symfony\UX\Map\InfoWindow;

$infoWindow = new InfoWindow(
    content: '<p>HTML content</p>',           // HTML body
    headerContent: '<b>Title</b>',            // Optional: HTML header
);
```

Attach to Marker, Polygon, or Circle via the `infoWindow` parameter.

---

### Polygon

```php
use Symfony\UX\Map\Polygon;
use Symfony\UX\Map\Point;

$polygon = new Polygon(
    points: [
        new Point(48.8566, 2.3522),
        new Point(48.8606, 2.3376),
        new Point(48.8530, 2.3499),
    ],
    infoWindow: new InfoWindow(content: 'Area description'),  // Optional
    extra: ['type' => 'zone'],                                 // Optional
);
```

---

### Polyline

```php
use Symfony\UX\Map\Polyline;
use Symfony\UX\Map\Point;

$polyline = new Polyline(
    points: [
        new Point(48.8566, 2.3522),
        new Point(48.8738, 2.2950),
        new Point(48.8530, 2.3499),
    ],
    extra: ['route' => 'walking'],  // Optional
);
```

---

### Circle

```php
use Symfony\UX\Map\Circle;
use Symfony\UX\Map\Point;

$circle = new Circle(
    center: new Point(48.8566, 2.3522),   // Required
    radius: 500,                           // Required: meters
    infoWindow: new InfoWindow(content: '500m radius'),  // Optional
    extra: ['zone' => 'delivery'],         // Optional
);
```

---

### Icon (Marker Icons)

```php
use Symfony\UX\Map\Icon;

// Using a UX Icon (requires symfony/ux-icons)
$icon = Icon::ux('mdi:map-marker')
    ->width(32)
    ->height(32);

// Using a URL
$icon = Icon::url('/images/marker.png')
    ->width(32)
    ->height(32);
```

---

## Twig Rendering

### ux_map() Function

```twig
{{ ux_map(map, attributes) }}
```

| Parameter    | Type   | Description |
|-------------|--------|-------------|
| `map`       | Map    | The Map PHP object |
| `attributes`| array  | HTML attributes for the container `<div>` |

The container `<div>` must have explicit dimensions (via `style` or CSS class).

### Twig Component

```html
<twig:ux:map
    :center="[lat, lng]"
    zoom="12"
    :markers='[...]'
    :fitBoundsToMarkers="true"
    style="height: 400px;"
/>
```

---

## Configuration

```yaml
# config/packages/ux_map.yaml
ux_map:
    # Renderer DSN (set via environment variable)
    renderer: '%env(resolve:default::UX_MAP_DSN)%'

    google_maps:
        # Default Map ID for all Google Maps instances
        default_map_id: null
```

### Environment Variable

```bash
# .env

# Leaflet (free, no API key)
UX_MAP_DSN=leaflet://default

# Google Maps
UX_MAP_DSN=google://YOUR_API_KEY@default
```

---

## Leaflet Options

```php
use Symfony\UX\Map\Bridge\Leaflet\LeafletOptions;
use Symfony\UX\Map\Bridge\Leaflet\Option\TileLayer;
use Symfony\UX\Map\Bridge\Leaflet\Option\ZoomControlOptions;
use Symfony\UX\Map\Bridge\Leaflet\Option\AttributionControlOptions;
use Symfony\UX\Map\Bridge\Leaflet\Option\ControlPosition;
```

| Method | Type | Description |
|--------|------|-------------|
| `tileLayer(TileLayer)` | TileLayer | Custom tile layer URL and options |
| `zoomControl(bool)` | bool | Show/hide zoom control |
| `zoomControlOptions(ZoomControlOptions)` | ZoomControlOptions | Zoom control position |
| `attributionControl(bool)` | bool | Show/hide attribution |
| `attributionControlOptions(AttributionControlOptions)` | AttributionControlOptions | Attribution position |

### TileLayer

```php
new TileLayer(
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '&copy; OpenStreetMap',
    options: ['minZoom' => 1, 'maxZoom' => 19],
)
```

### Leaflet ControlPosition

```php
ControlPosition::TOP_LEFT
ControlPosition::TOP_RIGHT
ControlPosition::BOTTOM_LEFT
ControlPosition::BOTTOM_RIGHT
```

---

## Google Maps Options

```php
use Symfony\UX\Map\Bridge\Google\GoogleOptions;
use Symfony\UX\Map\Bridge\Google\Option\ControlPosition;
use Symfony\UX\Map\Bridge\Google\Option\GestureHandling;
use Symfony\UX\Map\Bridge\Google\Option\MapTypeControlOptions;
use Symfony\UX\Map\Bridge\Google\Option\MapTypeControlStyle;
use Symfony\UX\Map\Bridge\Google\Option\ZoomControlOptions;
use Symfony\UX\Map\Bridge\Google\Option\StreetViewControlOptions;
use Symfony\UX\Map\Bridge\Google\Option\FullscreenControlOptions;
```

| Method | Type | Description |
|--------|------|-------------|
| `mapId(string)` | string | Google Maps Map ID |
| `gestureHandling(GestureHandling)` | enum | How gestures are handled |
| `backgroundColor(string)` | string | Background color |
| `doubleClickZoom(bool)` | bool | Enable double-click zoom |
| `zoomControl(bool)` | bool | Show zoom control |
| `zoomControlOptions(ZoomControlOptions)` | object | Zoom control position |
| `mapTypeControl(bool)` | bool | Show map type selector |
| `mapTypeControlOptions(MapTypeControlOptions)` | object | Map type control config |
| `streetViewControl(bool)` | bool | Show street view |
| `streetViewControlOptions(StreetViewControlOptions)` | object | Street view config |
| `fullscreenControl(bool)` | bool | Show fullscreen button |
| `fullscreenControlOptions(FullscreenControlOptions)` | object | Fullscreen config |

### GestureHandling

```php
GestureHandling::COOPERATIVE   // Require Ctrl+scroll to zoom
GestureHandling::GREEDY        // All gestures pan/zoom the map
GestureHandling::NONE          // Disable all gestures
GestureHandling::AUTO          // Default behavior
```

### Google ControlPosition

```php
ControlPosition::BLOCK_START_INLINE_START
ControlPosition::BLOCK_START_INLINE_CENTER
ControlPosition::BLOCK_START_INLINE_END
ControlPosition::INLINE_START_BLOCK_START
ControlPosition::INLINE_START_BLOCK_CENTER
ControlPosition::INLINE_START_BLOCK_END
ControlPosition::INLINE_END_BLOCK_START
ControlPosition::INLINE_END_BLOCK_CENTER
ControlPosition::INLINE_END_BLOCK_END
ControlPosition::BLOCK_END_INLINE_START
ControlPosition::BLOCK_END_INLINE_CENTER
ControlPosition::BLOCK_END_INLINE_END
```

---

## LiveComponent Integration

### ComponentWithMapTrait

```php
use Symfony\UX\Map\Live\ComponentWithMapTrait;

#[AsLiveComponent]
final class InteractiveMap
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    protected function instantiateMap(): Map
    {
        // Build and return a Map object
        return (new Map())
            ->center(new Point(48.8566, 2.3522))
            ->zoom(12);
    }
}
```

The trait exposes `$this->map` in the template. Render it with:

```twig
{{ ux_map(this.map, {style: 'height: 400px;'}) }}
```

When LiveProps change and the component re-renders, the map updates automatically (markers added/removed, center/zoom adjusted).

---

## JavaScript Events

All events are dispatched on the map's container element.

### Connect Event

```javascript
element.addEventListener('ux:map:connect', (event) => {
    const { map, markers } = event.detail;
    // `map` is the native Leaflet/Google Maps instance
    // `markers` is an array of native marker instances
});
```

### Element Lifecycle Events

Pattern: `ux:map:{element}:before-create` and `ux:map:{element}:after-create`

```javascript
// Before marker creation (can modify definition)
element.addEventListener('ux:map:marker:before-create', (event) => {
    const { definition } = event.detail;
});

// After marker creation (access native marker object)
element.addEventListener('ux:map:marker:after-create', (event) => {
    const { marker, definition } = event.detail;
    // `definition.extra` contains custom data from PHP
});
```

Elements: `marker`, `polygon`, `polyline`, `circle`, `info-window`.
