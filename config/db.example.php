<?php
/**
 * Database Configuration (example)
 *
 * Copy this file, then fill in YOUR own values:
 *   copy config\db.example.php config\db.php
 *
 * config/db.php is gitignored. Never commit real hosting credentials,
 * cron secrets, recovery keys, or production site URLs.
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',    'ParcelTrack Pro');
define('APP_VERSION', '1.0.0');

$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('BASE_URL',    $__scheme . '://' . $__host);
define('UPLOAD_DIR',  __DIR__ . '/../assets/uploads/proofs/');
define('UPLOAD_URL',  BASE_URL . '/assets/uploads/proofs/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 MB

/**
 * Returns a shared PDO instance (lazy singleton).
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log('[DB] Connection failed: ' . $e->getMessage());
        http_response_code(503);
        die('Database connection unavailable. Please try again later.');
    }

    return $pdo;
}
