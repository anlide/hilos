-- HIL-1445: journal tables in the separately provisioned change log database.
-- Keep the primary migration track; the known token is expanded by Migration.

-- The applied 016 migration may contain history. Refuse to discard any of it.
DELIMITER $$
CREATE OR REPLACE PROCEDURE `hilos_migration_085_old_log_empty`()
BEGIN
    IF EXISTS (SELECT 1 FROM `hilos_change_log_table`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_table is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM `hilos_change_log_field`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_field is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM `hilos_change_log`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM `hilos_change_log_value`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_value is not empty';
    END IF;
END$$
DELIMITER ;
CALL `hilos_migration_085_old_log_empty`();
DROP PROCEDURE `hilos_migration_085_old_log_empty`;

CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log_table` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_hcl_table_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log_field` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `table_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_hcl_field_table_name` (`table_id`, `name`),
    KEY `idx_hcl_field_table` (`table_id`),
    CONSTRAINT `fk_hcl_field_table` FOREIGN KEY (`table_id`)
        REFERENCES {{change_log_database}}.`hilos_change_log_table` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log_receipt` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME(6) NOT NULL,
    `actor_user_id` INT UNSIGNED NULL,
    `subject_user_id` INT UNSIGNED NULL,
    `session_id` INT UNSIGNED NULL,
    `channel` VARCHAR(16) NOT NULL,
    `action` VARCHAR(255) NOT NULL,
    `agent` VARCHAR(255) NULL,
    `source` VARCHAR(512) NULL,
    PRIMARY KEY (`id`, `created_at`),
    KEY `idx_hcl_receipt_created` (`created_at`, `id`),
    KEY `idx_hcl_receipt_actor` (`actor_user_id`, `created_at`, `id`),
    KEY `idx_hcl_receipt_channel` (`channel`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (`created_at`) (
    PARTITION `p_future` VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME(6) NOT NULL,
    `receipt_id` BIGINT UNSIGNED NULL,
    `table_id` INT UNSIGNED NOT NULL,
    `record_key` TEXT NOT NULL,
    `record_key_hash` BINARY(32) NULL,
    `mutation_type` ENUM('create', 'update', 'delete') NOT NULL,
    PRIMARY KEY (`id`, `created_at`),
    KEY `idx_hcl_receipt` (`receipt_id`, `created_at`, `id`),
    KEY `idx_hcl_table_created` (`table_id`, `created_at`, `id`),
    KEY `idx_hcl_table_record` (`table_id`, `record_key_hash`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (`created_at`) (
    PARTITION `p_future` VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log_change` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME(6) NOT NULL,
    `log_id` BIGINT UNSIGNED NOT NULL,
    `field_id` INT UNSIGNED NOT NULL,
    `kind` ENUM('inline', 'fact', 'long') NOT NULL,
    `old_present` TINYINT(1) NOT NULL,
    `new_present` TINYINT(1) NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    PRIMARY KEY (`id`, `created_at`),
    KEY `idx_hcl_change_log` (`log_id`, `created_at`, `id`),
    KEY `idx_hcl_change_field` (`field_id`, `created_at`, `log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (`created_at`) (
    PARTITION `p_future` VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS {{change_log_database}}.`hilos_change_log_value` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME(6) NOT NULL,
    `change_id` BIGINT UNSIGNED NOT NULL,
    `old_value` LONGTEXT NULL,
    `new_value` LONGTEXT NULL,
    PRIMARY KEY (`id`, `created_at`),
    UNIQUE KEY `uk_hcl_value_change` (`change_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (`created_at`) (
    PARTITION `p_future` VALUES LESS THAN (MAXVALUE)
);

DROP TABLE `hilos_change_log_value`;
DROP TABLE `hilos_change_log`;
DROP TABLE `hilos_change_log_field`;
DROP TABLE `hilos_change_log_table`;
