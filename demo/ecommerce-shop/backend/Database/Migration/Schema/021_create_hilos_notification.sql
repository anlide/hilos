-- Migration: Create hilos_notification table
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_notification.sql.
-- Backs the shell bell (HIL-1225): the center, the unread badge and mark-read, with no delivery.

CREATE TABLE `hilos_notification` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(100) NOT NULL,
    `severity` ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info',
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT DEFAULT NULL,
    `data` JSON DEFAULT NULL,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notification_user_read` (`user_id`, `read_at`),
    KEY `idx_notification_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
