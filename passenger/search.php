<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'Find a trip';

$routes = $pdo->query('SELECT id, route_name, origin, destination, distance_km FROM routes ORDER BY route_name')->fetchAll();

$results = [];
$selectedRouteId = null;
if (isset($_GET['route_id']) && $_GET['route_id'] !== '') {
    $selectedRouteId = (int) $_GET['route_id'];
    $stmt = $pdo->prepare(
        "SELECT f.vehicle_type, f.base_fare, f.per_km_rate, r.distance_km, r.id AS route_id,
                t.id AS trip_id, t.departure_time, t.seats_available, t.status AS trip_status,
                v.plate_number
         FROM fares f
         JOIN routes r ON r.id = f.route_id
         LEFT JOIN trips t ON t.route_id = r.id AND t.vehicle_id IN (
             SELECT id FROM vehicles WHERE vehicle_type = f.vehicle_type
         ) AND t.status IN ('scheduled','boarding','in_progress')
         LEFT JOIN vehicles v ON v.id = t.vehicle_id
         WHERE f.route_id = ?
         ORDER BY f.base_fare ASC"
    );
    $stmt->execute([$selectedRouteId]);
    $results = $stmt->fetchAll();
}

require __DIR__ . '/../includes/header.php';
?>
<h2>Find a trip</h2>
<form method="get" class="inline-form">
    <label>Route
        <select name="route_id" onchange="this.form.submit()">
            <option value="">-- choose a route --</option>
            <?php foreach ($routes as $r): ?>
                <option value="<?= (int)$r['id'] ?>" <?= $selectedRouteId === (int)$r['id'] ? 'selected' : '' ?>>
                    <?= clean($r['route_name']) ?> (<?= clean($r['distance_km']) ?> km)
                </option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<?php if ($selectedRouteId && $results): ?>
<p>All options for this route, side by side &mdash; live fares, no guesswork (Example B: choosing the best journey).</p>
<table class="data-table">
    <thead><tr><th>Mode</th><th>Vehicle</th><th>Distance</th><th>Solo fare</th><th>Shared fare</th><th>Seats left</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($results as $row):
        $solo   = estimate_fare((float)$row['base_fare'], (float)$row['per_km_rate'], (float)$row['distance_km'], 'solo');
        $shared = estimate_fare((float)$row['base_fare'], (float)$row['per_km_rate'], (float)$row['distance_km'], 'shared');
    ?>
        <tr>
            <td><?= clean(str_replace('_', ' ', $row['vehicle_type'])) ?></td>
            <td><?= $row['plate_number'] ? clean($row['plate_number']) : '<em>no active trip</em>' ?></td>
            <td><?= clean($row['distance_km']) ?> km</td>
            <td>UGX <?= number_format($solo, 2) ?></td>
            <td>UGX <?= number_format($shared, 2) ?></td>
            <td><?= $row['seats_available'] !== null ? (int)$row['seats_available'] : '-' ?></td>
            <td>
                <?php if ($row['trip_id']): ?>
                    <a class="cta small" href="<?= BASE_URL ?>/passenger/book.php?trip_id=<?= (int)$row['trip_id'] ?>&vehicle_type=<?= urlencode($row['vehicle_type']) ?>">Book</a>
                <?php else: ?>
                    <span class="hint">no live trip</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php elseif ($selectedRouteId): ?>
    <p>No fare options configured for this route yet.</p>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
