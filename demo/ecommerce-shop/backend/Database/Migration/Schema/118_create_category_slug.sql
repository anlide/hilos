-- Migration: Create category_slug table
--
-- Every address a category has ever had, the current one too. No cascade on category_id: the
-- addresses of a deleted category stay, and NULL means the address is out of use for good.
-- slug compares byte for byte (utf8mb4_bin), the same way PHP compares the strings.

CREATE TABLE `category_slug` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `category_id` INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_category_slug_slug` (`slug`),
    KEY `idx_category_slug_category` (`category_id`),
    CONSTRAINT `fk_category_slug_category` FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
