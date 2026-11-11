-- hilos-framework: {"format":1,"kind":"change","units":["user_verification"],"parts":[{"changeId":"20261111013234414363_HIL-1921_auth_proof_moments","unit":"user_verification","upSha256":"3a3dc55150209489b68a820f56e6ff2c54ce6b86f6829475083cba7953b9e218","downSha256":"963d4af66e35bb34658aaa7e1d83a325543ba6442eb5d94aafd7edd77cb367a3"}],"upSha256":"3a3dc55150209489b68a820f56e6ff2c54ce6b86f6829475083cba7953b9e218","downSha256":"963d4af66e35bb34658aaa7e1d83a325543ba6442eb5d94aafd7edd77cb367a3"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user_verification` WHERE `expires_at` < 1000 OR `expires_at` > 2147483647999 OR `consumed_at` < 1000 OR `consumed_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user_verification` ADD COLUMN `__hil1921_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`, ADD COLUMN `__hil1921_consumed_at` TIMESTAMP NULL DEFAULT NULL AFTER `consumed_at`, ADD COLUMN `__hil1921_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_user_verification` SET `__hil1921_expires_at` = FROM_UNIXTIME(FLOOR(`expires_at` / 1000)), `__hil1921_consumed_at` = FROM_UNIXTIME(FLOOR(`consumed_at` / 1000)), `__hil1921_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_user_verification` DROP COLUMN `expires_at`, DROP COLUMN `consumed_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_expires_at` `expires_at` TIMESTAMP NOT NULL, CHANGE COLUMN `__hil1921_consumed_at` `consumed_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
