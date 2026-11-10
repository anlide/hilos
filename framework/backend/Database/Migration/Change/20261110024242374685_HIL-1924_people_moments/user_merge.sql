ALTER TABLE `hilos_user_merge` ADD COLUMN `__hil1924_merged_at` BIGINT NULL AFTER `merged_at`;
UPDATE `hilos_user_merge` SET `__hil1924_merged_at` = UNIX_TIMESTAMP(`merged_at`) * 1000;
ALTER TABLE `hilos_user_merge` DROP COLUMN `merged_at`, CHANGE COLUMN `__hil1924_merged_at` `merged_at` BIGINT NULL DEFAULT NULL;
