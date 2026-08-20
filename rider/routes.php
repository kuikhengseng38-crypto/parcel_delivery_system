<?php
/** Multi-stop route planner and route history for riders. */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth('rider');

$pdo = db();
$riderStmt = $pdo->prepare('SELECT id FROM riders WHERE user_id = ?');
$riderStmt->execute([current_user_id()]);
$rider = $riderStmt->fetch();
if (!$rider) redirect('/rider/dashboard.php');
$riderId = (int) $rider['id'];

$location = $pdo->prepare('SELECT latitude, longitude FROM rider_locations WHERE rider_id = ? ORDER BY recorded_at DESC LIMIT 1');
$location->execute([$riderId]);
$location = $location->fetch();

$active = $pdo->prepare("SELECT id, tracking_number, recipient_name, recipient_address, recipient_latitude, recipient_longitude, status FROM parcels WHERE rider_id = ? AND status NOT IN ('delivered','failed') ORDER BY FIELD(status,'out_for_delivery','pending'), updated_at DESC");
$active->execute([$riderId]);
$active = $active->fetchAll();

$history = $pdo->prepare("SELECT rp.*, COUNT(rs.id) AS stop_count,
    GROUP_CONCAT(CONCAT(rs.stop_order, '. ', p.tracking_number) ORDER BY rs.stop_order SEPARATOR '  →  ') AS stop_summary
    FROM route_plans rp
    LEFT JOIN route_plan_stops rs ON rs.route_plan_id = rp.id
    LEFT JOIN parcels p ON p.id = rs.parcel_id
    WHERE rp.rider_id = ? GROUP BY rp.id ORDER BY rp.created_at DESC LIMIT 10");
$history->execute([$riderId]);
$history = $history->fetchAll();

$pageTitle = 'Route Planner';
$activePage = 'routes';
$role = 'rider';
$usesMap = true;
$extraScripts = ['/assets/js/route_planner.js'];
require_once __DIR__ . '/../includes/header.php';
?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<script>window.RoutePlannerData = <?= json_encode([
    'origin' => $location ? ['lat'=>(float)$location['latitude'], 'lng'=>(float)$location['longitude']] : null,
    'parcels' => array_map(fn($p) => ['id'=>(int)$p['id'], 'tracking_number'=>$p['tracking_number'], 'recipient_name'=>$p['recipient_name'], 'recipient_address'=>$p['recipient_address'], 'latitude'=>$p['recipient_latitude'] === null ? null : (float)$p['recipient_latitude'], 'longitude'=>$p['recipient_longitude'] === null ? null : (float)$p['recipient_longitude']], $active),
], JSON_UNESCAPED_UNICODE) ?>;</script>

<div class="section-header">
    <div><div class="section-title">Multi-stop route planner</div><div class="section-subtitle">Optimise today’s active deliveries, then save an immutable route snapshot.</div></div>
</div>
<div class="planner-layout">
    <section class="planner-card">
        <div class="planner-card-head"><strong>Route stops</strong><span class="route-badge route-badge-muted" id="plannerStopCount"><?= count($active) ?> active</span></div>
        <div class="planner-actions"><button class="btn btn-primary btn-sm" id="optimiseRouteBtn" type="button" <?= empty($active) ? 'disabled' : '' ?>>Optimise route</button><button class="btn btn-secondary btn-sm" id="saveRouteBtn" type="button" disabled>Save to history</button></div>
        <div class="planner-summary" id="plannerSummary"><?= $location ? 'Ready to plan from your latest GPS location.' : 'GPS location is required to calculate an accurate route.' ?></div>
        <ol class="route-stop-list" id="routeStopList">
        <?php foreach ($active as $index => $parcel): ?><li><span class="stop-number"><?= $index + 1 ?></span><div><strong><?= e($parcel['tracking_number']) ?></strong><small><?= e($parcel['recipient_name']) ?> · <?= e($parcel['recipient_address']) ?></small></div></li><?php endforeach; ?>
        </ol>
    </section>
    <section class="planner-map-card"><div id="routePlannerMap" class="route-map"></div></section>
</div>

<div class="section-header" style="margin-top:var(--space-8);"><div><div class="section-title">Route history</div><div class="section-subtitle">Saved routes preserve their stop order, distance, and expected travel time.</div></div></div>
<div class="card">
<?php if (!$history): ?><div class="empty-state"><h3>No saved routes yet</h3><p>Optimise active deliveries and save the route to create a history record.</p></div>
<?php else: ?><div class="route-history-list"><?php foreach ($history as $plan): ?><div class="route-history-row"><div><strong><?= e($plan['name']) ?></strong><small><?= fmt_date($plan['created_at']) ?> · <?= (int)$plan['stop_count'] ?> stop<?= (int)$plan['stop_count'] === 1 ? '' : 's' ?></small><small class="route-history-stops"><?= e($plan['stop_summary'] ?: 'No stops recorded') ?></small></div><div class="route-history-metrics"><span><?= $plan['total_distance_m'] ? number_format($plan['total_distance_m']/1000, 1) . ' km' : '—' ?></span><span><?= $plan['total_duration_s'] ? ceil($plan['total_duration_s']/60) . ' min' : '—' ?></span></div></div><?php endforeach; ?></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
