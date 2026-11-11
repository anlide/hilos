-- hilos-framework: {"format":1,"kind":"change","units":["step_up"],"parts":[{"changeId":"20261111013234414363_HIL-1921_auth_proof_moments","unit":"step_up","upSha256":"ac967b1aa091fbc6eba71c25e7a6cf216c772d70d9d2e20ea80ac0a51f0f2419","downSha256":"96b3ccc611dc67297027710ab03dd04e73778aa02354a9becccdb739d0287550"}],"upSha256":"ac967b1aa091fbc6eba71c25e7a6cf216c772d70d9d2e20ea80ac0a51f0f2419","downSha256":"96b3ccc611dc67297027710ab03dd04e73778aa02354a9becccdb739d0287550"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_step_up` WHERE `confirmed_until` < 1000 OR `confirmed_until` > 2147483647999 OR `created_at` < 1000 OR `created_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auth proof moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_step_up` ADD COLUMN `__hil1921_confirmed_until` TIMESTAMP NULL DEFAULT NULL AFTER `confirmed_until`, ADD COLUMN `__hil1921_created_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`;
    UPDATE `hilos_step_up` SET `__hil1921_confirmed_until` = FROM_UNIXTIME(FLOOR(`confirmed_until` / 1000)), `__hil1921_created_at` = FROM_UNIXTIME(FLOOR(`created_at` / 1000));
    ALTER TABLE `hilos_step_up` DROP COLUMN `confirmed_until`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_confirmed_until` `confirmed_until` TIMESTAMP NOT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
END$$
DELIMITER ;
