ALTER TABLE `hilos_verifier_circle` ADD COLUMN `__hil1919_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_verifier_circle` SET `__hil1919_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_verifier_circle` DROP COLUMN `created_at`, CHANGE COLUMN `__hil1919_created_at` `created_at` BIGINT NOT NULL;
