-- hilos-framework: {"format":1,"kind":"change","units":["user_photo"],"parts":[{"changeId":"20261110024242374685_HIL-1924_people_moments","unit":"user_photo","upSha256":"4cb961f48d510c0f819c7d1dcf96afed0d3bc8e83004cab225eebda84b692fa7","downSha256":"da945063455e69a23407be1b9896b1d3f34cdf09af7f38c3633c1948d711b18a"}],"upSha256":"4cb961f48d510c0f819c7d1dcf96afed0d3bc8e83004cab225eebda84b692fa7","downSha256":"da945063455e69a23407be1b9896b1d3f34cdf09af7f38c3633c1948d711b18a"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_user_photo` WHERE `set_at` < 1000 OR `set_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_user_photo` ADD COLUMN `__hil1924_set_at` TIMESTAMP NULL DEFAULT NULL AFTER `set_at`;
    UPDATE `hilos_user_photo` SET `__hil1924_set_at` = FROM_UNIXTIME(FLOOR(`set_at` / 1000));
    ALTER TABLE `hilos_user_photo` DROP COLUMN `set_at`, CHANGE COLUMN `__hil1924_set_at` `set_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
