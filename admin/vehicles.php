<?php
require_once __DIR__ . '/../config/config.php';
require_role(['admin','operator']);
$pageTitle = 'Vehicles';
$u = current_user();

// Determine which operator scope this user manages (admin sees all)
$operatorId = null;
if ($u['role'] === 'operator') {
    $op = $pdo->prepare('SELECT id FROM operators WHERE owner_user_id = ?');
    $op->execute([$u['id']]);
    $operatorId = $op->fetchColumn() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $plate = trim((string)($_POST['plate_number'] ?? ''));
    $type  = in_array($_POST['vehicle_type'] ?? '', ['boda','taxi','bus','electric_bus','truck'], true) ? $_POST['vehicle_type'] : 'taxi';
    $capacity = max(1, (int) ($_POST['capacity'] ?? 1));
    $opForInsert = $operatorId ?: (int) ($_POST['operator_id'] ?? 0);

    if ($plate && $opForInsert) {
        $stmt = $pdo->prepare('INSERT INTO vehicles (operator_id, plate_number, vehicle_type, capacity) VALUES (?,?,?,?)');
        $stmt->execute([$opForInsert, $plate, $type, $capacity]);
        flash('success', 'Vehicle added.');
    } else {
        flash('error', 'Plate number and operator are required.');
    }
    redirect('/admin/vehicles.php');
}

$sql = "SELECT v.*, o.company_name FROM vehicles v JOIN operators o ON o.id = v.operator_id";
$params = [];
if ($operatorId) { $sql .= " WHERE v.operator_id = ?"; $params[] = $operatorId; }
$sql .= " ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

$operators = $u['role'] === 'admin' ? $pdo->query('SELECT id, company_name FROM operators')->fetchAll() : [];

require __DIR__ . '/../includes/header.php';
?>
<h2>Vehicles</h2>
<div class="form-card">
    <h3>Add vehicle</h3>
    <form method="post">
        <?= csrf_field() ?>
        <?php if ($u['role'] === 'admin'): ?>
        <label>Operator
            <select name="operator_id" required>
                <?php foreach ($operators as $o): ?>
                    <option value="<?= (int)$o['id'] ?>"><?= clean($o['company_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label>Plate number<input type="text" name="plate_number" required></label>
        <label>Type
            <select name="vehicle_type">
                <option value="boda">Boda</option>
                <option value="taxi">Taxi</option>
                <option value="bus">Bus</option>
                <option value="electric_bus">Electric bus</option>
                <option value="truck">Truck</option>
            </select>
        </label>
        <label>Capacity<input type="number" name="capacity" min="1" value="1"></label>
        <button type="submit" class="cta">Add vehicle</button>
    </form>
</div>

<table class="data-table">
    <thead><tr><th>Plate</th><th>Type</th><th>Capacity</th><th>Operator</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($vehicles as $v): ?>
        <tr>
            <td><?= clean($v['plate_number']) ?></td>
            <td><?= clean(str_replace('_',' ',$v['vehicle_type'])) ?></td>
            <td><?= (int)$v['capacity'] ?></td>
            <td><?= clean($v['company_name']) ?></td>
            <td><span class="badge badge-<?= clean($v['status']) ?>"><?= clean($v['status']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require __DIR__ . '/../includes/footer.php'; ?>
