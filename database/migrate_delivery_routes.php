<?php
require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this migration from the command line only.');
}

db()->exec("CREATE TABLE IF NOT EXISTS delivery_route_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parcel_id INT UNSIGNED NOT NULL,
    rider_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NOT NULL,
    path_json LONGTEXT NOT NULL,
    point_count INT UNSIGNED NOT NULL DEFAULT 0,
    distance_m INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_route_parcel (parcel_id),
    KEY idx_delivery_routes_rider_time (rider_id, completed_at),
    CONSTRAINT fk_delivery_route_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_route_rider FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "delivery_route_records ready\n";
