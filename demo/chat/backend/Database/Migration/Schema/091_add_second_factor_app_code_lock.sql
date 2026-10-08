-- Migration: Add the second-factor app-code lock (HIL-1285)
-- Created: 2026-10-08
-- Index: 091
--
-- Wrong authenticator-app codes are counted per person, wherever the code is
-- entered: the sign-in step, the profile and the confirmation of an operation.
-- `app_code_misses` counts them in a window of one day that the first miss
-- opens at `app_code_misses_from`. The ceiling locks app codes until
-- `app_code_locked_until`; `app_code_lock_step` is the step of that lock on
-- the ladder. An expired lock keeps its end, so the next lock knows whether a
-- day has passed since it and the ladder starts again.

ALTER TABLE `hilos_second_factor_setting`
    ADD COLUMN `app_code_misses` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pending_reset_wait_from`,
    ADD COLUMN `app_code_misses_from` TIMESTAMP NULL DEFAULT NULL AFTER `app_code_misses`,
    ADD COLUMN `app_code_lock_step` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `app_code_misses_from`,
    ADD COLUMN `app_code_locked_until` TIMESTAMP NULL DEFAULT NULL AFTER `app_code_lock_step`;
