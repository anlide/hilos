-- hilos-framework: {"format":1,"kind":"change","units":["database_marker"],"parts":[{"changeId":"20261109000848893922_HIL-1918_bootstrap_moments","unit":"database_marker","upSha256":"43cd04116985ea537a7b98dffac6da618bd4a2bc7798162db3a0b5284fb8d2ed","downSha256":"aecb2b9f635b78da7bfb11b3b4d327d966329305a057673a6ce5c3076f2f2a3c"}],"upSha256":"43cd04116985ea537a7b98dffac6da618bd4a2bc7798162db3a0b5284fb8d2ed","downSha256":"aecb2b9f635b78da7bfb11b3b4d327d966329305a057673a6ce5c3076f2f2a3c"}
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
