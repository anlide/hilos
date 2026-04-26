-- Migration: Create Hilos change log tables (stub/reference)
-- Copy this SQL to project migration (e.g. 016_create_hilos_change_log.sql)
--
-- Trigger naming convention: hilos_cl_{table}_{after_insert|after_update|after_delete}
--
-- TODO: [change-log] Retention must be explicitly configured in application config:
--       forever | 100 days | 1 year | 10 years. Implement a cron/daemon job to purge
--       expired rows from hilos_change_log (and related hilos_change_log_value rows).
--
-- When track_values is false for a field, leave old_value/new_value NULL on hilos_change_log (fact-only).
-- When values exceed the configured size threshold, store them in hilos_change_log_value (MEDIUMTEXT)
-- and leave main row old_value/new_value NULL.

CREATE TABLE `hilos_change_log_table` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_hcl_table_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_change_log_field` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `table_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_hcl_field_table_name` (`table_id`, `name`),
    KEY `idx_hcl_field_table` (`table_id`),
    CONSTRAINT `fk_hcl_field_table` FOREIGN KEY (`table_id`) REFERENCES `hilos_change_log_table` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_change_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `table_id` INT UNSIGNED NOT NULL,
    `record_id` BIGINT UNSIGNED NOT NULL,
    `field_id` INT UNSIGNED NOT NULL,
    `mutation_type` ENUM('create', 'update', 'delete') NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_hcl_table_record` (`table_id`, `record_id`),
    KEY `idx_hcl_batch` (`batch_id`),
    KEY `idx_hcl_created_at` (`created_at`),
    KEY `idx_hcl_user` (`user_id`),
    KEY `idx_hcl_field` (`field_id`),
    CONSTRAINT `fk_hcl_log_table` FOREIGN KEY (`table_id`) REFERENCES `hilos_change_log_table` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_hcl_log_field` FOREIGN KEY (`field_id`) REFERENCES `hilos_change_log_field` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_change_log_value` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `log_id` BIGINT UNSIGNED NOT NULL,
    `old_value` MEDIUMTEXT DEFAULT NULL,
    `new_value` MEDIUMTEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_hcl_value_log` (`log_id`),
    CONSTRAINT `fk_hcl_value_log` FOREIGN KEY (`log_id`) REFERENCES `hilos_change_log` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
