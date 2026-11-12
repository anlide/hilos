-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_setting"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_setting","upSha256":"4751b552baafb5158e62bcfdb4c8221b22dbf83770bfd794edf2a30757e0e104","downSha256":"1272afe53346cf9ace26dd70ed8e9bd2315e469a3063b860bbd95184e7244c9f"}],"upSha256":"4751b552baafb5158e62bcfdb4c8221b22dbf83770bfd794edf2a30757e0e104","downSha256":"1272afe53346cf9ace26dd70ed8e9bd2315e469a3063b860bbd95184e7244c9f"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_second_factor_setting`
        WHERE `pending_reset_wait_from` < 1000 OR `pending_reset_wait_from` > 2147483647999
            OR `app_code_misses_from` < 1000 OR `app_code_misses_from` > 2147483647999
            OR `app_code_locked_until` < 1000 OR `app_code_locked_until` > 2147483647999
            OR `updated_at` < 1000 OR `updated_at` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Second factor setting moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_second_factor_setting`
        ADD COLUMN IF NOT EXISTS `__hil1922_pending_reset_wait_from` TIMESTAMP NULL DEFAULT NULL AFTER `pending_reset_wait_from`,
        ADD COLUMN IF NOT EXISTS `__hil1922_app_code_misses_from` TIMESTAMP NULL DEFAULT NULL AFTER `app_code_misses_from`,
        ADD COLUMN IF NOT EXISTS `__hil1922_app_code_locked_until` TIMESTAMP NULL DEFAULT NULL AFTER `app_code_locked_until`,
        ADD COLUMN IF NOT EXISTS `__hil1922_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
    UPDATE `hilos_second_factor_setting` SET
        `__hil1922_pending_reset_wait_from` = FROM_UNIXTIME(FLOOR(`pending_reset_wait_from` / 1000)),
        `__hil1922_app_code_misses_from` = FROM_UNIXTIME(FLOOR(`app_code_misses_from` / 1000)),
        `__hil1922_app_code_locked_until` = FROM_UNIXTIME(FLOOR(`app_code_locked_until` / 1000)),
        `__hil1922_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_second_factor_setting`
        DROP COLUMN `pending_reset_wait_from`, CHANGE COLUMN `__hil1922_pending_reset_wait_from` `pending_reset_wait_from` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `app_code_misses_from`, CHANGE COLUMN `__hil1922_app_code_misses_from` `app_code_misses_from` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `app_code_locked_until`, CHANGE COLUMN `__hil1922_app_code_locked_until` `app_code_locked_until` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1922_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
