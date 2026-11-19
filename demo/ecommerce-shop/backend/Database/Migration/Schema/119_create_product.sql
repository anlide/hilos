-- Migration: Create product table
--
-- category_id and picture_file_id have no cascade: the database refuses to remove a category or
-- a file a product still names. price is in kopecks, a whole number: no fractions in the database.
-- slug compares byte for byte (utf8mb4_bin), the same way PHP compares the strings.
-- changed_at is epoch milliseconds, written by the application.

CREATE TABLE `product` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `kind` ENUM('unique', 'stock') NOT NULL,
    `price` INT UNSIGNED NOT NULL,
    `description` VARCHAR(1000) NOT NULL,
    `picture_file_id` INT UNSIGNED NOT NULL,
    `slug` VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `changed_at` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_slug` (`slug`),
    UNIQUE KEY `uk_product_picture` (`picture_file_id`),
    KEY `idx_product_category` (`category_id`),
    KEY `idx_product_changed` (`changed_at`),
    CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_product_picture` FOREIGN KEY (`picture_file_id`) REFERENCES `hilos_file` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
