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
