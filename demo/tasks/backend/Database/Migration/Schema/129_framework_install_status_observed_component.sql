-- hilos-framework: {"format":1,"kind":"install","units":["status_observed_component"],"parts":[{"changeId":"20261114021837718689_HIL-1887_status_observed_component","unit":"status_observed_component","upSha256":"44505a6f93423ed002c25eb8777523b576b30bbd096a6fbd3ca9cce8337e3c74","downSha256":"c21849997d6a6641a4a31b7770acc0b8d5f6cd70bf6ebb7195c1e8eeacd6a7d6"}],"upSha256":"44505a6f93423ed002c25eb8777523b576b30bbd096a6fbd3ca9cce8337e3c74","downSha256":"c21849997d6a6641a4a31b7770acc0b8d5f6cd70bf6ebb7195c1e8eeacd6a7d6"}
CREATE TABLE `hilos_status_observed_component` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `component_key` VARCHAR(128) NOT NULL,
    `observed_since` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_observed_component_key` (`component_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
