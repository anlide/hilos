-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_trust"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_trust","upSha256":"418c137c3d6c2b7ef5e6630ded73bfcd08be2887e0dcb73ca253b1268bc09913","downSha256":"72c5f64129fa520c3451a2a4d4141117d762357c029a5d735b2aafc56db9b971"}],"upSha256":"418c137c3d6c2b7ef5e6630ded73bfcd08be2887e0dcb73ca253b1268bc09913","downSha256":"72c5f64129fa520c3451a2a4d4141117d762357c029a5d735b2aafc56db9b971"}
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
