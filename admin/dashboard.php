<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','operator']);
$pageTitle = 'Dashboard';
$u = current_user();

$totalVehicles = $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalTrips    = $pdo->query("SELECT COUNT(*) FROM trips WHERE status='in_progress'")->fetchColumn();
$totalBookings = $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='success'")->fetchColumn();
$openSos       = $pdo->query("SELECT COUNT(*) FROM sos_alerts WHERE status='open'")->fetchColumn();

$byType = $pdo->query(
    "SELECT vehicle_type, COUNT(*) AS n FROM vehicles GROUP BY vehicle_type"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>Network overview</h2>
<div class="stats-grid">
    <div class="stat-card"><span class="stat-value"><?= (int)$totalVehicles ?></span><span class="stat-label">Vehicles on platform</span></div>
    <div class="stat-card"><span class="stat-value"><?= (int)$totalTrips ?></span><span class="stat-label">Trips live now</span></div>
    <div class="stat-card"><span class="stat-value"><?= (int)$totalBookings ?></span><span class="stat-label">Total bookings</span></div>
    <div class="stat-card"><span class="stat-value">UGX <?= number_format($totalRevenue,0) ?></span><span class="stat-label">Payments processed</span></div>
    <div class="stat-card <?= $openSos > 0 ? 'stat-alert' : '' ?>"><span class="stat-value"><?= (int)$openSos ?></span><span class="stat-label">Open SOS alerts</span></div>
</div>

<h3>Fleet mix</h3>
<table class="data-table">
    <thead><tr><th>Vehicle type</th><th>Count</th></tr></thead>
    <tbody>
    <?php foreach ($byType as $row): ?>
        <tr><td><?= clean(str_replace('_',' ',$row['vehicle_type'])) ?></td><td><?= (int)$row['n'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="quick-actions">
    <a class="module-card link" href="<?= BASE_URL ?>/admin/vehicles.php">Manage vehicles</a>
    <a class="module-card link" href="<?= BASE_URL ?>/admin/routes.php">Manage routes &amp; fares</a>
    <a class="module-card link" href="<?= BASE_URL ?>/admin/trips.php">Schedule &amp; manage trips</a>
    <a class="module-card link" href="<?= BASE_URL ?>/admin/subscriptions.php">Billing &amp; subscriptions</a>
    <a class="module-card link" href="<?= BASE_URL ?>/admin/ai_insights.php">AI insights</a>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
