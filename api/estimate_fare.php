<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

$routeId  = (int) ($_GET['route_id'] ?? 0);
$type     = $_GET['vehicle_type'] ?? 'taxi';
$bookingType = ($_GET['booking_type'] ?? 'solo') === 'shared' ? 'shared' : 'solo';

$stmt = $pdo->prepare(
    "SELECT f.base_fare, f.per_km_rate, r.distance_km
     FROM fares f JOIN routes r ON r.id = f.route_id
     WHERE f.route_id = ? AND f.vehicle_type = ?"
);
$stmt->execute([$routeId, $type]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'no fare rule for this route/vehicle type']);
    exit;
}

$fare = estimate_fare((float)$row['base_fare'], (float)$row['per_km_rate'], (float)$row['distance_km'], $bookingType);
echo json_encode(['ok' => true, 'fare' => $fare, 'distance_km' => $row['distance_km']]);
