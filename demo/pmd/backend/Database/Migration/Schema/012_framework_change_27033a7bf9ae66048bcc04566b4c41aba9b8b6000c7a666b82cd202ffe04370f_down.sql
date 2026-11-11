-- hilos-framework: {"format":1,"kind":"change","units":["registration_reservation"],"parts":[{"changeId":"20261111013234414363_HIL-1921_auth_proof_moments","unit":"registration_reservation","upSha256":"2f033a4371e967263542bf55a0e77b259614e483ac9ed8d0b92d4cf9181d5706","downSha256":"41ac12805344609e97df200b45aadf24336923a0b2e4a2f058804b1ecd0c3454"}],"upSha256":"2f033a4371e967263542bf55a0e77b259614e483ac9ed8d0b92d4cf9181d5706","downSha256":"41ac12805344609e97df200b45aadf24336923a0b2e4a2f058804b1ecd0c3454"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_registration_reservation` WHERE `code_accepted_at` < 1000 OR `code_accepted_at` > 2147483647999 OR `expires_at` < 1000 OR `expires_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_registration_reservation` ADD COLUMN `__hil1921_code_accepted_at` TIMESTAMP NULL DEFAULT NULL AFTER `code_accepted_at`, ADD COLUMN `__hil1921_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`, ADD COLUMN `__hil1921_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_registration_reservation` SET `__hil1921_code_accepted_at` = FROM_UNIXTIME(FLOOR(`code_accepted_at` / 1000)), `__hil1921_expires_at` = FROM_UNIXTIME(FLOOR(`expires_at` / 1000)), `__hil1921_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_registration_reservation` DROP INDEX `idx_reservation_expires`, DROP COLUMN `code_accepted_at`, DROP COLUMN `expires_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_code_accepted_at` `code_accepted_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_expires_at` `expires_at` TIMESTAMP NOT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD INDEX `idx_reservation_expires` (`expires_at`);
END$$
DELIMITER ;
