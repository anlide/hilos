-- Moves the unfinished-registration memory onto the session row (HIL-612) and drops
-- the table it used to live in. Mirrors the framework stub create_hilos_session.sql.
--
-- The wait was always memory ABOUT a session, keyed by the very token that names the
-- session row, so it now travels with that row instead of standing beside it. The
-- reverse question - "which sessions are waiting on this address?" - is still asked
-- (a desktop and a phone can both sit on one code screen), so the column keeps an
-- index of its own and no uniqueness.
--
-- `pending_registration_since` carries over the wait's `created_at`. The two are not
-- the same clock: `created_at` was written once, while the new column is restamped by
-- every resend, and the sweep of abandoned registrations reads it. Carrying the
-- creation time is the truthful start for rows that already exist - it is the last
-- moment those waits are known to have been written.

ALTER TABLE `hilos_session`
    ADD COLUMN `pending_registration_identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER `expires_at`,
    ADD COLUMN `pending_registration_since` TIMESTAMP NULL DEFAULT NULL AFTER `pending_registration_identifier`,
    ADD KEY `idx_session_pending_registration` (`pending_registration_identifier`);

UPDATE `hilos_session` `s`
    JOIN `hilos_registration_wait` `w` ON `w`.`session_token` = `s`.`token`
    SET `s`.`pending_registration_identifier` = `w`.`identifier`,
        `s`.`pending_registration_since` = `w`.`created_at`;

DROP TABLE `hilos_registration_wait`;
