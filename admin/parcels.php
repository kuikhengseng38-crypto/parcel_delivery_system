<?php
/**
 * admin/parcels.php — Parcel Management (List & Search)
 *
 * Shows a searchable, filterable, paginated table of all parcels.
 * Admin can assign a rider, view details, edit, or delete.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo = db();

// ── Filters from query string ────────────────────────────────────────────────
$search   = get_param('q');
$status   = get_param('status');
$riderId  = get_param('rider');
$filter   = get_param('filter');   // 'unassigned'
$page     = max(1, (int) get_param('page', '1'));
$perPage  = 20;

// ── Build query ──────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(p.tracking_number LIKE ? OR p.recipient_name LIKE ? OR p.sender_name LIKE ? OR p.recipient_phone LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

if ($status !== '') {
    $allowed = ['pending', 'out_for_delivery', 'delivered', 'failed'];
    if (in_array($status, $allowed)) {
        $where[]  = 'p.status = ?';
        $params[] = $status;
    }
}

if ($riderId !== '') {
    $where[]  = 'p.rider_id = ?';
    $params[] = (int) $riderId;
}

if ($filter === 'unassigned') {
    $where[] = 'p.rider_id IS NULL';
}

$whereSQL = implode(' AND ', $where);

// Total count for pagination
$countSQL = "SELECT COUNT(*) FROM parcels p WHERE {$whereSQL}";
$countStmt = $pdo->prepare($countSQL);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$pg      = paginate($total, $perPage, $page);
$parcels = (function () use ($pdo, $whereSQL, $params, $perPage, $pg) {
    $sql = "SELECT p.*, u.name AS rider_name, r.is_online AS rider_online
            FROM parcels p
            LEFT JOIN riders r ON r.id = p.rider_id
            LEFT JOIN users u ON u.id = r.user_id
            WHERE {$whereSQL}
            ORDER BY p.created_at DESC
            LIMIT {$perPage} OFFSET {$pg['offset']}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
})();

// All riders for assign modal
$riders = $pdo->query(
    'SELECT r.id, u.name, r.is_online FROM riders r JOIN users u ON u.id = r.user_id ORDER BY r.is_online DESC, u.name'
)->fetchAll();

// Handle POST: assign rider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    require_csrf();

    $parcelId = (int) $_POST['parcel_id'];
    $newRider = $_POST['rider_id'] === '' ? null : (int) $_POST['rider_id'];

    $stmt = $pdo->prepare('UPDATE parcels SET rider_id = ? WHERE id = ?');
    $stmt->execute([$newRider, $parcelId]);

    log_activity(current_user_id(), 'assign_rider', "Parcel #{$parcelId} assigned to rider ID: " . ($newRider ?? 'none'));
    header('Location: ' . BASE_URL . '/admin/parcels.php?q=' . urlencode($search) . '&status=' . urlencode($status) . '&page=' . $page . '&assigned=1');
    exit;
}

// Handle POST: delete parcel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    require_csrf();

    $parcelId = (int) $_POST['parcel_id'];
    $stmt     = $pdo->prepare('DELETE FROM parcels WHERE id = ?');
    $stmt->execute([$parcelId]);

    log_activity(current_user_id(), 'parcel_deleted', "Deleted parcel ID #{$parcelId}.");
    header('Location: ' . BASE_URL . '/admin/parcels.php?deleted=1');
    exit;
}

$pageTitle  = 'Parcels';
$activePage = 'parcels';
$role       = 'admin';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<div class="section-header">
    <div>
        <div class="section-title">Parcel Management</div>
        <div class="section-subtitle"><?= number_format($total) ?> parcel<?= $total !== 1 ? 's' : '' ?> found</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/parcel_create.php" class="btn btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Parcel
    </a>
</div>

<?php if (isset($_GET['assigned'])): ?>
<div class="alert alert-success mb-4">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Rider assigned successfully.
</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success mb-4">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    Parcel deleted.
</div>
<?php endif; ?>

<div class="card">
    <!-- Toolbar -->
    <div class="table-toolbar">
        <form method="GET" action="" style="display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap;flex:1;">
            <div class="search-input-wrap" style="flex:1;min-width:200px;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input
                    type="text"
                    id="parcelSearch"
                    name="q"
                    class="form-control"
                    placeholder="Search tracking #, name, phone…"
                    value="<?= e($search) ?>"
                >
            </div>

            <select name="status" class="form-control" style="width:180px;" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending"          <?= $status === 'pending'          ? 'selected' : '' ?>>Pending</option>
                <option value="out_for_delivery" <?= $status === 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                <option value="delivered"        <?= $status === 'delivered'        ? 'selected' : '' ?>>Delivered</option>
                <option value="failed"           <?= $status === 'failed'           ? 'selected' : '' ?>>Failed</option>
            </select>

            <select name="rider" class="form-control" style="width:160px;" onchange="this.form.submit()">
                <option value="">All Riders</option>
                <?php foreach ($riders as $r): ?>
                <option value="<?= $r['id'] ?>" <?= (int)$riderId === (int)$r['id'] ? 'selected' : '' ?>>
                    <?= e($r['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if ($search || $status || $riderId || $filter): ?>
            <a href="<?= BASE_URL ?>/admin/parcels.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <table class="data-table" id="parcelsTable">
            <thead>
                <tr>
                    <th>Tracking #</th>
                    <th>Recipient</th>
                    <th>Sender</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Assigned Rider</th>
                    <th>Created</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($parcels)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                            <h3>No parcels found</h3>
                            <p>Try adjusting your search or filters.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($parcels as $p): ?>
                <tr>
                    <td><span class="tracking-number"><?= e($p['tracking_number']) ?></span></td>
                    <td>
                        <div class="fw-600"><?= e($p['recipient_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($p['recipient_phone']) ?></div>
                    </td>
                    <td>
                        <div><?= e($p['sender_name']) ?></div>
                        <div class="text-xs text-muted"><?= e($p['sender_phone']) ?></div>
                    </td>
                    <td style="max-width:180px;">
                        <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= e($p['recipient_address']) ?>">
                            <?= e($p['recipient_address']) ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?= status_class($p['status']) ?>">
                            <span class="badge-dot"></span>
                            <?= status_label($p['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($p['rider_name']): ?>
                        <div class="d-flex align-center gap-2">
                            <?php if ($p['rider_online']): ?>
                            <span class="online-dot"></span>
                            <?php else: ?>
                            <span class="offline-dot"></span>
                            <?php endif; ?>
                            <?= e($p['rider_name']) ?>
                        </div>
                        <?php else: ?>
                        <span class="text-muted text-xs">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-xs text-muted"><?= fmt_date($p['created_at'], 'M d, Y') ?></td>
                    <td>
                        <div class="d-flex align-center gap-2" style="justify-content:flex-end;">
                            <a href="<?= BASE_URL ?>/admin/parcel_edit.php?id=<?= $p['id'] ?>" class="btn btn-secondary btn-icon" title="Edit">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </a>
                            <button
                                class="btn btn-secondary btn-icon"
                                title="Assign Rider"
                                onclick="openAssignModal(<?= $p['id'] ?>, '<?= e($p['tracking_number']) ?>', <?= $p['rider_id'] ?? 'null' ?>)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>
                            </button>
                            <button
                                class="btn btn-danger btn-icon"
                                title="Delete"
                                onclick="deleteParcel(<?= $p['id'] ?>, '<?= e($p['tracking_number']) ?>')"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pg['totalPages'] > 1): ?>
    <div class="pagination">
        <div class="pagination-info">
            Showing <?= ($pg['offset'] + 1) ?>–<?= min($pg['offset'] + $perPage, $total) ?> of <?= $total ?>
        </div>
        <div class="pagination-links">
            <?php for ($i = 1; $i <= $pg['totalPages']; $i++): ?>
            <?php
                $url = BASE_URL . '/admin/parcels.php?page=' . $i;
                if ($search) $url .= '&q=' . urlencode($search);
                if ($status) $url .= '&status=' . urlencode($status);
                if ($riderId) $url .= '&rider=' . $riderId;
            ?>
            <a href="<?= $url ?>" class="page-link <?= $pg['page'] === $i ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Assign Rider Modal ──────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="assignModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Assign Rider</h2>
            <button class="modal-close" onclick="closeModal('assignModal')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="parcel_id" id="assignParcelId">

                <p class="text-sm mb-4">
                    Assigning rider for parcel: <strong id="assignTrackingNum"></strong>
                </p>

                <div class="form-group">
                    <label class="form-label" for="assignRider">Select Rider</label>
                    <select name="rider_id" id="assignRider" class="form-control">
                        <option value="">— Unassign —</option>
                        <?php foreach ($riders as $r): ?>
                        <option value="<?= $r['id'] ?>">
                            <?= e($r['name']) ?> <?= $r['is_online'] ? '🟢 Online' : '⚫ Offline' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Form (hidden) -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="parcel_id" id="deleteParcelId">
</form>

<script>
function openAssignModal(parcelId, tracking, currentRider) {
    document.getElementById('assignParcelId').value  = parcelId;
    document.getElementById('assignTrackingNum').textContent = tracking;

    const select = document.getElementById('assignRider');
    if (currentRider !== null) {
        select.value = String(currentRider);
    } else {
        select.value = '';
    }

    openModal('assignModal');
}

function deleteParcel(parcelId, tracking) {
    confirmDialog(
        `Delete parcel "${tracking}"? This action cannot be undone.`,
        function () {
            document.getElementById('deleteParcelId').value = parcelId;
            document.getElementById('deleteForm').submit();
        },
        'Delete Parcel',
        'danger'
    );
}
</script>

<!-- Shared confirm modal -->
<div class="modal-backdrop" id="confirmModal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h2 class="modal-title">Confirm Action</h2>
            <button class="modal-close" onclick="closeModal('confirmModal')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <p id="confirmMessage" class="text-sm"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
