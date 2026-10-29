# UX Map Patterns

## Basic Controller with Map

```php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Point;

class StoreController extends AbstractController
{
    #[Route('/stores', name: 'app_stores')]
    public function index(): Response
    {
        $map = (new Map())
            ->center(new Point(48.8566, 2.3522))
            ->zoom(12)
            ->fitBoundsToMarkers();

        // Markers from database
        foreach ($this->storeRepository->findAll() as $store) {
            $map->addMarker(new Marker(
                position: new Point($store->getLatitude(), $store->getLongitude()),
                title: $store->getName(),
                infoWindow: new InfoWindow(
                    headerContent: sprintf('<b>%s</b>', $store->getName()),
                    content: sprintf('<p>%s</p><a href="%s">Details</a>',
                        $store->getAddress(),
                        $this->generateUrl('app_store_show', ['id' => $store->getId()])
                    ),
                ),
            ));
        }

        return $this->render('store/index.html.twig', ['map' => $map]);
    }
}
```

```twig
{# templates/store/index.html.twig #}
{{ ux_map(map, {style: 'height: 500px; width: 100%;', class: 'rounded-lg shadow'}) }}
```

---

## Custom Tile Layer (Leaflet)

```php
use Symfony\UX\Map\Bridge\Leaflet\LeafletOptions;
use Symfony\UX\Map\Bridge\Leaflet\Option\TileLayer;

// OpenStreetMap (default)
$options = (new LeafletOptions())
    ->tileLayer(new TileLayer(
        url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        attribution: '&copy; OpenStreetMap contributors',
    ));

// Stadia Maps (dark theme)
$options = (new LeafletOptions())
    ->tileLayer(new TileLayer(
        url: 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png',
        attribution: '&copy; Stadia Maps',
    ));

$map->options($options);
```

---

## Markers with Extra Data + JS Handling

Pass custom data from PHP to JavaScript via `extra`:

```php
$map->addMarker(new Marker(
    position: new Point($store->getLatitude(), $store->getLongitude()),
    title: $store->getName(),
    extra: [
        'id' => $store->getId(),
        'category' => $store->getCategory(),
        'url' => $this->generateUrl('app_store_show', ['id' => $store->getId()]),
    ],
));
```

```javascript
// assets/controllers/store-map_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.element.addEventListener('ux:map:marker:after-create', (event) => {
            const { marker, definition } = event.detail;
            const { id, category, url } = definition.extra;

            // Add click handler to navigate
            marker.addEventListener('click', () => {
                window.location.href = url;
            });
        });
    }
}
```

---

## LiveComponent: Reactive Map with Filters

```php
namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\Map\InfoWindow;
use Symfony\UX\Map\Live\ComponentWithMapTrait;
use Symfony\UX\Map\Map;
use Symfony\UX\Map\Marker;
use Symfony\UX\Map\Point;

#[AsLiveComponent]
final class StoreMap
{
    use DefaultActionTrait;
    use ComponentWithMapTrait;

    #[LiveProp(writable: true)]
    public string $category = '';

    #[LiveProp(writable: true)]
    public string $search = '';

    public function __construct(
        private readonly StoreRepository $storeRepository,
    ) {}

    protected function instantiateMap(): Map
    {
        $map = (new Map())
            ->center(new Point(48.8566, 2.3522))
            ->zoom(12)
            ->fitBoundsToMarkers();

        $stores = $this->storeRepository->findByFilters($this->category, $this->search);

        foreach ($stores as $store) {
            $map->addMarker(new Marker(
                position: new Point($store->getLatitude(), $store->getLongitude()),
                title: $store->getName(),
                infoWindow: new InfoWindow($store->getName()),
            ));
        }

        return $map;
    }
}
```

```twig
{# templates/components/StoreMap.html.twig #}
<div {{ attributes }}>
    <div class="flex gap-4 mb-4">
        <select data-model="category">
            <option value="">All categories</option>
            <option value="restaurant">Restaurants</option>
            <option value="shop">Shops</option>
        </select>
        <input type="search" data-model="debounce(300)|search" placeholder="Search...">
    </div>

    {{ ux_map(this.map, {style: 'height: 400px; width: 100%;'}) }}
</div>
```

---

## Multiple Maps on One Page

```php
// Controller
return $this->render('dashboard.html.twig', [
    'overview_map' => $overviewMap,
    'detail_map' => $detailMap,
]);
```

```twig
<div class="grid grid-cols-2 gap-4">
    {{ ux_map(overview_map, {style: 'height: 300px;'}) }}
    {{ ux_map(detail_map, {style: 'height: 300px;'}) }}
</div>
```

Each map gets its own Stimulus controller instance. If you add `data-controller="custom-map"`, each map element dispatches its own events independently.

---

## Inline Map with Twig Component (No PHP)

For simple static maps, define everything in Twig:

```html
<twig:ux:map
    :center="[51.5074, -0.1278]"
    zoom="13"
    :markers='[
        {"position": [51.5074, -0.1278], "title": "London Bridge"},
        {"position": [51.5014, -0.1419], "title": "Big Ben",
         "infoWindow": {"content": "<b>Big Ben</b><br>Westminster"}}
    ]'
    :fitBoundsToMarkers="true"
    style="height: 400px; width: 100%;"
/>
```

---

## Zones with Polygons and Circles

```php
// Delivery zone
$map->addPolygon(new Polygon(
    points: [
        new Point(48.87, 2.33),
        new Point(48.87, 2.37),
        new Point(48.84, 2.37),
        new Point(48.84, 2.33),
    ],
    infoWindow: new InfoWindow(content: 'Delivery zone A'),
    extra: ['zone' => 'A', 'color' => '#3b82f6'],
));

// Radius around a point
$map->addCircle(new Circle(
    center: new Point(48.8566, 2.3522),
    radius: 1000,  // 1km
    infoWindow: new InfoWindow(content: '1km delivery radius'),
));
```

---

## Route Display with Polyline

```php
// Display a route between waypoints
$map->addPolyline(new Polyline(
    points: [
        new Point(48.8566, 2.3522),  // Start
        new Point(48.8606, 2.3376),  // Waypoint
        new Point(48.8738, 2.2950),  // End
    ],
    extra: ['routeType' => 'walking'],
));
```
