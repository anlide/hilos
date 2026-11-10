ALTER TABLE `guest` ADD COLUMN `__hil1930_created_at` TIMESTAMP NULL;
UPDATE `guest` SET `__hil1930_created_at` = FROM_UNIXTIME(`created_at` DIV 1000);
ALTER TABLE `guest` MODIFY COLUMN `__hil1930_created_at` DATETIME NOT NULL;
ALTER TABLE `guest` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1930_created_at` `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
