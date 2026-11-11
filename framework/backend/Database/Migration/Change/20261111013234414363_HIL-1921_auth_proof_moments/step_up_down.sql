DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_step_up` WHERE `confirmed_until` < 1000 OR `confirmed_until` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_step_up` ADD COLUMN `__hil1921_confirmed_until` TIMESTAMP NULL DEFAULT NULL AFTER `confirmed_until`, ADD COLUMN `__hil1921_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_step_up` SET `__hil1921_confirmed_until` = FROM_UNIXTIME(FLOOR(`confirmed_until` / 1000)), `__hil1921_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_step_up` DROP COLUMN `confirmed_until`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_confirmed_until` `confirmed_until` TIMESTAMP NOT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
