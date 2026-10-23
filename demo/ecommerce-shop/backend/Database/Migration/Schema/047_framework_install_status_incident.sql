-- hilos-framework: {"format":1,"kind":"install","units":["status_incident"],"parts":[{"changeId":"20261023115508508976_HIL-1688_status_machine","unit":"status_incident","upSha256":"503666fbd66cfb715bd36ba0d8a37d27b934f0637c2e7fae0b16cf2128183109","downSha256":"5a90a8f3305d729279f7e117a8e7cd451fb761515549f258347d5346e88e485b"}],"upSha256":"503666fbd66cfb715bd36ba0d8a37d27b934f0637c2e7fae0b16cf2128183109","downSha256":"5a90a8f3305d729279f7e117a8e7cd451fb761515549f258347d5346e88e485b"}
-- Durable candidate and public incident history of the Status machine (HIL-1688).
CREATE TABLE `hilos_status_incident` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `component_key` VARCHAR(128) NOT NULL,
    `component_name` VARCHAR(255) NOT NULL,
    `active_component_key` VARCHAR(128) NULL DEFAULT NULL,
    `severity` VARCHAR(32) NOT NULL,
    `peak_severity` VARCHAR(32) NULL DEFAULT NULL,
    `detected_at` BIGINT UNSIGNED NOT NULL,
    `publish_at` BIGINT UNSIGNED NOT NULL,
    `published_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `suppressed_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `resolved_at` BIGINT UNSIGNED NULL DEFAULT NULL,
    `outcome` VARCHAR(32) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_incident_active_component` (`active_component_key`),
    KEY `idx_status_incident_component_detected` (`component_key`, `detected_at` DESC, `id` DESC),
    KEY `idx_status_incident_published` (`published_at`, `id`),
    KEY `idx_status_incident_resolved` (`resolved_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
