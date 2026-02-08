-- Migration: Allow NULL user_id and add data field to event table
-- Created: 2026-01-18
-- Index: 008
-- Description: Allow user_id to be NULL for system events and add TEXT data field for event-specific data (JSON as string)

-- Modify column to allow NULL (MODIFY is simpler than CHANGE when only changing nullability)
ALTER TABLE `event` 
    MODIFY COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL;

-- Add data column to store event-specific data
ALTER TABLE `event` 
    ADD COLUMN `data` TEXT NULL DEFAULT NULL AFTER `timestamp`;

