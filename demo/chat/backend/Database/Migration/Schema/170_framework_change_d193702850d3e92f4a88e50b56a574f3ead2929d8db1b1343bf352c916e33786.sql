-- hilos-framework: {"format":1,"kind":"change","units":["data_export"],"parts":[{"changeId":"20261114014747194725_HIL-1925_export_legal_moments","unit":"data_export","upSha256":"889a28799959427e963dbd165c55fa5c27ad887a5155bc2c9df2404ac6015ae2","downSha256":"e20a949dc37f1e6b4927b15d855c8c3b91d4da120689c23b85dbf1b98cac7d54"}],"upSha256":"889a28799959427e963dbd165c55fa5c27ad887a5155bc2c9df2404ac6015ae2","downSha256":"e20a949dc37f1e6b4927b15d855c8c3b91d4da120689c23b85dbf1b98cac7d54"}
ALTER TABLE `hilos_data_export`
    ADD COLUMN `__hil1925_requested_at` BIGINT NULL AFTER `requested_at`,
    ADD COLUMN `__hil1925_finished_at` BIGINT NULL AFTER `finished_at`,
    ADD COLUMN `__hil1925_expires_at` BIGINT NULL AFTER `expires_at`;
UPDATE `hilos_data_export` SET
    `__hil1925_requested_at` = UNIX_TIMESTAMP(`requested_at`) * 1000,
    `__hil1925_finished_at` = UNIX_TIMESTAMP(`finished_at`) * 1000,
    `__hil1925_expires_at` = UNIX_TIMESTAMP(`expires_at`) * 1000;
ALTER TABLE `hilos_data_export`
    DROP INDEX `idx_data_export_state`,
    DROP INDEX `idx_data_export_expires`,
    DROP COLUMN `requested_at`,
    CHANGE COLUMN `__hil1925_requested_at` `requested_at` BIGINT NOT NULL,
    DROP COLUMN `finished_at`,
    CHANGE COLUMN `__hil1925_finished_at` `finished_at` BIGINT NULL DEFAULT NULL,
    DROP COLUMN `expires_at`,
    CHANGE COLUMN `__hil1925_expires_at` `expires_at` BIGINT NULL DEFAULT NULL,
    ADD KEY `idx_data_export_state` (`state`, `requested_at`),
    ADD KEY `idx_data_export_expires` (`expires_at`);
