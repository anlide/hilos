-- Migration: Create hilos_file_variant table (stub/reference)
-- Copy this SQL to a project migration after hilos_file.
--
-- Image copies of registry files (HIL-141), under their own names in the same storage.
-- Only the files library writes these rows. It removes them BEFORE the original row:
-- CASCADE is a backstop, not a project link that would make the janitor mark a file bound.
-- A signature different from the declared variant's settings asks for a new rendering.

CREATE TABLE `hilos_file_variant` (
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
