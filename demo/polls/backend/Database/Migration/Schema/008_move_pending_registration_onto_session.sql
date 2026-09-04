-- Migration: Move the pending registration onto hilos_session
-- Created: 2026-08-20
-- Index: 008
-- Description:
--   The other half of the session activation this demo took on in HIL-407. The
--   unfinished-registration memory the framework reads on every handshake stops
--   being a table of its own (HIL-612) and becomes two columns of the session row
--   it always described — so a demo with no registration flow at all now carries
--   an empty column instead of an empty table.
--
--   The column definitions mirror the framework stub
--   framework/backend/Database/Migration/Stub/create_hilos_session.sql, which now
--   ships them. The carry-over below is written even though this demo's
--   `hilos_registration_wait` is certainly empty: a migration states what it does to
--   any database it is run against, not what we happen to know about this one.

ALTER TABLE `hilos_session`
    ADD COLUMN `pending_registration_identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER `expires_at`,
    ADD COLUMN `pending_registration_since` TIMESTAMP NULL DEFAULT NULL AFTER `pending_registration_identifier`,
    ADD KEY `idx_session_pending_registration` (`pending_registration_identifier`);

UPDATE `hilos_session` `s`
    JOIN `hilos_registration_wait` `w` ON `w`.`session_token` = `s`.`token`
    SET `s`.`pending_registration_identifier` = `w`.`identifier`,
        `s`.`pending_registration_since` = `w`.`created_at`;

DROP TABLE `hilos_registration_wait`;
