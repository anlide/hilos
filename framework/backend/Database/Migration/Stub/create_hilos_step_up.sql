-- Migration: Create hilos_step_up table (stub/reference)
-- Copy this SQL to a project migration (e.g. 0NN_create_hilos_step_up.sql).
--
-- One row confirms one operation for one person in one browser until a moment (HIL-495).
-- The browser is the SHA-256 hash of its session token. A fresh sign-in rotates that token,
-- so confirmations from the old signed-in life stop matching without a logout write.

CREATE TABLE `hilos_step_up` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `operation` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `confirmed_until` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_step_up` (`session_token_hash`, `user_id`, `operation`),
    KEY `idx_step_up_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
