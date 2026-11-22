-- Migration: Create session_voucher_cart table
--
-- A browser session has one row while its cart holds a voucher.
-- The session reference stays soft so the session sweep can finish before the cart is cleared.

CREATE TABLE `session_voucher_cart` (
    `session_id` INT UNSIGNED NOT NULL,
    `voucher_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`session_id`),
    KEY `idx_session_voucher_cart_voucher` (`voucher_id`),
    CONSTRAINT `fk_session_voucher_cart_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `voucher` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
