<?php
/**
 * Shared HTML Header / Sidebar
 *
 * Expected variables set by the including page:
 *   $pageTitle  string  — Browser <title> text
 *   $activePage string  — Nav item to mark active (e.g. 'dashboard', 'parcels')
 *   $role       string  — 'admin' or 'rider'
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$pageTitle  = $pageTitle  ?? APP_NAME;
$activePage = $activePage ?? '';
$role       = $role       ?? current_role();

// Build the correct nav links depending on role
$navLinks = [];
if ($role === 'admin') {
    $navLinks = [
        ['href' => '/admin/dashboard.php',     'icon' => 'grid',          'label' => 'Dashboard',      'key' => 'dashboard'],
        ['href' => '/admin/rider_map.php',      'icon' => 'map-pin',       'label' => 'Live Map',       'key' => 'map'],
        ['href' => '/admin/parcels.php',        'icon' => 'package',       'label' => 'Parcels',        'key' => 'parcels'],
        ['href' => '/admin/riders.php',         'icon' => 'users',         'label' => 'Riders',         'key' => 'riders'],
        ['href' => '/admin/reports.php',        'icon' => 'bar-chart-2',   'label' => 'Reports',        'key' => 'reports'],
        ['href' => '/admin/activity_logs.php',  'icon' => 'activity',      'label' => 'Activity Logs',  'key' => 'logs'],
        ['href' => '/admin/profile.php',        'icon' => 'user',          'label' => 'My Profile',     'key' => 'profile'],
    ];
} else {
    $navLinks = [
        ['href' => '/rider/dashboard.php',      'icon' => 'grid',       'label' => 'Dashboard',  'key' => 'dashboard'],
        ['href' => '/rider/routes.php',         'icon' => 'map',        'label' => 'Routes',     'key' => 'routes'],
        ['href' => '/rider/parcels.php',        'icon' => 'package',    'label' => 'My Parcels', 'key' => 'parcels'],
        ['href' => '/rider/history.php',        'icon' => 'clock',      'label' => 'History',    'key' => 'history'],
        ['href' => '/rider/profile.php',        'icon' => 'user',       'label' => 'Profile',    'key' => 'profile'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> — Courier & Parcel Delivery Management">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

    <!-- Feather Icons (lightweight SVG icon set) -->
    <script src="https://cdn.jsdelivr.net/npm/feather-icons@4.29.2/dist/feather.min.js" defer></script>

    <!-- Leaflet CSS (only loaded when map page includes it) -->
    <?php if (!empty($usesMap)): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <?php endif; ?>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Application CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/<?= e($role) ?>.css">
</head>
<body class="role-<?= e($role) ?>">

<!-- =========================================================
     Mobile Overlay (closes sidebar on small screens)
     ========================================================= -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- =========================================================
     Sidebar Navigation
     ========================================================= -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <i data-feather="truck"></i>
        </div>
        <span class="sidebar-title"><?= APP_NAME ?></span>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <?php foreach ($navLinks as $link): ?>
            <li class="nav-item <?= $activePage === $link['key'] ? 'active' : '' ?>">
                <a href="<?= BASE_URL . e($link['href']) ?>">
                    <i data-feather="<?= e($link['icon']) ?>"></i>
                    <span><?= e($link['label']) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info" style="gap:var(--space-3);">
            <!-- Clickable sidebar avatar -->
            <div style="position:relative;flex-shrink:0;">
                <div class="user-avatar" id="sidebarAvatarEl"
                     style="width:38px;height:38px;cursor:pointer;overflow:hidden;"
                     title="Change profile photo"
                     onclick="document.getElementById('sidebarAvatarFile').click()">
                    <?php $__avatar = current_user_avatar(); ?>
                    <?php if ($__avatar): ?>
                    <img src="<?= BASE_URL ?>/assets/uploads/avatars/<?= e(rawurlencode($__avatar)) ?>"
                         alt="<?= e(current_user_name()) ?>"
                         style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">
                    <?php else: ?>
                    <?= strtoupper(substr(current_user_name(), 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <!-- Tiny camera badge -->
                 <div onclick="document.getElementById('sidebarAvatarFile').click()"
                     style="position:absolute;bottom:-1px;right:-1px;width:14px;height:14px;border-radius:50%;background:var(--color-accent,#2563eb);border:2px solid var(--sidebar-bg,#1f2937);display:flex;align-items:center;justify-content:center;cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:7px;height:7px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </div>
                <input type="file" id="sidebarAvatarFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
            </div>
            <div class="user-meta">
                <span class="user-name"><?= e(current_user_name()) ?></span>
                <span class="user-role"><?= ucfirst(e($role)) ?></span>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout" title="Logout">
            <i data-feather="log-out"></i>
        </a>
    </div>
</aside>

<!-- Sidebar avatar upload (shared across all pages) -->
<script>
(function () {
    const fileInput = document.getElementById('sidebarAvatarFile');
    const avatarEl  = document.getElementById('sidebarAvatarEl');
    if (!fileInput || !avatarEl) return;

    const csrfToken = '<?= e(csrf_token()) ?>';
    const baseUrl   = '<?= BASE_URL ?>';

    fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        if (file.size > 5 * 1024 * 1024) {
            alert('File is too large. Maximum size is 5 MB.');
            this.value = '';
            return;
        }

        // Instant optimistic preview in sidebar
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
                    // Confirm with server URL
                    avatarEl.innerHTML = '<img src="' + data.url + '?t=' + Date.now() + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;">';
                    // Also sync any in-page avatar (rider profile page, admin list)
                    if (typeof window.onSidebarAvatarUpdated === 'function') {
                        window.onSidebarAvatarUpdated(data.url);
                    }
                } else {
                    alert(data.message || 'Upload failed.');
                }
            })
            .catch(() => alert('Network error. Please try again.'));

        this.value = '';
    });
})();
</script>

<!-- =========================================================
     Main Content Area
     ========================================================= -->
<div class="main-wrapper">

    <!-- Top header bar -->
    <header class="top-bar">
        <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()" aria-label="Toggle menu">
            <i data-feather="menu"></i>
        </button>

        <div class="top-bar-title">
            <h1><?= e($pageTitle) ?></h1>
        </div>

        <div class="top-bar-actions">
            <?php if ($role === 'rider'): ?>
            <!-- Online/Offline toggle shown in top bar for quick access -->
            <div class="online-toggle-wrap" id="topBarOnlineToggle">
                <!-- Populated by rider JS -->
            </div>
            <?php endif; ?>

            <span class="top-bar-time" id="topBarTime"></span>
        </div>
    </header>

    <!-- Page content inserted here -->
    <main class="page-content">
