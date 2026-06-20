-- Migration: Create event_user_rename table
-- Created: 2026-06-20
-- Index: 003
-- Description:
--   Minimal standalone audit of admin user renames: one row per rename with the
--   target user, the before/after display names, and when it happened. No chat
--   style event aggregator parent — this table carries its own id and timestamp.

CREATE TABLE `event_user_rename` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_user_id` INT UNSIGNED NOT NULL,
    `old_name` VARCHAR(64) NOT NULL,
    `new_name` VARCHAR(64) NOT NULL,
    `timestamp` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `target_user_id` (`target_user_id`),
    KEY `timestamp` (`timestamp`),
    CONSTRAINT `fk_event_user_rename_target_user`
        FOREIGN KEY (`target_user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
