DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (
        SELECT 1 FROM `shop_guest_cart_transfer`
        WHERE `received` = FALSE AND `voucher_superseded` = TRUE
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rollback would reactivate a superseded guest voucher';
    END IF;

    ALTER TABLE `shop_guest_cart_transfer` DROP COLUMN `voucher_superseded`;
END$$
DELIMITER ;
