-- Migration Rollback: Restore admin and block flags on the user table
-- Created: 2026-06-19
-- Index: 020
-- Description:
--   - Drop the `block` flag
--   - Drop the `admin` flag

ALTER TABLE `user`
    DROP COLUMN `block`;

ALTER TABLE `user`
    DROP COLUMN `admin`;
