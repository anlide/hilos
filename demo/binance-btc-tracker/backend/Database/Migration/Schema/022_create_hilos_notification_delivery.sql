-- Migration: Create hilos_notification_delivery table
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_notification_delivery.sql.
-- One row per channel delivery of a notification (HIL-1224): the email and SMS channels
-- move it from pending to sent or failed, and the channel's delivery journal reads it.

CREATE TABLE `hilos_notification_delivery` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `notification_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(50) NOT NULL,
    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `delivered_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_notification_delivery_notification` (`notification_id`),
    KEY `idx_notification_delivery_channel_status` (`channel`, `status`),
    KEY `idx_notification_delivery_created` (`created_at`),
    KEY `idx_notification_delivery_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
