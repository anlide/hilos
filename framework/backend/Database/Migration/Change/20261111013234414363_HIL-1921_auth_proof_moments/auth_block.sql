ALTER TABLE `hilos_auth_block` ADD COLUMN `__hil1921_blocked_until` BIGINT NULL AFTER `blocked_until`, ADD COLUMN `__hil1921_updated_at` BIGINT NULL AFTER `updated_at`;
UPDATE `hilos_auth_block` SET `__hil1921_blocked_until` = UNIX_TIMESTAMP(`blocked_until`) * 1000, `__hil1921_updated_at` = UNIX_TIMESTAMP(`updated_at`) * 1000;
ALTER TABLE `hilos_auth_block` DROP COLUMN `blocked_until`, DROP COLUMN `updated_at`, CHANGE COLUMN `__hil1921_blocked_until` `blocked_until` BIGINT NULL DEFAULT NULL, CHANGE COLUMN `__hil1921_updated_at` `updated_at` BIGINT NOT NULL;
