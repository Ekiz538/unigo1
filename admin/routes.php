<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','operator']);
$pageTitle = 'Routes & fares';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'route') {
    verify_csrf();

    $name = trim((string)($_POST['route_name'] ?? ''));
    $origin = trim((string)($_POST['origin'] ?? ''));
    $destination = trim((string)($_POST['destination'] ?? ''));
    $distance = (float) ($_POST['distance_km'] ?? 0);
    if ($name && $origin && $destination && $distance > 0) {
        $stmt = $pdo->prepare('INSERT INTO routes (route_name, origin, destination, distance_km) VALUES (?,?,?,?)');
        $stmt->execute([$name, $origin, $destination, $distance]);
        flash('success', 'Route added.');
    } else {
        flash('error', 'All route fields are required.');
    }
    redirect('/admin/routes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'fare') {
    verify_csrf();

    $routeId = (int) ($_POST['route_id'] ?? 0);
    $type = in_array($_POST['vehicle_type'] ?? '', ['boda','taxi','bus','electric_bus','truck'], true) ? $_POST['vehicle_type'] : 'taxi';
    $base = (float) ($_POST['base_fare'] ?? 0);
    $perKm = (float) ($_POST['per_km_rate'] ?? 0);
    if ($routeId && $base > 0 && $perKm >= 0) {
        $stmt = $pdo->prepare('INSERT INTO fares (route_id, vehicle_type, base_fare, per_km_rate) VALUES (?,?,?,?)');
        $stmt->execute([$routeId, $type, $base, $perKm]);
        flash('success', 'Fare rule added.');
    }
    redirect('/admin/routes.php');
}

$routes = $pdo->query('SELECT * FROM routes ORDER BY route_name')->fetchAll();
$fares  = $pdo->query(
    "SELECT f.*, r.route_name FROM fares f JOIN routes r ON r.id = f.route_id ORDER BY r.route_name"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>Routes &amp; fares</h2>

<div class="form-card">
    <h3>Add route</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="route">
        <label>Route name<input type="text" name="route_name" required></label>
        <label>Origin<input type="text" name="origin" required></label>
        <label>Destination<input type="text" name="destination" required></label>
        <label>Distance (km)<input type="number" step="0.1" name="distance_km" required></label>
        <button type="submit" class="cta">Add route</button>
    </form>
</div>

<div class="form-card">
    <h3>Add fare rule</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="fare">
        <label>Route
            <select name="route_id" required>
                <?php foreach ($routes as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= clean($r['route_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Vehicle type
            <select name="vehicle_type">
                <option value="boda">Boda</option>
                <option value="taxi">Taxi</option>
                <option value="bus">Bus</option>
                <option value="electric_bus">Electric bus</option>
                <option value="truck">Truck</option>
            </select>
        </label>
        <label>Base fare<input type="number" step="0.01" name="base_fare" required></label>
        <label>Per-km rate<input type="number" step="0.01" name="per_km_rate" required></label>
        <button type="submit" class="cta">Add fare</button>
    </form>
</div>

<h3>All routes</h3>
<table class="data-table">
    <thead><tr><th>Route</th><th>Origin</th><th>Destination</th><th>Distance</th></tr></thead>
    <tbody>
    <?php foreach ($routes as $r): ?>
        <tr><td><?= clean($r['route_name']) ?></td><td><?= clean($r['origin']) ?></td><td><?= clean($r['destination']) ?></td><td><?= clean($r['distance_km']) ?> km</td></tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3>All fare rules</h3>
<table class="data-table">
    <thead><tr><th>Route</th><th>Vehicle type</th><th>Base fare</th><th>Per km</th></tr></thead>
    <tbody>
    <?php foreach ($fares as $f): ?>
        <tr><td><?= clean($f['route_name']) ?></td><td><?= clean(str_replace('_',' ',$f['vehicle_type'])) ?></td><td>UGX <?= number_format($f['base_fare'],2) ?></td><td>UGX <?= number_format($f['per_km_rate'],2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../includes/footer.php'; ?>
