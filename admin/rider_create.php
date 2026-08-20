<?php
/**
 * admin/rider_create.php — Register a New Rider
 *
 * Creates a user account (role = rider) and the matching rider profile
 * in one atomic transaction. Supports optional profile picture upload.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo    = db();
$errors = [];
$values = [
    'name'         => '',
    'email'        => '',
    'password'     => '',
    'phone'        => '',
    'vehicle_type' => '',
    'plate_number' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Collect inputs
    foreach ($values as $key => $_) {
        $values[$key] = trim($_POST[$key] ?? '');
    }

    // ── Validation ────────────────────────────────────────────────────────────
    if ($values['name'] === '') {
        $errors['name'] = 'Full name is required.';
    }

    if ($values['email'] === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $chk->execute([$values['email']]);
        if ($chk->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    if ($values['password'] === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($values['password']) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    if ($values['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    }

    if ($values['vehicle_type'] === '') {
        $errors['vehicle_type'] = 'Vehicle type is required.';
    }

    // Validate avatar (optional)
    $avatarFilename = null;
    if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['avatar'] = 'Upload error (code ' . $file['error'] . ').';
        } elseif ($file['size'] > MAX_FILE_SIZE) {
            $errors['avatar'] = 'Profile picture must be under 5 MB.';
        } else {
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mimeType])) {
                $errors['avatar'] = 'Only JPEG, PNG, and WebP images are allowed.';
            } else {
                $avatarFilename = 'avatar_tmp_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mimeType];
            }
        }
    }

    // ── Insert ────────────────────────────────────────────────────────────────
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // 1) Create user account
            $hash = password_hash($values['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare(
                'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)'
            )->execute([$values['name'], $values['email'], $hash, 'rider']);

            $userId = (int) $pdo->lastInsertId();

            // 2) Handle avatar file save (rename with real user ID)
            if ($avatarFilename && !empty($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatarDir = __DIR__ . '/../assets/uploads/avatars/';
                if (!is_dir($avatarDir)) {
                    mkdir($avatarDir, 0755, true);
                }
                $ext = pathinfo($avatarFilename, PATHINFO_EXTENSION);
                $finalFilename = 'avatar_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $avatarDir . $finalFilename)) {
                    $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$finalFilename, $userId]);
                }
            }

            // 3) Create rider profile
            $pdo->prepare(
                'INSERT INTO riders (user_id, phone, vehicle_type, plate_number) VALUES (?, ?, ?, ?)'
            )->execute([
                $userId,
                $values['phone'],
                $values['vehicle_type'],
                $values['plate_number'] ?: '',
            ]);

            $pdo->commit();

            log_activity(
                current_user_id(),
                'rider_created',
                "Created rider account for {$values['name']} ({$values['email']}) — User ID #{$userId}."
            );

            header('Location: ' . BASE_URL . '/admin/riders.php?created=1');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors['general'] = 'An unexpected error occurred. Please try again.';
            error_log('[rider_create] ' . $e->getMessage());
        }
    }
}

$pageTitle  = 'Add Rider';
$activePage = 'riders';
$role       = 'admin';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<div class="section-header">
    <div>
        <div class="section-title">Add New Rider</div>
        <div class="section-subtitle">Register a rider account and delivery profile.</div>
    </div>
    <a href="<?= BASE_URL ?>/admin/riders.php" class="btn btn-secondary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Riders
    </a>
</div>

<div class="card" style="max-width:760px;">
    <div class="card-header">
        <div class="card-title">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>
            Rider Information
        </div>
        <span class="text-xs text-muted">All fields marked * are required</span>
    </div>
    <div class="card-body">

        <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger" style="background:var(--color-danger-light,#fef2f2);border:1px solid #fca5a5;border-radius:var(--radius-md);padding:var(--space-3) var(--space-4);color:#991b1b;margin-bottom:var(--space-5);font-size:var(--font-size-sm);">
            <?= e($errors['general']) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" novalidate id="riderCreateForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <!-- ── Profile Picture ─────────────────────────────────────── -->
            <h3 style="font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:var(--space-4);">Profile Picture</h3>

            <div style="display:flex;align-items:center;gap:var(--space-5);margin-bottom:var(--space-5);">
                <!-- Preview circle -->
                <div id="avatarPreviewWrap" style="position:relative;flex-shrink:0;">
                    <div id="avatarPreview" style="width:88px;height:88px;border-radius:50%;background:var(--color-accent);display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;color:#fff;overflow:hidden;border:3px solid var(--color-border);cursor:pointer;" title="Click to change photo" onclick="document.getElementById('avatarInput').click()">
                        <span id="avatarInitial"><?= strtoupper(substr($values['name'] ?: 'R', 0, 1)) ?></span>
                        <img id="avatarImg" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;border-radius:50%;">
                    </div>
                    <!-- Camera overlay -->
                    <label for="avatarInput" style="position:absolute;bottom:0;right:0;width:26px;height:26px;border-radius:50%;background:var(--color-accent);border:2px solid #fff;display:flex;align-items:center;justify-content:center;cursor:pointer;" title="Upload photo">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    </label>
                </div>

                <div>
                    <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp" style="display:none;">
                    <div style="font-size:var(--font-size-sm);font-weight:600;color:var(--color-text);margin-bottom:var(--space-1);">Upload a profile picture</div>
                    <div class="text-xs text-muted" style="margin-bottom:var(--space-2);">JPEG, PNG, or WebP · Max 5 MB · Optional</div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('avatarInput').click()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Choose Photo
                    </button>
                    <?php if (isset($errors['avatar'])): ?>
                    <div class="form-error" style="display:block;margin-top:var(--space-2);"><?= e($errors['avatar']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-5) 0;">

            <!-- ── Account Details ────────────────────────────────────── -->
            <h3 style="font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:var(--space-4);">Account Details</h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="name">Full Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name"
                           class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                           value="<?= e($values['name']) ?>" required autocomplete="off">
                    <?php if (isset($errors['name'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="email">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email"
                           class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                           value="<?= e($values['email']) ?>" required autocomplete="off">
                    <?php if (isset($errors['email'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['email']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password <span class="required">*</span></label>
                <div style="position:relative;">
                    <input type="password" id="password" name="password"
                           class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                           value="<?= e($values['password']) ?>" required minlength="8"
                           placeholder="Minimum 8 characters"
                           style="padding-right:3rem;">
                    <button type="button" id="togglePassword"
                            style="position:absolute;right:0.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--color-text-muted);padding:0;line-height:1;"
                            aria-label="Toggle password visibility">
                        <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                <div class="form-error" style="display:block"><?= e($errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-5) 0;">

            <!-- ── Rider Profile ───────────────────────────────────────── -->
            <h3 style="font-size:var(--font-size-sm);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-bottom:var(--space-4);">Rider Profile</h3>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
                    <input type="tel" id="phone" name="phone"
                           class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                           value="<?= e($values['phone']) ?>" required
                           placeholder="09XX-XXX-XXXX">
                    <?php if (isset($errors['phone'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['phone']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="vehicle_type">Vehicle Type <span class="required">*</span></label>
                    <select id="vehicle_type" name="vehicle_type"
                            class="form-control <?= isset($errors['vehicle_type']) ? 'is-invalid' : '' ?>" required>
                        <option value="">— Select vehicle —</option>
                        <?php
                        $vehicles = ['Motorcycle', 'Bicycle', 'Car', 'Van', 'Truck', 'E-bike', 'Scooter'];
                        foreach ($vehicles as $v):
                        ?>
                        <option value="<?= e($v) ?>" <?= $values['vehicle_type'] === $v ? 'selected' : '' ?>>
                            <?= e($v) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['vehicle_type'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['vehicle_type']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group" style="max-width:320px;">
                <label class="form-label" for="plate_number">Plate Number <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                <input type="text" id="plate_number" name="plate_number"
                       class="form-control"
                       value="<?= e($values['plate_number']) ?>"
                       placeholder="e.g. ABC-1234"
                       style="text-transform:uppercase;">
            </div>

            <div class="d-flex align-center gap-3" style="margin-top:var(--space-6);">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>
                    Add Rider
                </button>
                <a href="<?= BASE_URL ?>/admin/riders.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ── Avatar live preview ──────────────────────────────────────────────────
    const avatarInput   = document.getElementById('avatarInput');
    const avatarImg     = document.getElementById('avatarImg');
    const avatarInitial = document.getElementById('avatarInitial');

    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            // Size guard (client-side)
            if (file.size > 5 * 1024 * 1024) {
                alert('File is too large. Maximum size is 5 MB.');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                avatarImg.src = e.target.result;
                avatarImg.style.display = 'block';
                avatarInitial.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });
    }

    // ── Sync initials with name field ────────────────────────────────────────
    const nameInput = document.getElementById('name');
    if (nameInput && avatarInitial) {
        nameInput.addEventListener('input', function () {
            if (avatarImg.style.display === 'none') {
                avatarInitial.textContent = (this.value.trim()[0] || 'R').toUpperCase();
            }
        });
    }

    // ── Password show/hide toggle ────────────────────────────────────────────
    const toggle   = document.getElementById('togglePassword');
    const pwdInput = document.getElementById('password');
    const eyeIcon  = document.getElementById('eyeIcon');

    if (toggle && pwdInput) {
        toggle.addEventListener('click', function () {
            const isText = pwdInput.type === 'text';
            pwdInput.type = isText ? 'password' : 'text';
            eyeIcon.innerHTML = isText
                ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
                : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>'
                  + '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
                  + '<line x1="1" y1="1" x2="23" y2="23"/>';
        });
    }

    // ── Auto-uppercase plate number ──────────────────────────────────────────
    const plateInput = document.getElementById('plate_number');
    if (plateInput) {
        plateInput.addEventListener('input', function () {
            const pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
