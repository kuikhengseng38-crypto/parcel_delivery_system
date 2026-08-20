<?php
/**
 * api/update_status.php — Parcel Status Update via AJAX
 *
 * POST: parcel_id, status, remarks (optional)
 * Rider only. Validates ownership before updating.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.');
}

require_auth('rider');
require_csrf();

$input    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$parcelId = (int) ($input['parcel_id'] ?? 0);
$status   = trim($input['status'] ?? '');
$remarks  = trim($input['remarks'] ?? '');

$allowed = ['pending', 'out_for_delivery', 'delivered', 'failed'];
if ($parcelId <= 0 || !in_array($status, $allowed)) {
    json_response(false, 'Invalid parcel ID or status.');
}

$pdo = db();

// Verify rider owns the parcel
$stmt = $pdo->prepare(
    'SELECT p.id, p.status, r.id AS rider_id FROM parcels p
     JOIN riders r ON r.id = p.rider_id
     WHERE p.id = ? AND r.user_id = ?'
);
$stmt->execute([$parcelId, current_user_id()]);

$parcel = $stmt->fetch();
if (!$parcel) {
    json_response(false, 'Parcel not found or access denied.');
}

// Update status
$pdo->prepare('UPDATE parcels SET status = ?, updated_at = NOW() WHERE id = ?')
    ->execute([$status, $parcelId]);

// Insert history
$pdo->prepare(
    'INSERT INTO parcel_status_history (parcel_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)'
)->execute([$parcelId, $status, $remarks ?: null, current_user_id()]);

if ($status === 'delivered' && $parcel['status'] !== 'delivered') {
    capture_delivery_route($pdo, $parcelId, (int) $parcel['rider_id']);
}

log_activity(current_user_id(), 'status_update', "Parcel #{$parcelId} → {$status}. {$remarks}");

json_response(true, 'Status updated to "' . status_label($status) . '".', [
    'status'       => $status,
    'status_label' => status_label($status),
]);
