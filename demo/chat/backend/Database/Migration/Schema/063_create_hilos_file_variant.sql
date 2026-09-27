-- Adds the image variants of the files registry (HIL-141).
-- Mirrors the framework stub create_hilos_file_variant.sql.
-- Landed as 059 after 060/061 (HIL-300/303) and 062 (HIL-498), and the
-- migrator, which applies what is above MAX(index), skipped it on live
-- databases; renumbered 063. A database built from scratch since 0afc9f551
-- already has the table under 059, hence IF NOT EXISTS.

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
