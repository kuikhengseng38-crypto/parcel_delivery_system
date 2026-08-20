<?php
/**
 * Shared Helper Functions
 *
 * Utility functions used across the application: input sanitisation,
 * tracking number generation, activity logging, pagination, etc.
 */

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------------
// Input / Output Helpers
// ---------------------------------------------------------------------------

/**
 * Sanitise a string value for safe HTML output.
 *
 * @param mixed $value
 * @return string
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Get a sanitised POST value, or a default if not set.
 *
 * @param string $key
 * @param mixed  $default
 * @return string
 */
function post(string $key, $default = ''): string
{
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

/**
 * Get a sanitised GET value, or a default if not set.
 *
 * @param string $key
 * @param mixed  $default
 * @return string
 */
function get_param(string $key, $default = ''): string
{
    return isset($_GET[$key]) ? trim($_GET[$key]) : $default;
}

/**
 * Output a JSON response and halt.
 *
 * @param bool   $success
 * @param string $message
 * @param array  $data     Additional payload to merge into the response
 * @return void
 */
function json_response(bool $success, string $message = '', array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// ---------------------------------------------------------------------------
// Tracking Number
// ---------------------------------------------------------------------------

/**
 * Generate a unique parcel tracking number in the format PDS-YYYYMMDD-XXXX.
 *
 * Retries up to 10 times to avoid (extremely unlikely) collisions.
 *
 * @return string
 * @throws RuntimeException if unique number cannot be generated
 */
function generate_tracking_number(): string
{
    $pdo  = db();
    $date = date('Ymd');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $suffix  = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $number  = "PDS-{$date}-{$suffix}";

        $stmt = $pdo->prepare('SELECT id FROM parcels WHERE tracking_number = ? LIMIT 1');
        $stmt->execute([$number]);

        if (!$stmt->fetch()) {
            return $number;
        }
    }

    throw new RuntimeException('Could not generate a unique tracking number.');
}

// ---------------------------------------------------------------------------
// Activity Logging
// ---------------------------------------------------------------------------

/**
 * Insert an entry into the activity_logs table.
 *
 * @param int    $userId
 * @param string $action   Short action label, e.g. 'parcel_created'
 * @param string $details  Human-readable description
 * @return void
 */
function log_activity(int $userId, string $action, string $details = ''): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Handle IPv4-mapped IPv6 addresses (e.g. ::1 from localhost)
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, action, details, ip_address)
             VALUES (:uid, :action, :details, :ip)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':action'  => $action,
            ':details' => $details,
            ':ip'      => substr($ip, 0, 45),
        ]);
    } catch (PDOException $e) {
        error_log('[Log] Failed to write activity log: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Status Helpers
// ---------------------------------------------------------------------------

/**
 * Return a human-readable label for a parcel status value.
 *
 * @param string $status
 * @return string
 */
function status_label(string $status): string
{
    $map = [
        'pending'          => 'Pending',
        'out_for_delivery' => 'Out for Delivery',
        'delivered'        => 'Delivered',
        'failed'           => 'Failed Delivery',
    ];

    return $map[$status] ?? ucfirst($status);
}

/**
 * Return a CSS class name for a parcel status badge.
 *
 * @param string $status
 * @return string
 */
function status_class(string $status): string
{
    $map = [
        'pending'          => 'badge-warning',
        'out_for_delivery' => 'badge-info',
        'delivered'        => 'badge-success',
        'failed'           => 'badge-danger',
    ];

    return $map[$status] ?? 'badge-secondary';
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

/**
 * Calculate simple pagination metadata.
 *
 * @param int $totalRows   Total number of matching rows
 * @param int $perPage     Rows per page (default 20)
 * @param int $currentPage Current page number (1-based)
 * @return array{total: int, perPage: int, page: int, totalPages: int, offset: int}
 */
function paginate(int $totalRows, int $perPage = 20, int $currentPage = 1): array
{
    $totalPages = (int) ceil($totalRows / $perPage);
    $page       = max(1, min($currentPage, $totalPages ?: 1));
    $offset     = ($page - 1) * $perPage;

    return [
        'total'      => $totalRows,
        'perPage'    => $perPage,
        'page'       => $page,
        'totalPages' => $totalPages,
        'offset'     => $offset,
    ];
}

// ---------------------------------------------------------------------------
// File Upload
// ---------------------------------------------------------------------------

/**
 * Validate and move an uploaded image file.
 *
 * Returns the relative file path on success, or throws on failure.
 *
 * @param array  $file       Entry from $_FILES
 * @param string $uploadDir  Absolute path to the destination directory
 * @return string            Relative path stored in the DB (filename only)
 * @throws RuntimeException on validation or move failure
 */
function handle_photo_upload(array $file, string $uploadDir): string
{
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error code: ' . $file['error']);
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File exceeds the maximum allowed size (5 MB).');
    }

    // Re-verify MIME type using finfo, not the client-supplied value.
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Invalid file type. Only JPEG, PNG, and WebP are allowed.');
    }

    $ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType];
    $filename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    $dest     = rtrim($uploadDir, '/') . '/' . $filename;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save the uploaded file.');
    }

    return $filename;
}

// ---------------------------------------------------------------------------
// Date Formatting
// ---------------------------------------------------------------------------

/**
 * Format a MySQL datetime string for display.
 *
 * @param string|null $datetime
 * @param string      $format
 * @return string
 */
function fmt_date(?string $datetime, string $format = 'M d, Y h:i A'): string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '—';
    }

    try {
        $dt = new DateTimeImmutable($datetime);
        return $dt->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Return a human-friendly "time ago" string.
 *
 * @param string $datetime  MySQL datetime string
 * @return string
 */
function time_ago(string $datetime): string
{
    try {
        $past    = new DateTimeImmutable($datetime);
        $now     = new DateTimeImmutable();
        $diff    = $now->diff($past);

        if ($diff->days > 30)  return fmt_date($datetime, 'M d, Y');
        if ($diff->days >= 1)  return $diff->days . 'd ago';
        if ($diff->h >= 1)     return $diff->h . 'h ago';
        if ($diff->i >= 1)     return $diff->i . 'm ago';
        return 'Just now';
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Preserve the rider's actual GPS trace when a delivery is completed.
 * A unique parcel key makes this an immutable first-completion snapshot.
 */
function capture_delivery_route(PDO $pdo, int $parcelId, int $riderId): void
{
    $exists = $pdo->prepare('SELECT id FROM delivery_route_records WHERE parcel_id = ?');
    $exists->execute([$parcelId]);
    if ($exists->fetch()) return;

    $start = $pdo->prepare("SELECT created_at FROM parcel_status_history WHERE parcel_id = ? AND status = 'out_for_delivery' ORDER BY created_at DESC LIMIT 1");
    $start->execute([$parcelId]);
    $startedAt = $start->fetchColumn() ?: date('Y-m-d H:i:s', time() - 4 * 3600);
    $completedAt = date('Y-m-d H:i:s');

    $locations = $pdo->prepare('SELECT latitude, longitude, recorded_at FROM rider_locations WHERE rider_id = ? AND recorded_at BETWEEN ? AND ? ORDER BY recorded_at ASC');
    $locations->execute([$riderId, $startedAt, $completedAt]);
    $points = array_map(static fn(array $row): array => [
        'lat' => (float) $row['latitude'], 'lng' => (float) $row['longitude'], 'at' => $row['recorded_at'],
    ], $locations->fetchAll());

    $distance = 0.0;
    for ($i = 1, $count = count($points); $i < $count; $i++) {
        $lat1 = deg2rad($points[$i - 1]['lat']); $lat2 = deg2rad($points[$i]['lat']);
        $dLat = $lat2 - $lat1; $dLng = deg2rad($points[$i]['lng'] - $points[$i - 1]['lng']);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $distance += 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    $record = $pdo->prepare('INSERT IGNORE INTO delivery_route_records (parcel_id, rider_id, started_at, completed_at, path_json, point_count, distance_m) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $record->execute([$parcelId, $riderId, $startedAt, $completedAt, json_encode($points, JSON_UNESCAPED_UNICODE), count($points), (int) round($distance)]);
}
