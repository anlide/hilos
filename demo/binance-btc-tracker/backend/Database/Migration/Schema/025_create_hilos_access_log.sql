-- Creates the account access log (HIL-1174).
-- Mirrors the framework stub create_hilos_access_log.sql.
--
-- When an account was used and from which network address: a row on every sign-in and
-- on every connection of a signed-in session from an address it did not have yet. Rows
-- live 12 months; the sessions library sweeps older ones every hour. A privacy text
-- deviating from standard.access_log keeps no log, and the table then stays empty.

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
