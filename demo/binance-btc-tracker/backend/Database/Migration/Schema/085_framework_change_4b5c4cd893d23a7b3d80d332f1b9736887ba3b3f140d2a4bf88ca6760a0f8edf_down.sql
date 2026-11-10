-- hilos-framework: {"format":1,"kind":"change","units":["user"],"parts":[{"changeId":"20261110024242374685_HIL-1924_people_moments","unit":"user","upSha256":"7ae00a3b4fa3d9d136d4c7785bb0ba3fcbed7b1b39dc8c82c7930b3b3a47c51f","downSha256":"2703ac0fb347a90eeabc665e55ca4768dbcf8fa54a6723f0974af4317cefa419"}],"upSha256":"7ae00a3b4fa3d9d136d4c7785bb0ba3fcbed7b1b39dc8c82c7930b3b3a47c51f","downSha256":"2703ac0fb347a90eeabc665e55ca4768dbcf8fa54a6723f0974af4317cefa419"}
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
