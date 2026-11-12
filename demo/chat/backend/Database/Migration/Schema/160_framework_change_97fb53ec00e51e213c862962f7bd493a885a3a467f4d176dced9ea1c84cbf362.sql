-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_backup_code"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_backup_code","upSha256":"470a756947e89840cb217a0457e18fe3a71401c36602f6d8c47196c618abadce","downSha256":"b63ed8ffb9da7646503e98f164725fd19e5016377e445ab987734555c25ac29c"}],"upSha256":"470a756947e89840cb217a0457e18fe3a71401c36602f6d8c47196c618abadce","downSha256":"b63ed8ffb9da7646503e98f164725fd19e5016377e445ab987734555c25ac29c"}
ALTER TABLE `hilos_second_factor_backup_code`
    ADD COLUMN `__hil1922_used_at` BIGINT NULL AFTER `used_at`,
    ADD COLUMN `__hil1922_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_second_factor_backup_code` SET
    `__hil1922_used_at` = UNIX_TIMESTAMP(`used_at`) * 1000,
    `__hil1922_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_second_factor_backup_code`
    DROP COLUMN `used_at`, ADD COLUMN `used_at` BIGINT NULL DEFAULT NULL AFTER `__hil1922_used_at`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1922_created_at`;
UPDATE `hilos_second_factor_backup_code` SET
    `used_at` = `__hil1922_used_at`,
    `created_at` = `__hil1922_created_at`;
ALTER TABLE `hilos_second_factor_backup_code`
    DROP COLUMN `__hil1922_used_at`,
    DROP COLUMN `__hil1922_created_at`;
