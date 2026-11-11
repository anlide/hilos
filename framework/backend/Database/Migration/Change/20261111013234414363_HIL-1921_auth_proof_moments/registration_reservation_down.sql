DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_registration_reservation` WHERE `code_accepted_at` < 1000 OR `code_accepted_at` > 2147483647999 OR `expires_at` < 1000 OR `expires_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_registration_reservation` ADD COLUMN `__hil1921_code_accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `code_accepted_at`, ADD COLUMN `__hil1921_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`, ADD COLUMN `__hil1921_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_registration_reservation` SET `__hil1921_code_accepted_at` = FROM_UNIXTIME(FLOOR(`code_accepted_at` / 1000)), `__hil1921_expires_at` = FROM_UNIXTIME(FLOOR(`expires_at` / 1000)), `__hil1921_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_registration_reservation` DROP INDEX `idx_reservation_expires`, DROP COLUMN `code_accepted_at`, DROP COLUMN `expires_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_code_accepted_at` `code_accepted_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_expires_at` `expires_at` TIMESTAMP NOT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD INDEX `idx_reservation_expires` (`expires_at`);
END$$
DELIMITER ;
