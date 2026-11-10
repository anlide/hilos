ALTER TABLE `hilos_oauth_provider` ADD COLUMN `__hil1920_created_at` BIGINT NULL AFTER `created_at`, ADD COLUMN `__hil1920_updated_at` BIGINT NULL AFTER `updated_at`;
UPDATE `hilos_oauth_provider` SET `__hil1920_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000, `__hil1920_updated_at` = UNIX_TIMESTAMP(`updated_at`) * 1000;
ALTER TABLE `hilos_oauth_provider` DROP COLUMN `created_at`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1920_created_at` `created_at` BIGINT NOT NULL, CHANGE COLUMN `__hil1920_updated_at` `updated_at` BIGINT NOT NULL;
