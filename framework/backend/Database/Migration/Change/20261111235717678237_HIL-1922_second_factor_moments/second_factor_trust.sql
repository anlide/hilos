ALTER TABLE `hilos_second_factor_trust`
    ADD COLUMN `__hil1922_trusted_until` BIGINT NULL AFTER `trusted_until`,
    ADD COLUMN `__hil1922_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_second_factor_trust` SET
    `__hil1922_trusted_until` = UNIX_TIMESTAMP(`trusted_until`) * 1000,
    `__hil1922_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_second_factor_trust`
    DROP COLUMN `trusted_until`, ADD COLUMN `trusted_until` BIGINT NOT NULL AFTER `__hil1922_trusted_until`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1922_created_at`;
UPDATE `hilos_second_factor_trust` SET
    `trusted_until` = `__hil1922_trusted_until`,
    `created_at` = `__hil1922_created_at`;
ALTER TABLE `hilos_second_factor_trust`
    DROP COLUMN `__hil1922_trusted_until`,
    DROP COLUMN `__hil1922_created_at`;
