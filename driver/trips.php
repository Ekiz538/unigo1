<?php
require_once __DIR__ . '/../config/config.php';
require_role(['driver']);
$pageTitle = 'My trips';
$u = current_user();

$d = $pdo->prepare('SELECT id, vehicle_id FROM drivers WHERE user_id = ?');
$d->execute([$u['id']]);
$driver = $d->fetch();
if (!$driver) { flash('error','No driver profile found.'); redirect('/driver/dashboard.php'); }

// Handle trip status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();

    $tripId = (int) $_POST['trip_id'];
    if ($_POST['action'] === 'start') {
        $pdo->prepare("UPDATE trips SET status='in_progress' WHERE id=? AND driver_id=?")->execute([$tripId, $driver['id']]);
    } elseif ($_POST['action'] === 'complete') {
        $pdo->prepare("UPDATE trips SET status='completed' WHERE id=? AND driver_id=?")->execute([$tripId, $driver['id']]);
    }
    redirect('/driver/trips.php');
}

$stmt = $pdo->prepare(
    "SELECT t.id, t.status, t.departure_time, t.seats_available, t.current_lat, t.current_lng, r.route_name
     FROM trips t JOIN routes r ON r.id = t.route_id
     WHERE t.driver_id = ?
     ORDER BY t.departure_time DESC"
);
$stmt->execute([$driver['id']]);
$trips = $stmt->fetchAll();

$hasMapKey = GOOGLE_MAPS_API_KEY !== 'YOUR_GOOGLE_MAPS_API_KEY';

require __DIR__ . '/../includes/header.php';
?>
<h2>My trips</h2>
<?php if (!$trips): ?>
    <p>No trips assigned yet.</p>
<?php endif; ?>
<?php foreach ($trips as $t): ?>
<div class="form-card">
    <h3><?= clean($t['route_name']) ?></h3>
    <p>Departure: <?= clean($t['departure_time']) ?> &middot; Status: <span class="badge badge-<?= clean($t['status']) ?>"><?= clean($t['status']) ?></span></p>
    <p>Seats available: <?= (int)$t['seats_available'] ?></p>

    <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
        <?php if ($t['status'] === 'scheduled' || $t['status'] === 'boarding'): ?>
            <button class="cta small" name="action" value="start">Start trip</button>
        <?php elseif ($t['status'] === 'in_progress'): ?>
            <button class="cta small" name="action" value="complete">Complete trip</button>
        <?php endif; ?>
    </form>

    <?php if ($t['status'] === 'in_progress'): ?>
    <div class="gps-reporter" data-trip-id="<?= (int)$t['id'] ?>" data-lat="<?= clean($t['current_lat'] ?? '0.3136') ?>" data-lng="<?= clean($t['current_lng'] ?? '32.5811') ?>">
        <p class="hint">Simulating GPS device: click to push a location update.</p>
        <button type="button" class="cta small ghost report-btn">Report current location</button>
        <span class="report-status"></span>
        <?php if ($hasMapKey): ?>
            <div class="mini-map" id="map-<?= (int)$t['id'] ?>" style="width:100%; height:280px; border-radius:8px; margin-top:10px;"></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
const driverMaps = {}; // tripId -> { map, marker }

function initMap() {
    document.querySelectorAll('.gps-reporter').forEach(wrap => {
        const tripId = wrap.dataset.tripId;
        const mapDiv = document.getElementById(`map-${tripId}`);
        if (!mapDiv) return;

        const start = { lat: parseFloat(wrap.dataset.lat), lng: parseFloat(wrap.dataset.lng) };
        const map = new google.maps.Map(mapDiv, { center: start, zoom: 13 });
        const marker = new google.maps.Marker({
            position: start, map, title: 'My vehicle',
            icon: { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW, scale: 6, fillColor: '#f2a900', fillOpacity: 1, strokeColor: '#1c2321', strokeWeight: 1 },
        });
        driverMaps[tripId] = { map, marker };
    });
}

document.querySelectorAll('.report-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const wrap = btn.closest('.gps-reporter');
        const tripId = wrap.dataset.tripId;
        const statusEl = wrap.querySelector('.report-status');

        function send(lat, lng, speed) {
            fetch('<?= BASE_URL ?>/driver/update_location.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `trip_id=${tripId}&lat=${lat}&lng=${lng}&speed=${speed}`
            }).then(r => r.json()).then(data => {
                statusEl.textContent = data.ok ? `Reported @ ${data.lat}, ${data.lng} (${data.speed} km/h)` : 'Failed';
                if (data.ok && driverMaps[tripId]) {
                    const pos = { lat: parseFloat(data.lat), lng: parseFloat(data.lng) };
                    driverMaps[tripId].marker.setPosition(pos);
                    driverMaps[tripId].map.panTo(pos);
                }
            });
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                pos => send(pos.coords.latitude, pos.coords.longitude, (Math.random()*40+5).toFixed(1)),
                ()  => send((0.2+Math.random()*0.1).toFixed(6), (32.5+Math.random()*0.1).toFixed(6), (Math.random()*40+5).toFixed(1))
            );
        } else {
            send((0.2+Math.random()*0.1).toFixed(6), (32.5+Math.random()*0.1).toFixed(6), (Math.random()*40+5).toFixed(1));
        }
    });
});
</script>
<?php if ($hasMapKey): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode(GOOGLE_MAPS_API_KEY) ?>&callback=initMap" async defer></script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
