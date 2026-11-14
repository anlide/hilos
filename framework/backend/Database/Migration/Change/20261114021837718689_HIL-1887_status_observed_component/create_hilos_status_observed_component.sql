CREATE TABLE `hilos_status_observed_component` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `component_key` VARCHAR(128) NOT NULL,
    `observed_since` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_observed_component_key` (`component_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
