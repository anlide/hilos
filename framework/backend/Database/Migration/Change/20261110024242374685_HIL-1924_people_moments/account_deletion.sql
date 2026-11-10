ALTER TABLE `hilos_account_deletion`
    ADD COLUMN `__hil1924_requested_at` BIGINT NULL AFTER `requested_at`,
    ADD COLUMN `__hil1924_effective_at` BIGINT NULL AFTER `effective_at`,
    ADD COLUMN `__hil1924_canceled_at` BIGINT NULL AFTER `canceled_at`,
    ADD COLUMN `__hil1924_completed_at` BIGINT NULL AFTER `completed_at`;
UPDATE `hilos_account_deletion` SET
    `__hil1924_requested_at` = UNIX_TIMESTAMP(`requested_at`) * 1000,
    `__hil1924_effective_at` = UNIX_TIMESTAMP(`effective_at`) * 1000,
    `__hil1924_canceled_at` = UNIX_TIMESTAMP(`canceled_at`) * 1000,
    `__hil1924_completed_at` = UNIX_TIMESTAMP(`completed_at`) * 1000;
ALTER TABLE `hilos_account_deletion`
    DROP INDEX `idx_account_deletion_effective`,
    DROP COLUMN `requested_at`,
    CHANGE COLUMN `__hil1924_requested_at` `requested_at` BIGINT NOT NULL,
    DROP COLUMN `effective_at`,
    CHANGE COLUMN `__hil1924_effective_at` `effective_at` BIGINT NOT NULL,
    DROP COLUMN `canceled_at`,
    CHANGE COLUMN `__hil1924_canceled_at` `canceled_at` BIGINT NULL DEFAULT NULL,
    DROP COLUMN `completed_at`,
    CHANGE COLUMN `__hil1924_completed_at` `completed_at` BIGINT NULL DEFAULT NULL,
    ADD KEY `idx_account_deletion_effective` (`effective_at`);
