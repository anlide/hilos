-- Migration Rollback: Rename bot and moderator primary key columns, restore created_at/updated_at
-- Created: 2025-02-18
-- Index: 010
-- Description:
--   - Primary key columns id -> id_bot, id -> id_moderator
--   - Restore created_at, updated_at in bot and moderator

ALTER TABLE `bot`
    CHANGE COLUMN `id` `id_bot` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `active`,
    ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

ALTER TABLE `moderator`
    CHANGE COLUMN `id` `id_moderator` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `active`,
    ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
