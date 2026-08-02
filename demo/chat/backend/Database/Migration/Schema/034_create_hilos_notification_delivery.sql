-- Activates the framework hilos_notification_delivery table (HIL-505) for demo/chat.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_notification_delivery.sql;
-- one row per resolved channel of an emitted notification, read by the admin
-- delivery-logs page and the hilosNotificationDeliveries RT table.

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
