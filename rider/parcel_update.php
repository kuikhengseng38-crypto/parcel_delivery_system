<?php
/**
 * rider/parcel_update.php — Update Parcel Status + Photo Upload
 *
 * Rider selects new status, adds remarks, and optionally
 * captures/uploads a delivery proof photo.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('rider');

$pdo      = db();
$userId   = current_user_id();
$parcelId = (int) get_param('id');

// Verify parcel belongs to this rider
$rider = $pdo->prepare('SELECT id, is_online FROM riders WHERE user_id = ?');
$rider->execute([$userId]);
$rider = $rider->fetch();

if (!$rider) redirect('/rider/dashboard.php');
$riderId = (int) $rider['id'];

$parcel = $pdo->prepare('SELECT * FROM parcels WHERE id = ? AND rider_id = ?');
$parcel->execute([$parcelId, $riderId]);
$parcel = $parcel->fetch();

if (!$parcel) redirect('/rider/parcels.php');

// Status history
$history = $pdo->prepare(
    'SELECT h.*, u.name AS by_name
     FROM parcel_status_history h
     JOIN users u ON u.id = h.updated_by
     WHERE h.parcel_id = ?
     ORDER BY h.created_at DESC'
);
$history->execute([$parcelId]);
$history = $history->fetchAll();

// Handle status update POST
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $newStatus = trim($_POST['status'] ?? '');
    $remarks   = trim($_POST['remarks'] ?? '');
    $allowed   = ['pending', 'out_for_delivery', 'delivered', 'failed'];

    if (!in_array($newStatus, $allowed)) {
        $errorMsg = 'Invalid status selected.';
    } else {
        // Update parcel status
        $pdo->prepare('UPDATE parcels SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $parcelId]);

        // Insert history entry
        $pdo->prepare(
            'INSERT INTO parcel_status_history (parcel_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)'
        )->execute([$parcelId, $newStatus, $remarks ?: null, $userId]);

        if ($newStatus === 'delivered' && $parcel['status'] !== 'delivered') {
            capture_delivery_route($pdo, $parcelId, $riderId);
        }

        log_activity($userId, 'status_update', "Parcel #{$parcelId} ({$parcel['tracking_number']}) → {$newStatus}. Remarks: {$remarks}");

        $parcel['status'] = $newStatus; // Update in memory
        $successMsg = 'Parcel status updated to "' . status_label($newStatus) . '".';

        // Re-fetch history
        $history = $pdo->prepare(
            'SELECT h.*, u.name AS by_name FROM parcel_status_history h JOIN users u ON u.id = h.updated_by WHERE h.parcel_id = ? ORDER BY h.created_at DESC'
        );
        $history->execute([$parcelId]);
        $history = $history->fetchAll();
    }
}

// Proof photos
$photos = $pdo->prepare('SELECT * FROM delivery_photos WHERE parcel_id = ? ORDER BY uploaded_at DESC');
$photos->execute([$parcelId]);
$photos = $photos->fetchAll();

$pageTitle    = 'Update Parcel';
$activePage   = 'parcels';
$role         = 'rider';
$extraScripts = ['/assets/js/upload.js', '/assets/js/tracking.js'];
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<div class="section-header">
    <div>
        <div class="section-title">Update Parcel</div>
        <div class="section-subtitle">
            <span class="tracking-number"><?= e($parcel['tracking_number']) ?></span>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/rider/parcels.php" class="btn btn-secondary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back
    </a>
</div>

<?php if ($successMsg): ?>
<div class="alert alert-success mb-4">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    <?= e($successMsg) ?>
</div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div class="alert alert-danger mb-4">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= e($errorMsg) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:var(--space-6);align-items:start;">

    <div style="display:flex;flex-direction:column;gap:var(--space-5);">

        <!-- Parcel Info Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">Recipient Details</div>
                <span class="badge <?= status_class($parcel['status']) ?>">
                    <span class="badge-dot"></span>
                    <?= status_label($parcel['status']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div>
                        <div class="text-xs text-muted mb-4" style="text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">Name</div>
                        <div class="fw-600"><?= e($parcel['recipient_name']) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-muted mb-4" style="text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">Phone</div>
                        <a href="tel:<?= e($parcel['recipient_phone']) ?>" class="fw-600"><?= e($parcel['recipient_phone']) ?></a>
                    </div>
                </div>
                <div style="margin-top:var(--space-4);">
                    <div class="text-xs text-muted mb-4" style="text-transform:uppercase;letter-spacing:0.06em;font-weight:700;">Address</div>
                    <div><?= e($parcel['recipient_address']) ?></div>
                </div>
                <?php if ($parcel['notes']): ?>
                <div style="margin-top:var(--space-4);padding:var(--space-3);background:var(--color-warning-bg);border-radius:var(--radius-md);border-left:3px solid var(--color-warning);">
                    <strong style="font-size:var(--font-size-xs);">Special Instructions:</strong>
                    <div class="text-sm"><?= e($parcel['notes']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status Update Form -->
        <div class="card update-form-card" style="max-width:none;">
            <div class="card-header">
                <div class="card-title">Update Delivery Status</div>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="statusForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <!-- Status Selector -->
                    <div class="form-group">
                        <label class="form-label">New Status</label>
                        <div class="status-selector">
                            <label class="status-option pending">
                                <input type="radio" name="status" value="pending" <?= $parcel['status'] === 'pending' ? 'checked' : '' ?>>
                                <span class="status-option-label">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    Pending
                                </span>
                            </label>

                            <label class="status-option out_delivery">
                                <input type="radio" name="status" value="out_for_delivery" <?= $parcel['status'] === 'out_for_delivery' ? 'checked' : '' ?>>
                                <span class="status-option-label">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                    Out for Delivery
                                </span>
                            </label>

                            <label class="status-option delivered">
                                <input type="radio" name="status" value="delivered" <?= $parcel['status'] === 'delivered' ? 'checked' : '' ?>>
                                <span class="status-option-label">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    Delivered
                                </span>
                            </label>

                            <label class="status-option failed">
                                <input type="radio" name="status" value="failed" <?= $parcel['status'] === 'failed' ? 'checked' : '' ?>>
                                <span class="status-option-label">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                    Failed Delivery
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="form-group">
                        <label class="form-label" for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" class="form-control" rows="3" placeholder="e.g. Delivered to guard house, Recipient not home…"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Update Status
                    </button>
                </form>
            </div>
        </div>

        <!-- Photo Upload -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Delivery Proof Photo
                </div>
            </div>
            <div class="card-body">
                <div class="camera-section" onclick="document.getElementById('photoInput').click()">
                    <input
                        type="file"
                        id="photoInput"
                        name="photo"
                        accept="image/*"
                        capture="environment"
                        onclick="event.stopPropagation()"
                    >
                    <div class="camera-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </div>
                    <h4>Tap to capture photo</h4>
                    <p>Use your phone camera or choose from gallery.<br>Max 5 MB · JPEG / PNG / WebP</p>
                </div>

                <div class="photo-preview" id="photoPreview">
                    <img src="" alt="Preview">
                    <div class="photo-preview-actions">
                        <button type="button" class="btn btn-danger btn-sm" id="removePhotoBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Remove
                        </button>
                    </div>
                    <span class="photo-preview-badge"></span>
                </div>

                <div class="upload-progress" id="uploadProgress">
                    <div class="progress-bar-wrap">
                        <div class="progress-bar"></div>
                    </div>
                    <div class="progress-label">0%</div>
                </div>

                <button type="button" class="btn btn-success w-100 mt-4" id="uploadPhotoBtn" style="margin-top:var(--space-4);">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                    Upload Photo
                </button>

                <?php if (!empty($photos)): ?>
                <div style="margin-top:var(--space-5);">
                    <div class="text-xs text-muted fw-600" style="text-transform:uppercase;letter-spacing:0.06em;margin-bottom:var(--space-3);">Previously Uploaded</div>
                    <div class="photo-grid" style="grid-template-columns:repeat(auto-fill,minmax(80px,1fr));">
                        <?php foreach ($photos as $ph): ?>
                        <div class="photo-thumb">
                            <img src="<?= BASE_URL ?>/assets/uploads/proofs/<?= e($ph['photo_path']) ?>" alt="Proof" loading="lazy">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status History -->
    <div class="card" style="position:sticky;top:calc(var(--topbar-height) + var(--space-4));">
        <div class="card-header">
            <div class="card-title">Status History</div>
        </div>
        <div class="card-body" style="padding-top:0;padding-bottom:0;">
            <?php if (empty($history)): ?>
            <p class="text-sm text-muted" style="padding:var(--space-4) 0;">No history yet.</p>
            <?php else: ?>
            <div class="status-timeline" style="padding:var(--space-4) 0;">
                <?php foreach ($history as $h): ?>
                <?php
                    $dotClass = match($h['status']) {
                        'delivered'        => 'success',
                        'out_for_delivery' => 'active',
                        'failed'           => 'danger',
                        default            => '',
                    };
                ?>
                <div class="timeline-item">
                    <div class="timeline-dot <?= $dotClass ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <?php if ($h['status'] === 'delivered'): ?><polyline points="20 6 9 17 4 12"/>
                            <?php elseif ($h['status'] === 'failed'): ?><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            <?php else: ?><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            <?php endif; ?>
                        </svg>
                    </div>
                    <div class="timeline-body">
                        <div class="timeline-status"><?= status_label($h['status']) ?></div>
                        <?php if ($h['remarks']): ?>
                        <div class="timeline-remarks"><?= e($h['remarks']) ?></div>
                        <?php endif; ?>
                        <div class="timeline-time"><?= time_ago($h['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    Tracking.init({ currentlyOnline: <?= $rider['is_online'] ? 'true' : 'false' ?> });

    PhotoUpload.init({
        inputId:    'photoInput',
        previewId:  'photoPreview',
        progressId: 'uploadProgress',
    });

    document.getElementById('uploadPhotoBtn').addEventListener('click', function () {
        if (!PhotoUpload.hasPhoto()) {
            showToast('Please select or capture a photo first.', 'warning');
            return;
        }

        this.disabled = true;
        PhotoUpload.uploadPhoto(<?= $parcelId ?>).finally(() => {
            this.disabled = false;
        });
    });

    // Record one last high-accuracy GPS point before completing a delivery.
    // This makes the route snapshot end at the rider's actual delivery location.
    const statusForm = document.getElementById('statusForm');
    statusForm.addEventListener('submit', function (event) {
        const selected = statusForm.querySelector('input[name="status"]:checked');
        if (!selected || selected.value !== 'delivered' || statusForm.dataset.locationCaptured) return;
        event.preventDefault();
        statusForm.dataset.locationCaptured = '1';
        const finish = () => statusForm.submit();
        if (!navigator.geolocation) { finish(); return; }
        navigator.geolocation.getCurrentPosition(position => {
            ajax(App.baseUrl + '/api/update_location.php', {
                method: 'POST',
                data: { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy || null },
            }).finally(finish);
        }, finish, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
