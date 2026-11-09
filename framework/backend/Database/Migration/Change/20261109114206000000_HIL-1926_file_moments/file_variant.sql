ALTER TABLE `hilos_file_variant` ADD COLUMN `__hil1926_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_file_variant` SET `__hil1926_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_file_variant` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1926_created_at` `created_at` BIGINT NOT NULL;
