-- Migration: Remove unused tables and fields
-- Created: 2025-01-XX
-- Index: 007
-- Description: 
--   - Remove user_setting table (no longer used)
--   - Remove theme field from user table (no longer used)
--   - Remove message table (no longer used)
--   - Make event.timestamp NOT NULL (required field)

-- Remove user_setting table
DROP TABLE IF EXISTS `user_setting`;

-- Remove theme column from user table
ALTER TABLE `user` 
    DROP COLUMN `theme`;

-- Remove message table
DROP TABLE IF EXISTS `message`;

-- Make event.timestamp NOT NULL
-- First, update any NULL values to current timestamp
UPDATE `event` 
SET `timestamp` = CURRENT_TIMESTAMP 
WHERE `timestamp` IS NULL;

-- Then alter the column to be NOT NULL
ALTER TABLE `event` 
    MODIFY COLUMN `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

