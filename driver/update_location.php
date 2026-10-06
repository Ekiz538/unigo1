<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_role'] !== 'driver') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$tripId = (int) ($_POST['trip_id'] ?? 0);
$lat    = (float) ($_POST['lat'] ?? 0);
$lng    = (float) ($_POST['lng'] ?? 0);
$speed  = (float) ($_POST['speed'] ?? 0);

$d = $pdo->prepare('SELECT id, vehicle_id FROM drivers WHERE user_id = ?');
$d->execute([$_SESSION['user_id']]);
$driver = $d->fetch();

$t = $pdo->prepare('SELECT id, vehicle_id FROM trips WHERE id = ? AND driver_id = ?');
$t->execute([$tripId, $driver['id'] ?? 0]);
$trip = $t->fetch();

if (!$trip) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'trip not found for this driver']);
    exit;
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare('INSERT INTO gps_pings (vehicle_id, trip_id, latitude, longitude, speed_kmh) VALUES (?,?,?,?,?)');
    $ins->execute([$trip['vehicle_id'], $tripId, $lat, $lng, $speed]);

    $upd = $pdo->prepare('UPDATE trips SET current_lat = ?, current_lng = ? WHERE id = ?');
    $upd->execute([$lat, $lng, $tripId]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'lat' => $lat, 'lng' => $lng, 'speed' => $speed]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db error']);
}
