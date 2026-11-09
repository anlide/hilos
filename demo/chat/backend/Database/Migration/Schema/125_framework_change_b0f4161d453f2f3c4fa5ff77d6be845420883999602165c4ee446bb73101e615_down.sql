-- hilos-framework: {"format":1,"kind":"change","units":["account_erasure_result"],"parts":[{"changeId":"20261109072435317522_HIL-1987_drop_account_erasure_tables","unit":"account_erasure_result","upSha256":"733a7d68e9fafbbd003ebb25cf3cceb821380b42eeee52a5563df70558338f13","downSha256":"da747c9bec6a3cfdfe2975db343d27f129d155f739b0c0311fb087044a2e740c"}],"upSha256":"733a7d68e9fafbbd003ebb25cf3cceb821380b42eeee52a5563df70558338f13","downSha256":"da747c9bec6a3cfdfe2975db343d27f129d155f739b0c0311fb087044a2e740c"}
-- Immutable participant preparations and results, one record per assignment.
-- The unique assignment key keeps a repeated completion on its original record.
-- CASCADE permits parent PURGE during anonymized restore; payloads and owner
-- addresses are personal data, so the entire result table is classified PURGE.
CREATE TABLE IF NOT EXISTS `hilos_account_erasure_result` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assignment_id` BIGINT UNSIGNED NOT NULL,
    `owner_agent_id` VARCHAR(383) COLLATE utf8mb4_bin NOT NULL,
    `payload_json` JSON NOT NULL,
    `created_at` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_erasure_result_assignment` (`assignment_id`),
    KEY `idx_account_erasure_result_owner` (`owner_agent_id`, `id`),
    CONSTRAINT `fk_account_erasure_result_assignment` FOREIGN KEY (`assignment_id`)
        REFERENCES `hilos_account_erasure_assignment` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
