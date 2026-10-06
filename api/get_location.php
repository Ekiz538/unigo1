<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$tripId = (int) ($_GET['trip_id'] ?? 0);
$stmt = $pdo->prepare('SELECT current_lat, current_lng, status FROM trips WHERE id = ?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'trip not found']);
    exit;
}

echo json_encode([
    'ok'     => true,
    'lat'    => $trip['current_lat'],
    'lng'    => $trip['current_lng'],
    'status' => $trip['status'],
]);
