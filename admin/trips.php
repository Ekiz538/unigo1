<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','operator']);
$pageTitle = 'Trips';
$u = current_user();

$operatorId = null;
if ($u['role'] === 'operator') {
    $op = $pdo->prepare('SELECT id FROM operators WHERE owner_user_id = ?');
    $op->execute([$u['id']]);
    $operatorId = $op->fetchColumn() ?: null;
}

// Create a new trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();

    $vehicleId  = (int) ($_POST['vehicle_id'] ?? 0);
    $routeId    = (int) ($_POST['route_id'] ?? 0);
    $driverId   = !empty($_POST['driver_id']) ? (int) $_POST['driver_id'] : null;
    $departure  = $_POST['departure_time'] ?? '';
    $status     = in_array($_POST['status'] ?? '', ['scheduled','boarding','in_progress'], true) ? $_POST['status'] : 'scheduled';

    $veh = $pdo->prepare('SELECT capacity FROM vehicles WHERE id = ?');
    $veh->execute([$vehicleId]);
    $capacity = (int) $veh->fetchColumn();

    if ($vehicleId && $routeId && $departure && $capacity > 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO trips (vehicle_id, route_id, driver_id, departure_time, status, seats_available)
             VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([$vehicleId, $routeId, $driverId, $departure, $status, $capacity]);

        // If the driver is assigned and vehicle has stop coordinates, seed the
        // trip's starting position from the route's first stop so tracking has
        // something to show immediately.
        $tripId = (int) $pdo->lastInsertId();
        $stop = $pdo->prepare('SELECT latitude, longitude FROM route_stops WHERE route_id = ? ORDER BY sequence_no ASC LIMIT 1');
        $stop->execute([$routeId]);
        $firstStop = $stop->fetch();
        if ($firstStop) {
            $pdo->prepare('UPDATE trips SET current_lat = ?, current_lng = ? WHERE id = ?')
                ->execute([$firstStop['latitude'], $firstStop['longitude'], $tripId]);
        }
        if ($driverId) {
            $pdo->prepare("UPDATE drivers SET vehicle_id = ?, status = 'on_trip' WHERE id = ?")
                ->execute([$vehicleId, $driverId]);
        }

        flash('success', 'Trip created.');
    } else {
        flash('error', 'Vehicle, route, and departure time are required (and the vehicle needs a capacity > 0).');
    }
    redirect('/admin/trips.php');
}

// Cancel a trip
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    verify_csrf();

    $tripId = (int) $_POST['trip_id'];
    $pdo->prepare("UPDATE trips SET status = 'cancelled' WHERE id = ?")->execute([$tripId]);
    flash('success', 'Trip cancelled.');
    redirect('/admin/trips.php');
}

// Data for the "create trip" form, scoped to this operator if applicable
$vehSql = "SELECT v.id, v.plate_number, v.vehicle_type, v.capacity FROM vehicles v WHERE v.status = 'active'";
$vehParams = [];
if ($operatorId) { $vehSql .= " AND v.operator_id = ?"; $vehParams[] = $operatorId; }
$vehStmt = $pdo->prepare($vehSql);
$vehStmt->execute($vehParams);
$vehicles = $vehStmt->fetchAll();

$routes = $pdo->query('SELECT id, route_name FROM routes ORDER BY route_name')->fetchAll();

$drvSql = "SELECT d.id, u.full_name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.status != 'on_trip'";
$drivers = $pdo->query($drvSql)->fetchAll();

// Existing trips list
$listSql = "SELECT t.id, t.departure_time, t.status, t.seats_available, t.current_lat, t.current_lng,
                   r.route_name, v.plate_number, v.vehicle_type, u.full_name AS driver_name
            FROM trips t
            JOIN routes r ON r.id = t.route_id
            JOIN vehicles v ON v.id = t.vehicle_id
            LEFT JOIN drivers d ON d.id = t.driver_id
            LEFT JOIN users u ON u.id = d.user_id";
$listParams = [];
if ($operatorId) { $listSql .= " WHERE v.operator_id = ?"; $listParams[] = $operatorId; }
$listSql .= " ORDER BY t.departure_time DESC LIMIT 50";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($listParams);
$trips = $listStmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>Trips</h2>

<div class="form-card">
    <h3>Schedule a new trip</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label>Vehicle
            <select name="vehicle_id" required>
                <option value="">-- choose vehicle --</option>
                <?php foreach ($vehicles as $v): ?>
                    <option value="<?= (int)$v['id'] ?>">
                        <?= clean($v['plate_number']) ?> (<?= clean(str_replace('_',' ',$v['vehicle_type'])) ?>, <?= (int)$v['capacity'] ?> seats)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Route
            <select name="route_id" required>
                <option value="">-- choose route --</option>
                <?php foreach ($routes as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= clean($r['route_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Driver (optional)
            <select name="driver_id">
                <option value="">-- unassigned --</option>
                <?php foreach ($drivers as $d): ?>
                    <option value="<?= (int)$d['id'] ?>"><?= clean($d['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Departure time<input type="datetime-local" name="departure_time" required></label>
        <label>Initial status
            <select name="status">
                <option value="scheduled">Scheduled</option>
                <option value="boarding">Boarding</option>
                <option value="in_progress">In progress (live now)</option>
            </select>
        </label>
        <button type="submit" class="cta">Create trip</button>
    </form>
    <?php if (!$vehicles): ?>
        <p class="hint">No active vehicles found — add one under <a href="<?= BASE_URL ?>/admin/vehicles.php">Vehicles</a> first.</p>
    <?php endif; ?>
</div>

<h3>All trips</h3>
<table class="data-table">
    <thead><tr><th>Route</th><th>Vehicle</th><th>Driver</th><th>Departure</th><th>Status</th><th>Seats left</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($trips as $t): ?>
        <tr>
            <td><?= clean($t['route_name']) ?></td>
            <td><?= clean($t['plate_number']) ?> (<?= clean(str_replace('_',' ',$t['vehicle_type'])) ?>)</td>
            <td><?= $t['driver_name'] ? clean($t['driver_name']) : '<em>unassigned</em>' ?></td>
            <td><?= clean($t['departure_time']) ?></td>
            <td><span class="badge badge-<?= clean($t['status']) ?>"><?= clean($t['status']) ?></span></td>
            <td><?= (int)$t['seats_available'] ?></td>
            <td>
                <?php if (!in_array($t['status'], ['completed','cancelled'], true)): ?>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
                    <button class="cta small ghost" type="submit" onclick="return confirm('Cancel this trip?')">Cancel</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../includes/footer.php'; ?>
