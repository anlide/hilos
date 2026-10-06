-- Drop the separate journal schema's tables after checking that no history remains.

DELIMITER $$
CREATE OR REPLACE PROCEDURE `hilos_migration_085_new_log_empty`()
BEGIN
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_table`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_table is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_field`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_field is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_receipt`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_receipt is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_change`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_change is not empty';
    END IF;
    IF EXISTS (SELECT 1 FROM {{change_log_database}}.`hilos_change_log_value`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'hilos_change_log_value is not empty';
    END IF;
END$$
DELIMITER ;
CALL `hilos_migration_085_new_log_empty`();
DROP PROCEDURE `hilos_migration_085_new_log_empty`;

DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_value`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_change`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_receipt`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_field`;
DROP TABLE IF EXISTS {{change_log_database}}.`hilos_change_log_table`;
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
