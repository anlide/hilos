ALTER TABLE `hilos_user_rename` ADD COLUMN `__hil1924_renamed_at` BIGINT NULL AFTER `renamed_at`;
UPDATE `hilos_user_rename` SET `__hil1924_renamed_at` = UNIX_TIMESTAMP(`renamed_at`) * 1000;
ALTER TABLE `hilos_user_rename` DROP COLUMN `renamed_at`, CHANGE COLUMN `__hil1924_renamed_at` `renamed_at` BIGINT NOT NULL;
