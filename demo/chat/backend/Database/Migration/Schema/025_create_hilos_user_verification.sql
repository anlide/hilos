-- Activates the framework hilos_user_verification table (HIL-365) for demo/chat.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_user_verification.sql;
-- backs the chat email-confirm and password-recovery reference flows.

CREATE TABLE `hilos_user_verification` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `type` ENUM('register_confirm', 'password_reset', 'email_change') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `code_hash` VARCHAR(255) DEFAULT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uv_type_identifier` (`type`, `identifier`),
    KEY `idx_uv_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
