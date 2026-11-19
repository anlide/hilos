-- Migration: Create category table
--
-- picture_file_id holds the registry row without a cascade on purpose: the database refusing
-- to remove a file a category still names is how the files library learns the shop links it.
-- slug compares byte for byte (utf8mb4_bin), the same way PHP compares the strings.

CREATE TABLE `category` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `position` INT UNSIGNED NOT NULL,
    `picture_file_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_category_name` (`name`),
    UNIQUE KEY `uk_category_slug` (`slug`),
    UNIQUE KEY `uk_category_picture` (`picture_file_id`),
    KEY `idx_category_position` (`position`),
    CONSTRAINT `fk_category_picture` FOREIGN KEY (`picture_file_id`) REFERENCES `hilos_file` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
