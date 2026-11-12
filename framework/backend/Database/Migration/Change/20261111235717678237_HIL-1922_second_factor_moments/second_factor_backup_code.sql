ALTER TABLE `hilos_second_factor_backup_code`
    ADD COLUMN `__hil1922_used_at` BIGINT NULL AFTER `used_at`,
    ADD COLUMN `__hil1922_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_second_factor_backup_code` SET
    `__hil1922_used_at` = UNIX_TIMESTAMP(`used_at`) * 1000,
    `__hil1922_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_second_factor_backup_code`
    DROP COLUMN `used_at`, ADD COLUMN `used_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_used_at`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1922_created_at`;
UPDATE `hilos_second_factor_backup_code` SET
    `used_at` = `__hil1922_used_at`,
    `created_at` = `__hil1922_created_at`;
ALTER TABLE `hilos_second_factor_backup_code`
    DROP COLUMN `__hil1922_used_at`,
    DROP COLUMN `__hil1922_created_at`;
