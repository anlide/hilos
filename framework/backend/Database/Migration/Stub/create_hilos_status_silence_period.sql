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
