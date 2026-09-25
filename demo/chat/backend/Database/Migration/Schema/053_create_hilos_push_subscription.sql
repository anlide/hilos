-- Activates the framework push-subscription table for chat (HIL-288).
-- Mirrors framework/backend/Database/Migration/Stub/create_hilos_push_subscription.sql.

CREATE TABLE `hilos_push_subscription` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `endpoint` VARCHAR(512) NOT NULL,
    `p256dh` VARCHAR(255) NOT NULL,
    `auth` VARCHAR(255) NOT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `device_name` VARCHAR(64) DEFAULT NULL,
    `endpoint_hash` CHAR(64) NOT NULL,
    `gone_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_push_subscription_endpoint` (`endpoint`),
    KEY `idx_push_subscription_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
