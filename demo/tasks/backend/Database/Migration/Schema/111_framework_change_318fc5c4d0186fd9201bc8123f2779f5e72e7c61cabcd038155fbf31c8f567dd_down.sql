-- hilos-framework: {"format":1,"kind":"change","units":["auth_block"],"parts":[{"changeId":"20261111013234414363_HIL-1921_auth_proof_moments","unit":"auth_block","upSha256":"2eca5f099d1f7088fb55f77c7c8e1b6b0530851d227408e1586f37dba441489f","downSha256":"0a0e899c1f66786c106d528bbd3a4fc5cd1636e139ab7e21f8e802f4a80a4561"}],"upSha256":"2eca5f099d1f7088fb55f77c7c8e1b6b0530851d227408e1586f37dba441489f","downSha256":"0a0e899c1f66786c106d528bbd3a4fc5cd1636e139ab7e21f8e802f4a80a4561"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_auth_block` WHERE `blocked_until` < 1000 OR `blocked_until` > 2147483647999 OR `updated_at` < 1000 OR `updated_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_auth_block` ADD COLUMN `__hil1921_blocked_until` TIMESTAMP NULL DEFAULT NULL AFTER `blocked_until`, ADD COLUMN `__hil1921_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
    UPDATE `hilos_auth_block` SET `__hil1921_blocked_until` = FROM_UNIXTIME(FLOOR(`blocked_until` / 1000)), `__hil1921_updated_at` = FROM_UNIXTIME(FLOOR(`updated_at` / 1000));
    ALTER TABLE `hilos_auth_block` DROP COLUMN `blocked_until`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1921_blocked_until` `blocked_until` TIMESTAMP NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_updated_at` `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
END$$
DELIMITER ;
