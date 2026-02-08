-- Migration Rollback: Rename id columns to standard naming
-- Created: 2025-01-XX
-- Index: 004
-- Description: 
--   - Primary key columns: id -> id_*
--   - Foreign key columns: entity_id -> id_entity

-- IMPORTANT: Foreign keys must be renamed first, then primary keys
-- because foreign keys reference the primary key column name

-- Rename foreign key user_id to id_user in user_setting table
-- (references user.id which will be renamed to user.id_user later)
-- Step 1: Drop foreign key constraint
ALTER TABLE `user_setting` 
    DROP FOREIGN KEY `fk_user_setting_user`;

-- Step 2: Drop old indexes
ALTER TABLE `user_setting` 
    DROP KEY `user_id`,
    DROP KEY `user_id_key`;

-- Step 3: Rename column
ALTER TABLE `user_setting` 
    CHANGE COLUMN `user_id` `id_user` INT UNSIGNED NOT NULL;

-- Step 4: Add new indexes
ALTER TABLE `user_setting` 
    ADD KEY `id_user` (`id_user`),
    ADD UNIQUE KEY `id_user_key` (`id_user`, `key`);

-- Rename foreign key user_id to id_user in event table
-- Step 1: Drop foreign key constraint
ALTER TABLE `event` 
    DROP FOREIGN KEY `fk_event_user`;

-- Step 2: Drop old index
ALTER TABLE `event` 
    DROP KEY `user_id`;

-- Step 3: Rename column
ALTER TABLE `event` 
    CHANGE COLUMN `user_id` `id_user` INT UNSIGNED NOT NULL;

-- Step 4: Add new index
ALTER TABLE `event` 
    ADD KEY `id_user` (`id_user`);

-- Rename foreign key user_id to id_user in message table
-- Step 1: Drop foreign key constraint
ALTER TABLE `message` 
    DROP FOREIGN KEY `fk_message_user`;

-- Step 2: Drop old index
ALTER TABLE `message` 
    DROP KEY `user_id`;

-- Step 3: Rename column
ALTER TABLE `message` 
    CHANGE COLUMN `user_id` `id_user` INT UNSIGNED NOT NULL;

-- Step 4: Add new index
ALTER TABLE `message` 
    ADD KEY `id_user` (`id_user`);

-- Rename primary key in user_setting table
ALTER TABLE `user_setting` 
    CHANGE COLUMN `id` `id_user_setting` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename primary key in event table
ALTER TABLE `event` 
    CHANGE COLUMN `id` `id_event` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename primary key in message table
ALTER TABLE `message` 
    CHANGE COLUMN `id` `id_message` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename primary key in user table
ALTER TABLE `user` 
    CHANGE COLUMN `id` `id_user` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Re-add foreign key constraints after primary key is renamed
ALTER TABLE `user_setting` 
    ADD CONSTRAINT `fk_user_setting_user` FOREIGN KEY (`id_user`) REFERENCES `user` (`id_user`) ON DELETE CASCADE;

ALTER TABLE `event` 
    ADD CONSTRAINT `fk_event_user` FOREIGN KEY (`id_user`) REFERENCES `user` (`id_user`) ON DELETE CASCADE;

ALTER TABLE `message` 
    ADD CONSTRAINT `fk_message_user` FOREIGN KEY (`id_user`) REFERENCES `user` (`id_user`) ON DELETE CASCADE;
