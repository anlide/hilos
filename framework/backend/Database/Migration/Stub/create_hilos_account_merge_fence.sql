CREATE TABLE IF NOT EXISTS `hilos_account_merge_fence` (
    `collection_key` VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
    `row_id` BIGINT UNSIGNED NOT NULL,
    `operation_id` BIGINT UNSIGNED NOT NULL,
    `assignment_id` BIGINT UNSIGNED NOT NULL,
    `owner_user_id` BIGINT UNSIGNED NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL,
    `deleted` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`collection_key`, `row_id`),
    KEY `idx_account_merge_fence_owner` (`owner_user_id`, `collection_key`, `row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
