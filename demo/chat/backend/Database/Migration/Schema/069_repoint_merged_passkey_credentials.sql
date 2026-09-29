-- Migration: Hand an earlier merge's device keys to the survivor (HIL-1132)
-- Created: 2026-09-29
-- Index: 069
--
-- Until HIL-1132 an account merge moved the sign-in anchor and left the device
-- key recorded for the loser. The anchor is the truth: the foreign key, the
-- profile and the set tree all hang on it. This repair writes the anchor's
-- person onto every key whose user_id disagrees, and a second run changes
-- nothing. The rewrite is irreversible: the loser ids the keys carried are
-- not stored anywhere else.

UPDATE `hilos_passkey_credential` `c`
    JOIN `hilos_identity` `i` ON `i`.`id` = `c`.`identity_id`
    SET `c`.`user_id` = `i`.`user_id`
    WHERE `c`.`user_id` <> `i`.`user_id`;
