-- Migration: Create hilos_legal_acceptance_export table (stub/reference)
-- Copy this SQL to the next free project schema migration.
-- One file of acceptance records per administrator; a restore requiring anonymization purges the queue (HIL-1234).

CREATE TABLE `hilos_legal_acceptance_export` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `state` ENUM('preparing','ready','failed') NOT NULL,
    `document` VARCHAR(16) NULL DEFAULT NULL,
    `revision_id` VARCHAR(32) NULL DEFAULT NULL,
    `search` VARCHAR(200) NULL DEFAULT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `finished_at` TIMESTAMP NULL DEFAULT NULL,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    `stored_name` VARCHAR(64) NULL DEFAULT NULL,
    `size_bytes` BIGINT UNSIGNED NULL DEFAULT NULL,
    `records` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_legal_acceptance_export_user` (`user_id`),
    KEY `idx_legal_acceptance_export_state` (`state`, `requested_at`),
    KEY `idx_legal_acceptance_export_expires` (`expires_at`),
    CONSTRAINT `fk_legal_acceptance_export_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
