-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_backup_code"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_backup_code","upSha256":"470a756947e89840cb217a0457e18fe3a71401c36602f6d8c47196c618abadce","downSha256":"b63ed8ffb9da7646503e98f164725fd19e5016377e445ab987734555c25ac29c"}],"upSha256":"470a756947e89840cb217a0457e18fe3a71401c36602f6d8c47196c618abadce","downSha256":"b63ed8ffb9da7646503e98f164725fd19e5016377e445ab987734555c25ac29c"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_second_factor_backup_code`
        WHERE `used_at` < 1000 OR `used_at` > 2147483647999
            OR `created_at` < 1000 OR `created_at` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second factor backup code moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_second_factor_backup_code`
        ADD COLUMN IF NOT EXISTS `__hil1922_used_at` TIMESTAMP NULL DEFAULT NULL AFTER `used_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_second_factor_backup_code` SET
        `__hil1922_used_at` = FROM_UNIXTIME(FLOOR(`used_at` / 1000)),
        `__hil1922_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_second_factor_backup_code`
        DROP COLUMN `used_at`, CHANGE COLUMN `__hil1922_used_at` `used_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `created_at`, CHANGE COLUMN `__hil1922_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
