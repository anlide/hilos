-- Durable erasure coordinator state; request and person references intentionally remain soft.
CREATE TABLE IF NOT EXISTS `hilos_account_erasure_operation` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT UNSIGNED NOT NULL,
    `root_user_id` INT UNSIGNED NOT NULL,
    `circle_user_ids` JSON NOT NULL,
    `stage` VARCHAR(64) COLLATE utf8mb4_nopad_bin NOT NULL,
    `created_at` BIGINT NOT NULL,
    `updated_at` BIGINT NOT NULL,
    `finished_at` BIGINT NULL,
    `outcome` VARCHAR(32) COLLATE utf8mb4_nopad_bin NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_erasure_request` (`request_id`),
    KEY `idx_account_erasure_open` (`finished_at`, `id`),
    KEY `idx_account_erasure_root` (`root_user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;
