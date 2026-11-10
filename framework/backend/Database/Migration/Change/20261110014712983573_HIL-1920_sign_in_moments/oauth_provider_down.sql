DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_oauth_provider` WHERE `created_at` < 1000 OR `created_at` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sign-in moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_oauth_provider` ADD COLUMN `__hil1920_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`, ADD COLUMN `__hil1920_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
    UPDATE `hilos_oauth_provider` SET `__hil1920_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)), `__hil1920_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_oauth_provider` DROP COLUMN `created_at`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1920_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1920_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
END$$
DELIMITER ;
