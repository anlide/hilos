-- Reverts 046: gives the hold its credential column back and drops the proof mark.
-- A registration standing on the password screen when the rollback runs loses its proof
-- and falls back to the code screen, which is the same place an expired hold sends it.

ALTER TABLE `hilos_registration_reservation`
    DROP COLUMN `code_accepted_at`,
    ADD COLUMN `secret` VARCHAR(255) DEFAULT NULL AFTER `session_token`;
