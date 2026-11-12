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
