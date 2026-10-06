<?php
require_once __DIR__ . '/../config/config.php';
require_role(['passenger']);
$pageTitle = 'Book a trip';
$u = current_user();

$tripId = (int) ($_GET['trip_id'] ?? $_POST['trip_id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT t.id, t.departure_time, t.seats_available, r.route_name, r.distance_km, v.vehicle_type, v.plate_number
     FROM trips t
     JOIN routes r ON r.id = t.route_id
     JOIN vehicles v ON v.id = t.vehicle_id
     WHERE t.id = ?"
);
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    flash('error', 'That trip could not be found.');
    redirect('/passenger/search.php');
}

$fareStmt = $pdo->prepare('SELECT base_fare, per_km_rate FROM fares WHERE route_id = (SELECT route_id FROM trips WHERE id = ?) AND vehicle_type = ?');
$fareStmt->execute([$tripId, $trip['vehicle_type']]);
$fareRow = $fareStmt->fetch();
$baseFare  = $fareRow ? (float)$fareRow['base_fare'] : 1000;
$perKmRate = $fareRow ? (float)$fareRow['per_km_rate'] : 100;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $bookingType = $_POST['booking_type'] === 'shared' ? 'shared' : 'solo';
    $fare = estimate_fare($baseFare, $perKmRate, (float)$trip['distance_km'], $bookingType);

    // Optimistic pre-check: gives a fast, friendly failure before we touch
    // the database. This is NOT the safety guarantee - the authoritative
    // check is the rowCount() test inside the transaction below.
    if ((int)$trip['seats_available'] < 1) {
        flash('error', 'Sorry, this trip is fully booked.');
        redirect('/passenger/search.php');
    }

    $pdo->beginTransaction();
    try {
        // Claim the seat FIRST. This UPDATE is the concurrency control point:
        // the WHERE clause carries the availability invariant, and InnoDB
        // takes an exclusive row lock on the trip for the rest of the
        // transaction. rowCount() is what tells us the guard actually fired.
        //
        // Fixes D-01: previously this statement ran but its affected-row
        // count was discarded, so two concurrent requests could both book
        // the final seat.
        $claim = $pdo->prepare(
            'UPDATE trips
                SET seats_available = seats_available - 1,
                    seats_taken     = seats_taken + 1
              WHERE id = ? AND seats_available > 0'
        );
        $claim->execute([$tripId]);

        if ($claim->rowCount() !== 1) {
            // Another transaction took the last seat between our pre-check
            // and this update. Abandon cleanly.
            $pdo->rollBack();
            flash('error', 'Sorry, that seat was just taken. Please choose another trip.');
            redirect('/passenger/search.php');
        }

        // Seat allocation. We now hold the trip row lock, so reading
        // seats_taken back is safe and deterministic. The counter we just
        // incremented IS this passenger's seat number, which is why two
        // concurrent bookings can never be handed the same seat.
        //
        // Fixes D-01: previously this was random_int(1, 40), so two
        // passengers could be issued the identical seat number.
        $seatRow = $pdo->prepare('SELECT seats_taken FROM trips WHERE id = ?');
        $seatRow->execute([$tripId]);
        $seatNumber = 'S' . (int) $seatRow->fetchColumn();

        $ins = $pdo->prepare(
            'INSERT INTO bookings (trip_id, passenger_id, seat_number, fare_amount, booking_type, status)
             VALUES (?,?,?,?,?,?)'
        );
        $ins->execute([$tripId, $u['id'], $seatNumber, $fare, $bookingType, 'pending']);
        $bookingId = (int) $pdo->lastInsertId();

        $pdo->commit();
        redirect('/passenger/pay.php?booking_id=' . $bookingId);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Booking failed, please try again.');
    }
}

require __DIR__ . '/../includes/header.php';
$soloFare   = estimate_fare($baseFare, $perKmRate, (float)$trip['distance_km'], 'solo');
$sharedFare = estimate_fare($baseFare, $perKmRate, (float)$trip['distance_km'], 'shared');
?>
<h2>Confirm your booking</h2>
<div class="form-card">
    <p><strong><?= clean($trip['route_name']) ?></strong> via <?= clean(str_replace('_',' ',$trip['vehicle_type'])) ?> (<?= clean($trip['plate_number']) ?>)</p>
    <p>Departure: <?= clean($trip['departure_time']) ?> &middot; Seats available: <?= (int)$trip['seats_available'] ?></p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="trip_id" value="<?= (int)$trip['id'] ?>">
        <label><input type="radio" name="booking_type" value="solo" checked> Solo ride &mdash; UGX <?= number_format($soloFare,2) ?></label>
        <label><input type="radio" name="booking_type" value="shared"> Shared ride &mdash; UGX <?= number_format($sharedFare,2) ?> (save ~35%)</label>
        <button type="submit" class="cta">Reserve seat</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
