-- Migration: Create hilos_access_log table (stub/reference)
-- Copy this SQL to the next free project schema migration.
--
-- The account access log (HIL-1174): when an account was used and from which network
-- address, one row per use. The sessions library writes it at two moments only - a
-- session gets a person, by any road of signing in ('sign_in', with the session's address
-- at that moment), and a signed-in session connects from an address it did not have yet
-- ('new_address'). A session that sits on one address for weeks gives one row; an
-- impersonation, a refused sign-in and an anonymous session give none.
--
-- `ip_address` is text as the transport gave it, VARCHAR(45) so an IPv6 address fits;
-- NULL means the transport gave no address. Rows live 12 months: the sessions library
-- sweeps older ones every hour.
--
-- The project's privacy text is the switch: a current revision deviating from
-- standard.access_log keeps no log, and the same sweep removes every row already
-- written. The table is created all the same - it is empty by the text, not by absence.
--
-- No foreign key to the person, as on the framework's other tables about a person.
-- A restore that requires anonymization purges the table whole.

CREATE TABLE `hilos_access_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `event` ENUM('sign_in','new_address') NOT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `occurred_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_access_log_user` (`user_id`, `occurred_at`),
    KEY `idx_access_log_occurred` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
