-- hilos-framework: {"format":1,"kind":"change","units":["notification"],"parts":[{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"notification","upSha256":"94aaf870c0113632ef7144c262a3334193f8d035302c6130ce7212f8bed623cb","downSha256":"1f369a7c5b2e94da1077a1e81c6ad0f30d886b2248ffde8783010254cbd36b2d"}],"upSha256":"94aaf870c0113632ef7144c262a3334193f8d035302c6130ce7212f8bed623cb","downSha256":"1f369a7c5b2e94da1077a1e81c6ad0f30d886b2248ffde8783010254cbd36b2d"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_notification` WHERE `read_at` < 1000 OR `read_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_notification` ADD COLUMN `__hil1923_read_at` TIMESTAMP NULL, ADD COLUMN `__hil1923_created_at` TIMESTAMP NULL;
    UPDATE `hilos_notification` SET `__hil1923_read_at` = FROM_UNIXTIME(FLOOR(`read_at` / 1000)), `__hil1923_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_notification` DROP INDEX `idx_notification_user_read`, DROP INDEX `idx_notification_user_created`, DROP COLUMN `read_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1923_read_at` `read_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1923_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD INDEX `idx_notification_user_read` (`user_id`, `read_at`), ADD INDEX `idx_notification_user_created` (`user_id`, `created_at`);
END$$
DELIMITER ;
