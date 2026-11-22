-- Migration: Create user_voucher_cart table
--
-- A person has one row while its cart holds a voucher.
-- RESTRICT leaves removal of the binding to the person's agent during erasure.

CREATE TABLE `user_voucher_cart` (
    `user_id` INT UNSIGNED NOT NULL,
    `voucher_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`),
    KEY `idx_user_voucher_cart_voucher` (`voucher_id`),
    CONSTRAINT `fk_user_voucher_cart_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_user_voucher_cart_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `voucher` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
