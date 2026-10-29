CREATE TABLE `hilos_status_maintenance_period` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(32) NOT NULL,
    `work_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `work_public_id` CHAR(32) NULL DEFAULT NULL,
    `announcement_known` BOOLEAN NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `component_keys_json` TEXT NOT NULL,
    `started_at` BIGINT UNSIGNED NOT NULL,
    `last_observed_at` BIGINT UNSIGNED NOT NULL,
    `ended_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `completion_observed_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_maintenance_period_public` (`public_id`),
    KEY `idx_status_maintenance_period_started` (`started_at` DESC, `id` DESC),
    KEY `idx_status_maintenance_period_completion` (`completion_observed_at`, `id`),
    KEY `idx_status_maintenance_period_work` (`work_id`, `id`),
    CONSTRAINT `fk_status_maintenance_period_work` FOREIGN KEY (`work_id`)
        REFERENCES `hilos_status_work` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
