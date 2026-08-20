-- Reverts 041: puts the reservation key back on the address.
--
-- The table is emptied again, for the mirror reason: with UNIQUE(identifier) restored,
-- the rows this key allows - several browsers holding one address - cannot all survive,
-- and a hold is worth minutes, not a merge rule.

DELETE FROM `hilos_registration_reservation`;

ALTER TABLE `hilos_registration_reservation`
    DROP INDEX `idx_reservation_identifier`,
    DROP INDEX `uk_reservation_session`,
    ADD UNIQUE KEY `uk_reservation_identifier` (`identifier`),
    DROP COLUMN `session_token`;
