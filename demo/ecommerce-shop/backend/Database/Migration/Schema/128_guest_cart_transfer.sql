-- A browser session starts with product cart epoch zero; older carts can await transfer.
ALTER TABLE `hilos_session`
    ADD COLUMN `cart_epoch` INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `session_product_cart`
    ADD COLUMN `cart_epoch` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `session_id`,
    DROP INDEX `uk_session_product_cart`,
    ADD UNIQUE KEY `uk_session_product_cart` (`session_id`, `cart_epoch`, `product_id`);

-- A transfer can outlive both the source session and its cart lines.
CREATE TABLE `shop_guest_cart_transfer` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `cart_epoch` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `received` BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shop_guest_cart_transfer` (`session_id`, `cart_epoch`),
    KEY `idx_shop_guest_cart_transfer_user` (`user_id`),
    CONSTRAINT `fk_shop_guest_cart_transfer_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- An account cart can hold the full transfer sum within JavaScript's safe integer range.
ALTER TABLE `user_product_cart`
    DROP CONSTRAINT `chk_user_product_cart_quantity`,
    MODIFY COLUMN `quantity` BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT `chk_user_product_cart_quantity` CHECK (`quantity` BETWEEN 1 AND 9007199254740991);
