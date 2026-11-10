DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user_merge` WHERE `merged_at` < 1000 OR `merged_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user_merge` ADD COLUMN `__hil1924_merged_at` TIMESTAMP NULL DEFAULT NULL AFTER `merged_at`;
    UPDATE `hilos_user_merge` SET `__hil1924_merged_at` = FROM_UNIXTIME(FLOOR(`merged_at` / 1000));
    ALTER TABLE `hilos_user_merge` DROP COLUMN `merged_at`, CHANGE COLUMN `__hil1924_merged_at` `merged_at` TIMESTAMP NULL DEFAULT NULL;
END$$
DELIMITER ;
