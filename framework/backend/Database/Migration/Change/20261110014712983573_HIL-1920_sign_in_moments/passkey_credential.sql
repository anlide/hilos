ALTER TABLE `hilos_passkey_credential` ADD COLUMN `__hil1920_last_used_at` BIGINT NULL AFTER `last_used_at`, ADD COLUMN `__hil1920_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_passkey_credential` SET `__hil1920_last_used_at` = UNIX_TIMESTAMP(`last_used_at`) * 1000, `__hil1920_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_passkey_credential` DROP COLUMN `last_used_at`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1920_last_used_at` `last_used_at` BIGINT NULL DEFAULT NULL, CHANGE COLUMN `__hil1920_created_at` `created_at` BIGINT NOT NULL;
