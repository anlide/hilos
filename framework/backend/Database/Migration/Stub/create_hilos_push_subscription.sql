-- Migration: Create hilos_push_subscription table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_push_subscription.sql)
--
-- Browser push subscriptions per user device (HIL-199). One row is one device's
-- opt-in: the profile section registers the service worker, subscribes through the
-- browser push manager, and sends the endpoint plus its `p256dh` / `auth` keys; the
-- row is the address a push delivery is sent to.
--
-- `endpoint` is the device identity and is UNIQUE: a re-subscribe (rotated keys, or
-- the same endpoint arriving under a new owner) upserts the one row rather than
-- inserting a duplicate. A stale endpoint the push transport reports gone (404/410)
-- is deleted.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. `user_id` is a soft ref, indexed so a
-- recipient's subscriptions resolve for delivery; rows are cleaned up best-effort
-- with the user (soft ref, no cascade). `user_agent` and `last_seen_at` are
-- informational (which device, last refreshed).

CREATE TABLE `hilos_push_subscription` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `endpoint` VARCHAR(512) NOT NULL,
    `p256dh` VARCHAR(255) NOT NULL,
    `auth` VARCHAR(255) NOT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_push_subscription_endpoint` (`endpoint`),
    KEY `idx_push_subscription_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
