-- Migration: Create voucher table
--
-- The shop stores codes in uppercase. The ascii_bin collation compares them byte for byte,
-- the same way PHP and the ORM compare strings in memory. The shop normalizes spring10 and
-- SPRING10 to the same code before saving. The voucher library can create and update rows.

CREATE TABLE `voucher` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `discount_percent` TINYINT UNSIGNED NOT NULL,
    `active` TINYINT(1) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_voucher_code` (`code`),
    CONSTRAINT `chk_voucher_discount_percent` CHECK (`discount_percent` BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
