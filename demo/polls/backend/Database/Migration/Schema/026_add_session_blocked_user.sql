-- Adds the blocked account a browser lost or was refused (HIL-289).
-- Mirrors the framework stub create_hilos_session.sql.
--
-- The "Access closed" card is served from the column on every handshake. Its index is
-- the reverse lookup an unblock makes; the index on `impersonator_user_id` finds the
-- impersonations a blocked administrator loses without scanning the session table.
-- No backfill: no browser has met a block before this column exists.

ALTER TABLE `hilos_session`
    ADD COLUMN `blocked_user_id` INT UNSIGNED DEFAULT NULL AFTER `device_name`,
    ADD KEY `idx_session_blocked_user` (`blocked_user_id`),
    ADD KEY `idx_session_impersonator` (`impersonator_user_id`);
