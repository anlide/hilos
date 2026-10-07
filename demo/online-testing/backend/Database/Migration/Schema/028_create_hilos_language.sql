-- Migration: Create hilos_language table (stub/reference)
-- Copy this SQL to a project migration before hilos_locale.

CREATE TABLE `hilos_language` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `native_name` VARCHAR(64) NOT NULL,
    `rtl` TINYINT(1) NOT NULL DEFAULT 0,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_language_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
