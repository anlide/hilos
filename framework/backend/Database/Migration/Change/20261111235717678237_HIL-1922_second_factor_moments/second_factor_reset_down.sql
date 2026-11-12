DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_second_factor_reset`
        WHERE `requested_at` < 1000 OR `requested_at` > 2147483647999
            OR `effective_at` < 1000 OR `effective_at` > 2147483647999
            OR `notified_at` < 1000 OR `notified_at` > 2147483647999
            OR `canceled_at` < 1000 OR `canceled_at` > 2147483647999
            OR `completed_at` < 1000 OR `completed_at` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second factor reset moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_second_factor_reset`
        ADD COLUMN IF NOT EXISTS `__hil1922_requested_at` TIMESTAMP NULL DEFAULT NULL AFTER `requested_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_effective_at` TIMESTAMP NULL DEFAULT NULL AFTER `effective_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_notified_at` TIMESTAMP NULL DEFAULT NULL AFTER `notified_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_canceled_at` TIMESTAMP NULL DEFAULT NULL AFTER `canceled_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_completed_at` TIMESTAMP NULL DEFAULT NULL AFTER `completed_at`;
    UPDATE `hilos_second_factor_reset` SET
        `__hil1922_requested_at` = FROM_UNIXTIME(FLOOR(`requested_at` / 1000)),
        `__hil1922_effective_at` = FROM_UNIXTIME(FLOOR(`effective_at` / 1000)),
        `__hil1922_notified_at` = FROM_UNIXTIME(FLOOR(`notified_at` / 1000)),
        `__hil1922_canceled_at` = FROM_UNIXTIME(FLOOR(`canceled_at` / 1000)),
        `__hil1922_completed_at` = FROM_UNIXTIME(FLOOR(`completed_at` / 1000));
    ALTER TABLE `hilos_second_factor_reset`
        DROP INDEX `idx_second_factor_reset_effective`,
        DROP COLUMN `requested_at`, CHANGE COLUMN `__hil1922_requested_at` `requested_at` TIMESTAMP NOT NULL,
        DROP COLUMN `effective_at`, CHANGE COLUMN `__hil1922_effective_at` `effective_at` TIMESTAMP NOT NULL,
        DROP COLUMN `notified_at`, CHANGE COLUMN `__hil1922_notified_at` `notified_at` TIMESTAMP NOT NULL,
        DROP COLUMN `canceled_at`, CHANGE COLUMN `__hil1922_canceled_at` `canceled_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `completed_at`, CHANGE COLUMN `__hil1922_completed_at` `completed_at` TIMESTAMP NULL DEFAULT NULL,
        ADD KEY `idx_second_factor_reset_effective` (`effective_at`);
END$$
DELIMITER ;
