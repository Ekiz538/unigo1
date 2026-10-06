<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'Safety / SOS';
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $lat = (float) ($_POST['lat'] ?? 0);
    $lng = (float) ($_POST['lng'] ?? 0);
    $tripId = !empty($_POST['trip_id']) ? (int) $_POST['trip_id'] : null;

    $stmt = $pdo->prepare('INSERT INTO sos_alerts (user_id, trip_id, latitude, longitude, status) VALUES (?,?,?,?,?)');
    $stmt->execute([$u['id'], $tripId, $lat, $lng, 'open']);

    flash('success', 'SOS alert sent. Authorities and your trusted contact have been notified.');
    redirect('/passenger/sos.php');
}

$stmt = $pdo->prepare('SELECT id, latitude, longitude, status, created_at FROM sos_alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$u['id']]);
$history = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>Safety &amp; SOS</h2>
<div class="form-card sos-card">
    <p>Press the button below in an emergency. Your live location will be sent to UniGo's safety
       desk and your trusted contact, as described in the case study's safety &amp; SOS feature.</p>
    <form method="post" id="sosForm">
        <?= csrf_field() ?>
        <input type="hidden" name="lat" id="sosLat" value="0.3136">
        <input type="hidden" name="lng" id="sosLng" value="32.5811">
        <button type="submit" class="cta sos-btn">SEND SOS</button>
    </form>
</div>

<h3>Recent alerts</h3>
<?php if (!$history): ?>
    <p>No alerts sent.</p>
<?php else: ?>
<table class="data-table">
    <thead><tr><th>Time</th><th>Location</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr>
            <td><?= clean($h['created_at']) ?></td>
            <td><?= clean($h['latitude']) ?>, <?= clean($h['longitude']) ?></td>
            <td><span class="badge badge-<?= clean($h['status']) ?>"><?= clean($h['status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<script>
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(pos => {
        document.getElementById('sosLat').value = pos.coords.latitude;
        document.getElementById('sosLng').value = pos.coords.longitude;
    });
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
