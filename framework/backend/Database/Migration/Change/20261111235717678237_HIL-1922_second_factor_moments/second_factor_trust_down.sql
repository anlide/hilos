DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_second_factor_trust`
        WHERE `trusted_until` < 1000 OR `trusted_until` > 2147483647999
            OR `created_at` < 1000 OR `created_at` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second factor trust moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_second_factor_trust`
        ADD COLUMN IF NOT EXISTS `__hil1922_trusted_until` TIMESTAMP NULL DEFAULT NULL AFTER `trusted_until`,
        ADD COLUMN IF NOT EXISTS `__hil1922_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_second_factor_trust` SET
        `__hil1922_trusted_until` = FROM_UNIXTIME(FLOOR(`trusted_until` / 1000)),
        `__hil1922_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_second_factor_trust`
        DROP COLUMN `trusted_until`, CHANGE COLUMN `__hil1922_trusted_until` `trusted_until` TIMESTAMP NOT NULL,
        DROP COLUMN `created_at`, CHANGE COLUMN `__hil1922_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
