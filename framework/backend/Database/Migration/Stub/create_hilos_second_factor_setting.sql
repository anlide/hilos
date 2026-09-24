-- Migration: Create hilos_second_factor_setting table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_second_factor_setting.sql)
--
-- A person's own choice of how long a removal of their second factor waits (HIL-494),
-- within the bounds the administrator set. No row, or a NULL `reset_wait_days`, means
-- the administrator's default.
--
-- Lengthening applies at once; shortening does not. A shorter wait is parked in
-- `pending_reset_wait_days` and takes over at `pending_reset_wait_from`, which is the
-- moment the wait in force when it was asked for would have run out - otherwise whoever
-- grabbed a live session would shorten the wait to a day and ask for the removal.
--
-- Keyed by the person: there is one choice per account.

CREATE TABLE `hilos_second_factor_setting` (
    `user_id` INT UNSIGNED NOT NULL,
    `reset_wait_days` SMALLINT UNSIGNED DEFAULT NULL,
    `pending_reset_wait_days` SMALLINT UNSIGNED DEFAULT NULL,
    `pending_reset_wait_from` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
