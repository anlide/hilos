ALTER TABLE `hilos_second_factor`
    ADD COLUMN `__hil1922_confirmed_at` BIGINT NULL AFTER `confirmed_at`,
    ADD COLUMN `__hil1922_last_used_at` BIGINT NULL AFTER `last_used_at`,
    ADD COLUMN `__hil1922_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_second_factor` SET
    `__hil1922_confirmed_at` = UNIX_TIMESTAMP(`confirmed_at`) * 1000,
    `__hil1922_last_used_at` = UNIX_TIMESTAMP(`last_used_at`) * 1000,
    `__hil1922_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_second_factor`
    DROP COLUMN `confirmed_at`, ADD COLUMN `confirmed_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_confirmed_at`,
    DROP COLUMN `last_used_at`, ADD COLUMN `last_used_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_last_used_at`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1922_created_at`;
UPDATE `hilos_second_factor` SET
    `confirmed_at` = `__hil1922_confirmed_at`,
    `last_used_at` = `__hil1922_last_used_at`,
    `created_at` = `__hil1922_created_at`;
ALTER TABLE `hilos_second_factor`
    DROP COLUMN `__hil1922_confirmed_at`,
    DROP COLUMN `__hil1922_last_used_at`,
    DROP COLUMN `__hil1922_created_at`;
