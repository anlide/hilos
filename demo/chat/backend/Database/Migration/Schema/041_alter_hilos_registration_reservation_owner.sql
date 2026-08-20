-- Re-keys hilos_registration_reservation from the address to the browser (HIL-608):
-- a hold belongs to the session that started it, so only that session can land it.
-- Mirrors the framework stub create_hilos_registration_reservation.sql.
--
-- The table is emptied first. A hold lives minutes, so there is almost never a live
-- one at migration time, and the alternative would be filling a NOT NULL column with
-- an empty token - a hold with no owner, which is exactly what this migration removes.
--
-- UNIQUE moves to `session_token` (one browser leads one registration) and `identifier`
-- keeps a plain index (several browsers may be registering one address; the first to
-- prove it wins the account and the rest are told it is taken). `idx_reservation_expires`
-- is untouched - the sweep still asks the same question.

DELETE FROM `hilos_registration_reservation`;

ALTER TABLE `hilos_registration_reservation`
    ADD COLUMN `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL AFTER `identifier`,
    DROP INDEX `uk_reservation_identifier`,
    ADD UNIQUE KEY `uk_reservation_session` (`session_token`),
    ADD KEY `idx_reservation_identifier` (`identifier`);
