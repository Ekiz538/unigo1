<?php
require_once __DIR__ . '/../config/config.php';
require_role(['driver']);
$pageTitle = 'Driver dashboard';
$u = current_user();

$stmt = $pdo->prepare(
    "SELECT d.id AS driver_id, d.status AS driver_status, v.plate_number, v.vehicle_type
     FROM drivers d LEFT JOIN vehicles v ON v.id = d.vehicle_id
     WHERE d.user_id = ?"
);
$stmt->execute([$u['id']]);
$driver = $stmt->fetch();

$activeTrip = null;
if ($driver) {
    $t = $pdo->prepare(
        "SELECT t.id, t.status, t.departure_time, t.seats_available, r.route_name
         FROM trips t JOIN routes r ON r.id = t.route_id
         WHERE t.driver_id = ? AND t.status IN ('scheduled','boarding','in_progress')
         ORDER BY t.departure_time DESC LIMIT 1"
    );
    $t->execute([$driver['driver_id']]);
    $activeTrip = $t->fetch();
}

require __DIR__ . '/../includes/header.php';
?>
<h2>Welcome, <?= clean($u['name']) ?></h2>
<?php if (!$driver): ?>
    <p>Your driver profile has not been fully set up yet. Contact an administrator.</p>
<?php else: ?>
    <p>Vehicle: <?= $driver['plate_number'] ? clean($driver['plate_number']) . ' (' . clean(str_replace('_',' ',$driver['vehicle_type'])) . ')' : 'Not yet assigned' ?></p>
    <p>Status: <span class="badge badge-<?= clean($driver['driver_status']) ?>"><?= clean($driver['driver_status']) ?></span></p>

    <?php if ($activeTrip): ?>
    <div class="form-card">
        <h3>Current trip</h3>
        <p><?= clean($activeTrip['route_name']) ?> &middot; <?= clean($activeTrip['departure_time']) ?></p>
        <p>Seats available: <?= (int)$activeTrip['seats_available'] ?> &middot; Status: <?= clean($activeTrip['status']) ?></p>
        <a class="cta" href="<?= BASE_URL ?>/driver/trips.php">Manage this trip &amp; report location</a>
    </div>
    <?php else: ?>
        <p>No active trip right now. <a href="<?= BASE_URL ?>/driver/trips.php">View my trips</a>.</p>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
