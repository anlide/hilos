-- hilos-framework: {"format":1,"kind":"change","units":["user_merge"],"parts":[{"changeId":"20261110024242374685_HIL-1924_people_moments","unit":"user_merge","upSha256":"3c8759e3317adb6b134393b5ee7e5e53c915d8c0f8ba0eed78712bdf6c0f7aa4","downSha256":"798a3786c4ac7e5da18e8ded7ac5730fd4f405b14b753011cc81e2f754fdaf96"}],"upSha256":"3c8759e3317adb6b134393b5ee7e5e53c915d8c0f8ba0eed78712bdf6c0f7aa4","downSha256":"798a3786c4ac7e5da18e8ded7ac5730fd4f405b14b753011cc81e2f754fdaf96"}
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
