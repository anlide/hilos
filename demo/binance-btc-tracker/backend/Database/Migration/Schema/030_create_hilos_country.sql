-- Migration: Create hilos_country table (stub/reference)
-- Copy this SQL to a project migration after hilos_language and before hilos_locale.
-- The locale stub adds the composite foreign key on default_locale_id once both tables exist.

CREATE TABLE `hilos_country` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `currency_symbol` VARCHAR(8) NOT NULL,
    `currency_code` CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `default_locale_id` INT UNSIGNED DEFAULT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_country_code` (`code`),
    KEY `idx_country_default_locale` (`id`, `default_locale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
