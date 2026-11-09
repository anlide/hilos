DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_admin_view_mode_latch' AND COLUMN_NAME = 'closed_at') REGEXP '^bigint(\\([0-9]+\\))?( signed)?$' THEN
        IF EXISTS (SELECT 1 FROM `hilos_admin_view_mode_latch` WHERE `closed_at` < 0 OR `closed_at` > 4294967295999) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bootstrap moment cannot be represented by the legacy type';
        END IF;
        ALTER TABLE `hilos_admin_view_mode_latch` ADD COLUMN IF NOT EXISTS `__hil1918_closed_at` INT UNSIGNED NULL;
        UPDATE `hilos_admin_view_mode_latch` SET `__hil1918_closed_at` = FLOOR(`closed_at` / 1000);
        ALTER TABLE `hilos_admin_view_mode_latch` DROP COLUMN `closed_at`, CHANGE COLUMN `__hil1918_closed_at` `closed_at` INT UNSIGNED NOT NULL;
    ELSEIF NOT ((SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_admin_view_mode_latch' AND COLUMN_NAME = 'closed_at') REGEXP '^int(\\([0-9]+\\))? unsigned$') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported bootstrap moment type';
    END IF;
END$$
DELIMITER ;
