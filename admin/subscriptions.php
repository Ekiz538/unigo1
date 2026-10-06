<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','operator']);
$pageTitle = 'Billing & subscriptions';
$u = current_user();

// Recalculate the current draft invoice for a given operator on demand.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recalculate') {
    verify_csrf();

    $subId = (int) $_POST['subscription_id'];

    $sub = $pdo->prepare('SELECT s.*, o.economy_index FROM subscriptions s JOIN operators o ON o.id = s.operator_id WHERE s.id = ?');
    $sub->execute([$subId]);
    $row = $sub->fetch();

    if ($row) {
        // Parameterised, not interpolated, so every query in the codebase
        // binds its values and the injection-safety story is consistent.
        $vehStmt = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE operator_id = ?');
        $vehStmt->execute([$row['operator_id']]);
        $vehicleCount = (int) $vehStmt->fetchColumn();
        $kmStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(t.distance_covered),0) FROM (
                SELECT tr.id, r.distance_km AS distance_covered
                FROM trips tr JOIN routes r ON r.id = tr.route_id
                JOIN vehicles v ON v.id = tr.vehicle_id
                WHERE v.operator_id = ? AND tr.status = 'completed'
             ) t"
        );
        $kmStmt->execute([$row['operator_id']]);
        $kmTracked = (float) $kmStmt->fetchColumn();

        $due = calculate_subscription_due(
            (float)$row['base_fee_monthly'],
            (float)$row['per_vehicle_fee'],
            $vehicleCount,
            (float)$row['distance_fee_per_km'],
            $kmTracked,
            (float)$row['economy_index']
        );

        $upd = $pdo->prepare('UPDATE subscriptions SET total_vehicles = ?, total_km_tracked = ?, amount_due = ? WHERE id = ?');
        $upd->execute([$vehicleCount, $kmTracked, $due, $subId]);
        flash('success', 'Invoice recalculated.');
    }
    redirect('/admin/subscriptions.php');
}

$operatorId = null;
if ($u['role'] === 'operator') {
    $op = $pdo->prepare('SELECT id FROM operators WHERE owner_user_id = ?');
    $op->execute([$u['id']]);
    $operatorId = $op->fetchColumn() ?: null;
}

$sql = "SELECT s.*, o.company_name, o.economy_index FROM subscriptions s JOIN operators o ON o.id = s.operator_id";
$params = [];
if ($operatorId) { $sql .= " WHERE s.operator_id = ?"; $params[] = $operatorId; }
$sql .= " ORDER BY s.billing_period_start DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subs = $stmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<h2>Billing &amp; subscriptions</h2>
<p class="hint">Fees = base platform fee + (per-vehicle fee &times; vehicles) + (distance fee &times; km tracked), then
   scaled by each operator's economy adjustment index &mdash; the model described in Section 10 of the case study.</p>

<table class="data-table">
    <thead><tr><th>Operator</th><th>Period</th><th>Vehicles</th><th>KM tracked</th><th>Economy idx</th><th>Amount due</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($subs as $s): ?>
        <tr>
            <td><?= clean($s['company_name']) ?></td>
            <td><?= clean($s['billing_period_start']) ?> &ndash; <?= clean($s['billing_period_end']) ?></td>
            <td><?= (int)$s['total_vehicles'] ?></td>
            <td><?= number_format($s['total_km_tracked'],1) ?> km</td>
            <td><?= number_format($s['economy_index'],2) ?>x</td>
            <td>US$ <?= number_format($s['amount_due'],2) ?></td>
            <td><span class="badge badge-<?= clean($s['status']) ?>"><?= clean($s['status']) ?></span></td>
            <td>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="recalculate">
                    <input type="hidden" name="subscription_id" value="<?= (int)$s['id'] ?>">
                    <button class="cta small ghost" type="submit">Recalculate</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../includes/footer.php'; ?>
