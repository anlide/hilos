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
