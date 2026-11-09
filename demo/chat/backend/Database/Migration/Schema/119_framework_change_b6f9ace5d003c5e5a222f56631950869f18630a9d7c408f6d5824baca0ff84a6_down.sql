-- hilos-framework: {"format":1,"kind":"change","units":["admin_view_mode_latch"],"parts":[{"changeId":"20261109000848893922_HIL-1918_bootstrap_moments","unit":"admin_view_mode_latch","upSha256":"36835d946746adbefe4bf10afa273dd0bbcaecc605ab9006f7d4748d846372a8","downSha256":"58e821093cc581a3429d7cb24678a6befa452f04e0e4767dfda442e919d4553a"}],"upSha256":"36835d946746adbefe4bf10afa273dd0bbcaecc605ab9006f7d4748d846372a8","downSha256":"58e821093cc581a3429d7cb24678a6befa452f04e0e4767dfda442e919d4553a"}
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
