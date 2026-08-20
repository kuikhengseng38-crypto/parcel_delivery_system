<?php
/**
 * admin/riders.php — Rider Management List
 *
 * Shows all riders with their online status, vehicle info,
 * parcel counts, and links to the map.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo    = db();
$search = get_param('q');
$page   = max(1, (int) get_param('page', '1'));
$perPage = 20;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $like     = "%{$search}%";
    $where[]  = '(u.name LIKE ? OR u.email LIKE ? OR r.phone LIKE ? OR r.plate_number LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$whereSQL = implode(' AND ', $where);

$total = (function () use ($pdo, $whereSQL, $params) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM riders r JOIN users u ON u.id = r.user_id WHERE {$whereSQL}");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
})();

$pg = paginate($total, $perPage, $page);

$riders = (function () use ($pdo, $whereSQL, $params, $perPage, $pg) {
    $sql = "SELECT r.*, u.name, u.email, u.avatar,
                   (SELECT COUNT(*) FROM parcels WHERE rider_id = r.id AND status = 'out_for_delivery') AS active_parcels,
                   (SELECT COUNT(*) FROM parcels WHERE rider_id = r.id AND status = 'delivered')        AS delivered_count,
                   (SELECT latitude  FROM rider_locations WHERE rider_id = r.id ORDER BY recorded_at DESC LIMIT 1) AS last_lat,
                   (SELECT longitude FROM rider_locations WHERE rider_id = r.id ORDER BY recorded_at DESC LIMIT 1) AS last_lng
            FROM riders r
            JOIN users u ON u.id = r.user_id
            WHERE {$whereSQL}
            ORDER BY r.is_online DESC, u.name
            LIMIT {$perPage} OFFSET {$pg['offset']}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
})();

$pageTitle  = 'Riders';
$activePage = 'riders';
$role       = 'admin';
$created    = get_param('created') === '1';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<?php if ($created): ?>
<div id="flashMsg" style="background:#f0fdf4;border:1px solid #86efac;border-radius:var(--radius-md,8px);padding:var(--space-3,0.75rem) var(--space-4,1rem);color:#15803d;font-size:var(--font-size-sm,0.875rem);margin-bottom:var(--space-4,1rem);display:flex;align-items:center;gap:0.5rem;">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
    Rider account created successfully.
    <button onclick="document.getElementById('flashMsg').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#15803d;font-size:1.1rem;line-height:1;" aria-label="Dismiss">&times;</button>
</div>
<?php endif; ?>

<div class="section-header">
    <div>
        <div class="section-title">Delivery Riders</div>
        <div class="section-subtitle"><?= $total ?> rider<?= $total !== 1 ? 's' : '' ?> registered</div>
    </div>
    <div class="d-flex align-center gap-3">
        <a href="<?= BASE_URL ?>/admin/rider_create.php" class="btn btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>
            Add Rider
        </a>
        <a href="<?= BASE_URL ?>/admin/rider_map.php" class="btn btn-secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
            Live Map
        </a>
    </div>
</div>

<div class="card">
    <!-- Search Toolbar -->
    <div class="table-toolbar">
        <form method="GET" style="display:flex;gap:var(--space-3);flex:1;">
            <div class="search-input-wrap" style="flex:1;max-width:360px;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="q" class="form-control" placeholder="Search name, email, phone, plate…" value="<?= e($search) ?>">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if ($search): ?>
            <a href="<?= BASE_URL ?>/admin/riders.php" class="btn btn-ghost btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <table class="data-table" id="ridersTable">
            <thead>
                <tr>
                    <th>Rider</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th>Vehicle</th>
                    <th>Active Parcels</th>
                    <th>Delivered</th>
                    <th>Last Seen</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($riders)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            <h3>No riders found</h3>
                            <p>No delivery riders match your search.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($riders as $r): ?>
                <tr>
                    <td>
                        <div class="d-flex align-center gap-3">
                            <!-- Avatar with inline change-photo trigger -->
                            <div style="position:relative;flex-shrink:0;" title="Click to change photo">
                                <div class="user-avatar rider-avatar-cell"
                                     data-user-id="<?= $r['user_id'] ?>"
                                     style="width:40px;height:40px;font-size:0.9rem;cursor:pointer;overflow:hidden;"
                                     onclick="document.getElementById('avatarFile_<?= $r['user_id'] ?>').click()">
                                    <?php if ($r['avatar']): ?>
                                    <img src="<?= BASE_URL ?>/assets/uploads/avatars/<?= e(rawurlencode($r['avatar'])) ?>"
                                         alt="<?= e($r['name']) ?>"
                                         style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">
                                    <?php else: ?>
                                    <?= strtoupper(substr($r['name'], 0, 1)) ?>
                                    <?php endif; ?>
                                </div>
                                <!-- Camera badge overlay -->
                                <div onclick="document.getElementById('avatarFile_<?= $r['user_id'] ?>').click()"
                                      style="position:absolute;bottom:-2px;right:-2px;width:18px;height:18px;border-radius:50%;background:var(--color-accent);border:2px solid var(--color-surface,#fff);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:9px;height:9px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                </div>
                                <!-- Hidden file input -->
                                <input type="file" id="avatarFile_<?= $r['user_id'] ?>" accept="image/jpeg,image/png,image/webp"
                                       style="display:none;"
                                       data-user-id="<?= $r['user_id'] ?>"
                                       data-rider-name="<?= e($r['name']) ?>"
                                       class="avatar-file-input">
                            </div>
                            <div>
                                <div class="fw-600"><?= e($r['name']) ?></div>
                                <div class="text-xs text-muted"><?= e($r['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($r['is_online']): ?>
                        <span class="badge badge-success"><span class="badge-dot"></span>Online</span>
                        <?php else: ?>
                        <span class="badge badge-secondary"><span class="badge-dot"></span>Offline</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm"><?= e($r['phone'] ?: '—') ?></td>
                    <td>
                        <div class="text-sm"><?= e($r['vehicle_type'] ?: '—') ?></div>
                        <div class="text-xs text-muted"><?= e($r['plate_number'] ?: '') ?></div>
                    </td>
                    <td>
                        <span class="badge badge-info">
                            <span class="badge-dot"></span>
                            <?= $r['active_parcels'] ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-success">
                            <span class="badge-dot"></span>
                            <?= $r['delivered_count'] ?>
                        </span>
                    </td>
                    <td class="text-xs text-muted"><?= $r['last_seen'] ? time_ago($r['last_seen']) : '—' ?></td>
                    <td>
                        <div class="d-flex align-center gap-2" style="justify-content:flex-end;">
                            <?php if ($r['is_online'] && $r['last_lat']): ?>
                            <a href="<?= BASE_URL ?>/admin/rider_map.php" class="btn btn-secondary btn-icon" title="View on Map">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                            </a>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/admin/parcels.php?rider=<?= $r['id'] ?>" class="btn btn-secondary btn-icon" title="View Parcels">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                            </a>
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
        <div class="pagination-info">Showing <?= $pg['offset'] + 1 ?>–<?= min($pg['offset'] + $perPage, $total) ?> of <?= $total ?></div>
        <div class="pagination-links">
            <?php for ($i = 1; $i <= $pg['totalPages']; $i++): ?>
            <a href="?page=<?= $i ?>&q=<?= urlencode($search) ?>" class="page-link <?= $pg['page'] === $i ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- ── Avatar upload toast ────────────────────────────────────────────── -->
<div id="avatarToast" style="display:none;position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:#1e293b;color:#fff;border-radius:10px;padding:0.75rem 1.25rem;font-size:0.875rem;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;align-items:center;gap:0.5rem;min-width:240px;opacity:0;transition:opacity .25s;"></div>

<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const baseUrl   = document.querySelector('meta[name="base-url"]')?.content  || '';
    const toast     = document.getElementById('avatarToast');

    function showToast(msg, ok) {
        toast.innerHTML = (ok
            ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        ) + ' ' + msg;
        toast.style.display = 'flex';
        toast.style.background = ok ? '#166534' : '#7f1d1d';
        requestAnimationFrame(() => { toast.style.opacity = '1'; });
        clearTimeout(toast._t);
        toast._t = setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { toast.style.display = 'none'; }, 260);
        }, 3500);
    }

    document.querySelectorAll('.avatar-file-input').forEach(function (input) {
        input.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                showToast('File too large (max 5 MB).', false);
                this.value = '';
                return;
            }

            const userId   = this.dataset.userId;
            const avatarEl = this.closest('[style*="position:relative"]').querySelector('.rider-avatar-cell');

            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('user_id',    userId);
            fd.append('avatar',     file);

            // Optimistic preview
            const reader = new FileReader();
            reader.onload = function (e) {
                avatarEl.innerHTML = '<img src="' + e.target.result + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">';
            };
            reader.readAsDataURL(file);

            fetch(baseUrl + '/api/update_avatar.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('Profile picture updated.', true);
                        // Replace preview with server URL to ensure correctness
                        avatarEl.innerHTML = '<img src="' + data.url + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">';
                    } else {
                        showToast(data.message || 'Upload failed.', false);
                    }
                })
                .catch(() => showToast('Network error. Please try again.', false));

            this.value = '';
        });
    });
})();
</script>
