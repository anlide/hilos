-- Migration: Create product_warehouse_stock table
--
-- Neither reference has a cascade: the database refuses to remove a warehouse or a product a row
-- still names. units is above zero: no row means no units. That a unique piece is one row of one
-- unit is the rule of the stock library, not of the table.

CREATE TABLE `product_warehouse_stock` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `units` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_warehouse_stock` (`product_id`, `warehouse_id`),
    KEY `idx_product_warehouse_stock_warehouse` (`warehouse_id`),
    CONSTRAINT `fk_product_warehouse_stock_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_product_warehouse_stock_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouse` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_product_warehouse_stock_units` CHECK (`units` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
