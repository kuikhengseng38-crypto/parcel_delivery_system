-- =============================================================
-- Parcel Delivery Management System — Database Schema
-- Database: parcel_delivery_db
-- Engine: InnoDB | Charset: utf8mb4
-- =============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `parcel_delivery_db`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `parcel_delivery_db`;

-- -------------------------------------------------------------
-- Table: users
-- Stores all system users (admins and riders share this table).
-- -------------------------------------------------------------
CREATE TABLE `users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table: riders
-- Extended profile for users with role = 'rider'.
-- -------------------------------------------------------------
CREATE TABLE `riders` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table: rider_locations
-- Rolling GPS history. Each ping inserts a new row.
-- -------------------------------------------------------------
CREATE TABLE `rider_locations` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rider_id`    INT UNSIGNED NOT NULL,
  `latitude`    DECIMAL(10,8) NOT NULL,
  `longitude`   DECIMAL(11,8) NOT NULL,
  `accuracy`    FLOAT         DEFAULT NULL COMMENT 'Accuracy in metres',
  `recorded_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rl_rider_time` (`rider_id`, `recorded_at`),
  CONSTRAINT `fk_rl_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table: parcels
-- Core parcel records.
-- -------------------------------------------------------------
CREATE TABLE `parcels` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_number`   VARCHAR(25)  NOT NULL,
  `sender_name`       VARCHAR(100) NOT NULL,
  `sender_phone`      VARCHAR(20)  NOT NULL,
  `recipient_name`    VARCHAR(100) NOT NULL,
  `recipient_phone`   VARCHAR(20)  NOT NULL,
  `recipient_address` TEXT         NOT NULL,
  `recipient_latitude`  DECIMAL(10,7) DEFAULT NULL COMMENT 'Manually selected delivery latitude',
  `recipient_longitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Manually selected delivery longitude',
  `weight`            DECIMAL(8,2) DEFAULT NULL COMMENT 'Weight in kilograms',
  `notes`             TEXT         DEFAULT NULL,
  `rider_id`          INT UNSIGNED DEFAULT NULL,
  `status`            ENUM('pending','out_for_delivery','delivered','failed') NOT NULL DEFAULT 'pending',
  `created_by`        INT UNSIGNED NOT NULL,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parcels_tracking` (`tracking_number`),
  KEY `idx_parcels_rider`  (`rider_id`),
  KEY `idx_parcels_status` (`status`),
  CONSTRAINT `fk_parcels_rider`      FOREIGN KEY (`rider_id`)   REFERENCES `riders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_parcels_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table: parcel_status_history
-- Full audit trail of every status change on a parcel.
-- -------------------------------------------------------------
CREATE TABLE `parcel_status_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Tables: route_plans / route_plan_stops
-- A saved snapshot of a rider's optimised multi-stop delivery route.
-- -------------------------------------------------------------
CREATE TABLE `route_plans` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rider_id`            INT UNSIGNED NOT NULL,
  `name`                VARCHAR(120) NOT NULL DEFAULT 'Delivery route',
  `origin_latitude`     DECIMAL(10,8) DEFAULT NULL,
  `origin_longitude`    DECIMAL(11,8) DEFAULT NULL,
  `total_distance_m`    INT UNSIGNED DEFAULT NULL,
  `total_duration_s`    INT UNSIGNED DEFAULT NULL,
  `status`              ENUM('planned','completed','cancelled') NOT NULL DEFAULT 'planned',
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_route_plans_rider_time` (`rider_id`, `created_at`),
  CONSTRAINT `fk_route_plans_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `route_plan_stops` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `route_plan_id`   INT UNSIGNED NOT NULL,
  `parcel_id`       INT UNSIGNED NOT NULL,
  `stop_order`      SMALLINT UNSIGNED NOT NULL,
  `latitude`        DECIMAL(10,8) NOT NULL,
  `longitude`       DECIMAL(11,8) NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_route_stop_order` (`route_plan_id`, `stop_order`),
  KEY `idx_route_stops_parcel` (`parcel_id`),
  CONSTRAINT `fk_route_stops_plan` FOREIGN KEY (`route_plan_id`) REFERENCES `route_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_route_stops_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actual GPS path captured when a parcel is first marked delivered.
CREATE TABLE `delivery_route_records` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parcel_id`        INT UNSIGNED NOT NULL,
  `rider_id`         INT UNSIGNED NOT NULL,
  `started_at`       DATETIME NOT NULL,
  `completed_at`     DATETIME NOT NULL,
  `path_json`        LONGTEXT NOT NULL,
  `point_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `distance_m`       INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_route_parcel` (`parcel_id`),
  KEY `idx_delivery_routes_rider_time` (`rider_id`, `completed_at`),
  CONSTRAINT `fk_delivery_route_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_delivery_route_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table: delivery_photos
-- Proof-of-delivery images uploaded by riders.
-- -------------------------------------------------------------
CREATE TABLE `delivery_photos` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parcel_id`   INT UNSIGNED NOT NULL,
  `rider_id`    INT UNSIGNED NOT NULL,
  `photo_path`  VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dp_parcel` (`parcel_id`),
  CONSTRAINT `fk_dp_parcel` FOREIGN KEY (`parcel_id`) REFERENCES `parcels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dp_rider`  FOREIGN KEY (`rider_id`)  REFERENCES `riders`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table: activity_logs
-- Records significant actions for auditing.
-- -------------------------------------------------------------
CREATE TABLE `activity_logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `action`     VARCHAR(255) NOT NULL,
  `details`    TEXT         DEFAULT NULL,
  `ip_address` VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_time` (`created_at`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- Seed Data
-- Admin:  admin@parcel.local  / Admin@1234
-- Rider:  rider@parcel.local  / Rider@1234
-- Rider2: rider2@parcel.local / Rider@1234
-- =============================================================

INSERT INTO `users` (`name`, `email`, `password`, `role`) VALUES
('System Admin',  'admin@parcel.local',  '$2y$12$WvAIeVqdfEWSP66tq5N8Be4zVv71T9xJhun98ua/1NPAdPZ.uvOui', 'admin'),
('Juan Dela Cruz','rider@parcel.local',  '$2y$12$vJ2WohXZwU4tX1yimmy.vuj56e2oeKQ8l5aRX5c.JmsN1RxQZwf2i', 'rider'),
('Maria Santos',  'rider2@parcel.local', '$2y$12$vJ2WohXZwU4tX1yimmy.vuj56e2oeKQ8l5aRX5c.JmsN1RxQZwf2i', 'rider');

-- The password hashes above are valid bcrypt hashes for the demo credentials.

-- Rider profiles
INSERT INTO `riders` (`user_id`, `phone`, `vehicle_type`, `plate_number`) VALUES
(2, '09171234567', 'Motorcycle', 'ABC-1234'),
(3, '09189876543', 'Bicycle',    'N/A');

-- Sample parcels
INSERT INTO `parcels` (`tracking_number`, `sender_name`, `sender_phone`, `recipient_name`, `recipient_phone`, `recipient_address`, `weight`, `rider_id`, `status`, `created_by`) VALUES
('PDS-20240101-0001', 'Acme Corp',       '02-8123-4567', 'Jose Rizal',    '09111111111', '123 Rizal Ave, Manila',           1.50, 1, 'out_for_delivery', 1),
('PDS-20240101-0002', 'Globe Telecom',   '02-7890-1234', 'Andres Bonifacio','09222222222','456 Bonifacio St, Quezon City',   0.80, 1, 'pending',          1),
('PDS-20240101-0003', 'SM Stores',       '02-5555-6666', 'Emilio Aguinaldo','09333333333','789 Aguinaldo Hwy, Cavite',       3.20, 2, 'delivered',        1),
('PDS-20240101-0004', 'Lazada PH',       '02-7777-8888', 'Gabriela Silang', '09444444444','321 Silang Rd, Ilocos Sur',       0.30, NULL,'pending',         1),
('PDS-20240101-0005', 'Shopee Express',  '02-9999-0000', 'Antonio Luna',    '09555555555','654 Luna St, Pampanga',           2.10, 2, 'failed',           1);

-- Status history for sample parcels
INSERT INTO `parcel_status_history` (`parcel_id`, `status`, `remarks`, `updated_by`) VALUES
(1, 'pending',          'Parcel received at sorting facility.',   1),
(1, 'out_for_delivery', 'Rider picked up the parcel.',            2),
(2, 'pending',          'Parcel received at sorting facility.',   1),
(3, 'pending',          'Parcel received.',                       1),
(3, 'out_for_delivery', 'En route to recipient.',                 3),
(3, 'delivered',        'Delivered successfully. Signed by recipient.', 3),
(4, 'pending',          'Awaiting rider assignment.',             1),
(5, 'pending',          'Parcel received.',                       1),
(5, 'out_for_delivery', 'Rider en route.',                        3),
(5, 'failed',           'Recipient not found at address.',        3);

-- Sample activity logs
INSERT INTO `activity_logs` (`user_id`, `action`, `details`, `ip_address`) VALUES
(1, 'login',          'Admin logged in.',                  '127.0.0.1'),
(1, 'parcel_created', 'Created parcel PDS-20240101-0001.', '127.0.0.1'),
(1, 'parcel_created', 'Created parcel PDS-20240101-0002.', '127.0.0.1'),
(2, 'login',          'Rider logged in.',                  '127.0.0.1'),
(2, 'status_update',  'Updated parcel #1 to out_for_delivery.', '127.0.0.1');

COMMIT;
