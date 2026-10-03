-- Migration: Create hilos_data_export table (stub/reference)
-- Copy this SQL to the next free project schema migration.
-- One copy per account; a restore requiring anonymization purges the queue (HIL-303).

CREATE TABLE `hilos_data_export` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `state` ENUM('preparing','ready','failed') NOT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `finished_at` TIMESTAMP NULL DEFAULT NULL,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    `stored_name` VARCHAR(64) NULL DEFAULT NULL,
    `size_bytes` BIGINT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_data_export_user` (`user_id`),
    KEY `idx_data_export_state` (`state`, `requested_at`),
    KEY `idx_data_export_expires` (`expires_at`),
    CONSTRAINT `fk_data_export_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
