-- hilos-framework: {"format":1,"kind":"change","units":["second_factor"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor","upSha256":"9ab179260b9273ed84d44f9bdc2ceb2af92f5880519bd8ccdaea347bb6a6ed6c","downSha256":"2aa7867882c78c7bb3b26b7fde839d5323ba17d064f7b5f1146f3bc7985d1ae0"}],"upSha256":"9ab179260b9273ed84d44f9bdc2ceb2af92f5880519bd8ccdaea347bb6a6ed6c","downSha256":"2aa7867882c78c7bb3b26b7fde839d5323ba17d064f7b5f1146f3bc7985d1ae0"}
ALTER TABLE `hilos_second_factor`
    ADD COLUMN `__hil1922_confirmed_at` BIGINT NULL AFTER `confirmed_at`,
    ADD COLUMN `__hil1922_last_used_at` BIGINT NULL AFTER `last_used_at`,
    ADD COLUMN `__hil1922_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_second_factor` SET
    `__hil1922_confirmed_at` = UNIX_TIMESTAMP(`confirmed_at`) * 1000,
    `__hil1922_last_used_at` = UNIX_TIMESTAMP(`last_used_at`) * 1000,
    `__hil1922_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_second_factor`
    DROP COLUMN `confirmed_at`, ADD COLUMN `confirmed_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_confirmed_at`,
    DROP COLUMN `last_used_at`, ADD COLUMN `last_used_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_last_used_at`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1922_created_at`;
UPDATE `hilos_second_factor` SET
    `confirmed_at` = `__hil1922_confirmed_at`,
    `last_used_at` = `__hil1922_last_used_at`,
    `created_at` = `__hil1922_created_at`;
ALTER TABLE `hilos_second_factor`
    DROP COLUMN `__hil1922_confirmed_at`,
    DROP COLUMN `__hil1922_last_used_at`,
    DROP COLUMN `__hil1922_created_at`;
