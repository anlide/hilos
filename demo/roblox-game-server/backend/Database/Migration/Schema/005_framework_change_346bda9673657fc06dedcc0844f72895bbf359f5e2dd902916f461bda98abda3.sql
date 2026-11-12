-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_reset"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_reset","upSha256":"134375d6e708cb7033774b2b9a748669bd38300eba898a35e6346a215499ae12","downSha256":"87b677c3ec11a3ce89fbe8be5aab57d08bce13aa5d5a5044d36cf2af42491495"}],"upSha256":"134375d6e708cb7033774b2b9a748669bd38300eba898a35e6346a215499ae12","downSha256":"87b677c3ec11a3ce89fbe8be5aab57d08bce13aa5d5a5044d36cf2af42491495"}
ALTER TABLE `hilos_second_factor_reset`
    ADD COLUMN `__hil1922_requested_at` BIGINT NULL AFTER `requested_at`,
    ADD COLUMN `__hil1922_effective_at` BIGINT NULL AFTER `effective_at`,
    ADD COLUMN `__hil1922_notified_at` BIGINT NULL AFTER `notified_at`,
    ADD COLUMN `__hil1922_canceled_at` BIGINT NULL AFTER `canceled_at`,
    ADD COLUMN `__hil1922_completed_at` BIGINT NULL AFTER `completed_at`;
UPDATE `hilos_second_factor_reset` SET
    `__hil1922_requested_at` = UNIX_TIMESTAMP(`requested_at`) * 1000,
    `__hil1922_effective_at` = UNIX_TIMESTAMP(`effective_at`) * 1000,
    `__hil1922_notified_at` = UNIX_TIMESTAMP(`notified_at`) * 1000,
    `__hil1922_canceled_at` = UNIX_TIMESTAMP(`canceled_at`) * 1000,
    `__hil1922_completed_at` = UNIX_TIMESTAMP(`completed_at`) * 1000;
ALTER TABLE `hilos_second_factor_reset`
    DROP INDEX `idx_second_factor_reset_effective`,
    DROP COLUMN `requested_at`, ADD COLUMN `requested_at` BIGINT NOT NULL AFTER `__hil1922_requested_at`,
    DROP COLUMN `effective_at`, ADD COLUMN `effective_at` BIGINT NOT NULL AFTER `__hil1922_effective_at`,
    DROP COLUMN `notified_at`, ADD COLUMN `notified_at` BIGINT NOT NULL AFTER `__hil1922_notified_at`,
    DROP COLUMN `canceled_at`, ADD COLUMN `canceled_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_canceled_at`,
    DROP COLUMN `completed_at`, ADD COLUMN `completed_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_completed_at`,
    ADD KEY `idx_second_factor_reset_effective` (`effective_at`);
UPDATE `hilos_second_factor_reset` SET
    `requested_at` = `__hil1922_requested_at`,
    `effective_at` = `__hil1922_effective_at`,
    `notified_at` = `__hil1922_notified_at`,
    `canceled_at` = `__hil1922_canceled_at`,
    `completed_at` = `__hil1922_completed_at`;
ALTER TABLE `hilos_second_factor_reset`
    DROP COLUMN `__hil1922_requested_at`,
    DROP COLUMN `__hil1922_effective_at`,
    DROP COLUMN `__hil1922_notified_at`,
    DROP COLUMN `__hil1922_canceled_at`,
    DROP COLUMN `__hil1922_completed_at`;
