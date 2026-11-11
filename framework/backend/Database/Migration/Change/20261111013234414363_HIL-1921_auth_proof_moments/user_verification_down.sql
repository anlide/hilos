DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user_verification` WHERE `expires_at` < 1000 OR `expires_at` > 2147483647999 OR `consumed_at` < 1000 OR `consumed_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user_verification` ADD COLUMN `__hil1921_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`, ADD COLUMN `__hil1921_consumed_at` TIMESTAMP NULL DEFAULT NULL AFTER `consumed_at`, ADD COLUMN `__hil1921_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_user_verification` SET `__hil1921_expires_at` = FROM_UNIXTIME(FLOOR(`expires_at` / 1000)), `__hil1921_consumed_at` = FROM_UNIXTIME(FLOOR(`consumed_at` / 1000)), `__hil1921_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_user_verification` DROP COLUMN `expires_at`, DROP COLUMN `consumed_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_expires_at` `expires_at` TIMESTAMP NOT NULL, CHANGE COLUMN `__hil1921_consumed_at` `consumed_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
