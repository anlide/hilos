-- Sessions already signed in on 127 have left their epoch-zero guest cart.
UPDATE `hilos_session`
SET `cart_epoch` = 1
WHERE `cart_epoch` = 0 AND (`user_id` IS NOT NULL OR `impersonator_user_id` IS NOT NULL);

-- Existing bindings belong to epoch zero on the uninterrupted upgrade from 127.
ALTER TABLE `session_voucher_cart`
    DROP PRIMARY KEY,
    ADD COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD COLUMN `cart_epoch` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `session_id`,
    ADD PRIMARY KEY (`id`),
    ADD UNIQUE KEY `uk_session_voucher_cart` (`session_id`, `cart_epoch`);
