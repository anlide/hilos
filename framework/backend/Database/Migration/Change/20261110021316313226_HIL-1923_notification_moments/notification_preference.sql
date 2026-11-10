ALTER TABLE `hilos_notification_preference` ADD COLUMN `__hil1923_created_at` BIGINT NULL, ADD COLUMN `__hil1923_updated_at` BIGINT NULL;
UPDATE `hilos_notification_preference` SET `__hil1923_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000, `__hil1923_updated_at` = UNIX_TIMESTAMP(`updated_at`) * 1000;
ALTER TABLE `hilos_notification_preference` DROP COLUMN `created_at`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1923_created_at` `created_at` BIGINT NOT NULL, CHANGE COLUMN `__hil1923_updated_at` `updated_at` BIGINT NOT NULL;
