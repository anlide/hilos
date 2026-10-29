-- hilos-framework: {"format":1,"kind":"install","units":["status_work_component"],"parts":[{"changeId":"20261029024734382401_HIL-1692_status_works","unit":"status_work_component","upSha256":"59455df91665b9d6ae0b9b4673e57513569bf9ec8c049b7efba433a0dfd6b63a","downSha256":"52067ca9080c10ccfd86acd5346baf0d897a6352897f3ff095454f50bb9660f5"}],"upSha256":"59455df91665b9d6ae0b9b4673e57513569bf9ec8c049b7efba433a0dfd6b63a","downSha256":"52067ca9080c10ccfd86acd5346baf0d897a6352897f3ff095454f50bb9660f5"}
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
