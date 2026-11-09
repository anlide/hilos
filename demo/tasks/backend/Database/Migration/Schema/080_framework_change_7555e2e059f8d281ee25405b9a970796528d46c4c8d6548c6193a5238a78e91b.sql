-- hilos-framework: {"format":1,"kind":"change","units":["account_erasure_operation"],"parts":[{"changeId":"20261109072435317521_HIL-1987_account_operation_tables","unit":"account_erasure_operation","upSha256":"b45d411a90362b9cba870265e8071e4870dc3c69ff22ea7df450bedb44f1beba","downSha256":"76829ea1906bd7a43c979d586f230a1bdc15c7a6aa697a7c42dc1e659efb1a80"}],"upSha256":"b45d411a90362b9cba870265e8071e4870dc3c69ff22ea7df450bedb44f1beba","downSha256":"76829ea1906bd7a43c979d586f230a1bdc15c7a6aa697a7c42dc1e659efb1a80"}
-- Durable account operation coordinator state; request and person references intentionally remain soft.
CREATE TABLE IF NOT EXISTS `hilos_account_operation` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind` VARCHAR(16) COLLATE utf8mb4_nopad_bin NOT NULL,
    `request_id` INT UNSIGNED NULL,
    `root_user_id` INT UNSIGNED NOT NULL,
    `loser_user_id` INT UNSIGNED NULL,
    `merge_choice` JSON DEFAULT NULL,
    `circle_user_ids` JSON NOT NULL,
    `stage` VARCHAR(64) COLLATE utf8mb4_nopad_bin NOT NULL,
    `created_at` BIGINT NOT NULL,
    `updated_at` BIGINT NOT NULL,
    `finished_at` BIGINT NULL,
    `open_loser_user_id` INT UNSIGNED NULL,
    `outcome` VARCHAR(32) COLLATE utf8mb4_nopad_bin NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_operation_request` (`request_id`),
    UNIQUE KEY `uq_account_operation_open_loser` (`open_loser_user_id`),
    KEY `idx_account_operation_open` (`kind`, `finished_at`, `id`),
    KEY `idx_account_operation_root` (`root_user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;
