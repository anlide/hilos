-- Activates the framework hilos_notification_preference table (HIL-513) for demo/simple-todo.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_notification_preference.sql;
-- the per-user layer of the NOTIFICATIONS feature this demo declares. Sparse opt-out: a row
-- exists only to record a mute, so an absent row means the channel is allowed.
--
-- Nothing in this demo writes a mute today - it delivers over no channel of its own - but the
-- feature owns both tables together, and the framework reads a recipient's preferences without
-- first asking whether the project migrated them. A demo that declared NOTIFICATIONS and skipped
-- this table would be the half-activated state the feature registry exists to reject.

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
