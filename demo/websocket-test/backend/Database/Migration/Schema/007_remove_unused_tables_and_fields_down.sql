-- Migration Rollback: Remove unused tables and fields
-- Created: 2025-01-XX
-- Index: 007
-- Description: 
--   - Restore user_setting table
--   - Restore theme field in user table
--   - Restore message table
--   - Make event.timestamp nullable again

-- Restore theme column in user table (VARCHAR(50) DEFAULT NULL)
ALTER TABLE `user` 
    ADD COLUMN `theme` VARCHAR(50) DEFAULT NULL AFTER `name`;

-- Restore message table
CREATE TABLE `message` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `timestamp` (`timestamp`),
    CONSTRAINT `fk_message_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Restore user_setting table
CREATE TABLE `user_setting` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `user_id_key` (`user_id`, `key`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `fk_user_setting_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Make event.timestamp nullable again
ALTER TABLE `event` 
    MODIFY COLUMN `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP;

