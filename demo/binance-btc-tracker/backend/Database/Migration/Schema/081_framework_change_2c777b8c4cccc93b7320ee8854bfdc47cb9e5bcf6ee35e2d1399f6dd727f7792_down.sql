-- hilos-framework: {"format":1,"kind":"change","units":["notification_preference"],"parts":[{"changeId":"20261110021316313226_HIL-1923_notification_moments","unit":"notification_preference","upSha256":"f0d5f57356351a7dea605266eda168f34754ea2624690789ddba05cdcb319974","downSha256":"559fd3c422b7e8ba1dfbd1b8e431f0a4de81a38a1e36496b7ed7b0a96d44cc4a"}],"upSha256":"f0d5f57356351a7dea605266eda168f34754ea2624690789ddba05cdcb319974","downSha256":"559fd3c422b7e8ba1dfbd1b8e431f0a4de81a38a1e36496b7ed7b0a96d44cc4a"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_notification_preference` WHERE `created_at` < 1000 OR `created_at` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_notification_preference` ADD COLUMN `__hil1923_created_at` TIMESTAMP NULL, ADD COLUMN `__hil1923_updated_at` TIMESTAMP NULL;
    UPDATE `hilos_notification_preference` SET `__hil1923_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000)), `__hil1923_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_notification_preference` DROP COLUMN `created_at`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1923_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CHANGE COLUMN `__hil1923_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
END$$
DELIMITER ;
