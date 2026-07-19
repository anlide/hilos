-- Migration: Add the impersonator marker to the session table
-- Created: 2026-07-19
-- Index: 023
-- Description:
--   Admin impersonation (HIL-166): a session persistently remembers the real
--   admin behind an active impersonation via `impersonator_user_id`. NULL means
--   the session acts as its own bound user; a set value means the current
--   `user_id` is a target the admin is acting as, and the marker holds the admin
--   to restore on stop. No DB-level foreign key (mirrors the `user_id`
--   convention); session→user integrity stays in application code.

ALTER TABLE `session`
    ADD COLUMN `impersonator_user_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`;
