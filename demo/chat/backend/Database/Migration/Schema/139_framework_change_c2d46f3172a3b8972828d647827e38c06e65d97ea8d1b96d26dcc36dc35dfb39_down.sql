-- hilos-framework: {"format":1,"kind":"change","units":["notification_delivery"],"parts":[{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"notification_delivery","upSha256":"d90be61266beaf94a8f40d26fe24f9bf74cb4447767ae479341fdfdddcde63ce","downSha256":"81de6e9abb90f1925e245a108faa0a70dee71ed054a1a38f289122ea3a0f0168"}],"upSha256":"d90be61266beaf94a8f40d26fe24f9bf74cb4447767ae479341fdfdddcde63ce","downSha256":"81de6e9abb90f1925e245a108faa0a70dee71ed054a1a38f289122ea3a0f0168"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_notification_delivery` WHERE `created_at` < 1000 OR `created_at` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999 OR `delivered_at` < 1000 OR `delivered_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_notification_delivery` ADD COLUMN `__hil1923_created_at` TIMESTAMP NULL, ADD COLUMN `__hil1923_updated_at` TIMESTAMP NULL, ADD COLUMN `__hil1923_delivered_at` TIMESTAMP NULL;
    UPDATE `hilos_notification_delivery` SET `__hil1923_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)), `__hil1923_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000)), `__hil1923_delivered_at` = FROM_UNIXTIME(FLOOR(`delivered_at` / 1000));
    ALTER TABLE `hilos_notification_delivery` DROP INDEX `idx_notification_delivery_created`, DROP INDEX `idx_notification_delivery_status_created`, DROP COLUMN `created_at`, DROP COLUMN `updated_at`, DROP COLUMN `delivered_at`, CHANGE COLUMN `__hil1923_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1923_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1923_delivered_at` `delivered_at` TIMESTAMP NULL DEFAULT NULL, ADD INDEX `idx_notification_delivery_created` (`created_at`), ADD INDEX `idx_notification_delivery_status_created` (`status`, `created_at`);
END$$
DELIMITER ;
