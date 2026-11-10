-- hilos-framework: {"format":1,"kind":"change","units":["account_deletion"],"parts":[{"changeId":"20261110024242374685_HIL-1924_people_moments","unit":"account_deletion","upSha256":"5474c8b99adbcff899089e668bf7f4d36f3d1ca9b7a762bcdfe8f22fab944f2d","downSha256":"4199b444b0f6156749e13becb8345a24c429fa18cbad48a0866b177fede49168"}],"upSha256":"5474c8b99adbcff899089e668bf7f4d36f3d1ca9b7a762bcdfe8f22fab944f2d","downSha256":"4199b444b0f6156749e13becb8345a24c429fa18cbad48a0866b177fede49168"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_account_deletion` WHERE `requested_at` < 1000 OR `requested_at` > 2147483647999 OR `effective_at` < 1000 OR `effective_at` > 2147483647999 OR `canceled_at` < 1000 OR `canceled_at` > 2147483647999 OR `completed_at` < 1000 OR `completed_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_account_deletion`
        ADD COLUMN `__hil1924_requested_at` TIMESTAMP NULL DEFAULT NULL AFTER `requested_at`,
        ADD COLUMN `__hil1924_effective_at` TIMESTAMP NULL DEFAULT NULL AFTER `effective_at`,
        ADD COLUMN `__hil1924_canceled_at` TIMESTAMP NULL DEFAULT NULL AFTER `canceled_at`,
        ADD COLUMN `__hil1924_completed_at` TIMESTAMP NULL DEFAULT NULL AFTER `completed_at`;
    UPDATE `hilos_account_deletion` SET
        `__hil1924_requested_at` = FROM_UNIXTIME(FLOOR(`requested_at` / 1000)),
        `__hil1924_effective_at` = FROM_UNIXTIME(FLOOR(`effective_at` / 1000)),
        `__hil1924_canceled_at` = FROM_UNIXTIME(FLOOR(`canceled_at` / 1000)),
        `__hil1924_completed_at` = FROM_UNIXTIME(FLOOR(`completed_at` / 1000));
    ALTER TABLE `hilos_account_deletion`
        DROP INDEX `idx_account_deletion_effective`,
        DROP COLUMN `requested_at`,
        CHANGE COLUMN `__hil1924_requested_at` `requested_at` TIMESTAMP NOT NULL,
        DROP COLUMN `effective_at`,
        CHANGE COLUMN `__hil1924_effective_at` `effective_at` TIMESTAMP NOT NULL,
        DROP COLUMN `canceled_at`,
        CHANGE COLUMN `__hil1924_canceled_at` `canceled_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `completed_at`,
        CHANGE COLUMN `__hil1924_completed_at` `completed_at` TIMESTAMP NULL DEFAULT NULL,
        ADD KEY `idx_account_deletion_effective` (`effective_at`);
END$$
DELIMITER ;
