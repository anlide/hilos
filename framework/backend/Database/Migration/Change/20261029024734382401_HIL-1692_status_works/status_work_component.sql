CREATE TABLE `hilos_status_work_component` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `work_id` BIGINT UNSIGNED NOT NULL,
    `component_key` VARCHAR(128) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_work_component` (`work_id`, `component_key`),
    KEY `idx_status_work_component_key` (`component_key`, `work_id`, `id`),
    CONSTRAINT `fk_status_work_component_work` FOREIGN KEY (`work_id`)
        REFERENCES `hilos_status_work` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
