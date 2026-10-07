-- Migration: Create hilos_locale table (stub/reference)
-- Copy this SQL to a project migration after hilos_language and hilos_country.
-- The composite country key permits only a locale of that country as its default;
-- a null default_locale_id is not checked by the key.

CREATE TABLE `hilos_locale` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `language_id` INT UNSIGNED NOT NULL,
    `country_id` INT UNSIGNED DEFAULT NULL,
    `date_format` VARCHAR(32) NOT NULL,
    `time_format` VARCHAR(32) NOT NULL,
    `number_format` VARCHAR(32) NOT NULL,
    `phone_format` VARCHAR(32) NOT NULL,
    `address_format` VARCHAR(64) NOT NULL,
    `measurement_system` ENUM('metric', 'imperial') NOT NULL,
    `collation` VARCHAR(32) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_locale_code` (`code`),
    UNIQUE KEY `uk_locale_pair` (`language_id`, `country_id`),
    KEY `idx_locale_country` (`country_id`, `id`),
    CONSTRAINT `fk_locale_language` FOREIGN KEY (`language_id`) REFERENCES `hilos_language` (`id`),
    CONSTRAINT `fk_locale_country` FOREIGN KEY (`country_id`) REFERENCES `hilos_country` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `hilos_country`
    ADD CONSTRAINT `fk_country_default_locale` FOREIGN KEY (`id`, `default_locale_id`)
        REFERENCES `hilos_locale` (`country_id`, `id`);
