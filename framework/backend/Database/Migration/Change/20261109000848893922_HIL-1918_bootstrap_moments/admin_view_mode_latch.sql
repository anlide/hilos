DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_admin_view_mode_latch' AND COLUMN_NAME = 'closed_at') REGEXP '^int(\\([0-9]+\\))? unsigned$' THEN
        ALTER TABLE `hilos_admin_view_mode_latch` ADD COLUMN IF NOT EXISTS `__hil1918_closed_at` BIGINT NULL;
        UPDATE `hilos_admin_view_mode_latch` SET `__hil1918_closed_at` = CAST(`closed_at` AS SIGNED) * 1000;
        ALTER TABLE `hilos_admin_view_mode_latch` DROP COLUMN `closed_at`, CHANGE COLUMN `__hil1918_closed_at` `closed_at` BIGINT NOT NULL;
    ELSEIF NOT ((SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_admin_view_mode_latch' AND COLUMN_NAME = 'closed_at') REGEXP '^bigint(\\([0-9]+\\))?( signed)?$') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported bootstrap moment type';
    END IF;
END$$
DELIMITER ;
