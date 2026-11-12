DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_second_factor_backup_code`
        WHERE `used_at` < 1000 OR `used_at` > 2147483647999
            OR `created_at` < 1000 OR `created_at` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second factor backup code moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_second_factor_backup_code`
        ADD COLUMN IF NOT EXISTS `__hil1922_used_at` TIMESTAMP NULL DEFAULT NULL AFTER `used_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_second_factor_backup_code` SET
        `__hil1922_used_at` = FROM_UNIXTIME(FLOOR(`used_at` / 1000)),
        `__hil1922_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_second_factor_backup_code`
        DROP COLUMN `used_at`, CHANGE COLUMN `__hil1922_used_at` `used_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `created_at`, CHANGE COLUMN `__hil1922_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
