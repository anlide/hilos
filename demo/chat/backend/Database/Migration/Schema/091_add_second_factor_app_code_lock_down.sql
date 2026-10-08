-- Migration Rollback: Remove the second-factor app-code lock (HIL-1285)
-- Index: 091

ALTER TABLE `hilos_second_factor_setting`
    DROP COLUMN `app_code_locked_until`,
    DROP COLUMN `app_code_lock_step`,
    DROP COLUMN `app_code_misses_from`,
    DROP COLUMN `app_code_misses`;
