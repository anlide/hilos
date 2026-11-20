-- Migration: Create session_product_cart table
--
-- The cart of a visitor without an account: one row per product the browser session put in it.
-- session_id is the number of a hilos_session row with no foreign key: RESTRICT would stop the
-- sweep of a session that still has a cart, and CASCADE would delete rows past their owner and
-- past its memory. The rows of swept sessions are removed by the guest carts library, on the
-- sweep frame. product_id has no cascade: the database refuses to remove a product a cart still
-- holds. quantity is above zero: a product out of the cart has no row. The name follows the
-- bridge rule: session x product, plus what the pair is - a cart; the session leads, and the
-- set is cut by it.

CREATE TABLE `session_product_cart` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session_product_cart` (`session_id`, `product_id`),
    KEY `idx_session_product_cart_product` (`product_id`),
    CONSTRAINT `fk_session_product_cart_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `chk_session_product_cart_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
