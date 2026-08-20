<?php
/**
 * admin/parcel_edit.php — Edit Parcel Details
 *
 * Also shows the full status history timeline and proof photos.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo      = db();
$parcelId = (int) get_param('id');

if ($parcelId <= 0) {
    redirect('/admin/parcels.php');
}

$parcel = $pdo->prepare(
    'SELECT p.*, u.name AS rider_name
     FROM parcels p
     LEFT JOIN riders r ON r.id = p.rider_id
     LEFT JOIN users  u ON u.id = r.user_id
     WHERE p.id = ?'
);
$parcel->execute([$parcelId]);
$parcel = $parcel->fetch();

if (!$parcel) {
    redirect('/admin/parcels.php');
}

$errors = [];
$riders = $pdo->query(
    'SELECT r.id, u.name, r.is_online FROM riders r JOIN users u ON u.id = r.user_id ORDER BY r.is_online DESC, u.name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $fields = ['sender_name','sender_phone','recipient_name','recipient_phone','recipient_address','recipient_latitude','recipient_longitude','weight','notes','rider_id','status'];
    $values = [];
    foreach ($fields as $f) {
        $values[$f] = trim($_POST[$f] ?? '');
    }

    $required = ['sender_name','sender_phone','recipient_name','recipient_phone','recipient_address'];
    foreach ($required as $f) {
        if ($values[$f] === '') $errors[$f] = 'Required.';
    }

    $latitude = $values['recipient_latitude'] !== '' ? filter_var($values['recipient_latitude'], FILTER_VALIDATE_FLOAT) : null;
    $longitude = $values['recipient_longitude'] !== '' ? filter_var($values['recipient_longitude'], FILTER_VALIDATE_FLOAT) : null;
    if (($latitude === null) !== ($longitude === null)
        || ($latitude !== null && ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180))) {
        $errors['recipient_location'] = 'Select a valid delivery point on the map, or clear both coordinates.';
    }

    $allowedStatuses = ['pending','out_for_delivery','delivered','failed'];
    if (!in_array($values['status'], $allowedStatuses)) {
        $errors['status'] = 'Invalid status.';
    }

    if (empty($errors)) {
        $riderId = $values['rider_id'] !== '' ? (int)$values['rider_id'] : null;
        $weight  = $values['weight'] !== '' ? (float)$values['weight'] : null;

        $stmt = $pdo->prepare(
            'UPDATE parcels SET sender_name=?,sender_phone=?,recipient_name=?,recipient_phone=?,
             recipient_address=?,recipient_latitude=?,recipient_longitude=?,weight=?,notes=?,rider_id=?,status=?,updated_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([
            $values['sender_name'], $values['sender_phone'],
            $values['recipient_name'], $values['recipient_phone'],
            $values['recipient_address'], $latitude, $longitude, $weight,
            $values['notes'] ?: null, $riderId, $values['status'],
            $parcelId,
        ]);

        // Add status history entry if status changed
        if ($values['status'] !== $parcel['status']) {
            $pdo->prepare(
                'INSERT INTO parcel_status_history (parcel_id, status, remarks, updated_by) VALUES (?, ?, ?, ?)'
            )->execute([
                $parcelId,
                $values['status'],
                'Status updated by admin.',
                current_user_id(),
            ]);
        }

        log_activity(current_user_id(), 'parcel_updated', "Updated parcel #{$parcelId} ({$parcel['tracking_number']}).");

        header('Location: ' . BASE_URL . '/admin/parcel_edit.php?id=' . $parcelId . '&saved=1');
        exit;
    }

    // Re-populate from POST on error
    $parcel = array_merge($parcel, $values);
}

// Status history
$history = $pdo->prepare(
    'SELECT h.*, u.name AS updated_by_name
     FROM parcel_status_history h
     JOIN users u ON u.id = h.updated_by
     WHERE h.parcel_id = ?
     ORDER BY h.created_at DESC'
);
$history->execute([$parcelId]);
$history = $history->fetchAll();

// Proof photos
$photos = $pdo->prepare(
    'SELECT dp.*, u.name AS rider_name
     FROM delivery_photos dp
     JOIN riders r ON r.id = dp.rider_id
     JOIN users u ON u.id = r.user_id
     WHERE dp.parcel_id = ?
     ORDER BY dp.uploaded_at DESC'
);
$photos->execute([$parcelId]);
$photos = $photos->fetchAll();

$pageTitle  = 'Edit Parcel #' . $parcel['tracking_number'];
$activePage = 'parcels';
$role       = 'admin';
$usesMap    = true;
$extraScripts = ['/assets/js/parcel_location_picker.js'];
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<div class="section-header">
    <div>
        <div class="section-title">Edit Parcel</div>
        <div class="section-subtitle">Tracking: <strong><?= e($parcel['tracking_number']) ?></strong></div>
    </div>
    <a href="<?= BASE_URL ?>/admin/parcels.php" class="btn btn-secondary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back
    </a>
</div>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success mb-4"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Parcel saved successfully.</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:var(--space-6);align-items:start;">

    <!-- Edit Form -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">Parcel Details</div>
            <span class="badge <?= status_class($parcel['status']) ?>">
                <span class="badge-dot"></span>
                <?= status_label($parcel['status']) ?>
            </span>
        </div>
        <div class="card-body">
            <form method="POST" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Sender Name <span class="required">*</span></label>
                        <input type="text" name="sender_name" class="form-control" value="<?= e($parcel['sender_name']) ?>" required>
                        <?php if (isset($errors['sender_name'])): ?><div class="form-error" style="display:block"><?= e($errors['sender_name']) ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sender Phone <span class="required">*</span></label>
                        <input type="tel" name="sender_phone" class="form-control" value="<?= e($parcel['sender_phone']) ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Recipient Name <span class="required">*</span></label>
                        <input type="text" name="recipient_name" class="form-control" value="<?= e($parcel['recipient_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Recipient Phone <span class="required">*</span></label>
                        <input type="tel" name="recipient_phone" class="form-control" value="<?= e($parcel['recipient_phone']) ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Delivery Address <span class="required">*</span></label>
                    <textarea id="recipient_address" name="recipient_address" class="form-control" rows="3" required><?= e($parcel['recipient_address']) ?></textarea>
                    <button type="button" id="locateRecipientAddress" class="btn btn-secondary btn-sm" style="margin-top:var(--space-2);">Auto-locate address</button>
                </div>

                <div class="form-group">
                    <label class="form-label">Exact delivery point <span class="text-muted">(recommended)</span></label>
                    <p class="text-xs text-muted" style="margin:-0.25rem 0 var(--space-3);">Click the map to save the recipient's exact location. Rider routes use this point first.</p>
                    <input type="hidden" id="recipient_latitude" name="recipient_latitude" value="<?= e($parcel['recipient_latitude'] ?? '') ?>">
                    <input type="hidden" id="recipient_longitude" name="recipient_longitude" value="<?= e($parcel['recipient_longitude'] ?? '') ?>">
                    <div id="recipientLocationMap" style="height:300px;border:1px solid var(--color-border);border-radius:var(--radius-md);"></div>
                    <div class="d-flex align-center gap-3" style="margin-top:var(--space-3);">
                        <span id="recipientLocationLabel" class="text-xs text-muted">No exact point selected.</span>
                        <button type="button" id="clearRecipientLocation" class="btn btn-secondary btn-sm">Clear point</button>
                    </div>
                    <?php if (isset($errors['recipient_location'])): ?><div class="form-error" style="display:block"><?= e($errors['recipient_location']) ?></div><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Weight (kg)</label>
                        <input type="number" name="weight" class="form-control" value="<?= e($parcel['weight'] ?? '') ?>" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach (['pending','out_for_delivery','delivered','failed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $parcel['status'] === $s ? 'selected' : '' ?>>
                                <?= status_label($s) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Assign Rider</label>
                    <select name="rider_id" class="form-control">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($riders as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= (int)$parcel['rider_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                            <?= e($r['name']) ?> <?= $r['is_online'] ? '🟢' : '⚫' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($parcel['notes'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Changes
                </button>
            </form>
        </div>
    </div>

    <!-- Right Column: History + Photos -->
    <div style="display:flex;flex-direction:column;gap:var(--space-5);">

        <!-- Status Timeline -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">Status History</div>
            </div>
            <div class="card-body">
                <?php if (empty($history)): ?>
                <p class="text-sm text-muted">No history yet.</p>
                <?php else: ?>
                <div class="status-timeline">
                    <?php foreach ($history as $h): ?>
                    <?php
                        $dotClass = match($h['status']) {
                            'delivered'        => 'success',
                            'out_for_delivery' => 'active',
                            'failed'           => 'danger',
                            default            => '',
                        };
                        $icon = match($h['status']) {
                            'delivered'        => 'check',
                            'out_for_delivery' => 'truck',
                            'failed'           => 'x',
                            default            => 'clock',
                        };
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-dot <?= $dotClass ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <?php if ($icon === 'check'): ?><polyline points="20 6 9 17 4 12"/><?php endif; ?>
                                <?php if ($icon === 'truck'): ?><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/><?php endif; ?>
                                <?php if ($icon === 'x'): ?><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/><?php endif; ?>
                                <?php if ($icon === 'clock'): ?><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/><?php endif; ?>
                            </svg>
                        </div>
                        <div class="timeline-body">
                            <div class="timeline-status"><?= status_label($h['status']) ?></div>
                            <?php if ($h['remarks']): ?>
                            <div class="timeline-remarks"><?= e($h['remarks']) ?></div>
                            <?php endif; ?>
                            <div class="timeline-time"><?= fmt_date($h['created_at']) ?> · <?= e($h['updated_by_name']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Proof Photos -->
        <?php if (!empty($photos)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">Delivery Proofs</div>
            </div>
            <div class="card-body">
                <div class="photo-grid">
                    <?php foreach ($photos as $photo): ?>
                    <div class="photo-thumb" onclick="openLightbox('<?= BASE_URL . '/assets/uploads/proofs/' . e($photo['photo_path']) ?>')">
                        <img src="<?= BASE_URL ?>/assets/uploads/proofs/<?= e($photo['photo_path']) ?>" alt="Proof photo" loading="lazy">
                        <div class="photo-overlay">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-muted mt-4">Uploaded by rider · <?= fmt_date($photos[0]['uploaded_at'] ?? '') ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <img id="lightboxImg" src="" alt="Proof photo" onclick="event.stopPropagation()">
</div>

<script>
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLightbox();
});

document.addEventListener('DOMContentLoaded', () => ParcelLocationPicker.init('recipientLocationMap'));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
