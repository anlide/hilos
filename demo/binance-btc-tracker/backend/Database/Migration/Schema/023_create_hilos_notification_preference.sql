-- Migration: Create hilos_notification_preference table
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_notification_preference.sql.
-- A person's opt-out of a delivery channel (HIL-1224), written from /profile/notifications;
-- no row means the channel is allowed.

CREATE TABLE `hilos_notification_preference` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `channel` VARCHAR(50) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_preference_user_channel` (`user_id`, `channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
