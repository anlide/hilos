DELIMITER $$
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM `hilos_access_log` WHERE `occurred_at` < 1000 OR `occurred_at` > 2147483647999) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Person moment cannot be represented by the legacy type';
    END IF;
    ALTER TABLE `hilos_access_log` ADD COLUMN `__hil1924_occurred_at` TIMESTAMP NULL DEFAULT NULL AFTER `occurred_at`;
    UPDATE `hilos_access_log` SET `__hil1924_occurred_at` = FROM_UNIXTIME(FLOOR(`occurred_at` / 1000));
    ALTER TABLE `hilos_access_log`
        DROP INDEX `idx_access_log_user`,
        DROP INDEX `idx_access_log_occurred`,
        DROP COLUMN `occurred_at`,
        CHANGE COLUMN `__hil1924_occurred_at` `occurred_at` TIMESTAMP NOT NULL,
        ADD KEY `idx_access_log_user` (`user_id`, `occurred_at`),
        ADD KEY `idx_access_log_occurred` (`occurred_at`);
END$$
DELIMITER ;
