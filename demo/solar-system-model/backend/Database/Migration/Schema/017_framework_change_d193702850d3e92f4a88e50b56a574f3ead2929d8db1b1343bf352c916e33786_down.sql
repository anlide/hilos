-- hilos-framework: {"format":1,"kind":"change","units":["data_export"],"parts":[{"changeId":"20261114014747194725_HIL-1925_export_legal_moments","unit":"data_export","upSha256":"889a28799959427e963dbd165c55fa5c27ad887a5155bc2c9df2404ac6015ae2","downSha256":"e20a949dc37f1e6b4927b15d855c8c3b91d4da120689c23b85dbf1b98cac7d54"}],"upSha256":"889a28799959427e963dbd165c55fa5c27ad887a5155bc2c9df2404ac6015ae2","downSha256":"e20a949dc37f1e6b4927b15d855c8c3b91d4da120689c23b85dbf1b98cac7d54"}
DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_data_export` WHERE `requested_at` < 1000 OR `requested_at` > 2147483647999 OR `finished_at` < 1000 OR `finished_at` > 2147483647999 OR `expires_at` < 1000 OR `expires_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Data export moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_data_export`
        ADD COLUMN `__hil1925_requested_at` TIMESTAMP NULL DEFAULT NULL AFTER `requested_at`,
        ADD COLUMN `__hil1925_finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `finished_at`,
        ADD COLUMN `__hil1925_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `expires_at`;
    UPDATE `hilos_data_export` SET
        `__hil1925_requested_at` = FROM_UNIXTIME(FLOOR(`requested_at` / 1000)),
        `__hil1925_finished_at` = FROM_UNIXTIME(FLOOR(`finished_at` / 1000)),
        `__hil1925_expires_at` = FROM_UNIXTIME(FLOOR(`expires_at` / 1000));
    ALTER TABLE `hilos_data_export`
        DROP INDEX `idx_data_export_state`,
        DROP INDEX `idx_data_export_expires`,
        DROP COLUMN `requested_at`,
        CHANGE COLUMN `__hil1925_requested_at` `requested_at` TIMESTAMP NOT NULL,
        DROP COLUMN `finished_at`,
        CHANGE COLUMN `__hil1925_finished_at` `finished_at` TIMESTAMP NULL DEFAULT NULL,
        DROP COLUMN `expires_at`,
        CHANGE COLUMN `__hil1925_expires_at` `expires_at` TIMESTAMP NULL DEFAULT NULL,
        ADD KEY `idx_data_export_state` (`state`, `requested_at`),
        ADD KEY `idx_data_export_expires` (`expires_at`);
END$$
DELIMITER ;
