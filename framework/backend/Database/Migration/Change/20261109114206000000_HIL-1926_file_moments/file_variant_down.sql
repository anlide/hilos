DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_file_variant` WHERE `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'File moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_file_variant` ADD COLUMN `__hil1926_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_file_variant` SET `__hil1926_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_file_variant` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1926_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
