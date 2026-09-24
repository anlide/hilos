-- Migration: Create hilos_second_factor_backup_code table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_second_factor_backup_code.sql)
--
-- One-shot backup codes of a person's second factor (HIL-494). A set is issued when the
-- first authenticator app is enrolled and whenever the person asks for new ones; the
-- new set replaces the old one whole.
--
-- `code` is stored normalized (no hyphen, lower case) and NOT hashed. The authenticator
-- secret beside it has to stay readable for the codes to be computed at all, so a dump
-- of the database opens the account with or without the backup codes; a hash here would
-- cost the "Show" screen and buy nothing. It is DB-only all the same - absent from the
-- ORM columns, so it never rides a cross-worker sync frame - and written by a targeted
-- UPDATE right after the row is inserted, which is why it is NULL-able; a row without a
-- code matches nothing.
--
-- `used_at` burns a code. The write that burns it carries `used_at IS NULL`, so the
-- same code typed in two tabs at once passes exactly once.

CREATE TABLE `hilos_second_factor_backup_code` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_backup_code_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
