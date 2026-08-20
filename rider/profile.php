<?php
/**
 * rider/profile.php — Rider Profile Management
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('rider');

$pdo    = db();
$userId = current_user_id();

// Fetch user + rider records
$user = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$user->execute([$userId]);
$user = $user->fetch();

$rider = $pdo->prepare('SELECT * FROM riders WHERE user_id = ?');
$rider->execute([$userId]);
$rider = $rider->fetch();

if (!$rider) redirect('/rider/dashboard.php');

$errors     = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = trim($_POST['action'] ?? '');

    if ($action === 'profile') {
        // Update profile info
        $name         = trim($_POST['name'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $vehicleType  = trim($_POST['vehicle_type'] ?? '');
        $plateNumber  = trim($_POST['plate_number'] ?? '');

        if (empty($name))  $errors['name']  = 'Name is required.';
        if (empty($phone)) $errors['phone'] = 'Phone is required.';

        if (empty($errors)) {
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $userId]);
            $pdo->prepare(
                'UPDATE riders SET phone = ?, vehicle_type = ?, plate_number = ? WHERE user_id = ?'
            )->execute([$phone, $vehicleType, $plateNumber, $userId]);

            log_activity($userId, 'profile_updated', 'Rider updated profile information.');
            $_SESSION['user_name'] = $name;
            $successMsg = 'Profile updated successfully.';

            // Refresh data
            $user['name'] = $name;
            $rider['phone']        = $phone;
            $rider['vehicle_type'] = $vehicleType;
            $rider['plate_number'] = $plateNumber;
        }
    } elseif ($action === 'password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors['new_password'] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $userId]);
            log_activity($userId, 'password_changed', 'Rider changed account password.');
            $successMsg = 'Password changed successfully.';
        }
    }
}

// Stats
$stats = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(status='delivered') AS delivered,
        SUM(status='failed')    AS failed
     FROM parcels WHERE rider_id = ?"
);
$stats->execute([$rider['id']]);
$stats = $stats->fetch();

$pageTitle  = 'My Profile';
$activePage = 'profile';
$role       = 'rider';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">

<?php if ($successMsg): ?>
<div class="alert alert-success mb-4">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
    <?= e($successMsg) ?>
</div>
<?php endif; ?>

<!-- Profile Header -->
<div class="profile-header">
    <div class="profile-avatar-wrap">
        <!-- Clickable avatar with camera badge -->
        <div style="position:relative;display:inline-block;">
            <div class="profile-avatar" id="profileAvatarEl"
                 style="cursor:pointer;overflow:hidden;"
                 title="Click to change profile photo"
                 onclick="document.getElementById('avatarFileInput').click()">
                <?php if ($user['avatar']): ?>
                <img id="profileAvatarImg"
                     src="<?= BASE_URL ?>/assets/uploads/avatars/<?= e(rawurlencode($user['avatar'])) ?>"
                     alt="<?= e($user['name']) ?>"
                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">
                <?php else: ?>
                <span id="profileAvatarInitial"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
                <?php endif; ?>
            </div>
            <!-- Camera badge -->
            <label for="avatarFileInput"
                     style="position:absolute;bottom:2px;right:2px;width:28px;height:28px;border-radius:50%;background:var(--color-accent);border:3px solid var(--color-bg,#f1f5f9);display:flex;align-items:center;justify-content:center;cursor:pointer;"
                   title="Upload new photo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </label>
            <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </div>
    </div>
    <div>
        <div class="profile-name"><?= e($user['name']) ?></div>
        <div class="profile-role">Delivery Rider</div>
        <div class="profile-badges">
            <span class="profile-badge">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <?= e($user['email']) ?>
            </span>
            <?php if ($rider['phone']): ?>
            <span class="profile-badge">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13"/></svg>
                <?= e($rider['phone']) ?>
            </span>
            <?php endif; ?>
            <?php if ($rider['vehicle_type']): ?>
            <span class="profile-badge">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                <?= e($rider['vehicle_type']) ?> · <?= e($rider['plate_number'] ?: '—') ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:var(--space-6);">
        <div class="hero-stat">
            <div class="hero-stat-value"><?= $stats['total'] ?></div>
            <div class="hero-stat-label">Total</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-value"><?= $stats['delivered'] ?></div>
            <div class="hero-stat-label">Delivered</div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-6);align-items:start;">

    <!-- Edit Profile -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Profile Information
            </div>
        </div>
        <div class="card-body">
            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="profile">

                <div class="form-group">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
                    <?php if (isset($errors['name'])): ?><div class="form-error" style="display:block"><?= e($errors['name']) ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                    <div class="form-hint">Contact admin to change your email.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number <span class="required">*</span></label>
                    <input type="tel" name="phone" class="form-control" value="<?= e($rider['phone']) ?>" placeholder="09XX-XXX-XXXX" required>
                    <?php if (isset($errors['phone'])): ?><div class="form-error" style="display:block"><?= e($errors['phone']) ?></div><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Vehicle Type</label>
                        <select name="vehicle_type" class="form-control">
                            <option value="">Select…</option>
                            <?php foreach (['Motorcycle','Bicycle','Tricycle','Van','Truck'] as $v): ?>
                            <option value="<?= $v ?>" <?= $rider['vehicle_type'] === $v ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Plate Number</label>
                        <input type="text" name="plate_number" class="form-control" value="<?= e($rider['plate_number']) ?>" placeholder="ABC-1234">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save Profile</button>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Change Password
            </div>
        </div>
        <div class="card-body">
            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="password">

                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" autocomplete="current-password" required>
                    <?php if (isset($errors['current_password'])): ?><div class="form-error" style="display:block"><?= e($errors['current_password']) ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" autocomplete="new-password" required>
                    <div class="form-hint">Minimum 8 characters.</div>
                    <?php if (isset($errors['new_password'])): ?><div class="form-error" style="display:block"><?= e($errors['new_password']) ?></div><?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" autocomplete="new-password" required>
                    <?php if (isset($errors['confirm_password'])): ?><div class="form-error" style="display:block"><?= e($errors['confirm_password']) ?></div><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<!-- ── Avatar upload toast ──────────────────────────────────────── -->
<div id="avatarToast" style="display:none;position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:#166534;color:#fff;border-radius:10px;padding:0.75rem 1.25rem;font-size:0.875rem;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;align-items:center;gap:0.5rem;min-width:240px;opacity:0;transition:opacity .25s;"></div>

<script>
(function () {
    const csrfToken = '<?= e(csrf_token()) ?>';
    const baseUrl   = '<?= BASE_URL ?>';
    const fileInput = document.getElementById('avatarFileInput');
    const avatarEl  = document.getElementById('profileAvatarEl');
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

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                showToast('File too large (max 5 MB).', false);
                this.value = '';
                return;
            }

            // Optimistic preview
            const reader = new FileReader();
            reader.onload = function (e) {
                avatarEl.innerHTML = '<img src="' + e.target.result + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">';
            };
            reader.readAsDataURL(file);

            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('avatar', file);

            fetch(baseUrl + '/api/update_avatar.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('Profile picture updated!', true);
                        const imgTag = '<img src="' + data.url + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">';
                        avatarEl.innerHTML = imgTag;
                        // Sync sidebar avatar
                        const sidebarEl = document.getElementById('sidebarAvatarEl');
                        if (sidebarEl) sidebarEl.innerHTML = imgTag;
                    } else {
                        showToast(data.message || 'Upload failed.', false);
                    }
                })
                .catch(() => showToast('Network error. Please try again.', false));

            this.value = '';
        });
    }
})();
</script>
