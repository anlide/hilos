-- Migration: Rename bot and moderator primary key columns to id, remove created_at/updated_at
-- Created: 2025-02-18
-- Index: 010
-- Description:
--   - Primary key columns id_bot -> id, id_moderator -> id
--   - Remove created_at, updated_at from bot and moderator

ALTER TABLE `bot`
    CHANGE COLUMN `id_bot` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    DROP COLUMN `created_at`,
    DROP COLUMN `updated_at`;

ALTER TABLE `moderator`
    CHANGE COLUMN `id_moderator` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    DROP COLUMN `created_at`,
    DROP COLUMN `updated_at`;
