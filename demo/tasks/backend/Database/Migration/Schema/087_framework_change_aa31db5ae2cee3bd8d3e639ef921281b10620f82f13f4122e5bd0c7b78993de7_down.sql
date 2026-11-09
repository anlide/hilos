-- hilos-framework: {"format":1,"kind":"change","units":["session"],"parts":[{"changeId":"20261109120150360881_HIL-1919_session_moments","unit":"session","upSha256":"845bad63c380800b8ec9f5f040c272ea1ff3c5de01882e595dc3be5e4b7010e9","downSha256":"269b2b5f7552d3d6087a6e2e4ad1dfe2124a8833fe96f7550412f40b2d635af3"}],"upSha256":"845bad63c380800b8ec9f5f040c272ea1ff3c5de01882e595dc3be5e4b7010e9","downSha256":"269b2b5f7552d3d6087a6e2e4ad1dfe2124a8833fe96f7550412f40b2d635af3"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `hilos_session`
        WHERE `created_at` < 1000 OR `created_at` > 2147483647999
            OR `last_seen_at` < 1000 OR `last_seen_at` > 2147483647999
            OR `expires_at` < 1000 OR `expires_at` > 2147483647999
            OR `pending_registration_since` < 1000 OR `pending_registration_since` > 2147483647999
            OR `pending_second_factor_until` < 1000 OR `pending_second_factor_until` > 2147483647999
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Session moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_session`
        ADD COLUMN IF NOT EXISTS `__hil1919_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`,
        ADD COLUMN IF NOT EXISTS `__hil1919_last_seen_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_seen_at`,
        ADD COLUMN IF NOT EXISTS `__hil1919_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`,
        ADD COLUMN IF NOT EXISTS `__hil1919_pending_registration_since` TIMESTAMP NULL DEFAULT NULL AFTER `pending_registration_since`,
        ADD COLUMN IF NOT EXISTS `__hil1919_pending_second_factor_until` TIMESTAMP NULL DEFAULT NULL AFTER `pending_second_factor_until`;
    UPDATE `hilos_session` SET
        `__hil1919_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)),
        `__hil1919_last_seen_at` = FROM_UNIXTIME(FLOOR(`last_seen_at` / 1000)),
        `__hil1919_expires_at` = FROM_UNIXTIME(FLOOR(`expires_at` / 1000)),
        `__hil1919_pending_registration_since` = FROM_UNIXTIME(FLOOR(`pending_registration_since` / 1000)),
        `__hil1919_pending_second_factor_until` = FROM_UNIXTIME(FLOOR(`pending_second_factor_until` / 1000));
    ALTER TABLE `hilos_session`
        DROP INDEX `idx_session_expires`,
        DROP INDEX `idx_session_anonymous_seen`,
        DROP COLUMN `created_at`, CHANGE COLUMN `__hil1919_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        DROP COLUMN `last_seen_at`, CHANGE COLUMN `__hil1919_last_seen_at` `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        DROP COLUMN `expires_at`, CHANGE COLUMN `__hil1919_expires_at` `expires_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `pending_registration_since`, CHANGE COLUMN `__hil1919_pending_registration_since` `pending_registration_since` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `pending_second_factor_until`, CHANGE COLUMN `__hil1919_pending_second_factor_until` `pending_second_factor_until` TIMESTAMP NULL DEFAULT NULL,
        ADD KEY `idx_session_expires` (`expires_at`),
        ADD KEY `idx_session_anonymous_seen` (`user_id`, `last_seen_at`);
END$$
DELIMITER ;
