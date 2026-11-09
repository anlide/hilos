DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_database_marker' AND COLUMN_NAME = 'written_at') = 'datetime' THEN
        ALTER TABLE `hilos_database_marker` ADD COLUMN IF NOT EXISTS `__hil1918_written_at` BIGINT NULL;
        UPDATE `hilos_database_marker` SET `__hil1918_written_at` = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `written_at`) DIV 1000;
        ALTER TABLE `hilos_database_marker` DROP COLUMN `written_at`, CHANGE COLUMN `__hil1918_written_at` `written_at` BIGINT NOT NULL;
    ELSEIF NOT ((SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_database_marker' AND COLUMN_NAME = 'written_at') REGEXP '^bigint(\\([0-9]+\\))?( signed)?$') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported bootstrap moment type';
    END IF;
END$$
DELIMITER ;
