-- Migration: Create hilos_country_name after language, country and locale.
CREATE TABLE `hilos_country_name` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `country_id` INT UNSIGNED NOT NULL,
    `language_id` INT UNSIGNED NOT NULL,
    `locale_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `locked` TINYINT(1) NOT NULL DEFAULT 0,
    `locale_slot` INT UNSIGNED GENERATED ALWAYS AS (COALESCE(`locale_id`, 0)) PERSISTENT,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_country_name_slot` (`country_id`, `language_id`, `locale_slot`),
    KEY `idx_country_name_language_id` (`language_id`),
    KEY `idx_country_name_locale` (`locale_id`),
    CONSTRAINT `fk_country_name_country_id` FOREIGN KEY (`country_id`) REFERENCES `hilos_country` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_country_name_language_id` FOREIGN KEY (`language_id`) REFERENCES `hilos_language` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_country_name_locale` FOREIGN KEY (`locale_id`) REFERENCES `hilos_locale` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
