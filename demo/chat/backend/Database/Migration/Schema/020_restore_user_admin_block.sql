-- Migration: Restore admin and block flags on the user table
-- Created: 2026-06-19
-- Index: 020
-- Description:
--   - Restore the `admin` operator flag (dropped in 006 as unused)
--   - Restore the `block` flag (dropped in 006 as unused)
--   Both back the Hilos users admin table (backend graduation step 2).

ALTER TABLE `user`
    ADD COLUMN `admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `name`,
    ADD KEY `admin` (`admin`);

ALTER TABLE `user`
    ADD COLUMN `block` TINYINT(1) NOT NULL DEFAULT 0 AFTER `admin`,
    ADD KEY `block` (`block`);
