DELIMITER $$
BEGIN NOT ATOMIC
    IF (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_migration_claim' AND COLUMN_NAME = 'claimed_at') REGEXP '^bigint(\\([0-9]+\\))?( signed)?$' THEN
        IF EXISTS (SELECT 1 FROM `hilos_migration_claim` WHERE `claimed_at` < -30610224000000 OR `claimed_at` > 253402300799999) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bootstrap moment cannot be represented by the legacy type';
        END IF;
        ALTER TABLE `hilos_migration_claim` ADD COLUMN IF NOT EXISTS `__hil1918_claimed_at` DATETIME NULL;
        UPDATE `hilos_migration_claim` SET `__hil1918_claimed_at` = TIMESTAMPADD(SECOND, FLOOR(`claimed_at` / 1000), '1970-01-01 00:00:00');
        ALTER TABLE `hilos_migration_claim` DROP COLUMN `claimed_at`, CHANGE COLUMN `__hil1918_claimed_at` `claimed_at` DATETIME NOT NULL;
    ELSEIF NOT ((SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hilos_migration_claim' AND COLUMN_NAME = 'claimed_at') = 'datetime') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported bootstrap moment type';
    END IF;
END$$
DELIMITER ;
