-- Migration: Create guest and empty the user table of visitors
-- Created: 2026-08-20
-- Index: 009
-- Description:
--   The visitor stops being a user (HIL-610). Until now the handshake minted a
--   `user` row for every new cookie, which is why that table filled up with
--   rows that were really sessions. From here a `user` row means an account,
--   and the only thing this demo still needs to remember about a visitor — the
--   display name it was given — lives in its own table, keyed by the session
--   token that earned it.
--
--   The token column is copied from `hilos_session`.`token` (006) down to its
--   binary collation: it holds the same value and has to compare the same way.
--
--   The carry-over is written in one direction only. Every session standing
--   behind a non-admin user hands that user's name to a guest row of its own,
--   then lets go of the user, and the visitor rows are deleted — their renames
--   first, because `fk_user_rename_target_user` (003) would otherwise hold them.
--   Sessions behind an administrator are untouched: an account is exactly what
--   survives this migration.

CREATE TABLE `guest` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_guest_session` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `guest` (`session_token`, `name`, `created_at`)
SELECT `s`.`token`, `u`.`name`, `s`.`created_at`
FROM `hilos_session` `s`
    JOIN `user` `u` ON `u`.`id` = `s`.`user_id`
WHERE `u`.`admin` = 0;

UPDATE `hilos_session` `s`
    JOIN `user` `u` ON `u`.`id` = `s`.`user_id`
    SET `s`.`user_id` = NULL
WHERE `u`.`admin` = 0;

DELETE `r` FROM `user_rename` `r`
    JOIN `user` `u` ON `u`.`id` = `r`.`target_user_id`
WHERE `u`.`admin` = 0;

DELETE FROM `user` WHERE `admin` = 0;
