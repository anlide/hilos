-- Migration: Repair the image variants table and the legal administration indexes (HIL-1238)
-- Created: 2026-10-01
-- Index: 072
--
-- Number 063 was taken by two files: 063_create_hilos_file_variant (HOTFIX H10
-- of 27.09, 021983fb4, the renumbered 059 of HIL-141) and
-- 063_add_legal_acceptance_document_index (HIL-941, 37584161c). The migrator
-- applied the first of them in alphabetical order and never the second, so a
-- database lacks one of the two depending on when it passed 063: a fresh one has
-- the indexes and no hilos_file_variant, one that passed H10 before HIL-941
-- landed has the table and no indexes. 063 keeps the indexes; this repair holds
-- on a database of either kind, hence IF NOT EXISTS on the table and on both keys.

CREATE TABLE IF NOT EXISTS `hilos_file_variant` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `file_id` INT UNSIGNED NOT NULL,
    `variant` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `signature` CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `stored_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `mime_type` VARCHAR(255) NOT NULL,
    `size` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_file_variant` (`file_id`, `variant`),
    UNIQUE KEY `uk_file_variant_stored_name` (`stored_name`),
    CONSTRAINT `fk_file_variant_file` FOREIGN KEY (`file_id`) REFERENCES `hilos_file` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `hilos_legal_acceptance`
    ADD KEY IF NOT EXISTS `idx_legal_acceptance_document_revision` (`document`, `revision_id`, `accepted_at`),
    ADD KEY IF NOT EXISTS `idx_legal_acceptance_accepted_at` (`accepted_at`);
