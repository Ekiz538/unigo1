<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'My bookings';
$u = current_user();

$stmt = $pdo->prepare(
    "SELECT b.id, b.seat_number, b.fare_amount, b.booking_type, b.status, b.created_at,
            t.departure_time, t.status AS trip_status, r.route_name, v.vehicle_type, v.plate_number
     FROM bookings b
     JOIN trips t ON t.id = b.trip_id
     JOIN routes r ON r.id = t.route_id
     JOIN vehicles v ON v.id = t.vehicle_id
     WHERE b.passenger_id = ?
     ORDER BY b.created_at DESC"
);
$stmt->execute([$u['id']]);
$bookings = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>My bookings</h2>
<?php if (!$bookings): ?>
    <p>You have no bookings yet.</p>
<?php else: ?>
<table class="data-table">
    <thead><tr><th>Route</th><th>Vehicle</th><th>Seat</th><th>Fare</th><th>Booking status</th><th>Trip status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($bookings as $b): ?>
        <tr>
            <td><?= clean($b['route_name']) ?></td>
            <td><?= clean(str_replace('_',' ',$b['vehicle_type'])) ?> (<?= clean($b['plate_number']) ?>)</td>
            <td><?= clean($b['seat_number']) ?></td>
            <td>UGX <?= number_format($b['fare_amount'],2) ?></td>
            <td><span class="badge badge-<?= clean($b['status']) ?>"><?= clean($b['status']) ?></span></td>
            <td><?= clean($b['trip_status']) ?></td>
            <td>
                <?php if ($b['status'] === 'pending'): ?>
                    <a href="<?= BASE_URL ?>/passenger/pay.php?booking_id=<?= (int)$b['id'] ?>">Pay</a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/passenger/track.php?booking_id=<?= (int)$b['id'] ?>">Track</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
