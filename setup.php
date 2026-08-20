<?php
/**
 * setup.php — One-Time Database Initialisation Script
 *
 * Run this ONCE from the browser to:
 *  1. Create the database and all tables.
 *  2. Insert seed users with properly hashed passwords.
 *  3. Insert sample parcels and activity logs.
 *
 * DELETE OR PROTECT THIS FILE after running it.
 * URL: http://localhost/parcel_delivery_system/setup.php
 */

// Reuse the application database credentials so setup and the running app
// cannot silently drift to different MySQL accounts/passwords.
require_once __DIR__ . '/config/db.php';

// Allow only localhost access
$allowedIPs = ['127.0.0.1', '::1', 'localhost'];
if (!in_array($_SERVER['REMOTE_ADDR'], $allowedIPs)) {
    http_response_code(403);
    die('Access denied. Run this script locally only.');
}

$host    = DB_HOST;
$dbName  = DB_NAME;
$dbUser  = DB_USER;
$dbPass  = DB_PASS;

// ── Step 1: Connect without selecting a database ─────────────────────────────
try {
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('<p style="color:red">Cannot connect to MySQL: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$steps = [];

// ── Step 2: Create database ──────────────────────────────────────────────────
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$dbName}`");
$steps[] = '✅ Database <strong>' . $dbName . '</strong> ready.';

// ── Step 3: Create tables ────────────────────────────────────────────────────
$tables = [
    'users' => "CREATE TABLE IF NOT EXISTS `users` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(100) NOT NULL,
        `email`      VARCHAR(150) NOT NULL,
        `password`   VARCHAR(255) NOT NULL,
        `role`       ENUM('admin','rider') NOT NULL DEFAULT 'rider',
        `avatar`     VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'riders' => "CREATE TABLE IF NOT EXISTS `riders` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`      INT UNSIGNED NOT NULL,
        `phone`        VARCHAR(20)  NOT NULL DEFAULT '',
        `vehicle_type` VARCHAR(50)  NOT NULL DEFAULT '',
        `plate_number` VARCHAR(20)  NOT NULL DEFAULT '',
        `is_online`    TINYINT(1)   NOT NULL DEFAULT 0,
        `last_seen`    TIMESTAMP    NULL DEFAULT NULL,
        `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_riders_user_id` (`user_id`),
        CONSTRAINT `fk_riders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'rider_locations' => "CREATE TABLE IF NOT EXISTS `rider_locations` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `rider_id`    INT UNSIGNED NOT NULL,
        `latitude`    DECIMAL(10,8) NOT NULL,
        `longitude`   DECIMAL(11,8) NOT NULL,
        `accuracy`    FLOAT DEFAULT NULL,
        `recorded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_rl_rider_time` (`rider_id`,`recorded_at`),
        CONSTRAINT `fk_rl_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'parcels' => "CREATE TABLE IF NOT EXISTS `parcels` (
        `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `tracking_number`   VARCHAR(25)  NOT NULL,
        `sender_name`       VARCHAR(100) NOT NULL,
        `sender_phone`      VARCHAR(20)  NOT NULL,
        `recipient_name`    VARCHAR(100) NOT NULL,
        `recipient_phone`   VARCHAR(20)  NOT NULL,
        `recipient_address` TEXT         NOT NULL,
        `recipient_latitude`  DECIMAL(10,7) DEFAULT NULL,
        `recipient_longitude` DECIMAL(10,7) DEFAULT NULL,
        `weight`            DECIMAL(8,2) DEFAULT NULL,
        `notes`             TEXT DEFAULT NULL,
        `rider_id`          INT UNSIGNED DEFAULT NULL,
        `status`            ENUM('pending','out_for_delivery','delivered','failed') NOT NULL DEFAULT 'pending',
        `created_by`        INT UNSIGNED NOT NULL,
        `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_parcels_tracking` (`tracking_number`),
        KEY `idx_parcels_rider`  (`rider_id`),
        KEY `idx_parcels_status` (`status`),
        CONSTRAINT `fk_parcels_rider`      FOREIGN KEY (`rider_id`)   REFERENCES `riders` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_parcels_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`  (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'parcel_status_history' => "CREATE TABLE IF NOT EXISTS `parcel_status_history` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parcel_id`  INT UNSIGNED NOT NULL,
        `status`     ENUM('pending','out_for_delivery','delivered','failed') NOT NULL,
        `remarks`    TEXT DEFAULT NULL,
        `updated_by` INT UNSIGNED NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_psh_parcel` (`parcel_id`),
        CONSTRAINT `fk_psh_parcel`     FOREIGN KEY (`parcel_id`)  REFERENCES `parcels` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_psh_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`   (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'route_plans' => "CREATE TABLE IF NOT EXISTS `route_plans` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `rider_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(120) NOT NULL DEFAULT 'Delivery route',
        `origin_latitude` DECIMAL(10,8) DEFAULT NULL,
        `origin_longitude` DECIMAL(11,8) DEFAULT NULL,
        `total_distance_m` INT UNSIGNED DEFAULT NULL,
        `total_duration_s` INT UNSIGNED DEFAULT NULL,
        `status` ENUM('planned','completed','cancelled') NOT NULL DEFAULT 'planned',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_route_plans_rider_time` (`rider_id`,`created_at`),
        CONSTRAINT `fk_route_plans_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'route_plan_stops' => "CREATE TABLE IF NOT EXISTS `route_plan_stops` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `route_plan_id` INT UNSIGNED NOT NULL,
        `parcel_id` INT UNSIGNED NOT NULL,
        `stop_order` SMALLINT UNSIGNED NOT NULL,
        `latitude` DECIMAL(10,8) NOT NULL,
        `longitude` DECIMAL(11,8) NOT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_route_stop_order` (`route_plan_id`,`stop_order`),
        KEY `idx_route_stops_parcel` (`parcel_id`),
        CONSTRAINT `fk_route_stops_plan` FOREIGN KEY (`route_plan_id`) REFERENCES `route_plans` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_route_stops_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'delivery_route_records' => "CREATE TABLE IF NOT EXISTS `delivery_route_records` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parcel_id` INT UNSIGNED NOT NULL,
        `rider_id` INT UNSIGNED NOT NULL,
        `started_at` DATETIME NOT NULL,
        `completed_at` DATETIME NOT NULL,
        `path_json` LONGTEXT NOT NULL,
        `point_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `distance_m` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_delivery_route_parcel` (`parcel_id`),
        KEY `idx_delivery_routes_rider_time` (`rider_id`,`completed_at`),
        CONSTRAINT `fk_delivery_route_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_delivery_route_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'delivery_photos' => "CREATE TABLE IF NOT EXISTS `delivery_photos` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `parcel_id`   INT UNSIGNED NOT NULL,
        `rider_id`    INT UNSIGNED NOT NULL,
        `photo_path`  VARCHAR(255) NOT NULL,
        `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_dp_parcel` (`parcel_id`),
        CONSTRAINT `fk_dp_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_dp_rider`  FOREIGN KEY (`rider_id`)  REFERENCES `riders`  (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'activity_logs' => "CREATE TABLE IF NOT EXISTS `activity_logs` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `action`     VARCHAR(255) NOT NULL,
        `details`    TEXT DEFAULT NULL,
        `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_al_user` (`user_id`),
        KEY `idx_al_time` (`created_at`),
        CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    $pdo->exec($sql);
    $steps[] = "✅ Table <strong>{$name}</strong> ready.";
}

// CREATE TABLE IF NOT EXISTS does not add columns to an existing installation.
$parcelColumns = $pdo->query('SHOW COLUMNS FROM `parcels`')->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'recipient_latitude'  => 'DECIMAL(10,7) DEFAULT NULL',
    'recipient_longitude' => 'DECIMAL(10,7) DEFAULT NULL',
] as $column => $definition) {
    if (!in_array($column, $parcelColumns, true)) {
        $pdo->exec("ALTER TABLE `parcels` ADD COLUMN `{$column}` {$definition} AFTER `recipient_address`");
        $steps[] = "✅ Column <strong>parcels.{$column}</strong> added.";
    }
}

// ── Step 4: Seed users (skip if already exist) ───────────────────────────────
$seedUsers = [
    ['System Admin',   'admin@parcel.local',  'Admin@1234', 'admin'],
    ['Juan Dela Cruz', 'rider@parcel.local',  'Rider@1234', 'rider'],
    ['Maria Santos',   'rider2@parcel.local', 'Rider@1234', 'rider'],
];

$insertedUsers = [];
foreach ($seedUsers as [$name, $email, $plainPw, $role]) {
    $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$email]);
    $existing = $check->fetch();

    if ($existing) {
        // Always update the password hash to ensure it's correct
        $hash = password_hash($plainPw, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE users SET password = ? WHERE email = ?')->execute([$hash, $email]);
        $insertedUsers[$email] = (int) $existing['id'];
        $steps[] = "🔄 User <strong>{$email}</strong> already exists — password hash refreshed (ID #{$existing['id']}).";
    } else {
        $hash = password_hash($plainPw, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)')->execute([$name,$email,$hash,$role]);
        $id = (int) $pdo->lastInsertId();
        $insertedUsers[$email] = $id;
        $steps[] = "✅ User <strong>{$email}</strong> created (ID #{$id}) · password: <code>{$plainPw}</code>";
    }
}

// ── Step 5: Seed rider profiles ──────────────────────────────────────────────
$riderProfiles = [
    ['rider@parcel.local',  '09171234567', 'Motorcycle', 'ABC-1234'],
    ['rider2@parcel.local', '09189876543', 'Bicycle',    'N/A'],
];

$insertedRiders = [];
foreach ($riderProfiles as [$email, $phone, $vehicle, $plate]) {
    $uid = $insertedUsers[$email] ?? null;
    if (!$uid) continue;

    $check = $pdo->prepare('SELECT id FROM riders WHERE user_id = ?');
    $check->execute([$uid]);
    $existing = $check->fetch();

    if ($existing) {
        $insertedRiders[$email] = (int) $existing['id'];
        $steps[] = "⏭️ Rider profile for <strong>{$email}</strong> already exists.";
    } else {
        $pdo->prepare('INSERT INTO riders (user_id, phone, vehicle_type, plate_number) VALUES (?,?,?,?)')->execute([$uid,$phone,$vehicle,$plate]);
        $rid = (int) $pdo->lastInsertId();
        $insertedRiders[$email] = $rid;
        $steps[] = "✅ Rider profile for <strong>{$email}</strong> created.";
    }
}

// ── Step 6: Seed sample parcels ──────────────────────────────────────────────
$adminId  = $insertedUsers['admin@parcel.local']  ?? 1;
$rider1Id = $insertedRiders['rider@parcel.local']  ?? null;
$rider2Id = $insertedRiders['rider2@parcel.local'] ?? null;

$existingParcels = (int) $pdo->query('SELECT COUNT(*) FROM parcels')->fetchColumn();
if ($existingParcels === 0 && $rider1Id && $rider2Id) {
    $today = date('Ymd');
    $samples = [
        ["PDS-{$today}-0001", 'Acme Corp', '02-8123-4567', 'Jose Rizal', '09111111111', '123 Rizal Ave, Manila', 1.50, $rider1Id, 'out_for_delivery'],
        ["PDS-{$today}-0002", 'Globe Telecom', '02-7890-1234', 'Andres Bonifacio', '09222222222', '456 Bonifacio St, Quezon City', 0.80, $rider1Id, 'pending'],
        ["PDS-{$today}-0003", 'SM Stores', '02-5555-6666', 'Emilio Aguinaldo', '09333333333', '789 Aguinaldo Hwy, Cavite', 3.20, $rider2Id, 'delivered'],
        ["PDS-{$today}-0004", 'Lazada PH', '02-7777-8888', 'Gabriela Silang', '09444444444', '321 Silang Rd, Ilocos Sur', 0.30, null, 'pending'],
        ["PDS-{$today}-0005", 'Shopee Express', '02-9999-0000', 'Antonio Luna', '09555555555', '654 Luna St, Pampanga', 2.10, $rider2Id, 'failed'],
    ];

    foreach ($samples as [$trk, $sname, $sphone, $rname, $rphone, $raddr, $wt, $rid, $stat]) {
        $pdo->prepare(
            'INSERT INTO parcels (tracking_number,sender_name,sender_phone,recipient_name,recipient_phone,recipient_address,weight,rider_id,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$trk,$sname,$sphone,$rname,$rphone,$raddr,$wt,$rid,$stat,$adminId]);

        $pid = (int) $pdo->lastInsertId();

        // Status history entries
        $pdo->prepare('INSERT INTO parcel_status_history (parcel_id,status,remarks,updated_by) VALUES (?,\'pending\',\'Parcel received at sorting facility.\',?)')->execute([$pid,$adminId]);

        if ($stat !== 'pending') {
            $by = $rid ?? $adminId;
            $pdo->prepare('INSERT INTO parcel_status_history (parcel_id,status,remarks,updated_by) VALUES (?,?,?,?)')->execute([$pid,$stat,'Status updated.',$by]);
        }
    }

    $steps[] = '✅ 5 sample parcels inserted.';
} else {
    $steps[] = "⏭️ Parcels already seeded ({$existingParcels} found).";
}

// ── Step 7: Create upload directory ──────────────────────────────────────────
$uploadDir = __DIR__ . '/assets/uploads/proofs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    $steps[] = '✅ Upload directory created: <code>assets/uploads/proofs/</code>';
} else {
    $steps[] = '⏭️ Upload directory already exists.';
}

// ── Done ──────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — ParcelTrack Pro</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 720px; margin: 40px auto; padding: 0 20px; background: #f0f2f5; color: #1e293b; }
        h1 { font-size: 1.75rem; font-weight: 800; margin-bottom: 0.25rem; }
        .subtitle { color: #64748b; margin-bottom: 2rem; }
        .step { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin-bottom: 8px; font-size: 0.9rem; line-height: 1.6; }
        .done { background: #f0fdf4; border: 1px solid #86efac; margin-top: 2rem; padding: 1.5rem; border-radius: 12px; }
        .done h2 { color: #15803d; margin: 0 0 1rem; }
        .creds { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; margin-top: 1rem; }
        .creds table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .creds th { text-align: left; padding: 6px 8px; background: #f8fafc; font-weight: 600; color: #64748b; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.06em; }
        .creds td { padding: 8px; border-top: 1px solid #e2e8f0; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
        .warn { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-top: 1rem; color: #991b1b; font-size: 0.875rem; }
        a.btn { display: inline-block; background: #f97316; color: #fff; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; margin-top: 1rem; }
    </style>
</head>
<body>
<h1>🚀 ParcelTrack Pro Setup</h1>
<p class="subtitle">Database initialisation completed.</p>

<?php foreach ($steps as $step): ?>
<div class="step"><?= $step ?></div>
<?php endforeach; ?>

<div class="done">
    <h2>✅ Setup Complete!</h2>
    <p>Your database has been configured. Use the credentials below to log in:</p>

    <div class="creds">
        <table>
            <tr><th>Role</th><th>Email</th><th>Password</th></tr>
            <tr><td><strong>Admin</strong></td><td>admin@parcel.local</td><td><code>Admin@1234</code></td></tr>
            <tr><td>Rider 1</td><td>rider@parcel.local</td><td><code>Rider@1234</code></td></tr>
            <tr><td>Rider 2</td><td>rider2@parcel.local</td><td><code>Rider@1234</code></td></tr>
        </table>
    </div>

    <div class="warn">
        ⚠️ <strong>Important:</strong> Delete or rename <code>setup.php</code> after completing setup to prevent unauthorised re-initialisation.
    </div>

    <a class="btn" href="/parcel_delivery_system/login.php">Go to Login →</a>
</div>
</body>
</html>
