<?php
/**
 * admin/parcel_create.php — Create New Parcel
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo    = db();
$errors = [];
$values = [
    'sender_name'       => '',
    'sender_phone'      => '',
    'recipient_name'    => '',
    'recipient_phone'   => '',
    'recipient_address' => '',
    'recipient_latitude' => '',
    'recipient_longitude' => '',
    'weight'            => '',
    'notes'             => '',
    'rider_id'          => '',
];

$riders = $pdo->query(
    'SELECT r.id, u.name, r.is_online FROM riders r JOIN users u ON u.id = r.user_id ORDER BY r.is_online DESC, u.name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Collect and sanitise inputs
    foreach ($values as $key => $_) {
        $values[$key] = trim($_POST[$key] ?? '');
    }

    // Validate required fields
    $required = ['sender_name', 'sender_phone', 'recipient_name', 'recipient_phone', 'recipient_address'];
    foreach ($required as $field) {
        if ($values[$field] === '') {
            $errors[$field] = 'This field is required.';
        }
    }

    $latitude = $values['recipient_latitude'] !== '' ? filter_var($values['recipient_latitude'], FILTER_VALIDATE_FLOAT) : null;
    $longitude = $values['recipient_longitude'] !== '' ? filter_var($values['recipient_longitude'], FILTER_VALIDATE_FLOAT) : null;
    if (($latitude === null) !== ($longitude === null)
        || ($latitude !== null && ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180))) {
        $errors['recipient_location'] = 'Select a valid delivery point on the map, or clear both coordinates.';
    }

    if (empty($errors)) {
        $tracking = generate_tracking_number();
        $riderId  = $values['rider_id'] !== '' ? (int) $values['rider_id'] : null;
        $weight   = $values['weight'] !== '' ? (float) $values['weight'] : null;

        $stmt = $pdo->prepare(
            'INSERT INTO parcels
             (tracking_number, sender_name, sender_phone, recipient_name, recipient_phone,
              recipient_address, recipient_latitude, recipient_longitude, weight, notes, rider_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
        );

        $stmt->execute([
            $tracking,
            $values['sender_name'],
            $values['sender_phone'],
            $values['recipient_name'],
            $values['recipient_phone'],
            $values['recipient_address'],
            $latitude,
            $longitude,
            $weight,
            $values['notes'] ?: null,
            $riderId,
            current_user_id(),
        ]);

        $parcelId = (int) $pdo->lastInsertId();

        // Insert initial status history
        $pdo->prepare(
            'INSERT INTO parcel_status_history (parcel_id, status, remarks, updated_by) VALUES (?, \'pending\', ?, ?)'
        )->execute([$parcelId, 'Parcel created and registered.', current_user_id()]);

        log_activity(current_user_id(), 'parcel_created', "Created parcel {$tracking} (ID #{$parcelId}).");

        header('Location: ' . BASE_URL . '/admin/parcels.php?created=1');
        exit;
    }
}

$pageTitle  = 'New Parcel';
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
        <div class="section-title">Create New Parcel</div>
        <div class="section-subtitle">Fill in the sender and recipient details below.</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/parcels.php" class="btn btn-secondary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Parcels
    </a>
</div>

<div class="card" style="max-width:760px;">
    <div class="card-header">
        <div class="card-title">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            Parcel Information
        </div>
        <span class="text-xs text-muted">Tracking # will be auto-generated</span>
    </div>
    <div class="card-body">
        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <!-- Sender -->
            <h3 style="font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:var(--space-4);">Sender Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="sender_name">Sender Name <span class="required">*</span></label>
                    <input type="text" id="sender_name" name="sender_name" class="form-control <?= isset($errors['sender_name']) ? 'is-invalid' : '' ?>" value="<?= e($values['sender_name']) ?>" required>
                    <?php if (isset($errors['sender_name'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['sender_name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="sender_phone">Sender Phone <span class="required">*</span></label>
                    <input type="tel" id="sender_phone" name="sender_phone" class="form-control" value="<?= e($values['sender_phone']) ?>" required placeholder="09XX-XXX-XXXX">
                    <?php if (isset($errors['sender_phone'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['sender_phone']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-5) 0;">

            <!-- Recipient -->
            <h3 style="font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:var(--space-4);">Recipient Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="recipient_name">Recipient Name <span class="required">*</span></label>
                    <input type="text" id="recipient_name" name="recipient_name" class="form-control" value="<?= e($values['recipient_name']) ?>" required>
                    <?php if (isset($errors['recipient_name'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['recipient_name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="recipient_phone">Recipient Phone <span class="required">*</span></label>
                    <input type="tel" id="recipient_phone" name="recipient_phone" class="form-control" value="<?= e($values['recipient_phone']) ?>" placeholder="09XX-XXX-XXXX" required>
                    <?php if (isset($errors['recipient_phone'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['recipient_phone']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="recipient_address">Delivery Address <span class="required">*</span></label>
                <textarea id="recipient_address" name="recipient_address" class="form-control" rows="3" required><?= e($values['recipient_address']) ?></textarea>
                <button type="button" id="locateRecipientAddress" class="btn btn-secondary btn-sm" style="margin-top:var(--space-2);">Auto-locate address</button>
                <?php if (isset($errors['recipient_address'])): ?>
                <div class="form-error" style="display:block"><?= e($errors['recipient_address']) ?></div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Exact delivery point <span class="text-muted">(recommended)</span></label>
                <p class="text-xs text-muted" style="margin:-0.25rem 0 var(--space-3);">Click the map to save the recipient's exact location. Rider routes use this point first.</p>
                <input type="hidden" id="recipient_latitude" name="recipient_latitude" value="<?= e($values['recipient_latitude']) ?>">
                <input type="hidden" id="recipient_longitude" name="recipient_longitude" value="<?= e($values['recipient_longitude']) ?>">
                <div id="recipientLocationMap" style="height:300px;border:1px solid var(--color-border);border-radius:var(--radius-md);"></div>
                <div class="d-flex align-center gap-3" style="margin-top:var(--space-3);">
                    <span id="recipientLocationLabel" class="text-xs text-muted">No exact point selected.</span>
                    <button type="button" id="clearRecipientLocation" class="btn btn-secondary btn-sm">Clear point</button>
                </div>
                <?php if (isset($errors['recipient_location'])): ?><div class="form-error" style="display:block"><?= e($errors['recipient_location']) ?></div><?php endif; ?>
            </div>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-5) 0;">

            <!-- Additional Info -->
            <h3 style="font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:var(--space-4);">Additional Info</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="weight">Weight (kg)</label>
                    <input type="number" id="weight" name="weight" class="form-control" value="<?= e($values['weight']) ?>" min="0" step="0.01" placeholder="e.g. 1.50">
                </div>
                <div class="form-group">
                    <label class="form-label" for="rider_id">Assign Rider (optional)</label>
                    <select id="rider_id" name="rider_id" class="form-control">
                        <option value="">— Assign later —</option>
                        <?php foreach ($riders as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $values['rider_id'] == $r['id'] ? 'selected' : '' ?>>
                            <?= e($r['name']) ?> <?= $r['is_online'] ? '🟢' : '⚫' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Special Instructions / Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2" placeholder="e.g. Fragile — handle with care"><?= e($values['notes']) ?></textarea>
            </div>

            <div class="d-flex align-center gap-3" style="margin-top:var(--space-6);">
                <button type="submit" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Create Parcel
                </button>
                <a href="<?= BASE_URL ?>/admin/parcels.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => ParcelLocationPicker.init('recipientLocationMap'));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
