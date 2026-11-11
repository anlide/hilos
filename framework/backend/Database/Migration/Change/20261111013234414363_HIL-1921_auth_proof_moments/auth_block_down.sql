DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_auth_block` WHERE `blocked_until` < 1000 OR `blocked_until` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_auth_block` ADD COLUMN `__hil1921_blocked_until` TIMESTAMP NULL DEFAULT NULL AFTER `blocked_until`, ADD COLUMN `__hil1921_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
    UPDATE `hilos_auth_block` SET `__hil1921_blocked_until` = FROM_UNIXTIME(FLOOR(`blocked_until` / 1000)), `__hil1921_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_auth_block` DROP COLUMN `blocked_until`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1921_blocked_until` `blocked_until` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
END$$
DELIMITER ;
