-- Migration: Create the framework hilos_push_subscription table (HIL-1296).
-- Match the create_hilos_push_subscription.sql stub.
-- Index: 036
--
-- The push subscription table is required by the framework notifications feature: subscribing,
-- unsubscribing and removing a device are actions of its library in every project that declares
-- notifications, whether it delivers push or not. This demo does not deliver push,
-- so the table stays empty.

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
    KEY `idx_push_subscription_user` (`user_id`),
    CONSTRAINT `fk_push_subscription_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
