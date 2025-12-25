-- Migration Rollback: Remove password fields and unused columns from user table
-- Created: 2025-01-XX
-- Index: 006
-- Description: 
--   - Restore password_hash field
--   - Restore salt field
--   - Restore will_delete field
--   - Restore block field

-- Restore password_hash column (VARCHAR(64) NOT NULL)
ALTER TABLE `user` 
    ADD COLUMN `password_hash` VARCHAR(64) NOT NULL AFTER `id_user`;

-- Restore salt column (VARCHAR(32) NOT NULL)
ALTER TABLE `user` 
    ADD COLUMN `salt` VARCHAR(32) NOT NULL AFTER `password_hash`;

-- Restore will_delete column (INT UNSIGNED DEFAULT NULL)
ALTER TABLE `user` 
    ADD COLUMN `will_delete` INT UNSIGNED DEFAULT NULL AFTER `theme`;

-- Restore admin column (TINYINT(1) NOT NULL DEFAULT 0)
ALTER TABLE `user` 
    ADD COLUMN `admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `theme`,
    ADD KEY `admin` (`admin`);

-- Restore block column (TINYINT(1) NOT NULL DEFAULT 0)
ALTER TABLE `user` 
    ADD COLUMN `block` TINYINT(1) NOT NULL DEFAULT 0 AFTER `admin`,
    ADD KEY `block` (`block`);

