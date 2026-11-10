ALTER TABLE `guest` ADD COLUMN `__hil1930_created_at` BIGINT NULL;
UPDATE `guest` SET `__hil1930_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `guest` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1930_created_at` `created_at` BIGINT NOT NULL;
