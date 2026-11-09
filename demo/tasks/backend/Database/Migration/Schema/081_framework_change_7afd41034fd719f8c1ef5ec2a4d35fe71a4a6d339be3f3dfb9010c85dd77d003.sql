-- hilos-framework: {"format":1,"kind":"change","units":["account_erasure_assignment"],"parts":[{"changeId":"20261109072435317521_HIL-1987_account_operation_tables","unit":"account_erasure_assignment","upSha256":"fe67ccd3c07a0a5672487f803cc9eac53a9af1fcd832d52112baa14ef612e65a","downSha256":"dd8dbcc1eaab1fe1dd3226b5679d59e89b38023df513778531e6074072fe3e4f"}],"upSha256":"fe67ccd3c07a0a5672487f803cc9eac53a9af1fcd832d52112baa14ef612e65a","downSha256":"dd8dbcc1eaab1fe1dd3226b5679d59e89b38023df513778531e6074072fe3e4f"}
-- Immutable coordinator assignments; owner addresses do not require a live agent.
CREATE TABLE IF NOT EXISTS `hilos_account_operation_assignment` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `operation_id` BIGINT UNSIGNED NOT NULL,
    `stage` VARCHAR(64) COLLATE utf8mb4_nopad_bin NOT NULL,
    `assignment_key` VARCHAR(191) COLLATE utf8mb4_nopad_bin NOT NULL,
    `owner_type` VARCHAR(191) COLLATE utf8mb4_nopad_bin NOT NULL,
    `owner_index` VARCHAR(191) COLLATE utf8mb4_nopad_bin NULL,
    `user_id` INT UNSIGNED NULL,
    `payload_json` JSON NOT NULL,
    `created_at` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_operation_assignment` (`operation_id`, `stage`, `assignment_key`),
    KEY `idx_account_operation_owner` (`owner_type`, `owner_index`, `id`),
    CONSTRAINT `fk_account_operation_assignment_operation` FOREIGN KEY (`operation_id`)
        REFERENCES `hilos_account_operation` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;
