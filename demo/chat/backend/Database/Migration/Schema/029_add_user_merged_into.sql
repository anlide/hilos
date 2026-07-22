-- Migration: Add the account-merge tombstone marker to the user table
-- Created: 2026-07-22
-- Index: 029
-- Description:
--   Account merge (HIL-378): when two populated accounts are merged the loser
--   row is tombstoned rather than hard-deleted. `merged_into` records the
--   survivor user id (NULL for a normal, un-merged account); the existing
--   `block` flag closes the loser's login. No DB-level foreign key (mirrors the
--   `user_id` convention on `session`); user→user integrity stays in
--   application code. Indexed to find rows merged into a given survivor.

ALTER TABLE `user`
    ADD COLUMN `merged_into` INT UNSIGNED DEFAULT NULL AFTER `block`,
    ADD KEY `merged_into` (`merged_into`);
