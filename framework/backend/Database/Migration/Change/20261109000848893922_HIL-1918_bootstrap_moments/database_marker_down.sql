DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_database_marker' AND COLUMN_NAME = 'written_at') REGEXP '^bigint(\\([0-9]+\\))?( signed)?$' THEN
        IF EXISTS (SELECT 1 FROM `hilos_database_marker` WHERE `written_at` < -30610224000000 OR `written_at` > 253402300799999) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bootstrap moment cannot be represented by the legacy type';
        END IF;
        ALTER TABLE `hilos_database_marker` ADD COLUMN IF NOT EXISTS `__hil1918_written_at` DATETIME NULL;
        UPDATE `hilos_database_marker` SET `__hil1918_written_at` = TIMESTAMPADD(SECOND, FLOOR(`written_at` / 1000), '1970-01-01 00:00:00');
        ALTER TABLE `hilos_database_marker` DROP COLUMN `written_at`, CHANGE COLUMN `__hil1918_written_at` `written_at` DATETIME NOT NULL;
    ELSEIF NOT ((SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_database_marker' AND COLUMN_NAME = 'written_at') = 'datetime') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported bootstrap moment type';
    END IF;
END$$
DELIMITER ;
