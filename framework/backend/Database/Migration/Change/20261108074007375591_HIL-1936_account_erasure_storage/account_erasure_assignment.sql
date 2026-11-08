-- Immutable coordinator assignments; owner addresses do not require a live agent.
CREATE TABLE IF NOT EXISTS `hilos_account_erasure_assignment` (
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
    UNIQUE KEY `uq_account_erasure_assignment` (`operation_id`, `stage`, `assignment_key`),
    KEY `idx_account_erasure_owner` (`owner_type`, `owner_index`, `id`),
    CONSTRAINT `fk_account_erasure_assignment_operation` FOREIGN KEY (`operation_id`)
        REFERENCES `hilos_account_erasure_operation` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_nopad_bin;
