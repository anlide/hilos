ALTER TABLE `hilos_step_up` ADD COLUMN `__hil1921_confirmed_until` BIGINT NULL AFTER `confirmed_until`, ADD COLUMN `__hil1921_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_step_up` SET `__hil1921_confirmed_until` = UNIX_TIMESTAMP(`confirmed_until`) * 1000, `__hil1921_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_step_up` DROP COLUMN `confirmed_until`, DROP COLUMN `created_at`, CHANGE COLUMN `__hil1921_confirmed_until` `confirmed_until` BIGINT NOT NULL, CHANGE COLUMN `__hil1921_created_at` `created_at` BIGINT NOT NULL;
