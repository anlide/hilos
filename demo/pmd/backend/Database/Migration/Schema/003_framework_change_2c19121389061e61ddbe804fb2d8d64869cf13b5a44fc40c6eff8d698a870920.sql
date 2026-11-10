-- hilos-framework: {"format":1,"kind":"change","units":["account_deletion"],"parts":[{"changeId":"20261110024242374685_HIL-1924_people_moments","unit":"account_deletion","upSha256":"5474c8b99adbcff899089e668bf7f4d36f3d1ca9b7a762bcdfe8f22fab944f2d","downSha256":"4199b444b0f6156749e13becb8345a24c429fa18cbad48a0866b177fede49168"}],"upSha256":"5474c8b99adbcff899089e668bf7f4d36f3d1ca9b7a762bcdfe8f22fab944f2d","downSha256":"4199b444b0f6156749e13becb8345a24c429fa18cbad48a0866b177fede49168"}
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
