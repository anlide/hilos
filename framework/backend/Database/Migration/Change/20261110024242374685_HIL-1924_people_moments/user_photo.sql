ALTER TABLE `hilos_user_photo` ADD COLUMN `__hil1924_set_at` BIGINT NULL AFTER `set_at`;
UPDATE `hilos_user_photo` SET `__hil1924_set_at` = UNIX_TIMESTAMP(`set_at`) * 1000;
ALTER TABLE `hilos_user_photo` DROP COLUMN `set_at`, CHANGE COLUMN `__hil1924_set_at` `set_at` BIGINT NOT NULL;
