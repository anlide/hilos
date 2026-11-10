DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user` WHERE `last_activity` < 1000 OR `last_activity` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'User activity moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user` ADD COLUMN `__hil1924_last_activity` TIMESTAMP NULL DEFAULT NULL AFTER `last_activity`;
    UPDATE `hilos_user` SET `__hil1924_last_activity` = FROM_UNIXTIME(FLOOR(`last_activity` / 1000));
    ALTER TABLE `hilos_user` DROP INDEX `idx_user_last_activity`, DROP COLUMN `last_activity`, CHANGE COLUMN `__hil1924_last_activity` `last_activity` TIMESTAMP NULL DEFAULT NULL, ADD KEY `idx_user_last_activity` (`last_activity`);
END$$
DELIMITER ;
