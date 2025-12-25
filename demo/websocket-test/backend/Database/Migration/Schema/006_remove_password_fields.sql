-- Migration: Remove password fields and unused columns from user table
-- Created: 2025-01-XX
-- Index: 006
-- Description: 
--   - Remove password_hash field (no longer used, authentication via session_token)
--   - Remove salt field (no longer used, authentication via session_token)
--   - Remove will_delete field (no longer used)
--   - Remove block field (no longer used)
--   - Remove admin field (no longer used)

-- Remove password_hash column
ALTER TABLE `user` 
    DROP COLUMN `password_hash`;

-- Remove salt column
ALTER TABLE `user` 
    DROP COLUMN `salt`;

-- Remove will_delete column
ALTER TABLE `user` 
    DROP COLUMN `will_delete`;

-- Remove block column
ALTER TABLE `user` 
    DROP COLUMN `block`;

-- Remove admin column
ALTER TABLE `user` 
    DROP COLUMN `admin`;

