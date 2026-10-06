<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'My dashboard';
$u = current_user();

$stmt = $pdo->prepare(
    "SELECT b.id, b.status, b.fare_amount, t.departure_time, r.route_name
     FROM bookings b
     JOIN trips t ON t.id = b.trip_id
     JOIN routes r ON r.id = t.route_id
     WHERE b.passenger_id = ?
     ORDER BY b.created_at DESC LIMIT 5"
);
$stmt->execute([$u['id']]);
$recentBookings = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>Welcome back, <?= clean($u['name']) ?></h2>
<div class="quick-actions">
    <a class="module-card link" href="<?= BASE_URL ?>/passenger/search.php">Find &amp; book a trip</a>
    <a class="module-card link" href="<?= BASE_URL ?>/passenger/my_bookings.php">My bookings</a>
    <a class="module-card link" href="<?= BASE_URL ?>/passenger/sos.php">Safety / SOS</a>
</div>

<h3>Recent bookings</h3>
<?php if (!$recentBookings): ?>
    <p>No bookings yet. <a href="<?= BASE_URL ?>/passenger/search.php">Search a route</a> to get started.</p>
<?php else: ?>
<table class="data-table">
    <thead><tr><th>Route</th><th>Departure</th><th>Fare</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recentBookings as $b): ?>
        <tr>
            <td><?= clean($b['route_name']) ?></td>
            <td><?= clean($b['departure_time']) ?></td>
            <td>UGX <?= number_format($b['fare_amount'], 2) ?></td>
            <td><span class="badge badge-<?= clean($b['status']) ?>"><?= clean($b['status']) ?></span></td>
            <td><a href="<?= BASE_URL ?>/passenger/track.php?booking_id=<?= (int)$b['id'] ?>">Track</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
