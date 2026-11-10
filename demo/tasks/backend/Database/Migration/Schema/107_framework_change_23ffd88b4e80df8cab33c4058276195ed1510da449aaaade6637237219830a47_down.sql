-- hilos-framework: {"format":1,"kind":"change","units":["identity"],"parts":[{"changeId":"20261110014712983573_HIL-1920_sign_in_moments","unit":"identity","upSha256":"8734c4b591797b213be5ad64cd6f39c0bf0ecd6d303ed0ef3aa4f17954d830ab","downSha256":"4f9034b57aff3dd5ea6cbc19fc9675ff67ce7dab81ce5286d458cff1be4cbbbe"}],"upSha256":"8734c4b591797b213be5ad64cd6f39c0bf0ecd6d303ed0ef3aa4f17954d830ab","downSha256":"4f9034b57aff3dd5ea6cbc19fc9675ff67ce7dab81ce5286d458cff1be4cbbbe"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_identity` WHERE `created_at` < 1000 OR `created_at` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999 OR `password_changed_at` < 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sign-in moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_identity` ADD COLUMN `__hil1920_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`, ADD COLUMN `__hil1920_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
    UPDATE `hilos_identity` SET `__hil1920_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)), `__hil1920_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_identity` MODIFY COLUMN `password_changed_at` BIGINT UNSIGNED NULL DEFAULT NULL, DROP COLUMN `created_at`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1920_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1920_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
END$$
DELIMITER ;
