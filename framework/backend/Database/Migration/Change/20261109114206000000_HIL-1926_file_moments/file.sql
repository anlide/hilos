ALTER TABLE `hilos_file` ADD COLUMN `__hil1926_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_file` SET `__hil1926_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_file` DROP INDEX `idx_file_bound_created`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1926_created_at` `created_at` BIGINT NOT NULL, ADD KEY `idx_file_bound_created` (`bound`, `created_at`);
