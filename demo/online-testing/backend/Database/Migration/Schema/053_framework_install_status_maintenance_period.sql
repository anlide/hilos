-- hilos-framework: {"format":1,"kind":"install","units":["status_maintenance_period"],"parts":[{"changeId":"20261029024734382401_HIL-1692_status_works","unit":"status_maintenance_period","upSha256":"8dc4bab2a471b20ecb718cbf4984856100afc7cddd6a6c953d646ae00c523ba6","downSha256":"d6b6e574d4506b07af6700b20c0e2cd45435b4a36e26f25625770df5adbc4510"}],"upSha256":"8dc4bab2a471b20ecb718cbf4984856100afc7cddd6a6c953d646ae00c523ba6","downSha256":"d6b6e574d4506b07af6700b20c0e2cd45435b4a36e26f25625770df5adbc4510"}
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
