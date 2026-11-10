DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_passkey_credential` WHERE `last_used_at` < 1000 OR `last_used_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sign-in moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_passkey_credential` ADD COLUMN `__hil1920_last_used_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_used_at`, ADD COLUMN `__hil1920_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_passkey_credential` SET `__hil1920_last_used_at` = FROM_UNIXTIME(FLOOR(`last_used_at` / 1000)), `__hil1920_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_passkey_credential` DROP COLUMN `last_used_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1920_last_used_at` `last_used_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1920_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
