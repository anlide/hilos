-- hilos-framework: {"format":1,"kind":"install","units":["status_silence_period"],"parts":[{"changeId":"20261105060052889668_HIL-1886_status_silence_period","unit":"status_silence_period","upSha256":"8d9f2ee0493d5c901d62a2fd6f4d20739d5b7687ea0cbb836aaec058437e181c","downSha256":"8327dd20a0851c98038a8bb028cceafc32714a6290936a91b701d3c34fb514b8"}],"upSha256":"8d9f2ee0493d5c901d62a2fd6f4d20739d5b7687ea0cbb836aaec058437e181c","downSha256":"8327dd20a0851c98038a8bb028cceafc32714a6290936a91b701d3c34fb514b8"}
CREATE TABLE `hilos_status_silence_period` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(32) NOT NULL,
    `started_at` BIGINT NOT NULL,
    `ended_at` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_status_silence_period_public` (`public_id`),
    KEY `idx_status_silence_period_ended` (`ended_at`, `id`),
    CONSTRAINT `ck_status_silence_period_bounds` CHECK (`ended_at` > `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
