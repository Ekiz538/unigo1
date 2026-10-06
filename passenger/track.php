<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'Track trip';
$u = current_user();

$bookingId = (int) ($_GET['booking_id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT b.id, t.id AS trip_id, t.route_id, t.current_lat, t.current_lng, t.status AS trip_status,
            r.route_name, v.plate_number, v.vehicle_type
     FROM bookings b
     JOIN trips t ON t.id = b.trip_id
     JOIN routes r ON r.id = t.route_id
     JOIN vehicles v ON v.id = t.vehicle_id
     WHERE b.id = ? AND b.passenger_id = ?"
);
$stmt->execute([$bookingId, $u['id']]);
$trip = $stmt->fetch();

if (!$trip) {
    flash('error', 'Trip not found.');
    redirect('/passenger/my_bookings.php');
}

// Route stops, used to draw the planned path on the map for context.
$stopsStmt = $pdo->prepare('SELECT stop_name, latitude, longitude FROM route_stops WHERE route_id = ? ORDER BY sequence_no ASC');
$stopsStmt->execute([$trip['route_id']]);
$stops = $stopsStmt->fetchAll();

$hasMapKey = GOOGLE_MAPS_API_KEY !== 'YOUR_GOOGLE_MAPS_API_KEY';
$startLat = $trip['current_lat'] ?? ($stops[0]['latitude'] ?? 0.3136);
$startLng = $trip['current_lng'] ?? ($stops[0]['longitude'] ?? 32.5811);

require __DIR__ . '/../includes/header.php';
?>
<h2>Tracking: <?= clean($trip['route_name']) ?></h2>
<p>Vehicle <?= clean($trip['plate_number']) ?> (<?= clean(str_replace('_',' ',$trip['vehicle_type'])) ?>) &middot;
   Status: <span id="tripStatus"><?= clean($trip['trip_status']) ?></span></p>

<?php if (!$hasMapKey): ?>
<div class="alert alert-error">
    No Google Maps API key set yet &mdash; showing raw coordinates instead. Add a key to
    <code>GOOGLE_MAPS_API_KEY</code> in <code>config/config.php</code> to see the live map.
</div>
<?php endif; ?>

<div class="form-card" style="max-width:100%;">
    <p>Current position: Latitude <span id="lat"><?= clean($trip['current_lat'] ?? '-') ?></span>,
       Longitude <span id="lng"><?= clean($trip['current_lng'] ?? '-') ?></span></p>
    <p class="hint">This refreshes every 5 seconds from live GPS pings (Example A: avoiding congestion).</p>
    <?php if ($hasMapKey): ?>
        <div id="map" style="width:100%; height:420px; border-radius:8px; margin-top:10px;"></div>
    <?php endif; ?>
</div>

<script>
const tripId = <?= (int)$trip['trip_id'] ?>;
const routeStops = <?= json_encode($stops) ?>;
let map, vehicleMarker;

function initMap() {
    const start = { lat: <?= (float)$startLat ?>, lng: <?= (float)$startLng ?> };
    map = new google.maps.Map(document.getElementById('map'), {
        center: start,
        zoom: 13,
    });

    // Draw the planned route as a reference line + stop markers.
    if (routeStops.length > 1) {
        const path = routeStops.map(s => ({ lat: parseFloat(s.latitude), lng: parseFloat(s.longitude) }));
        new google.maps.Polyline({
            path, map,
            strokeColor: '#0a6e4b', strokeOpacity: 0.6, strokeWeight: 4,
        });
        routeStops.forEach(s => {
            new google.maps.Marker({
                position: { lat: parseFloat(s.latitude), lng: parseFloat(s.longitude) },
                map,
                title: s.stop_name,
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 5, fillColor: '#66766f', fillOpacity: 1, strokeWeight: 0 },
            });
        });
    }

    vehicleMarker = new google.maps.Marker({
        position: start,
        map,
        title: 'Your vehicle',
        icon: {
            path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
            scale: 6,
            fillColor: '#f2a900',
            fillOpacity: 1,
            strokeColor: '#1c2321',
            strokeWeight: 1,
        },
    });
}

async function pollLocation() {
    try {
        const res = await fetch(`<?= BASE_URL ?>/api/get_location.php?trip_id=${tripId}`);
        const data = await res.json();
        if (!data.ok) return;

        document.getElementById('lat').textContent = data.lat;
        document.getElementById('lng').textContent = data.lng;
        document.getElementById('tripStatus').textContent = data.status;

        if (vehicleMarker && data.lat && data.lng) {
            const pos = { lat: parseFloat(data.lat), lng: parseFloat(data.lng) };
            vehicleMarker.setPosition(pos);
            map.panTo(pos);
        }
    } catch (e) { /* ignore transient errors */ }
}
setInterval(pollLocation, 5000);
</script>
<?php if ($hasMapKey): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode(GOOGLE_MAPS_API_KEY) ?>&callback=initMap" async defer></script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
