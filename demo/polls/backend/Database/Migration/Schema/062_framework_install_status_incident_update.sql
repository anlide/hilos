-- hilos-framework: {"format":1,"kind":"install","units":["status_incident_update"],"parts":[{"changeId":"20261023115508508976_HIL-1688_status_machine","unit":"status_incident_update","upSha256":"35d51a1c7ba4541f3c2ac917420c4cfffc80aa59bad2dbeca82e6926d56599e8","downSha256":"3aeba9679051715e56397306feda3e4f64d30fd8c2c7c79b56f0cc21ccf9014a"}],"upSha256":"35d51a1c7ba4541f3c2ac917420c4cfffc80aa59bad2dbeca82e6926d56599e8","downSha256":"3aeba9679051715e56397306feda3e4f64d30fd8c2c7c79b56f0cc21ccf9014a"}
-- Immutable automatic and team updates; NULL request ids are untracked submissions (HIL-1688).
CREATE TABLE `hilos_status_incident_update` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `incident_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(32) NOT NULL,
    `severity` VARCHAR(32) NOT NULL,
    `text` TEXT NOT NULL,
    `at` BIGINT UNSIGNED NOT NULL,
    `request_id` VARCHAR(128) COLLATE utf8mb4_bin NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status_incident_update_timeline` (`incident_id`, `at`, `id`),
    UNIQUE KEY `uk_status_incident_update_request` (`incident_id`, `request_id`),
    CONSTRAINT `fk_status_incident_update_incident` FOREIGN KEY (`incident_id`)
        REFERENCES `hilos_status_incident` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
