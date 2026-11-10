-- hilos-framework: {"format":1,"kind":"change","units":["oauth_provider"],"parts":[{"changeId":"20261110014712983573_HIL-1920_sign_in_moments","unit":"oauth_provider","upSha256":"00101162d077a303ff19fcf63dfc46cf939c8c46456cc116fc363db053ad55f7","downSha256":"89e0850764c106553b179088237d5ffb88b4c39de912b018e059dbf306a52b2d"}],"upSha256":"00101162d077a303ff19fcf63dfc46cf939c8c46456cc116fc363db053ad55f7","downSha256":"89e0850764c106553b179088237d5ffb88b4c39de912b018e059dbf306a52b2d"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_oauth_provider` WHERE `created_at` < 1000 OR `created_at` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sign-in moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_oauth_provider` ADD COLUMN `__hil1920_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`, ADD COLUMN `__hil1920_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
    UPDATE `hilos_oauth_provider` SET `__hil1920_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)), `__hil1920_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_oauth_provider` DROP COLUMN `created_at`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1920_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1920_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
END$$
DELIMITER ;
