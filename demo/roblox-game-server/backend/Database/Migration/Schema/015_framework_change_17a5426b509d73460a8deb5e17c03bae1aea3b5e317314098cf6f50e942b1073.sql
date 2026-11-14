-- hilos-framework: {"format":1,"kind":"change","units":["legal_acceptance_export"],"parts":[{"changeId":"20261114014747194725_HIL-1925_export_legal_moments","unit":"legal_acceptance_export","upSha256":"697e18369f1c65c624a10e115143ac0e19ac266656992750a5d295acde4604c2","downSha256":"3c365aa20bb3a6ff32f2742cac40dba163766558dcc08589972eb98ca504fbcc"}],"upSha256":"697e18369f1c65c624a10e115143ac0e19ac266656992750a5d295acde4604c2","downSha256":"3c365aa20bb3a6ff32f2742cac40dba163766558dcc08589972eb98ca504fbcc"}
ALTER TABLE `hilos_legal_acceptance_export`
    ADD COLUMN `__hil1925_requested_at` BIGINT NULL AFTER `requested_at`,
    ADD COLUMN `__hil1925_finished_at` BIGINT NULL AFTER `finished_at`,
    ADD COLUMN `__hil1925_expires_at` BIGINT NULL AFTER `expires_at`;
UPDATE `hilos_legal_acceptance_export` SET
    `__hil1925_requested_at` = UNIX_TIMESTAMP(`requested_at`) * 1000,
    `__hil1925_finished_at` = UNIX_TIMESTAMP(`finished_at`) * 1000,
    `__hil1925_expires_at` = UNIX_TIMESTAMP(`expires_at`) * 1000;
ALTER TABLE `hilos_legal_acceptance_export`
    DROP INDEX `idx_legal_acceptance_export_state`,
    DROP INDEX `idx_legal_acceptance_export_expires`,
    DROP COLUMN `requested_at`,
    CHANGE COLUMN `__hil1925_requested_at` `requested_at` BIGINT NOT NULL,
    DROP COLUMN `finished_at`,
    CHANGE COLUMN `__hil1925_finished_at` `finished_at` BIGINT NULL DEFAULT NULL,
    DROP COLUMN `expires_at`,
    CHANGE COLUMN `__hil1925_expires_at` `expires_at` BIGINT NULL DEFAULT NULL,
    ADD KEY `idx_legal_acceptance_export_state` (`state`, `requested_at`),
    ADD KEY `idx_legal_acceptance_export_expires` (`expires_at`);
