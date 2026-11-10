-- hilos-framework: {"format":1,"kind":"change","units":["push_subscription"],"parts":[{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"push_subscription","upSha256":"1e00260fdf68931f378d121f6c9e170fd8b75c3724bf2ae7e2eba1d5e2c75341","downSha256":"403afc4e828acbbaa47e194322c201b2af2c20703fc4e030fa0d9a6407de397f"}],"upSha256":"1e00260fdf68931f378d121f6c9e170fd8b75c3724bf2ae7e2eba1d5e2c75341","downSha256":"403afc4e828acbbaa47e194322c201b2af2c20703fc4e030fa0d9a6407de397f"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_push_subscription` WHERE `gone_at` < 1000 OR `gone_at` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999 OR `last_seen_at` < 1000 OR `last_seen_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_push_subscription` ADD COLUMN `__hil1923_gone_at` TIMESTAMP NULL, ADD COLUMN `__hil1923_created_at` TIMESTAMP NULL, ADD COLUMN `__hil1923_last_seen_at` TIMESTAMP NULL;
    UPDATE `hilos_push_subscription` SET `__hil1923_gone_at` = FROM_UNIXTIME(FLOOR(`gone_at` / 1000)), `__hil1923_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)), `__hil1923_last_seen_at` = FROM_UNIXTIME(FLOOR(`last_seen_at` / 1000));
    ALTER TABLE `hilos_push_subscription` DROP COLUMN `gone_at`, DROP COLUMN `created_at`, DROP COLUMN `last_seen_at`, CHANGE COLUMN `__hil1923_gone_at` `gone_at` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1923_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1923_last_seen_at` `last_seen_at` TIMESTAMP NULL DEFAULT NULL;
END$$
DELIMITER ;
