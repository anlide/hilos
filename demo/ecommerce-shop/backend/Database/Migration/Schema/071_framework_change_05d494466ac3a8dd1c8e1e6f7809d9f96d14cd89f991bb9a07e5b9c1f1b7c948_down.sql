-- hilos-framework: {"format":1,"kind":"change","units":["verifier_circle"],"parts":[{"changeId":"20261109120150360881_HIL-1919_session_moments","unit":"verifier_circle","upSha256":"e7b4ac3d68e1df08d69b52033e40e90c1176bb075f89d405a9a8af57dbee813c","downSha256":"12d25906c8344f6105a6c17523914829f2c0754f077e77e9ede1e36cf67dcd32"}],"upSha256":"e7b4ac3d68e1df08d69b52033e40e90c1176bb075f89d405a9a8af57dbee813c","downSha256":"12d25906c8344f6105a6c17523914829f2c0754f077e77e9ede1e36cf67dcd32"}
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
