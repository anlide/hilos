-- Migration: Drop the legacy user.session_token column
-- Created: 2026-07-19
-- Index: 024
-- Description:
--   Remove the fused auto-guest session token from `user`. The session token now
--   lives on the `session` table (021_create_session), which separates the
--   transient cookie session from the durable account, so an anonymous visitor
--   no longer materializes a user row. Drops the UNIQUE index first, then the
--   column. Down re-adds the column and its unique index (see 005_add_session_fields).

ALTER TABLE `user`
    DROP INDEX `session_token`,
    DROP COLUMN `session_token`;
