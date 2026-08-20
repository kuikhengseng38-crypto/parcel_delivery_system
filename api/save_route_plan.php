<?php
/** Save a rider's current multi-stop route as a history snapshot. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false, 'Method not allowed.');
require_auth('rider');
require_csrf();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['stops']) || !is_array($input['stops'])) {
    json_response(false, 'A route needs at least one stop.');
}

$stops = array_values($input['stops']);
if (count($stops) > 25) json_response(false, 'A route can contain at most 25 stops.');

$pdo = db();
$riderStmt = $pdo->prepare('SELECT id FROM riders WHERE user_id = ?');
$riderStmt->execute([current_user_id()]);
$rider = $riderStmt->fetch();
if (!$rider) json_response(false, 'Rider profile not found.');
$riderId = (int) $rider['id'];

$ids = [];
foreach ($stops as $stop) {
    $id = filter_var($stop['parcel_id'] ?? null, FILTER_VALIDATE_INT);
    $lat = filter_var($stop['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($stop['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!$id || $lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        json_response(false, 'One or more route stops are invalid.');
    }
    $ids[] = (int) $id;
}
if (count(array_unique($ids)) !== count($ids)) json_response(false, 'A parcel can only appear once in a route.');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$owned = $pdo->prepare("SELECT id FROM parcels WHERE rider_id = ? AND status NOT IN ('delivered','failed') AND id IN ({$placeholders})");
$owned->execute(array_merge([$riderId], $ids));
if (count($owned->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
    json_response(false, 'Routes may only contain your active parcels.');
}

$name = trim((string) ($input['name'] ?? ''));
$name = $name !== '' ? mb_substr($name, 0, 120) : 'Delivery route — ' . date('M j, Y');
$originLat = filter_var($input['origin_latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$originLng = filter_var($input['origin_longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$distance = max(0, (int) ($input['total_distance_m'] ?? 0));
$duration = max(0, (int) ($input['total_duration_s'] ?? 0));

try {
    $pdo->beginTransaction();
    $plan = $pdo->prepare('INSERT INTO route_plans (rider_id, name, origin_latitude, origin_longitude, total_distance_m, total_duration_s) VALUES (?, ?, ?, ?, ?, ?)');
    $plan->execute([$riderId, $name, $originLat === false ? null : $originLat, $originLng === false ? null : $originLng, $distance ?: null, $duration ?: null]);
    $planId = (int) $pdo->lastInsertId();

    $addStop = $pdo->prepare('INSERT INTO route_plan_stops (route_plan_id, parcel_id, stop_order, latitude, longitude) VALUES (?, ?, ?, ?, ?)');
    foreach ($stops as $index => $stop) {
        $addStop->execute([$planId, (int) $stop['parcel_id'], $index + 1, (float) $stop['latitude'], (float) $stop['longitude']]);
    }
    $pdo->prepare('INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([current_user_id(), 'route_saved', "Saved route #{$planId} with " . count($stops) . ' stops.', $_SERVER['REMOTE_ADDR'] ?? '']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[Route] Could not save route: ' . $e->getMessage());
    json_response(false, 'Could not save the route. Please try again.');
}

json_response(true, 'Route saved to history.', ['id' => $planId]);
