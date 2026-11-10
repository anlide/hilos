DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user_rename` WHERE `renamed_at` < 1000 OR `renamed_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user_rename` ADD COLUMN `__hil1924_renamed_at` TIMESTAMP NULL DEFAULT NULL AFTER `renamed_at`;
    UPDATE `hilos_user_rename` SET `__hil1924_renamed_at` = FROM_UNIXTIME(FLOOR(`renamed_at` / 1000));
    ALTER TABLE `hilos_user_rename` DROP COLUMN `renamed_at`, CHANGE COLUMN `__hil1924_renamed_at` `renamed_at` TIMESTAMP NOT NULL;
END$$
DELIMITER ;
