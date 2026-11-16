CREATE TABLE IF NOT EXISTS `hilos_account_merge_copy` (
    `assignment_id` BIGINT UNSIGNED NOT NULL,
    `owner_agent_id` VARCHAR(383) COLLATE utf8mb4_bin NOT NULL,
    `payload_json` JSON NULL DEFAULT NULL,
    `created_at` BIGINT NOT NULL,
    PRIMARY KEY (`assignment_id`),
    KEY `idx_account_merge_copy_owner` (`owner_agent_id`, `assignment_id`),
    CONSTRAINT `fk_account_merge_copy_assignment` FOREIGN KEY (`assignment_id`)
        REFERENCES `hilos_account_operation_assignment` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
