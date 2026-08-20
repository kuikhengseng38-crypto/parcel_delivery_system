<?php
/**
 * admin/reports.php — Delivery Reports
 *
 * Date-range filtered summary of all parcel statuses.
 * Printable. Export section header included.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo = db();

// Date range (default: current month)
$dateFrom = get_param('from', date('Y-m-01'));
$dateTo   = get_param('to',   date('Y-m-d'));
$riderId  = get_param('rider', '');

// Sanitise dates
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : date('Y-m-01');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)   ? $dateTo   : date('Y-m-d');

$where  = ['p.created_at BETWEEN ? AND ?'];
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($riderId !== '') {
    $where[]  = 'p.rider_id = ?';
    $params[] = (int) $riderId;
}

$whereSQL = implode(' AND ', $where);

// Aggregated summary
$summary = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending')          AS pending,
        SUM(status = 'out_for_delivery') AS out_delivery,
        SUM(status = 'delivered')        AS delivered,
        SUM(status = 'failed')           AS failed,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) / COUNT(*) * 100 AS success_rate
     FROM parcels p
     WHERE {$whereSQL}"
);
$summary->execute($params);
$summary = $summary->fetch();

// Rider breakdown
$riderBreakdown = $pdo->prepare(
    "SELECT u.name AS rider_name, r.vehicle_type, r.plate_number,
            COUNT(p.id) AS total,
            SUM(p.status='delivered') AS delivered,
            SUM(p.status='failed')    AS failed,
            SUM(p.status='pending')   AS pending
     FROM riders r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN parcels p ON p.rider_id = r.id AND p.created_at BETWEEN ? AND ?
     GROUP BY r.id
     ORDER BY delivered DESC"
);
$riderBreakdown->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$riderBreakdown = $riderBreakdown->fetchAll();

// All parcels in range for the table
$parcels = $pdo->prepare(
    "SELECT p.tracking_number, p.recipient_name, p.recipient_address, p.status,
            p.created_at, p.updated_at, u.name AS rider_name
     FROM parcels p
     LEFT JOIN riders r ON r.id = p.rider_id
     LEFT JOIN users u ON u.id = r.user_id
     WHERE {$whereSQL}
     ORDER BY p.created_at DESC"
);
$parcels->execute($params);
$parcels = $parcels->fetchAll();

// Actual GPS paths captured at the moment riders completed a delivery.
$routeWhere = ['d.completed_at BETWEEN ? AND ?'];
$routeParams = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
if ($riderId !== '') { $routeWhere[] = 'd.rider_id = ?'; $routeParams[] = (int) $riderId; }
$deliveryRoutes = $pdo->prepare('SELECT d.*, p.tracking_number, p.recipient_name, p.recipient_address, u.name AS rider_name
    FROM delivery_route_records d
    JOIN parcels p ON p.id = d.parcel_id
    JOIN riders r ON r.id = d.rider_id
    JOIN users u ON u.id = r.user_id
    WHERE ' . implode(' AND ', $routeWhere) . ' ORDER BY d.completed_at DESC');
$deliveryRoutes->execute($routeParams);
$deliveryRoutes = $deliveryRoutes->fetchAll();

// For filter dropdown
$riders = $pdo->query(
    'SELECT r.id, u.name FROM riders r JOIN users u ON u.id = r.user_id ORDER BY u.name'
)->fetchAll();

$pageTitle  = 'Reports';
$activePage = 'reports';
$role       = 'admin';
$usesMap    = true;
$extraScripts = ['/assets/js/delivery_report_map.js'];
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<div class="section-header">
    <div>
        <div class="section-title">Delivery Reports</div>
        <div class="section-subtitle"><?= fmt_date($dateFrom, 'M d, Y') ?> — <?= fmt_date($dateTo, 'M d, Y') ?></div>
    </div>
    <button onclick="window.print()" class="btn btn-secondary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Report
    </button>
</div>

<!-- Filter Bar -->
<div class="report-filter-bar card mb-6" style="border-radius:var(--radius-lg);">
    <form method="GET" style="display:flex;align-items:flex-end;gap:var(--space-4);flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
            <label class="form-label">From</label>
            <input type="date" name="from" class="form-control" value="<?= e($dateFrom) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">To</label>
            <input type="date" name="to" class="form-control" value="<?= e($dateTo) ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:180px;">
            <label class="form-label">Rider</label>
            <select name="rider" class="form-control">
                <option value="">All Riders</option>
                <?php foreach ($riders as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $riderId == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Generate</button>
        <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-ghost">Reset</a>
    </form>
</div>

<!-- Summary Cards -->
<div class="stats-grid mb-6">
    <div class="stat-card accent">
        <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
        <div class="stat-info"><div class="stat-value"><?= $summary['total'] ?></div><div class="stat-label">Total Parcels</div></div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div class="stat-info"><div class="stat-value"><?= $summary['delivered'] ?></div><div class="stat-label">Delivered</div></div>
    </div>
    <div class="stat-card warning">
        <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="stat-info"><div class="stat-value"><?= $summary['pending'] ?></div><div class="stat-label">Pending</div></div>
    </div>
    <div class="stat-card danger">
        <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
        <div class="stat-info"><div class="stat-value"><?= $summary['failed'] ?></div><div class="stat-label">Failed</div></div>
    </div>
    <div class="stat-card success">
        <div class="stat-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <div class="stat-info"><div class="stat-value"><?= round($summary['success_rate'] ?? 0, 1) ?>%</div><div class="stat-label">Success Rate</div></div>
    </div>
</div>

<!-- Rider Breakdown -->
<div class="card mb-6">
    <div class="card-header">
        <div class="card-title">Rider Performance</div>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rider</th>
                    <th>Vehicle</th>
                    <th>Total</th>
                    <th>Delivered</th>
                    <th>Pending</th>
                    <th>Failed</th>
                    <th>Success Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($riderBreakdown as $rb): ?>
                <tr>
                    <td class="fw-600"><?= e($rb['rider_name']) ?></td>
                    <td class="text-sm text-muted"><?= e($rb['vehicle_type'] ?: '—') ?> · <?= e($rb['plate_number'] ?: '—') ?></td>
                    <td><?= $rb['total'] ?></td>
                    <td><span class="badge badge-success"><span class="badge-dot"></span><?= $rb['delivered'] ?></span></td>
                    <td><span class="badge badge-warning"><span class="badge-dot"></span><?= $rb['pending'] ?></span></td>
                    <td><span class="badge badge-danger"><span class="badge-dot"></span><?= $rb['failed'] ?></span></td>
                    <td>
                        <?php $rate = $rb['total'] > 0 ? round($rb['delivered'] / $rb['total'] * 100, 1) : 0; ?>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:6px;background:var(--color-border);border-radius:999px;overflow:hidden;">
                                <div style="height:100%;width:<?= $rate ?>%;background:var(--color-success);border-radius:999px;"></div>
                            </div>
                            <span class="text-xs"><?= $rate ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Completed Delivery Routes -->
<div class="section-header" style="margin-top:var(--space-8);">
    <div><div class="section-title">Completed delivery routes</div><div class="section-subtitle">Actual GPS route saved when the rider marked a parcel as delivered.</div></div>
</div>
<?php if (empty($deliveryRoutes)): ?>
<div class="card"><div class="empty-state"><h3>No delivery routes in this period</h3><p>Routes appear after a rider completes a delivery while GPS tracking is active.</p></div></div>
<?php else: ?>
<script>window.DeliveryRouteReportData = <?= json_encode(array_map(static fn($route) => [
    'id'=>(int)$route['id'], 'tracking_number'=>$route['tracking_number'], 'rider_name'=>$route['rider_name'],
    'recipient_address'=>$route['recipient_address'], 'points'=>json_decode($route['path_json'], true) ?: [],
], $deliveryRoutes), JSON_UNESCAPED_UNICODE) ?>;</script>
<div class="delivery-route-report-grid">
    <div class="card delivery-route-report-list">
        <?php foreach ($deliveryRoutes as $index => $route): ?>
        <button type="button" class="delivery-route-item <?= $index === 0 ? 'active' : '' ?>" data-route-id="<?= (int)$route['id'] ?>">
            <span><strong><?= e($route['rider_name']) ?>’s delivery route</strong><small>Completed <?= fmt_date($route['completed_at']) ?></small><small><?= e($route['recipient_name']) ?> · <?= (int)$route['point_count'] ?> GPS points</small></span>
            <span class="delivery-route-distance"><?= $route['distance_m'] ? number_format($route['distance_m'] / 1000, 1) . ' km' : 'No GPS path' ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <div class="card"><div id="deliveryRouteReportMap" class="delivery-route-report-map"></div><div class="delivery-route-map-caption" id="deliveryRouteMapCaption"></div></div>
</div>
<?php endif; ?>

<!-- Full Parcel Table -->
<div class="card">
    <div class="card-header">
        <div class="card-title">All Parcels in Period</div>
        <span class="text-xs text-muted"><?= count($parcels) ?> records</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Tracking #</th>
                    <th>Recipient</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Rider</th>
                    <th>Created</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parcels as $p): ?>
                <tr>
                    <td><span class="tracking-number"><?= e($p['tracking_number']) ?></span></td>
                    <td class="fw-600"><?= e($p['recipient_name']) ?></td>
                    <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= e($p['recipient_address']) ?>"><?= e($p['recipient_address']) ?></td>
                    <td><span class="badge <?= status_class($p['status']) ?>"><span class="badge-dot"></span><?= status_label($p['status']) ?></span></td>
                    <td class="text-sm"><?= e($p['rider_name'] ?? '—') ?></td>
                    <td class="text-xs text-muted"><?= fmt_date($p['created_at'], 'M d, Y') ?></td>
                    <td class="text-xs text-muted"><?= fmt_date($p['updated_at'], 'M d, Y') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($parcels)): ?>
                <tr><td colspan="7"><div class="empty-state"><p>No parcels in this date range.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
