-- hilos-framework: {"format":1,"kind":"change","units":["second_factor"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor","upSha256":"9ab179260b9273ed84d44f9bdc2ceb2af92f5880519bd8ccdaea347bb6a6ed6c","downSha256":"2aa7867882c78c7bb3b26b7fde839d5323ba17d064f7b5f1146f3bc7985d1ae0"}],"upSha256":"9ab179260b9273ed84d44f9bdc2ceb2af92f5880519bd8ccdaea347bb6a6ed6c","downSha256":"2aa7867882c78c7bb3b26b7fde839d5323ba17d064f7b5f1146f3bc7985d1ae0"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_second_factor`
        WHERE `confirmed_at` < 1000 OR `confirmed_at` > 2147483647999
            OR `last_used_at` < 1000 OR `last_used_at` > 2147483647999
            OR `created_at` < 1000 OR `created_at` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second factor moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_second_factor`
        ADD COLUMN IF NOT EXISTS `__hil1922_confirmed_at` TIMESTAMP NULL DEFAULT NULL AFTER `confirmed_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_last_used_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_used_at`,
        ADD COLUMN IF NOT EXISTS `__hil1922_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_second_factor` SET
        `__hil1922_confirmed_at` = FROM_UNIXTIME(FLOOR(`confirmed_at` / 1000)),
        `__hil1922_last_used_at` = FROM_UNIXTIME(FLOOR(`last_used_at` / 1000)),
        `__hil1922_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_second_factor`
        DROP COLUMN `confirmed_at`, CHANGE COLUMN `__hil1922_confirmed_at` `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `last_used_at`, CHANGE COLUMN `__hil1922_last_used_at` `last_used_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `created_at`, CHANGE COLUMN `__hil1922_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
