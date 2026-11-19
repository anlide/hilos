-- Migration: Create product_slug table
--
-- Every address a product has ever had, the current one too. A product is never deleted, so
-- an address always has its product; no cascade on product_id all the same.
-- slug compares byte for byte (utf8mb4_bin), the same way PHP compares the strings.

CREATE TABLE `product_slug` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_slug_slug` (`slug`),
    KEY `idx_product_slug_product` (`product_id`),
    CONSTRAINT `fk_product_slug_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
