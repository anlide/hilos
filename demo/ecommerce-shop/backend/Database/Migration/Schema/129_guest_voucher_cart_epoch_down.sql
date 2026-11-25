DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `session_voucher_cart` GROUP BY `session_id` HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Guest voucher slots share a legacy session key';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM `session_voucher_cart` AS cart
        JOIN `hilos_session` AS session ON session.`id` = cart.`session_id`
        WHERE cart.`cart_epoch` <> session.`cart_epoch`
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback would reactivate an older guest voucher';
    END IF;

    ALTER TABLE `session_voucher_cart`
        DROP PRIMARY KEY,
        DROP INDEX `uk_session_voucher_cart`,
        DROP COLUMN `id`,
        DROP COLUMN `cart_epoch`,
        ADD PRIMARY KEY (`session_id`);
END$$
DELIMITER ;
