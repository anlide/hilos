DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user_photo` WHERE `set_at` < 1000 OR `set_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user_photo` ADD COLUMN `__hil1924_set_at` TIMESTAMP NULL DEFAULT NULL AFTER `set_at`;
    UPDATE `hilos_user_photo` SET `__hil1924_set_at` = FROM_UNIXTIME(FLOOR(`set_at` / 1000));
    ALTER TABLE `hilos_user_photo` DROP COLUMN `set_at`, CHANGE COLUMN `__hil1924_set_at` `set_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
