<?php
/**
 * login.php — User Authentication
 *
 * Handles both GET (render form) and POST (process credentials).
 * On success, redirects to the role-appropriate dashboard.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in → go to dashboard
if (is_logged_in()) {
    redirect(current_role() === 'admin' ? '/admin/dashboard.php' : '/rider/dashboard.php');
}

$error    = '';
$emailVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');
        $emailVal = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            // Fetch user by email
            $stmt = db()->prepare('SELECT id, name, email, password, role, avatar FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                login_user($user);
                log_activity((int)$user['id'], 'login', 'User logged in successfully.');

                if ($user['role'] === 'admin') {
                    redirect('/admin/dashboard.php');
                } else {
                    redirect('/rider/dashboard.php');
                }
            } else {
                $error = 'Invalid email or password. Please try again.';
                // Brief sleep to mitigate brute-force (does not reveal which field was wrong)
                sleep(1);
            }
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= APP_NAME ?> — Sign in to manage parcel deliveries">
    <title>Sign In — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <style>
        /* Login-page specific styles */
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1f2937 50%, #334155 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--space-4);
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }

        .login-brand {
            text-align: center;
            margin-bottom: var(--space-8);
        }

        .login-logo {
            width: 64px;
            height: 64px;
            background: var(--color-accent);
            border-radius: var(--radius-xl);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: var(--space-4);
            box-shadow: 0 8px 24px rgba(37,99,235,0.25);
        }

        .login-logo svg {
            width: 32px;
            height: 32px;
            stroke: #fff;
        }

        .login-brand h1 {
            font-size: var(--font-size-2xl);
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
        }

        .login-brand p {
            font-size: var(--font-size-sm);
            color: rgba(255,255,255,0.55);
            margin-top: var(--space-1);
        }

        .login-card {
            background: rgba(255,255,255,0.97);
            border-radius: var(--radius-xl);
            padding: var(--space-8);
            box-shadow:
                0 18px 40px rgba(15,23,42,0.18),
                0 0 0 1px rgba(226,232,240,0.65);
        }

        .login-card h2 {
            font-size: var(--font-size-xl);
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: var(--space-2);
        }

        .login-card .subtitle {
            font-size: var(--font-size-sm);
            color: var(--color-text-muted);
            margin-bottom: var(--space-6);
        }

        .login-footer {
            text-align: center;
            margin-top: var(--space-6);
            font-size: var(--font-size-xs);
            color: rgba(255,255,255,0.4);
        }

        /* Password show/hide toggle */
        .password-wrap {
            position: relative;
        }

        .password-wrap .form-control {
            padding-right: 44px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--color-text-muted);
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color var(--transition-fast);
        }

        .toggle-password:hover { color: var(--color-text); }
        .toggle-password svg { width: 16px; height: 16px; stroke: currentColor; }

        .btn-login {
            width: 100%;
            padding: var(--space-4);
            font-size: var(--font-size-base);
            margin-top: var(--space-2);
        }

        .demo-credentials {
            margin-top: var(--space-6);
            padding: var(--space-4);
            background: #f8fafc;
            border-radius: var(--radius-md);
            border: 1px solid var(--color-border);
            font-size: var(--font-size-xs);
        }

        .demo-credentials strong {
            display: block;
            font-size: var(--font-size-xs);
            font-weight: 700;
            color: var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: var(--space-3);
        }

        .demo-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-2) 0;
            border-bottom: 1px solid var(--color-border);
        }

        .demo-row:last-child { border-bottom: none; }

        .demo-role {
            font-weight: 600;
            color: var(--color-text);
        }

        .demo-cred {
            font-family: 'Courier New', monospace;
            color: var(--color-text-muted);
        }

        .fill-demo-btn {
            background: none;
            border: none;
            color: var(--color-accent);
            font-size: var(--font-size-xs);
            font-weight: 600;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: var(--radius-sm);
            transition: background var(--transition-fast);
        }

        .fill-demo-btn:hover { background: var(--color-accent-light); }
    </style>
</head>
<body>

<div class="login-wrapper">
    <!-- Brand -->
    <div class="login-brand">
        <div class="login-logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
            </svg>
        </div>
        <h1><?= APP_NAME ?></h1>
        <p>Courier & Parcel Delivery Management</p>
    </div>

    <!-- Login Card -->
    <div class="login-card">
        <h2>Welcome back</h2>
        <p class="subtitle">Sign in to your account to continue</p>

        <?php if ($error): ?>
        <div class="alert alert-danger" role="alert">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/login.php" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="form-group">
                <label class="form-label" for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    value="<?= $emailVal ?>"
                    placeholder="you@example.com"
                    required
                    autocomplete="email"
                    autofocus
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Show/hide password">
                        <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-login" id="submitBtn">
                Sign In
            </button>
        </form>

        <p class="subtitle" style="margin-top:var(--space-4);font-size:var(--font-size-xs)">
            After setup, sign in with the account shown by <code>setup.php</code>, then change the password immediately.
        </p>
    </div>

    <div class="login-footer">
        &copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; v<?= APP_VERSION ?>
    </div>
</div>

<script>
    // Password show/hide
    const toggleBtn = document.getElementById('togglePassword');
    const passInput = document.getElementById('password');
    const eyeIcon   = document.getElementById('eyeIcon');

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const shown = passInput.type === 'text';
            passInput.type = shown ? 'password' : 'text';
            eyeIcon.innerHTML = shown
                ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>'
                : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        });
    }

    // Prevent double-submit
    document.getElementById('loginForm').addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;width:16px;height:16px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 700ms linear infinite;margin-right:8px;vertical-align:middle;"></span>Signing in…';
    });
</script>
<style>@keyframes spin { to { transform: rotate(360deg); } }</style>

</body>
</html>
