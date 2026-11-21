-- Migration: Create user_product_cart table
--
-- The cart of a person with an account: one row per product the person put in it. user_id has
-- no cascade: the database refuses to remove a person whose cart still stands, and the person's
-- agent erases the cart in its own part of the erasure. product_id has no cascade either, as in
-- the guest carts: the database refuses to remove a product a cart still holds. quantity is above
-- zero: a product out of the cart has no row. The name follows the bridge rule: person x product,
-- plus what the pair is - a cart; the person leads, and the set is cut by it.

CREATE TABLE `user_product_cart` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_product_cart` (`user_id`, `product_id`),
    KEY `idx_user_product_cart_product` (`product_id`),
    CONSTRAINT `fk_user_product_cart_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_product_cart_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_user_product_cart_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
