DROP TRIGGER IF EXISTS `hilos_cl_hilos_user_after_insert`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_user_after_update`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_user_after_delete`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_identity_after_insert`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_identity_after_update`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_identity_after_delete`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_second_factor_after_insert`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_second_factor_after_update`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_second_factor_after_delete`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_setting_after_insert`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_setting_after_update`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_setting_after_delete`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_moderation_rule_after_insert`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_moderation_rule_after_update`;
DROP TRIGGER IF EXISTS `hilos_cl_hilos_moderation_rule_after_delete`;

DROP TABLE IF EXISTS {{change_log_database}}.`__hil1929_hilos_change_log_receipt`;
DROP TABLE IF EXISTS {{change_log_database}}.`__hil1929_hilos_change_log`;
DROP TABLE IF EXISTS {{change_log_database}}.`__hil1929_hilos_change_log_change`;
DROP TABLE IF EXISTS {{change_log_database}}.`__hil1929_hilos_change_log_value`;

CREATE TABLE {{change_log_database}}.`__hil1929_hilos_change_log_receipt` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` BIGINT NOT NULL,
    `actor_user_id` INT UNSIGNED NULL,
    `subject_user_id` INT UNSIGNED NULL,
    `session_id` INT UNSIGNED NULL,
    `channel` VARCHAR(16) NOT NULL,
    `action` VARCHAR(255) NOT NULL,
    `agent` VARCHAR(255) NULL,
    `source` VARCHAR(512) NULL,
    `open_handovers` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`, `created_at`),
    KEY `idx_hcl_receipt_created` (`created_at`, `id`),
    KEY `idx_hcl_receipt_actor` (`actor_user_id`, `created_at`, `id`),
    KEY `idx_hcl_receipt_channel` (`channel`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (`created_at`) (
    PARTITION `p_future` VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE {{change_log_database}}.`__hil1929_hilos_change_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` BIGINT NOT NULL,
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

CREATE TABLE {{change_log_database}}.`__hil1929_hilos_change_log_change` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` BIGINT NOT NULL,
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

CREATE TABLE {{change_log_database}}.`__hil1929_hilos_change_log_value` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` BIGINT NOT NULL,
    `change_id` BIGINT UNSIGNED NOT NULL,
    `old_value` LONGTEXT NULL,
    `new_value` LONGTEXT NULL,
    PRIMARY KEY (`id`, `created_at`),
    UNIQUE KEY `uk_hcl_value_change` (`change_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (`created_at`) (
    PARTITION `p_future` VALUES LESS THAN (MAXVALUE)
);

INSERT INTO {{change_log_database}}.`__hil1929_hilos_change_log_receipt` (`id`, `created_at`, `actor_user_id`, `subject_user_id`, `session_id`, `channel`, `action`, `agent`, `source`, `open_handovers`)
SELECT `id`, TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `created_at`) DIV 1000, `actor_user_id`, `subject_user_id`, `session_id`, `channel`, `action`, `agent`, `source`, `open_handovers`
FROM {{change_log_database}}.`hilos_change_log_receipt`;

INSERT INTO {{change_log_database}}.`__hil1929_hilos_change_log` (`id`, `created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
SELECT `id`, TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `created_at`) DIV 1000, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`
FROM {{change_log_database}}.`hilos_change_log`;

INSERT INTO {{change_log_database}}.`__hil1929_hilos_change_log_change` (`id`, `created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
SELECT `id`, TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `created_at`) DIV 1000, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`
FROM {{change_log_database}}.`hilos_change_log_change`;

INSERT INTO {{change_log_database}}.`__hil1929_hilos_change_log_value` (`id`, `created_at`, `change_id`, `old_value`, `new_value`)
SELECT `id`, TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `created_at`) DIV 1000, `change_id`, `old_value`, `new_value`
FROM {{change_log_database}}.`hilos_change_log_value`;

RENAME TABLE
    {{change_log_database}}.`hilos_change_log_receipt` TO {{change_log_database}}.`__hil1929_old_hilos_change_log_receipt`,
    {{change_log_database}}.`__hil1929_hilos_change_log_receipt` TO {{change_log_database}}.`hilos_change_log_receipt`,
    {{change_log_database}}.`hilos_change_log` TO {{change_log_database}}.`__hil1929_old_hilos_change_log`,
    {{change_log_database}}.`__hil1929_hilos_change_log` TO {{change_log_database}}.`hilos_change_log`,
    {{change_log_database}}.`hilos_change_log_change` TO {{change_log_database}}.`__hil1929_old_hilos_change_log_change`,
    {{change_log_database}}.`__hil1929_hilos_change_log_change` TO {{change_log_database}}.`hilos_change_log_change`,
    {{change_log_database}}.`hilos_change_log_value` TO {{change_log_database}}.`__hil1929_old_hilos_change_log_value`,
    {{change_log_database}}.`__hil1929_hilos_change_log_value` TO {{change_log_database}}.`hilos_change_log_value`;

DROP TABLE {{change_log_database}}.`__hil1929_old_hilos_change_log_receipt`;
DROP TABLE {{change_log_database}}.`__hil1929_old_hilos_change_log`;
DROP TABLE {{change_log_database}}.`__hil1929_old_hilos_change_log_change`;
DROP TABLE {{change_log_database}}.`__hil1929_old_hilos_change_log_value`;
