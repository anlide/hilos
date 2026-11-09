DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_verifier_circle` WHERE `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verifier circle moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_verifier_circle` ADD COLUMN IF NOT EXISTS `__hil1919_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_verifier_circle` SET `__hil1919_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_verifier_circle` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1919_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
