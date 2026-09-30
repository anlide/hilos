-- Migration: Create hilos_legal_acceptance table
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_legal_acceptance.sql.
-- An immutable acceptance of one exact revision (HIL-498). Repeats create no new row.
-- Erased with the account; account merging leaves it with the person who accepted.

CREATE TABLE `hilos_legal_acceptance` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `document` VARCHAR(16) NOT NULL,
    `revision_id` VARCHAR(32) NOT NULL,
    `accepted_at` TIMESTAMP NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_legal_acceptance_user_document_revision` (`user_id`, `document`, `revision_id`),
    KEY `idx_legal_acceptance_document_revision` (`document`, `revision_id`, `accepted_at`),
    KEY `idx_legal_acceptance_accepted_at` (`accepted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
