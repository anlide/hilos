-- hilos-framework: {"format":1,"kind":"change","units":["access_log"],"parts":[{"changeId":"20261110024242374685_HIL-1924_people_moments","unit":"access_log","upSha256":"985119b2b3087d2a79507f6488053d92f4ef5bca0b93ea44559ffc152fa2f739","downSha256":"97c6dc54aa24641cc4261656213525dde52fd3e43bbf0ba7c1905717b992df8f"}],"upSha256":"985119b2b3087d2a79507f6488053d92f4ef5bca0b93ea44559ffc152fa2f739","downSha256":"97c6dc54aa24641cc4261656213525dde52fd3e43bbf0ba7c1905717b992df8f"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_access_log` WHERE `occurred_at` < 1000 OR `occurred_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_access_log` ADD COLUMN `__hil1924_occurred_at` TIMESTAMP NULL DEFAULT NULL AFTER `occurred_at`;
    UPDATE `hilos_access_log` SET `__hil1924_occurred_at` = FROM_UNIXTIME(FLOOR(`occurred_at` / 1000));
    ALTER TABLE `hilos_access_log`
        DROP INDEX `idx_access_log_user`,
        DROP INDEX `idx_access_log_occurred`,
        DROP COLUMN `occurred_at`,
        CHANGE COLUMN `__hil1924_occurred_at` `occurred_at` TIMESTAMP NOT NULL,
        ADD KEY `idx_access_log_user` (`user_id`, `occurred_at`),
        ADD KEY `idx_access_log_occurred` (`occurred_at`);
END$$
DELIMITER ;
