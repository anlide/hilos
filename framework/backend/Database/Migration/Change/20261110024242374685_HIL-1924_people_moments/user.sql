ALTER TABLE `hilos_user` ADD COLUMN `__hil1924_last_activity` BIGINT NULL AFTER `last_activity`;
UPDATE `hilos_user` SET `__hil1924_last_activity` = UNIX_TIMESTAMP(`last_activity`) * 1000;
ALTER TABLE `hilos_user` DROP INDEX `idx_user_last_activity`, DROP COLUMN `last_activity`, CHANGE COLUMN `__hil1924_last_activity` `last_activity` BIGINT NULL DEFAULT NULL, ADD KEY `idx_user_last_activity` (`last_activity`);
