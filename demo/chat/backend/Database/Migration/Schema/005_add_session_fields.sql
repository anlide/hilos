-- Migration: Add session fields to user table
-- Created: 2025-01-XX
-- Index: 005
-- Description: 
--   - Add session_token field for user session management (32 hex chars = 64 bytes)
--   - Add last_activity field to track user activity for auto-deletion
--   - Add indexes for efficient lookups

-- Add session_token column (VARCHAR(64) for hex string of 32 bytes)
ALTER TABLE `user` 
    ADD COLUMN `session_token` VARCHAR(64) DEFAULT NULL AFTER `will_delete`,
    ADD UNIQUE KEY `session_token` (`session_token`);

-- Add last_activity column (TIMESTAMP for tracking activity)
ALTER TABLE `user` 
    ADD COLUMN `last_activity` TIMESTAMP NULL DEFAULT NULL AFTER `session_token`,
    ADD KEY `last_activity` (`last_activity`);
