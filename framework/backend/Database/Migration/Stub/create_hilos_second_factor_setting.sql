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
-- Wrong authenticator-app codes are counted on the same row (HIL-1285), wherever
-- the code is entered: `app_code_misses` in a window of one day that the first
-- miss opens at `app_code_misses_from`. The ceiling locks app codes until
-- `app_code_locked_until`; `app_code_lock_step` is the step of that lock on the
-- ladder. An expired lock keeps its end, so the next lock knows whether a day has
-- passed since it and the ladder starts again.
--
-- Keyed by the person: there is one choice per account.
--
-- `pending_reset_wait_from`, `app_code_misses_from`, `app_code_locked_until` and `updated_at` are
-- signed BIGINT epoch milliseconds (HIL-1922), with no time zone and no database writer: the
-- application stamps each one. NULL is the absence of that moment, and 0 is the epoch rather
-- than absence. `app_code_lock_step` stays a step of the ladder, not a moment.

CREATE TABLE `hilos_second_factor_setting` (
    `user_id` INT UNSIGNED NOT NULL,
    `reset_wait_days` SMALLINT UNSIGNED DEFAULT NULL,
    `pending_reset_wait_days` SMALLINT UNSIGNED DEFAULT NULL,
    `pending_reset_wait_from` BIGINT NULL DEFAULT NULL,
    `app_code_misses` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `app_code_misses_from` BIGINT NULL DEFAULT NULL,
    `app_code_lock_step` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `app_code_locked_until` BIGINT NULL DEFAULT NULL,
    `updated_at` BIGINT NOT NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_second_factor_setting_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
