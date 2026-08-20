<?php
/**
 * admin/profile.php — Admin Profile Management
 *
 * Allows the admin to:
 *  • Change their profile picture (via AJAX)
 *  • Update their display name
 *  • Change their password
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth('admin');

$pdo    = db();
$userId = current_user_id();

// Fetch current admin record
$user = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$user->execute([$userId]);
$user = $user->fetch();

$errors     = [];
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = trim($_POST['action'] ?? '');

    // ── Update Name ──────────────────────────────────────────────────────────
    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Display name is required.';
        }

        if (empty($errors)) {
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $userId]);
            $_SESSION['user_name'] = $name;
            $user['name'] = $name;
            log_activity($userId, 'profile_updated', 'Admin updated display name.');
            $successMsg = 'Profile updated successfully.';
        }
    }

    // ── Change Password ──────────────────────────────────────────────────────
    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

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
            log_activity($userId, 'password_changed', 'Admin changed account password.');
            $successMsg = 'Password changed successfully.';
        }
    }
}

$pageTitle  = 'My Profile';
$activePage = 'profile';
$role       = 'admin';
?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url"   content="<?= BASE_URL ?>">

<?php if ($successMsg): ?>
<div id="successFlash" style="background:#f0fdf4;border:1px solid #86efac;border-radius:var(--radius-md,8px);padding:var(--space-3) var(--space-4);color:#15803d;font-size:var(--font-size-sm);margin-bottom:var(--space-5);display:flex;align-items:center;gap:0.5rem;">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;flex-shrink:0;"><polyline points="20 6 9 17 4 12"/></svg>
    <?= e($successMsg) ?>
    <button onclick="document.getElementById('successFlash').remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#15803d;font-size:1.1rem;line-height:1;" aria-label="Dismiss">&times;</button>
</div>
<?php endif; ?>

<!-- Profile Header Card -->
<div class="card" style="margin-bottom:var(--space-6);padding:var(--space-6);">
    <div style="display:flex;align-items:center;gap:var(--space-6);flex-wrap:wrap;">

        <!-- Large avatar with change-photo overlay -->
        <div style="position:relative;flex-shrink:0;">
            <div id="adminAvatarEl"
                  style="width:100px;height:100px;border-radius:50%;background:var(--color-accent);display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:700;color:#fff;overflow:hidden;cursor:pointer;border:3px solid var(--color-border);"
                 title="Click to change profile photo"
                 onclick="document.getElementById('adminAvatarFile').click()">
                <?php if ($user['avatar']): ?>
                <img src="<?= BASE_URL ?>/assets/uploads/avatars/<?= e(rawurlencode($user['avatar'])) ?>"
                     alt="<?= e($user['name']) ?>"
                     style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">
                <?php else: ?>
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <!-- Camera badge -->
            <label for="adminAvatarFile"
                     style="position:absolute;bottom:2px;right:2px;width:30px;height:30px;border-radius:50%;background:var(--color-accent);border:3px solid var(--color-surface,#fff);display:flex;align-items:center;justify-content:center;cursor:pointer;"
                   title="Upload new photo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </label>
            <input type="file" id="adminAvatarFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </div>

        <!-- Name / role info -->
        <div style="flex:1;min-width:0;">
            <div style="font-size:1.5rem;font-weight:700;color:var(--color-text);margin-bottom:var(--space-1);"><?= e($user['name']) ?></div>
            <div style="font-size:var(--font-size-sm);color:var(--color-text-muted);margin-bottom:var(--space-2);">Administrator</div>
            <div style="font-size:var(--font-size-sm);color:var(--color-text-muted);">
                <span style="margin-right:var(--space-4);">📧 <?= e($user['email']) ?></span>
                <span>🗓 Member since <?= fmt_date($user['created_at'], 'M Y') ?></span>
            </div>
        </div>

        <!-- Upload hint -->
        <div style="text-align:center;flex-shrink:0;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('adminAvatarFile').click()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Change Photo
            </button>
            <div class="text-xs text-muted" style="margin-top:var(--space-2);">JPEG · PNG · WebP · Max 5 MB</div>
        </div>
    </div>

    <!-- Avatar upload progress bar (hidden by default) -->
    <div id="avatarProgress" style="display:none;margin-top:var(--space-4);height:4px;border-radius:2px;background:var(--color-border);overflow:hidden;">
        <div id="avatarProgressBar" style="height:100%;width:0;background:var(--color-accent);transition:width .3s;"></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-6);align-items:start;">

    <!-- Edit Display Name -->
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
                    <label class="form-label" for="name">Display Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                           value="<?= e($user['name']) ?>" required>
                    <?php if (isset($errors['name'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['name']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                    <div class="form-hint">Email cannot be changed.</div>
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>
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
                    <input type="password" name="current_password" class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>"
                           autocomplete="current-password" required>
                    <?php if (isset($errors['current_password'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['current_password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>"
                           autocomplete="new-password" required>
                    <div class="form-hint">Minimum 8 characters.</div>
                    <?php if (isset($errors['new_password'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['new_password']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>"
                           autocomplete="new-password" required>
                    <?php if (isset($errors['confirm_password'])): ?>
                    <div class="form-error" style="display:block"><?= e($errors['confirm_password']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>
        </div>
    </div>

</div>

<!-- Avatar upload toast -->
<div id="avatarToast" style="display:none;position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;background:#166534;color:#fff;border-radius:10px;padding:0.75rem 1.25rem;font-size:0.875rem;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;align-items:center;gap:0.5rem;min-width:240px;opacity:0;transition:opacity .25s;"></div>

<script>
(function () {
    const csrfToken  = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const baseUrl    = document.querySelector('meta[name="base-url"]')?.content  || '';
    const fileInput  = document.getElementById('adminAvatarFile');
    const avatarEl   = document.getElementById('adminAvatarEl');
    const toast      = document.getElementById('avatarToast');

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
