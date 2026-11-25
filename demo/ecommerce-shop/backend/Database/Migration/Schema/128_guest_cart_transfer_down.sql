DELIMITER $$
BEGIN NOT ATOMIC
    -- Even a received transfer can still have source lines awaiting cleanup.
    IF EXISTS (SELECT 1 FROM `shop_guest_cart_transfer`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Guest cart transfers must be closed before rollback';
    END IF;
    IF EXISTS (
        SELECT 1 FROM `session_product_cart` GROUP BY `session_id`, `product_id` HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Guest cart lines share a legacy session and product key';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM `session_product_cart` AS cart
        JOIN `hilos_session` AS session ON session.`id` = cart.`session_id`
        WHERE cart.`cart_epoch` <> session.`cart_epoch`
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback would reactivate an older guest cart';
    END IF;
    IF EXISTS (SELECT 1 FROM `user_product_cart` WHERE `quantity` > 4294967295) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account cart quantity exceeds the legacy range';
    END IF;

    ALTER TABLE `user_product_cart`
        DROP CONSTRAINT `chk_user_product_cart_quantity`,
        MODIFY COLUMN `quantity` INT UNSIGNED NOT NULL,
        ADD CONSTRAINT `chk_user_product_cart_quantity` CHECK (`quantity` > 0);

    ALTER TABLE `session_product_cart`
        DROP INDEX `uk_session_product_cart`,
        DROP COLUMN `cart_epoch`,
        ADD UNIQUE KEY `uk_session_product_cart` (`session_id`, `product_id`);

    ALTER TABLE `hilos_session` DROP COLUMN `cart_epoch`;
    DROP TABLE `shop_guest_cart_transfer`;
END$$
DELIMITER ;
