-- Migration: Rename id columns to standard naming
-- Created: 2025-01-XX
-- Index: 004
-- Description: 
--   - Primary key columns: id_* -> id
--   - Foreign key columns: id_entity -> entity_id

-- Rename primary key in user table
ALTER TABLE `user` 
    CHANGE COLUMN `id_user` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename primary key in message table
ALTER TABLE `message` 
    CHANGE COLUMN `id_message` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename primary key in event table
ALTER TABLE `event` 
    CHANGE COLUMN `id_event` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename primary key in user_setting table
ALTER TABLE `user_setting` 
    CHANGE COLUMN `id_user_setting` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Rename foreign key id_user to user_id in message table
-- Step 1: Drop foreign key constraint
ALTER TABLE `message` 
    DROP FOREIGN KEY `fk_message_user`;

-- Step 2: Drop old index (if exists separately)
ALTER TABLE `message` 
    DROP KEY `id_user`;

-- Step 3: Rename column
ALTER TABLE `message` 
    CHANGE COLUMN `id_user` `user_id` INT UNSIGNED NOT NULL;

-- Step 4: Add new index
ALTER TABLE `message` 
    ADD KEY `user_id` (`user_id`);

-- Step 5: Add new foreign key constraint
ALTER TABLE `message` 
    ADD CONSTRAINT `fk_message_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;

-- Rename foreign key id_user to user_id in event table
-- Step 1: Drop foreign key constraint
ALTER TABLE `event` 
    DROP FOREIGN KEY `fk_event_user`;

-- Step 2: Drop old index (if exists separately)
ALTER TABLE `event` 
    DROP KEY `id_user`;

-- Step 3: Rename column
ALTER TABLE `event` 
    CHANGE COLUMN `id_user` `user_id` INT UNSIGNED NOT NULL;

-- Step 4: Add new index
ALTER TABLE `event` 
    ADD KEY `user_id` (`user_id`);

-- Step 5: Add new foreign key constraint
ALTER TABLE `event` 
    ADD CONSTRAINT `fk_event_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;

-- Rename foreign key id_user to user_id in user_setting table
-- Step 1: Drop foreign key constraint
ALTER TABLE `user_setting` 
    DROP FOREIGN KEY `fk_user_setting_user`;

-- Step 2: Drop old indexes
ALTER TABLE `user_setting` 
    DROP KEY `id_user`,
    DROP KEY `id_user_key`;

-- Step 3: Rename column
ALTER TABLE `user_setting` 
    CHANGE COLUMN `id_user` `user_id` INT UNSIGNED NOT NULL;

-- Step 4: Add new indexes
ALTER TABLE `user_setting` 
    ADD KEY `user_id` (`user_id`),
    ADD UNIQUE KEY `user_id_key` (`user_id`, `key`);

-- Step 5: Add new foreign key constraint
ALTER TABLE `user_setting` 
    ADD CONSTRAINT `fk_user_setting_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;
