<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'Pay for trip';
$u = current_user();

$bookingId = (int) ($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT b.id, b.fare_amount, b.status, r.route_name
     FROM bookings b
     JOIN trips t ON t.id = b.trip_id
     JOIN routes r ON r.id = t.route_id
     WHERE b.id = ? AND b.passenger_id = ?"
);
$stmt->execute([$bookingId, $u['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    flash('error', 'Booking not found.');
    redirect('/passenger/my_bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking['status'] === 'pending') {
    verify_csrf();

    $method = in_array($_POST['method'] ?? '', ['mobile_money','card','wallet'], true) ? $_POST['method'] : 'mobile_money';

    // Simulated payment gateway: always succeeds in this prototype.
    $ref = generate_transaction_ref('UNIGO');
    $pdo->beginTransaction();
    try {
        $pay = $pdo->prepare(
            'INSERT INTO payments (booking_id, amount, method, status, transaction_ref) VALUES (?,?,?,?,?)'
        );
        $pay->execute([$bookingId, $booking['fare_amount'], $method, 'success', $ref]);

        $upd = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
        $upd->execute([$bookingId]);

        $pdo->commit();
        flash('success', "Payment successful. Reference: $ref");
        redirect('/passenger/my_bookings.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'Payment failed, please try again.');
    }
}

require __DIR__ . '/../includes/header.php';
?>
<h2>Pay for your trip</h2>
<div class="form-card">
    <p><strong><?= clean($booking['route_name']) ?></strong></p>
    <p>Amount due: <strong>UGX <?= number_format($booking['fare_amount'], 2) ?></strong></p>
    <?php if ($booking['status'] !== 'pending'): ?>
        <p>This booking is already <?= clean($booking['status']) ?>.</p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>">
        <label>Payment method
            <select name="method">
                <option value="mobile_money">Mobile Money</option>
                <option value="card">Card</option>
                <option value="wallet">UniGo Wallet</option>
            </select>
        </label>
        <button type="submit" class="cta">Pay now</button>
    </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
