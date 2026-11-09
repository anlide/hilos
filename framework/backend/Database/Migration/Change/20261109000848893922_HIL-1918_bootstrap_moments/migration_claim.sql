DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_migration_claim' AND COLUMN_NAME = 'claimed_at') = 'datetime' THEN
        ALTER TABLE `hilos_migration_claim` ADD COLUMN IF NOT EXISTS `__hil1918_claimed_at` BIGINT NULL;
        UPDATE `hilos_migration_claim` SET `__hil1918_claimed_at` = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', `claimed_at`) DIV 1000;
        ALTER TABLE `hilos_migration_claim` DROP COLUMN `claimed_at`, CHANGE COLUMN `__hil1918_claimed_at` `claimed_at` BIGINT NOT NULL;
    ELSEIF NOT ((SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_migration_claim' AND COLUMN_NAME = 'claimed_at') REGEXP '^bigint(\\([0-9]+\\))?( signed)?$') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported bootstrap moment type';
    END IF;
END$$
DELIMITER ;
