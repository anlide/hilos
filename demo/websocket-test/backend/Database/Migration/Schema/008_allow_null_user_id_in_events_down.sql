-- Migration Rollback: Allow NULL user_id and add data field to event table
-- Created: 2026-01-18
-- Index: 008

-- Step 1: Remove data column
ALTER TABLE `event` 
    DROP COLUMN `data`;

-- Step 2: Update any NULL values to a default user (e.g., 1) before making NOT NULL
-- WARNING: This will set user_id = 1 for all events that currently have NULL
-- You may want to adjust this based on your data
UPDATE `event` SET `user_id` = 1 WHERE `user_id` IS NULL;

-- Step 3: Modify column back to NOT NULL
ALTER TABLE `event` 
    MODIFY COLUMN `user_id` INT UNSIGNED NOT NULL;
