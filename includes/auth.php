<?php
/**
 * Authentication & Session Helpers
 *
 * Provides session management, role-based access control,
 * and CSRF token generation/validation.
 */

// Ensure a session is active before calling any auth function.
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ]);
}

/**
 * Redirect to login and halt if the user is not authenticated.
 * Optionally restrict to a specific role.
 *
 * @param string|null $requiredRole  'admin' or 'rider', or null for any role
 * @return void
 */
function require_auth(?string $requiredRole = null): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('/login.php');
    }

    if ($requiredRole !== null && $_SESSION['user_role'] !== $requiredRole) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

/**
 * Return true if a user is currently logged in.
 *
 * @return bool
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Return the current user's role, or null if not logged in.
 *
 * @return string|null
 */
function current_role(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/**
 * Return the current user's ID, or null if not logged in.
 *
 * @return int|null
 */
function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Return the current user's name.
 *
 * @return string
 */
function current_user_name(): string
{
    return $_SESSION['user_name'] ?? 'Unknown';
}

/**
 * Return the current user's avatar filename, or null if none set.
 *
 * @return string|null
 */
function current_user_avatar(): ?string
{
    return $_SESSION['user_avatar'] ?? null;
}

/**
 * Log a user in by populating the session.
 *
 * @param array $user  Associative array with keys: id, name, email, role
 * @return void
 */
function login_user(array $user): void
{
    // Regenerate the session ID to prevent session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id']     = (int) $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['user_role']   = $user['role'];
    $_SESSION['user_avatar'] = $user['avatar'] ?? null;
    $_SESSION['logged_in_at'] = time();

    // Generate a CSRF token for this session if one doesn't exist.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_csrf_token();
    }
}

/**
 * Destroy the current session entirely.
 *
 * @return void
 */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

// ---------------------------------------------------------------------------
// CSRF Helpers
// ---------------------------------------------------------------------------

/**
 * Generate a cryptographically secure CSRF token and store it in the session.
 *
 * @return string  Hex-encoded 32-byte token
 */
function generate_csrf_token(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Return the current CSRF token, generating one if necessary.
 *
 * @return string
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        generate_csrf_token();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the CSRF token from a POST request.
 * Accepts the token from either a form field or the X-CSRF-Token header.
 *
 * @return bool
 */
function verify_csrf(): bool
{
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (empty($submitted) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Abort with 403 if CSRF validation fails.
 * Use at the top of any POST-handling page or API endpoint.
 *
 * @return void
 */
function require_csrf(): void
{
    if (!verify_csrf()) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid CSRF token.']));
    }
}

// ---------------------------------------------------------------------------
// Redirect Helper
// ---------------------------------------------------------------------------

/**
 * Redirect to a path relative to BASE_URL and halt execution.
 *
 * @param string $path  e.g. '/admin/dashboard.php'
 * @return void
 */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}
